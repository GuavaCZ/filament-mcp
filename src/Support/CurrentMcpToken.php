<?php

namespace Guava\FilamentMcp\Support;

use Guava\FilamentMcp\Models\McpToken;

/**
 * The MCP token behind the current request, when there is one.
 *
 * Setting a token also sets it as the request's ability grant, since they are the
 * same credential seen two ways. Read {@see CurrentMcpGrant} to find out what the
 * caller may do, since a request can be authorised by something that is not an
 * McpToken.
 */
class CurrentMcpToken
{
    public static function set(?McpToken $token): void
    {
        app()->instance(static::class, $token ?? false);

        CurrentMcpGrant::set($token);
    }

    public static function get(): ?McpToken
    {
        if (! app()->bound(static::class)) {
            return null;
        }

        $token = app(static::class);

        return $token instanceof McpToken ? $token : null;
    }
}
