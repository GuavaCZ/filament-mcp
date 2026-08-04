<?php

namespace Guava\FilamentMcp\Support;

use Filament\Panel;
use Filament\Resources\Resource;
use Guava\FilamentMcp\Mcp\ServerDefinition;
use RuntimeException;

/**
 * Applies Filament's tenancy wiring to resources that are exposed only over MCP.
 *
 * Filament registers the tenant global scope and the tenant-association observer
 * in `Panel::boot()`, iterating the panel's own `->resources([])` list.
 * `McpPlugin::resources([...])` is a separate list and nothing reconciles the two,
 * so a resource exposed over MCP but not registered on the panel would get
 * neither, leaving `getEloquentQuery()` unscoped across every tenant.
 *
 * This calls the same methods `Panel::boot()` calls rather than reimplementing
 * tenant scoping, since a subtly wrong reimplementation fails silently.
 */
class TenancyRegistrar
{
    /**
     * Models whose creation observer has been registered, per panel.
     *
     * Filament's global-scope registration is idempotent, but
     * `observeTenancyModelCreation()` is not: calling it twice registers the
     * `creating` listener twice and associates the tenant twice on every create.
     * Model event listeners live for the lifetime of the process, so this
     * registrar is a singleton rather than scoped, since a per-request guard would
     * let listeners pile up under Octane.
     *
     * @var array<string, array<class-string, true>>
     */
    protected array $observed = [];

    public function apply(Panel $panel, ?ServerDefinition $definition): void
    {
        if ($definition === null || ! $panel->hasTenancy()) {
            return;
        }

        foreach ($definition->mcpResources as $mcpResource) {
            $this->applyTo($panel, $mcpResource->getResource());
        }
    }

    /**
     * @param  class-string<resource>  $resource
     */
    protected function applyTo(Panel $panel, string $resource): void
    {
        $this->assertFilamentApiIsPresent($resource);

        $resource::registerTenancyModelGlobalScope($panel);

        $panelId = $panel->getId();

        if (! isset($this->observed[$panelId][$resource])) {
            $resource::observeTenancyModelCreation($panel);

            $this->observed[$panelId][$resource] = true;
        }

        $this->assertScopeApplied($panel, $resource);
    }

    /**
     * The two public static methods `Panel::boot()` uses. A Filament upgrade
     * renaming or removing either would stop MCP-only resources being scoped, so
     * this fails loudly rather than silently.
     *
     * @param  class-string<resource>  $resource
     */
    protected function assertFilamentApiIsPresent(string $resource): void
    {
        foreach (['registerTenancyModelGlobalScope', 'observeTenancyModelCreation', 'isScopedToTenant'] as $method) {
            if (method_exists($resource, $method)) {
                continue;
            }

            throw new RuntimeException(
                "[{$resource}::{$method}()] is missing, so this package cannot apply tenant scoping to "
                . 'resources exposed only over MCP. This usually means an incompatible Filament version. '
                . 'Register the resource on the panel itself as a workaround, and please report this.',
            );
        }
    }

    /**
     * Verify the scope took, rather than trusting that it did.
     *
     * @param  class-string<resource>  $resource
     */
    protected function assertScopeApplied(Panel $panel, string $resource): void
    {
        // The resource opted out of tenant scoping explicitly, which is
        // Filament's escape hatch for cross-tenant data and means the same here
        // as it does in the panel.
        if (! $resource::isScopedToTenant()) {
            return;
        }

        $model = $resource::getModel();

        if (! class_exists($model) || $model::hasGlobalScope($panel->getTenancyScopeName())) {
            return;
        }

        throw new RuntimeException(
            "Tenant scoping could not be applied to [{$resource}] on the [{$panel->getId()}] panel, so its "
            . 'records would be readable and writable across every tenant. Register the resource on the panel '
            . 'with ->resources([]), or opt out deliberately with ' . class_basename($resource) . '::scopeToTenant(false).',
        );
    }
}
