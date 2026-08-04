<?php

namespace Guava\FilamentMcp\Concerns;

use Closure;
use Filament\Facades\Filament;
use Guava\FilamentMcp\Filament\Pages\ManageMcpTokens;
use Illuminate\Contracts\Auth\Authenticatable;
use UnitEnum;

/**
 * Self-service token management, mountable in two places.
 *
 * `tokens()` and `tokensOnProfilePage()` decide where the manager appears,
 * `authorizeTokens()` decides who may use it. They stay separate because an MCP
 * token carries the user's full panel authority, so the gate has to hold whichever
 * mount the request came through.
 */
trait HasTokenManagement
{
    protected bool | Closure $tokens = false;

    protected bool | Closure $tokensOnProfilePage = false;

    protected ?Closure $authorizeTokensUsing = null;

    /** @var class-string<ManageMcpTokens> */
    protected string $tokensPage = ManageMcpTokens::class;

    protected string | UnitEnum | null $tokensNavigationGroup = null;

    protected ?int $tokensNavigationSort = null;

    protected bool $hasRegisteredProfileRenderHook = false;

    /**
     * Register the standalone MCP tokens page on the panel.
     */
    public function tokens(bool | Closure $condition = true): static
    {
        $this->tokens = $condition;

        return $this;
    }

    /**
     * Append the token manager to the panel's profile page.
     */
    public function tokensOnProfilePage(bool | Closure $condition = true): static
    {
        $this->tokensOnProfilePage = $condition;

        return $this;
    }

    /**
     * Gate token management, whichever mount it is reached through.
     *
     * The callback receives the authenticated user.
     */
    public function authorizeTokens(?Closure $callback): static
    {
        $this->authorizeTokensUsing = $callback;

        return $this;
    }

    /**
     * @param  class-string<ManageMcpTokens>  $page
     */
    public function tokensPage(string $page): static
    {
        $this->tokensPage = $page;

        return $this;
    }

    public function tokensNavigationGroup(string | UnitEnum | null $group): static
    {
        $this->tokensNavigationGroup = $group;

        return $this;
    }

    public function tokensNavigationSort(?int $sort): static
    {
        $this->tokensNavigationSort = $sort;

        return $this;
    }

    /**
     * Whether the current user may manage MCP tokens at all.
     */
    public function canManageTokens(): bool
    {
        $user = Filament::auth()->user();

        if ($user === null) {
            return false;
        }

        if ($this->authorizeTokensUsing === null) {
            return true;
        }

        return (bool) $this->evaluate($this->authorizeTokensUsing, [
            'user' => $user,
        ], [
            Authenticatable::class => $user,
        ]);
    }

    public function hasTokensPage(): bool
    {
        return (bool) $this->evaluate($this->tokens) && $this->canManageTokens();
    }

    public function hasTokensOnProfilePage(): bool
    {
        return (bool) $this->evaluate($this->tokensOnProfilePage) && $this->canManageTokens();
    }

    /**
     * Either mount is enabled, regardless of the current user.
     */
    public function hasTokenManagement(): bool
    {
        return $this->isTokensPageRegistered() || $this->isProfilePageMountRegistered();
    }

    /**
     * Configuration-time check. Must not evaluate user closures, since it runs
     * while the panel is being built, before a request is authenticated.
     */
    public function isTokensPageRegistered(): bool
    {
        return $this->tokens !== false;
    }

    public function isProfilePageMountRegistered(): bool
    {
        return $this->tokensOnProfilePage !== false;
    }

    /**
     * @return class-string<ManageMcpTokens>
     */
    public function getTokensPage(): string
    {
        return $this->tokensPage;
    }

    public function getTokensNavigationGroup(): string | UnitEnum | null
    {
        return $this->tokensNavigationGroup;
    }

    public function getTokensNavigationSort(): ?int
    {
        return $this->tokensNavigationSort;
    }
}
