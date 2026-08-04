<?php

namespace Guava\FilamentMcp\Support;

use Guava\FilamentMcp\Contracts\GrantsAbilities;

/**
 * The abilities the current MCP request carries.
 *
 * The single source of truth for what the caller may do, and fail-closed: an
 * authenticated request with no grant is refused rather than waved through, since
 * authenticating a user is not the same as saying what they may do.
 *
 * `AuthenticateMcp` sets this for MCP tokens. An application authenticating
 * requests through its own `McpPlugin::middleware([...])` sets it itself.
 */
class CurrentMcpGrant
{
    public static function set(?GrantsAbilities $grant): void
    {
        app()->instance(static::class, $grant ?? false);
    }

    public static function get(): ?GrantsAbilities
    {
        if (! app()->bound(static::class)) {
            return null;
        }

        $grant = app(static::class);

        return $grant instanceof GrantsAbilities ? $grant : null;
    }
}
