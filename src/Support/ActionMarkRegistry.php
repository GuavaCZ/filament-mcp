<?php

namespace Guava\FilamentMcp\Support;

use Filament\Actions\Action;
use Guava\FilamentMcp\Mcp\McpActionConfig;
use WeakMap;

class ActionMarkRegistry
{
    /**
     * @var WeakMap<Action, McpActionConfig>
     */
    protected WeakMap $marks;

    public function __construct()
    {
        $this->marks = new WeakMap;
    }

    public function mark(Action $action, McpActionConfig $config): void
    {
        $this->marks[$action] = $config;
    }

    public function configFor(Action $action): ?McpActionConfig
    {
        return $this->marks[$action] ?? null;
    }
}
