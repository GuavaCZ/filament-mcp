<?php

namespace Guava\FilamentMcp;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Guava\FilamentMcp\Concerns\CanBePublic;
use Guava\FilamentMcp\Concerns\CanDiscoverActions;
use Guava\FilamentMcp\Concerns\CanServeLocally;
use Guava\FilamentMcp\Concerns\HasCustomTools;
use Guava\FilamentMcp\Concerns\HasFieldMappers;
use Guava\FilamentMcp\Concerns\HasInstructions;
use Guava\FilamentMcp\Concerns\HasMcpResources;
use Guava\FilamentMcp\Concerns\HasMiddleware;
use Guava\FilamentMcp\Concerns\HasOauth;
use Guava\FilamentMcp\Concerns\HasPath;
use Guava\FilamentMcp\Concerns\HasPrompts;
use Guava\FilamentMcp\Concerns\HasServerName;
use Guava\FilamentMcp\Concerns\HasTenancy;
use Guava\FilamentMcp\Concerns\HasTokenManagement;
use Illuminate\Contracts\View\View;

class McpPlugin implements Plugin
{
    use CanBePublic;
    use CanDiscoverActions;
    use CanServeLocally;
    use EvaluatesClosures;
    use HasCustomTools;
    use HasFieldMappers;
    use HasInstructions;
    use HasMcpResources;
    use HasMiddleware;
    use HasOauth;
    use HasPath;
    use HasPrompts;
    use HasServerName;
    use HasTenancy;
    use HasTokenManagement;

    public const ID = 'guava::filament-mcp';

    public function getId(): string
    {
        return static::ID;
    }

    public function register(Panel $panel): void
    {
        $this->registerFieldMappers($panel->getId());

        if ($this->isTokensPageRegistered()) {
            $panel->pages([
                $this->getTokensPage(),
            ]);
        }
    }

    public function boot(Panel $panel): void
    {
        $this->registerProfileRenderHook($panel);
    }

    /**
     * Append the token manager to the panel's profile page.
     *
     * Runs in boot() because most panels call ->profile() after ->plugins(),
     * so the profile page class is not known during register().
     */
    protected function registerProfileRenderHook(Panel $panel): void
    {
        if ($this->hasRegisteredProfileRenderHook || ! $this->isProfilePageMountRegistered()) {
            return;
        }

        if (! $panel->hasProfile() || ($profilePage = $panel->getProfilePage()) === null) {
            return;
        }

        $this->hasRegisteredProfileRenderHook = true;

        $render = fn (): View => view('filament-mcp::hooks.profile-tokens');

        // Scoped to the configured page class, since BasePage::getRenderHookScopes()
        // returns [static::class] and a hook on the base class never fires for an
        // app's own subclass. Both hooks are needed because ->profile(isSimple: true)
        // swaps the layout and each layout exposes a different end hook.
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_END, $render, scopes: $profilePage);
        FilamentView::registerRenderHook(PanelsRenderHook::SIMPLE_PAGE_END, $render, scopes: $profilePage);
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }
}
