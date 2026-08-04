<?php

namespace Guava\FilamentMcp\Concerns;

use Closure;
use LogicException;

trait HasOauth
{
    /**
     * OAuth is not supported yet. Use MCP tokens instead.
     */
    public function oauth(bool | Closure $condition = true): static
    {
        throw new LogicException(
            'OAuth is not supported yet and is planned for a future release. The previous implementation '
            . 'never checked OAuth scopes, so every OAuth caller received full abilities. Use MCP tokens '
            . '(McpPlugin::tokens()) instead.',
        );
    }
}
