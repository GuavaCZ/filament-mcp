<?php

namespace Guava\FilamentMcp;

use Filament\Panel;
use Guava\FilamentMcp\Mcp\ServerDefinition;

class McpServerRegistry
{
    /**
     * @var array<string, ServerDefinition>
     */
    protected array $definitions = [];

    public function compile(Panel $panel, McpPlugin $plugin): ServerDefinition
    {
        return $this->definitions[$panel->getId()] = new ServerDefinition(
            panelId: $panel->getId(),
            path: $plugin->getPath() ?? 'mcp/' . $panel->getId(),
            serverName: $plugin->getServerName() ?? $panel->getBrandName(),
            instructions: $plugin->getInstructions() ?? "This MCP server exposes resources of the [{$panel->getId()}] panel.",
            mcpResources: $plugin->getMcpResources(),
            tools: $plugin->getTools(),
            prompts: $plugin->getPrompts(),
            public: $plugin->isPublic(),
            middleware: $plugin->getMiddleware(),
            discoverActions: $plugin->shouldDiscoverActions(),
            tenantInPath: $plugin->isTenantInPath(),
        );
    }

    public function get(string $panelId): ?ServerDefinition
    {
        return $this->definitions[$panelId] ?? null;
    }

    /**
     * @return array<string, ServerDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }
}
