<?php

namespace Guava\FilamentMcp\Contracts;

use Guava\FilamentMcp\Support\CurrentMcpGrant;

/**
 * Says what an authenticated MCP request is allowed to do.
 *
 * Abilities scope a credential, so a request without one of these is refused.
 * `McpToken` is the shipped implementation. An application authenticating MCP
 * requests through its own middleware supplies its own and registers it with
 * {@see CurrentMcpGrant::set()}.
 */
interface GrantsAbilities
{
    /**
     * Whether this credential grants the given `resource:operation` ability.
     */
    public function can(string $ability): bool;
}
