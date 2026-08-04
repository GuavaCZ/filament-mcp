<?php

namespace Guava\FilamentMcp\Servers;

use Filament\Facades\Filament;
use Guava\FilamentMcp\McpServerRegistry;
use Guava\FilamentMcp\Support\TenancyRegistrar;
use Laravel\Mcp\Server;
use RuntimeException;

class PanelMcpServer extends Server
{
    protected function boot(): void
    {
        $panelId = $this->resolvePanelId();

        $definition = app(McpServerRegistry::class)->get($panelId);

        if ($definition === null) {
            throw new RuntimeException("No MCP server definition found for panel [{$panelId}].");
        }

        if (Filament::getCurrentPanel()?->getId() !== $panelId) {
            Filament::setCurrentPanel(Filament::getPanel($panelId));
            Filament::bootCurrentPanel();
        }

        // Covers the stdio transport, which never passes through ApplyMcpContext.
        // Idempotent, so the HTTP path calling it too is free.
        app(TenancyRegistrar::class)->apply(Filament::getPanel($panelId), $definition);

        $this->name = $definition->serverName;
        $this->instructions = $definition->instructions;
        $this->tools = $definition->makeTools();
        $this->prompts = $definition->prompts;
    }

    protected function resolvePanelId(): string
    {
        $panelId = request()->route()?->defaults['mcpPanel']
            ?? config('filament-mcp.local_panel');

        if (! is_string($panelId)) {
            $definitions = app(McpServerRegistry::class)->all();

            if (count($definitions) === 1) {
                $panelId = array_key_first($definitions);
            }
        }

        if (! is_string($panelId)) {
            throw new RuntimeException(
                'Unable to resolve the Filament panel for this MCP server. '
                . 'For local (stdio) serving with multiple panels, set the '
                . '[filament-mcp.local_panel] config value (FILAMENT_MCP_LOCAL_PANEL).',
            );
        }

        return $panelId;
    }
}
