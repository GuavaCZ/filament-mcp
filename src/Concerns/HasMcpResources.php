<?php

namespace Guava\FilamentMcp\Concerns;

use Guava\FilamentMcp\Mcp\McpResource;

trait HasMcpResources
{
    /**
     * @var array<int, McpResource>
     */
    protected array $mcpResources = [];

    /**
     * @param  array<int, McpResource>  $resources
     */
    public function resources(array $resources): static
    {
        $this->mcpResources = $resources;

        return $this;
    }

    /**
     * @return array<int, McpResource>
     */
    public function getMcpResources(): array
    {
        return $this->mcpResources;
    }
}
