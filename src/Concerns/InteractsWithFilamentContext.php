<?php

namespace Guava\FilamentMcp\Concerns;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Resources\Resource;
use Guava\FilamentMcp\Enums\McpOperation;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\McpServerRegistry;
use Guava\FilamentMcp\Models\McpToken;
use Guava\FilamentMcp\Support\CurrentMcpToken;
use Guava\FilamentMcp\Support\ResourceAuthorizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Helpers for custom MCP tools that interact with the current Filament
 * panel context.
 */
trait InteractsWithFilamentContext
{
    protected function panel(): ?Panel
    {
        return Filament::getCurrentPanel();
    }

    protected function tenant(): ?Model
    {
        return Filament::getTenant();
    }

    protected function user(): ?Authenticatable
    {
        return Filament::auth()->user();
    }

    protected function token(): ?McpToken
    {
        return CurrentMcpToken::get();
    }

    /**
     * Authorize an operation the way the generated CRUD tools do: authentication,
     * token abilities, the resource's authorize() callback, then the model policy.
     * Returns null when allowed, or a denial reason to hand back to the agent.
     *
     * Pass the record for record-level operations. `can('update')` without one
     * asks the policy a class-level question, which is not the check the panel
     * applies to a row.
     *
     * @param  class-string<resource>  $resource
     */
    protected function authorizeResource(string $resource, McpOperation $operation, ?Model $record = null): ?string
    {
        return app(ResourceAuthorizer::class)->authorize($this->mcpResource($resource), $operation, $record);
    }

    /**
     * The record-independent half of authorization: authentication and token
     * abilities. Run this before resolving a record, so a denied caller cannot
     * tell "no such record" apart from "not allowed" and enumerate primary keys.
     *
     * @param  class-string<resource>  $resource
     */
    protected function preAuthorizeResource(string $resource, McpOperation $operation): ?string
    {
        return app(ResourceAuthorizer::class)->preAuthorize($this->mcpResource($resource), $operation);
    }

    /**
     * Resolve a record through the same query the generated tools use: the
     * resource's Eloquent query, with its tenant scoping and soft deletes, plus
     * the `McpResource::query()` callback when the resource is registered on the
     * current server.
     *
     * @param  class-string<resource>  $resource
     */
    protected function resolveRecordFor(string $resource, mixed $key): ?Model
    {
        $mcpResource = $this->mcpResource($resource);

        $query = $resource::getEloquentQuery();

        if ($callback = $mcpResource->getModifyQueryUsing()) {
            $query = $mcpResource->evaluate($callback, ['query' => $query]) ?? $query;
        }

        return $query->whereKey($key)->first();
    }

    /**
     * The resource's registration on the current panel's server, so its
     * authorize() callback, query() scoping and public() flag apply to a custom
     * tool as they do to the generated ones. An unregistered resource gets a bare
     * registration, which carries none of those and authorizes on the policy alone.
     *
     * @param  class-string<resource>  $resource
     */
    protected function mcpResource(string $resource): McpResource
    {
        $panelId = $this->panel()?->getId();

        $definition = $panelId === null ? null : app(McpServerRegistry::class)->get($panelId);

        foreach ($definition?->mcpResources ?? [] as $mcpResource) {
            if ($mcpResource->getResource() === $resource) {
                return $mcpResource;
            }
        }

        return McpResource::make($resource);
    }
}
