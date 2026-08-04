<?php

namespace Guava\FilamentMcp\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Guava\FilamentMcp\McpServerRegistry;
use Guava\FilamentMcp\Models\McpToken;
use Guava\FilamentMcp\Support\CurrentMcpToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthenticateMcp
{
    public function __construct(
        protected McpServerRegistry $registry,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->resolveToken($request);

        if ($token !== null) {
            $user = $token->tokenable;

            Auth::setUser($user);
            $request->setUserResolver(fn () => $user);
            CurrentMcpToken::set($token);

            $token->forceFill(['last_used_at' => now()])->saveQuietly();

            return $next($request);
        }

        // A caller presenting one of this package's tokens that did not
        // authenticate is refused, even on a server that welcomes guests.
        // Serving a revoked, expired or wrong-panel token as an anonymous caller
        // would make revocation look like it did nothing.
        if ($this->presentedPackageToken($request)) {
            return response()->json([
                'error' => 'Unauthenticated.',
            ], 401);
        }

        if ($this->allowsGuests($request)) {
            return $next($request);
        }

        return response()->json([
            'error' => 'Unauthenticated.',
        ], 401);
    }

    /**
     * Whether the request carries a bearer token minted by this package.
     *
     * Matched on the configured prefix, so a bearer belonging to another scheme
     * (an app authenticating MCP through its own middleware) falls through to
     * the guest path rather than being rejected here.
     */
    protected function presentedPackageToken(Request $request): bool
    {
        $bearer = $request->bearerToken();

        return filled($bearer)
            && str_starts_with($bearer, (string) config('filament-mcp.tokens.prefix', 'gmcp_'));
    }

    protected function resolveToken(Request $request): ?McpToken
    {
        $bearer = $request->bearerToken();

        if (blank($bearer)) {
            return null;
        }

        $token = McpToken::findByPlainTextToken($bearer);

        if ($token === null || $token->isExpired()) {
            return null;
        }

        // The tokenable is a MorphTo, so it is null once the user is deleted.
        // Treating that as an invalid token keeps the request on the 401 path
        // instead of handing null to Auth::setUser(), which is typed against
        // Authenticatable and would 500.
        if ($token->tokenable === null) {
            return null;
        }

        $panelId = $this->getPanelId($request);

        if ($token->panel_id !== null && $token->panel_id !== $panelId) {
            return null;
        }

        if (! $this->matchesPanelUserModel($token->tokenable, $panelId)) {
            return null;
        }

        return $token;
    }

    /**
     * A token authenticates by being set on the guard directly, which skips the
     * guard's own user provider. Nothing else compares the two, so without this
     * a token issued for a `User` would authenticate against a panel whose guard
     * is backed by `Admin`.
     */
    protected function matchesPanelUserModel(Authenticatable $user, ?string $panelId): bool
    {
        if ($panelId === null) {
            return true;
        }

        $model = $this->getPanelUserModel($panelId);

        // An unresolvable provider model means the app is doing something custom.
        // Leave the decision to that panel's own authorization.
        return $model === null || $user instanceof $model;
    }

    /**
     * @return class-string|null
     */
    protected function getPanelUserModel(string $panelId): ?string
    {
        try {
            $guard = Filament::getPanel($panelId)->getAuthGuard();
        } catch (Throwable) {
            return null;
        }

        $provider = config("auth.guards.{$guard}.provider");

        if (! is_string($provider)) {
            return null;
        }

        $model = config("auth.providers.{$provider}.model");

        return is_string($model) && class_exists($model) ? $model : null;
    }

    protected function allowsGuests(Request $request): bool
    {
        $panelId = $this->getPanelId($request);

        if ($panelId === null) {
            return false;
        }

        return (bool) $this->registry->get($panelId)?->hasPublicResources();
    }

    protected function getPanelId(Request $request): ?string
    {
        $panelId = $request->route()?->defaults['mcpPanel'] ?? null;

        return is_string($panelId) ? $panelId : null;
    }
}
