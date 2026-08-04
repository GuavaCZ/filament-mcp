<?php

namespace Guava\FilamentMcp\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Guava\FilamentMcp\McpServerRegistry;
use Guava\FilamentMcp\Support\TenancyRegistrar;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyMcpContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $panelId = $request->route()?->defaults['mcpPanel'] ?? null;

        if (is_string($panelId)) {
            $panel = Filament::getPanel($panelId);

            Filament::setCurrentPanel($panel);
            Filament::bootCurrentPanel();

            // Panel::boot() has just wired tenancy for the panel's own resources.
            // This covers the ones exposed only over MCP, which it never sees,
            // and must happen before any query runs.
            app(TenancyRegistrar::class)->apply($panel, app(McpServerRegistry::class)->get($panelId));

            $user = $request->user();

            if ($user !== null) {
                Filament::auth()->setUser($user);

                if ($response = $this->assertCanAccessPanel($user, $panel)) {
                    return $response;
                }

                if ($response = $this->assertEmailIsVerified($user, $panel)) {
                    return $response;
                }
            }

            if ($panel->hasTenancy()) {
                if ($response = $this->applyTenant($request, $panel)) {
                    return $response;
                }
            }
        }

        return $next($request);
    }

    /**
     * A bearer token is not a licence to enter a panel the user cannot enter
     * interactively.
     *
     * Mirrors Filament's own Authenticate middleware, which the MCP routes never
     * pass through. Without it a token minted without `--panel` reaches every
     * MCP-enabled panel, and a user whose panel access was revoked keeps working
     * until the token expires.
     */
    protected function assertCanAccessPanel(Authenticatable $user, Panel $panel): ?Response
    {
        $allowed = $user instanceof FilamentUser
            ? $user->canAccessPanel($panel)
            // Same rule Filament applies. A user model that does not implement
            // FilamentUser is only trusted in local development.
            : config('app.env') === 'local';

        if ($allowed) {
            return null;
        }

        return response()->json([
            'error' => "You cannot access the [{$panel->getId()}] panel.",
        ], 403);
    }

    /**
     * A panel that requires a verified email requires it here too.
     *
     * Filament enforces this as a `verified` middleware attached to a panel's
     * pages, and MCP routes are not panel pages, so nothing else applies it.
     */
    protected function assertEmailIsVerified(Authenticatable $user, Panel $panel): ?Response
    {
        if (! $panel->isEmailVerificationRequired()) {
            return null;
        }

        // Matching Filament, a user model that cannot express verification is
        // not held to it.
        if (! $user instanceof MustVerifyEmail || $user->hasVerifiedEmail()) {
            return null;
        }

        return response()->json([
            'error' => "Your email address must be verified to use the [{$panel->getId()}] panel.",
        ], 403);
    }

    protected function applyTenant(Request $request, Panel $panel): ?Response
    {
        $key = $request->route('mcpTenant') ?? $request->header(config('filament-mcp.tenant_header', 'X-Tenant'));

        if (blank($key) || ! is_string($key)) {
            return response()->json([
                'error' => 'This MCP server is tenant-scoped. Provide the tenant in the ['
                    . config('filament-mcp.tenant_header', 'X-Tenant')
                    . '] header or in the URL.',
            ], 400);
        }

        $tenant = $this->resolveTenant($panel, $key);

        if ($tenant === null) {
            return response()->json(['error' => "Tenant [{$key}] not found."], 404);
        }

        $user = Filament::auth()->user();

        if ($user !== null && $response = $this->assertCanAccessTenant($user, $tenant, $key)) {
            return $response;
        }

        // A fully public server has no user to check against. The tenant still
        // scopes every query, and only resources marked public() are reachable.

        Filament::setTenant($tenant, isQuiet: true);

        return null;
    }

    protected function assertCanAccessTenant(Authenticatable $user, Model $tenant, string $key): ?Response
    {
        // Filament refuses tenancy outright for a user model that cannot express
        // tenant membership. Folding `instanceof` into the denial condition below
        // would let exactly those users reach every tenant.
        if (! $user instanceof HasTenants) {
            return response()->json([
                'error' => 'This user cannot be scoped to a tenant.',
            ], 403);
        }

        if (! $user->canAccessTenant($tenant)) {
            return response()->json(['error' => "You cannot access tenant [{$key}]."], 403);
        }

        return null;
    }

    protected function resolveTenant(Panel $panel, string $key): ?Model
    {
        /** @var class-string<Model>|null $model */
        $model = $panel->getTenantModel();

        if ($model === null) {
            return null;
        }

        $attribute = $panel->getTenantSlugAttribute() ?? (new $model)->getKeyName();

        return $model::query()->where($attribute, $key)->first();
    }
}
