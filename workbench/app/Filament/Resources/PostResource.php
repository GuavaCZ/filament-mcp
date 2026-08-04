<?php

namespace Workbench\App\Filament\Resources;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use RuntimeException;
use Workbench\App\Filament\Resources\PostResource\Pages\ManagePosts;
use Workbench\App\Models\Post;

class PostResource extends Resource
{
    protected static ?string $model = Post::class;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                Textarea::make('content'),
                RichEditor::make('body'),
                RichEditor::make('body_json'),
                MarkdownEditor::make('summary'),
                FileUpload::make('thumbnail')
                    ->image()
                    ->disk('public')
                    ->directory('thumbnails')
                    ->maxSize(1024),
                FileUpload::make('gallery')
                    ->multiple()
                    ->disk('public')
                    ->directory('gallery')
                    ->maxFiles(3),
                // No accepted types and the agent's own filename kept, the most
                // permissive shape a file field can take.
                FileUpload::make('attachment')
                    ->preserveFilenames()
                    ->disk('public')
                    ->directory('attachments'),
                Repeater::make('links')
                    ->schema([
                        TextInput::make('label')->required(),
                        TextInput::make('url')->url()->required(),
                        Toggle::make('external'),
                    ])
                    ->maxItems(4),
                // Nested counterpart of GuardedNoteResource's `explodes`: a
                // sub-field whose rules cannot be compiled headlessly.
                Repeater::make('annotations')
                    ->schema([
                        TextInput::make('note')->required(),
                        TextInput::make('volatile')
                            ->required(fn () => throw new RuntimeException('needs runtime context')),
                    ]),
                Builder::make('blocks')
                    ->blocks([
                        Block::make('paragraph')->schema([
                            RichEditor::make('content')->required(),
                        ]),
                        Block::make('quote')->schema([
                            TextInput::make('text')->required(),
                            TextInput::make('attribution'),
                        ]),
                    ]),
                KeyValue::make('meta'),
                Select::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'published' => 'Published',
                        'archived' => 'Archived',
                    ])
                    ->default('draft')
                    ->required(),
                Select::make('category_id')
                    ->relationship('category', 'name'),
                // Filament marks a multiple relationship select dehydrated(false)
                // and writes it in saveRelationships(), so this is the field
                // shape the MCP write path has to sync rather than fill.
                Select::make('tags')
                    ->relationship('tags', 'name')
                    ->multiple(),
                TextInput::make('intro'),
                RichEditor::make('body_i18n')
                    ->json(),
                Toggle::make('is_featured'),
                DateTimePicker::make('published_at'),
                TextInput::make('secret_token')
                    ->dehydrated(false),
                // Stands in for the ->password()->dehydrateStateUsing(Hash::make)
                // shape, where the value must not reach the column as sent.
                TextInput::make('sealed_note')
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? "sealed:{$state}" : $state),
                // Role-gated. Hidden fields are kept out of writes and have to
                // stay out of reads too.
                TextInput::make('admin_note')
                    ->visible(fn (): bool => (bool) Filament::auth()->user()?->is_admin),
            ])
        ;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('status'),
                IconColumn::make('is_featured')
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('publish')
                    ->action(fn (Post $record) => $record->update(['status' => 'published']))
                    ->mcp(description: 'Publish the post.'),
                Action::make('feature')
                    ->authorize(fn () => false)
                    ->action(fn (Post $record) => $record->update(['is_featured' => true]))
                    ->mcp(),
                Action::make('unsafeAction')
                    ->action(fn ($livewire) => null)
                    ->mcp(),
                // Collects input before it runs, so `$data` has to arrive carrying
                // the modal's values and `reason` has to be enforced.
                Action::make('reject')
                    ->schema([
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(200),
                        Toggle::make('notify')
                            ->default(true),
                        // Not input: the panel would not submit either of these.
                        TextInput::make('ignored')
                            ->disabled(),
                        TextInput::make('undehydrated')
                            ->dehydrated(false),
                    ])
                    ->action(fn (Post $record, array $data) => $record->update([
                        'status' => 'archived',
                        'content' => $data['reason'],
                        'is_featured' => $data['notify'],
                    ]))
                    ->mcp(description: 'Reject the post with a reason.'),
                // A modal schema that cannot be built headlessly. Exposing it
                // would mean a tool that asks for nothing and validates nothing.
                Action::make('unsafeSchema')
                    ->schema(fn () => throw new RuntimeException('needs runtime context'))
                    ->action(fn (Post $record) => $record->update(['status' => 'published']))
                    ->mcp(),
                Action::make('archive')
                    ->action(fn (Post $record) => $record->update(['status' => 'archived'])),
                // Declares no ->authorize(), so the panel falls back to the
                // model policy. Named apart from the CRUD delete_post tool.
                DeleteAction::make()
                    ->mcp(name: 'remove_post'),
                // Throws with a message that must not reach the agent.
                Action::make('explode')
                    ->action(fn (Post $record) => throw new RuntimeException('database credentials are hunter2'))
                    ->mcp(),
            ])
        ;
    }

    public static function getGloballySearchableAttributes(): array
    {
        // `admin_note` is role-hidden by the form above and declared searchable
        // here, so a deny-list has to beat a resource's own searchable attributes
        // and not just the fallback derived from the form.
        return ['title', 'content', 'admin_note'];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePosts::route('/'),
        ];
    }
}
