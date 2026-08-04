<?php

namespace Guava\FilamentMcp\Livewire;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Guava\FilamentMcp\Enums\McpOperation;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\Mcp\ServerDefinition;
use Guava\FilamentMcp\McpPlugin;
use Guava\FilamentMcp\McpServerRegistry;
use Guava\FilamentMcp\Models\McpToken;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * The token manager. The standalone page, the profile page section and the render
 * hook are all thin mounts of this component.
 */
class ManageMcpTokens extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * Re-check on every subsequent request, not only on the first render.
     *
     * `mount()` runs once. Opening the create modal, submitting it and revoking a
     * token all arrive as Livewire updates, which hydrate the component and skip
     * `mount()`. Without this an `authorizeTokens()` callback that starts
     * returning false keeps minting bearer credentials while the page stays open.
     */
    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * The gate every mount shares.
     */
    public static function canAccess(): bool
    {
        return static::resolvePlugin()?->canManageTokens() ?? false;
    }

    /**
     * The profile-page render hook also honours the condition passed to
     * `tokensOnProfilePage()`. Registration only tests that the condition is not
     * literally false, which happens before any user is authenticated, so the
     * closure form would otherwise degrade to "always on".
     */
    public static function canAccessOnProfilePage(): bool
    {
        return static::resolvePlugin()?->hasTokensOnProfilePage() ?? false;
    }

    protected static function resolvePlugin(): ?McpPlugin
    {
        $panel = Filament::getCurrentPanel();

        if ($panel === null || ! $panel->hasPlugin(McpPlugin::ID)) {
            return null;
        }

        return McpPlugin::get();
    }

    public function render(): View
    {
        return view('filament-mcp::livewire.manage-mcp-tokens');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->getTokensQuery())
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-mcp::tokens.columns.name'))
                    ->searchable(),
                TextColumn::make('abilities')
                    ->label(__('filament-mcp::tokens.columns.abilities'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === '*'
                        ? __('filament-mcp::tokens.badges.full_access')
                        : $state),
                TextColumn::make('panel_id')
                    ->label(__('filament-mcp::tokens.columns.panel_id'))
                    ->placeholder(__('filament-mcp::tokens.placeholders.all_panels'))
                    ->visible(fn (): bool => $this->hasGlobalTokens()),
                TextColumn::make('last_used_at')
                    ->label(__('filament-mcp::tokens.columns.last_used_at'))
                    ->dateTime()
                    ->placeholder(__('filament-mcp::tokens.placeholders.never')),
                TextColumn::make('expires_at')
                    ->label(__('filament-mcp::tokens.columns.expires_at'))
                    ->dateTime()
                    ->badge(fn (?Carbon $state): bool => $state?->isPast() ?? false)
                    ->color(fn (?Carbon $state): ?string => $state?->isPast() ? 'danger' : null)
                    ->placeholder(__('filament-mcp::tokens.placeholders.never')),
            ])
            ->headerActions([
                $this->createAction(),
            ])
            ->recordActions([
                $this->revokeAction(),
            ])
            ->emptyStateHeading(__('filament-mcp::tokens.empty.heading'))
            ->emptyStateDescription(__('filament-mcp::tokens.empty.description'))
            ->emptyStateIcon('heroicon-o-key')
            ->defaultSort('id', 'desc')
        ;
    }

    public function createAction(): Action
    {
        return Action::make('create')
            ->label(__('filament-mcp::tokens.actions.create.label'))
            ->modalHeading(__('filament-mcp::tokens.actions.create.modal_heading'))
            ->icon('heroicon-m-plus')
            ->schema([
                TextInput::make('name')
                    ->label(__('filament-mcp::tokens.actions.create.fields.name.label'))
                    ->helperText(__('filament-mcp::tokens.actions.create.fields.name.helper_text'))
                    ->required()
                    ->maxLength(255),
                Radio::make('access')
                    ->label(__('filament-mcp::tokens.actions.create.fields.access.label'))
                    ->options([
                        'full' => __('filament-mcp::tokens.actions.create.fields.access.options.full'),
                        'custom' => __('filament-mcp::tokens.actions.create.fields.access.options.custom'),
                    ])
                    ->descriptions([
                        'full' => __('filament-mcp::tokens.actions.create.fields.access.descriptions.full'),
                        'custom' => __('filament-mcp::tokens.actions.create.fields.access.descriptions.custom'),
                    ])
                    ->default('full')
                    ->live()
                    ->visible(fn (): bool => filled($this->getAbilityOptions())),
                CheckboxList::make('abilities')
                    ->label(__('filament-mcp::tokens.actions.create.fields.abilities.label'))
                    ->options(fn (): array => $this->getAbilityOptions())
                    ->columns(2)
                    ->bulkToggleable()
                    ->searchable()
                    ->required()
                    ->visible(fn (Get $get): bool => $get('access') === 'custom'),
                DatePicker::make('expires_at')
                    ->label(__('filament-mcp::tokens.actions.create.fields.expires_at.label'))
                    ->helperText(__('filament-mcp::tokens.actions.create.fields.expires_at.helper_text'))
                    ->minDate(today()->addDay()),
            ])
            ->action(function (array $data): void {
                $abilities = ($data['access'] ?? 'full') === 'custom'
                    ? array_values($data['abilities'] ?? [])
                    : ['*'];

                [, $plainTextToken] = McpToken::generate(
                    tokenable: $this->getTokenable(),
                    name: $data['name'],
                    abilities: filled($abilities) ? $abilities : ['*'],
                    panelId: Filament::getCurrentPanel()?->getId(),
                    // The picker is day-granular, so the token stays valid for the
                    // whole of the chosen day rather than expiring at midnight.
                    expiresAt: filled($data['expires_at'] ?? null)
                        ? Carbon::parse($data['expires_at'])->endOfDay()
                        : null,
                );

                // Swap the create modal for the reveal modal rather than storing
                // the secret on the component. Mounted action arguments are
                // dropped once the modal closes, whereas a public property would
                // be sent to the browser on every subsequent request.
                $this->replaceMountedAction('reveal', arguments: [
                    'token' => $plainTextToken,
                ]);
            })
        ;
    }

    public function revealAction(): Action
    {
        return Action::make('reveal')
            ->modalHeading(__('filament-mcp::tokens.actions.reveal.heading'))
            ->modalDescription(__('filament-mcp::tokens.actions.reveal.description'))
            ->modalIcon('heroicon-o-key')
            ->modalIconColor('success')
            ->modalWidth(Width::TwoExtraLarge)
            ->closeModalByClickingAway(false)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('filament-mcp::tokens.actions.reveal.close_label'))
            ->schema(fn (array $arguments): array => array_values(array_filter([
                $this->getRevealEntry('token', $arguments['token'] ?? ''),
                $this->getRevealEntry('command', $this->getConnectionCommand($arguments['token'] ?? '')),
                $this->getRevealEntry('config', $this->getConnectionConfig($arguments['token'] ?? '')),
            ])))
        ;
    }

    public function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('filament-mcp::tokens.actions.revoke.label'))
            ->modalHeading(__('filament-mcp::tokens.actions.revoke.modal_heading'))
            ->modalDescription(__('filament-mcp::tokens.actions.revoke.modal_description'))
            ->modalSubmitActionLabel(__('filament-mcp::tokens.actions.revoke.modal_submit_label'))
            ->color('danger')
            ->icon('heroicon-m-trash')
            ->requiresConfirmation()
            ->action(function (McpToken $record): void {
                $record->delete();

                Notification::make()
                    ->title(__('filament-mcp::tokens.actions.revoke.notification'))
                    ->success()
                    ->send()
                ;
            })
        ;
    }

    protected function getRevealEntry(string $name, ?string $state): ?TextEntry
    {
        if (blank($state)) {
            return null;
        }

        $entry = TextEntry::make($name)
            ->label(__("filament-mcp::tokens.actions.reveal.entries.{$name}.label"))
            ->state($state)
            ->fontFamily(FontFamily::Mono)
            ->size('sm')
            ->copyable()
            ->copyableState($state)
            ->copyMessage(__('filament-mcp::tokens.actions.reveal.copied'))
            // `copyable()` alone gives no visual cue that the text is clickable,
            // and the secret is shown exactly once.
            ->hintIcon('heroicon-m-clipboard-document', tooltip: __('filament-mcp::tokens.actions.reveal.copy_hint'))
            // The JSON snippet is pretty printed and needs preserved whitespace.
            // It sits on the value rather than the entry wrapper, or the wrapper's
            // own markup whitespace renders too.
            ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(sprintf(
                '<pre style="margin:0;white-space:pre-wrap;word-break:break-all;font:inherit">%s</pre>',
                e($state),
            )))
        ;

        $helperText = __("filament-mcp::tokens.actions.reveal.entries.{$name}.helper_text");

        return $helperText === "filament-mcp::tokens.actions.reveal.entries.{$name}.helper_text"
            ? $entry
            : $entry->helperText($helperText);
    }

    /**
     * The `claude mcp add` invocation for this panel, ready to paste.
     */
    protected function getConnectionCommand(string $plainTextToken): ?string
    {
        $url = $this->getServerUrl();

        if ($url === null) {
            return null;
        }

        return sprintf(
            'claude mcp add --transport http %s %s --header "Authorization: Bearer %s"',
            $this->getServerSlug(),
            $url,
            $plainTextToken,
        );
    }

    /**
     * The equivalent `mcpServers` entry for clients configured through JSON.
     */
    protected function getConnectionConfig(string $plainTextToken): ?string
    {
        $url = $this->getServerUrl();

        if ($url === null) {
            return null;
        }

        return (string) json_encode([
            'mcpServers' => [
                $this->getServerSlug() => [
                    'type' => 'http',
                    'url' => $url,
                    'headers' => [
                        'Authorization' => "Bearer {$plainTextToken}",
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    protected function getServerUrl(): ?string
    {
        $definition = $this->getServerDefinition();

        if ($definition === null) {
            return null;
        }

        $path = $definition->path;

        if ($definition->tenantInPath && ($tenant = Filament::getTenant()) !== null) {
            $path .= '/' . $tenant->getRouteKey();
        }

        return url($path);
    }

    protected function getServerSlug(): string
    {
        $definition = $this->getServerDefinition();

        if ($definition === null) {
            return 'mcp';
        }

        return Str::slug($definition->serverName) ?: $definition->panelId;
    }

    protected function getServerDefinition(): ?ServerDefinition
    {
        return app(McpServerRegistry::class)->get(Filament::getCurrentPanel()?->getId() ?? '');
    }

    protected function getTokenable(): Model
    {
        $user = Filament::auth()->user();

        abort_if($user === null, 403);

        return $user;
    }

    protected function getTokensQuery(): Builder
    {
        $user = $this->getTokenable();
        $panelId = Filament::getCurrentPanel()?->getId();

        return McpToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            // Tokens are minted against the panel they were created in, so each
            // panel's page lists only its own. Tokens without a panel work
            // everywhere and stay visible in all of them.
            ->when($panelId !== null, fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query
                    ->where('panel_id', $panelId)
                    ->orWhereNull('panel_id'),
            ))
        ;
    }

    protected function hasGlobalTokens(): bool
    {
        return $this->getTokensQuery()->whereNull('panel_id')->exists();
    }

    /**
     * Every ability this panel's server understands, labelled for humans.
     *
     * @return array<string, string>
     */
    protected function getAbilityOptions(): array
    {
        $definition = $this->getServerDefinition();

        if ($definition === null) {
            return [];
        }

        $options = [];

        foreach ($definition->mcpResources as $mcpResource) {
            $label = $this->getResourceLabel($mcpResource);

            foreach ($mcpResource->getOperations() as $operation) {
                $options["{$mcpResource->getPluralName()}:{$operation->value}"] = sprintf(
                    '%s: %s',
                    $label,
                    $this->getOperationLabel($operation),
                );
            }

            foreach ($mcpResource->getActions() as $action) {
                $options["{$mcpResource->getPluralName()}:{$action}"] = sprintf(
                    '%s: %s',
                    $label,
                    Str::headline($action),
                );
            }
        }

        return $options;
    }

    protected function getResourceLabel(McpResource $mcpResource): string
    {
        $resource = $mcpResource->getResource();

        // Filament's plural model labels are lowercase by convention ("posts"),
        // which reads poorly at the start of a checkbox label.
        return Str::ucfirst(class_exists($resource)
            ? $resource::getPluralModelLabel()
            : Str::headline($mcpResource->getPluralName()));
    }

    protected function getOperationLabel(McpOperation $operation): string
    {
        return __("filament-mcp::tokens.operations.{$operation->value}");
    }
}
