<?php

namespace Guava\FilamentMcp\Commands;

use Filament\Facades\Filament;
use Guava\FilamentMcp\Models\McpToken;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class CreateTokenCommand extends Command
{
    protected $signature = 'mcp:token
        {user : The email of the user the token belongs to}
        {--name=CLI : A name for the token}
        {--panel= : Restrict the token to a single panel}
        {--guard= : The auth guard the user belongs to, if not the panel\'s}
        {--abilities=* : Token abilities, e.g. "posts:list" or "*"}
        {--expires= : Expiry in days}';

    protected $description = 'Create a Filament MCP access token for a user';

    public function handle(): int
    {
        $model = $this->resolveUserModel();

        if ($model === null) {
            return static::FAILURE;
        }

        $user = $model::query()->where('email', $this->argument('user'))->first();

        if ($user === null) {
            $this->error(sprintf(
                'No %s found with email [%s].',
                class_basename($model),
                $this->argument('user'),
            ));

            return static::FAILURE;
        }

        $abilities = array_values(array_filter((array) $this->option('abilities')));

        [$token, $plainTextToken] = McpToken::generate(
            tokenable: $user,
            name: (string) $this->option('name'),
            abilities: $abilities === [] ? ['*'] : $abilities,
            panelId: $this->option('panel') ?: null,
            expiresAt: $this->option('expires') ? now()->addDays((int) $this->option('expires')) : null,
        );

        $this->info('MCP token created. Store it now, it will not be shown again:');
        $this->newLine();
        $this->line("  <options=bold>{$plainTextToken}</>");
        $this->newLine();

        return static::SUCCESS;
    }

    /**
     * The model the token's user is looked up on.
     *
     * A panel authenticates through its own guard, often not the default one (an
     * admin panel backed by an `Admin` model is the common case). Reading
     * `auth.providers.users.model` unconditionally would issue a token against a
     * user the panel cannot authenticate.
     *
     * @return class-string<Model>|null
     */
    protected function resolveUserModel(): ?string
    {
        $guard = $this->option('guard') ?: $this->getPanelGuard();

        if ($guard === false) {
            return null;
        }

        $guard ??= config('auth.defaults.guard');

        $provider = config("auth.guards.{$guard}.provider");

        if (blank($provider)) {
            $this->error("The [{$guard}] guard has no user provider configured.");

            return null;
        }

        /** @var class-string<Model>|null $model */
        $model = config("auth.providers.{$provider}.model");

        if (blank($model) || ! class_exists($model)) {
            $this->error("The [{$provider}] user provider has no Eloquent model configured.");

            return null;
        }

        return $model;
    }

    /**
     * The guard of the panel this token is scoped to, null when it is not
     * scoped to one, or false when the named panel does not exist.
     */
    protected function getPanelGuard(): string | false | null
    {
        $panel = $this->option('panel');

        if (blank($panel)) {
            return null;
        }

        try {
            return Filament::getPanel($panel)->getAuthGuard();
        } catch (Throwable) {
            $this->error("Panel [{$panel}] is not registered.");

            return false;
        }
    }
}
