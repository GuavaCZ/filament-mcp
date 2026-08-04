<?php

namespace Guava\FilamentMcp\Testing;

use Guava\FilamentMcp\McpServerRegistry;
use RuntimeException;

trait TestsMcp
{
    public function mcp(string $panelId): McpTestClient
    {
        $definition = app(McpServerRegistry::class)->get($panelId);

        if ($definition === null) {
            throw new RuntimeException("No MCP server registered for panel [{$panelId}].");
        }

        return new McpTestClient($this, '/' . ltrim($definition->path, '/'));
    }
}
