<?php

namespace Guava\FilamentMcp;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Panel;
use Guava\FilamentMcp\Commands\CreateTokenCommand;
use Guava\FilamentMcp\Commands\PruneTokensCommand;
use Guava\FilamentMcp\Commands\PruneUploadsCommand;
use Guava\FilamentMcp\Http\Controllers\McpUploadController;
use Guava\FilamentMcp\Http\Middleware\ApplyMcpContext;
use Guava\FilamentMcp\Http\Middleware\AuthenticateMcp;
use Guava\FilamentMcp\Mcp\McpActionConfig;
use Guava\FilamentMcp\Mcp\ServerDefinition;
use Guava\FilamentMcp\Schema\FieldMapperRegistry;
use Guava\FilamentMcp\Servers\PanelMcpServer;
use Guava\FilamentMcp\Support\ActionDiscoverer;
use Guava\FilamentMcp\Support\ActionMarkRegistry;
use Guava\FilamentMcp\Support\CurrentMcpToken;
use Guava\FilamentMcp\Support\TenancyRegistrar;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentMcpServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-mcp';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations()
            ->hasMigration('create_mcp_tokens_table')
            ->hasMigration('create_mcp_uploads_table')
            ->runsMigrations()
            ->hasCommands(
                CreateTokenCommand::class,
                PruneTokensCommand::class,
                PruneUploadsCommand::class,
            )
        ;
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(McpServerRegistry::class, fn () => new McpServerRegistry);
        // Shared, so mappers registered by a panel survive later resolutions.
        $this->app->singleton(FieldMapperRegistry::class);
        // Singleton rather than scoped: it guards against registering a model's
        // `creating` listener twice, and those live as long as the process.
        $this->app->singleton(TenancyRegistrar::class);
        $this->app->scoped(ActionMarkRegistry::class);
        $this->app->scoped(ActionDiscoverer::class);
    }

    public function packageBooted(): void
    {
        $this->registerRateLimiter();
        $this->registerActionMacro();
        $this->registerUploadRoute();

        $this->app->booted(function () {
            foreach (Filament::getPanels() as $panel) {
                if (! $panel->hasPlugin(McpPlugin::ID)) {
                    continue;
                }

                $this->registerServer($panel);
            }
        });
    }

    protected function registerServer(Panel $panel): void
    {
        /** @var McpPlugin $plugin */
        $plugin = $panel->getPlugin(McpPlugin::ID);

        $definition = $this->app->make(McpServerRegistry::class)->compile($panel, $plugin);

        $path = $definition->tenantInPath
            ? $definition->path . '/{mcpTenant}'
            : $definition->path;

        Mcp::web($path, PanelMcpServer::class)
            ->name('filament-mcp.' . $definition->panelId)
            ->defaults('mcpPanel', $definition->panelId)
            ->middleware($this->getServerMiddleware($definition))
        ;

        if ($plugin->shouldServeLocally()) {
            Mcp::local($definition->panelId, PanelMcpServer::class);
        }
    }

    /**
     * @return array<int, class-string|string>
     */
    protected function getServerMiddleware(ServerDefinition $definition): array
    {
        return array_values(array_filter([
            config('filament-mcp.rate_limit') ? 'throttle:filament-mcp' : null,
            // Always authenticate, including on a public server. public() means
            // "guests are welcome", not "credentials are ignored"; AuthenticateMcp
            // lets guests through by itself when the server or one of its
            // resources is public.
            AuthenticateMcp::class,
            // After authentication, so it keys on who is actually calling rather
            // than on an unverified bearer token.
            config('filament-mcp.token_rate_limit') ? 'throttle:filament-mcp-token' : null,
            ApplyMcpContext::class,
            ...$definition->middleware,
        ]));
    }

    protected function registerActionMacro(): void
    {
        Action::macro('mcp', function (?string $description = null, ?Closure $schema = null, ?string $name = null): Action {
            /** @var Action $this The closure is rebound to the action instance when the macro runs. */
            app(ActionMarkRegistry::class)->mark($this, new McpActionConfig(
                description: $description,
                schema: $schema,
                name: $name,
            ));

            return $this;
        });
    }

    /**
     * The endpoint agents PUT file bytes to.
     *
     * Sits outside the MCP server and outside any panel: it carries no session
     * and no bearer token, only a URL signature bound to one upload id, so the
     * agent can hand the URL to curl without leaking a credential.
     */
    protected function registerUploadRoute(): void
    {
        $middleware = array_values(array_filter([
            'signed',
            config('filament-mcp.uploads.rate_limit') ? 'throttle:filament-mcp-uploads' : null,
        ]));

        Route::middleware($middleware)
            ->match(['put', 'post'], 'filament-mcp/uploads/{upload}', McpUploadController::class)
            ->name('filament-mcp.uploads.store')
        ;
    }

    /**
     * Two limiters, because one cannot be both early enough and specific enough.
     *
     * The first runs ahead of authentication and is keyed by IP. Keying it on the
     * bearer token would be free to defeat: the token is unverified there, so a
     * random bearer per request lands in a fresh bucket every time.
     *
     * The second runs after authentication, where the token is verified and worth
     * keying on. Agents often call from shared infrastructure, where a whole
     * platform collapses into one IP bucket and the noisiest tenant throttles all.
     */
    protected function registerRateLimiter(): void
    {
        RateLimiter::for(
            'filament-mcp',
            fn (Request $request) => $this->limit('rate_limit', $request->ip() ?? 'unknown'),
        );

        RateLimiter::for(
            'filament-mcp-token',
            fn (Request $request) => $this->limit('token_rate_limit', $this->getCallerKey($request)),
        );

        RateLimiter::for(
            'filament-mcp-uploads',
            fn (Request $request) => $this->limit('uploads.rate_limit', $request->ip() ?? 'unknown'),
        );
    }

    /**
     * Boot decides whether a limiter is attached at all, from the same config key.
     * This is the runtime half: a key emptied afterwards means no limit rather
     * than a fallback number nobody asked for.
     */
    protected function limit(string $key, string $by): Limit
    {
        $limit = config("filament-mcp.{$key}");

        return blank($limit)
            ? Limit::none()
            : Limit::perMinute((int) $limit)->by($by);
    }

    /**
     * Who this request is, as specifically as it can be identified.
     *
     * Prefixed per kind so a token id cannot collide with a user id and merge
     * the two buckets.
     */
    protected function getCallerKey(Request $request): string
    {
        if ($token = CurrentMcpToken::get()) {
            return 'token:' . $token->getKey();
        }

        // An app authenticating MCP through its own middleware has a user but
        // no MCP token; a guest on a public server has neither.
        if ($user = $request->user()) {
            return 'user:' . $user->getAuthIdentifier();
        }

        return 'ip:' . ($request->ip() ?? 'unknown');
    }
}
