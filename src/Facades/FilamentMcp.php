<?php

namespace Guava\FilamentMcp\Facades;

use Guava\FilamentMcp\Mcp\ServerDefinition;
use Guava\FilamentMcp\McpServerRegistry;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ServerDefinition|null get(string $panelId)
 * @method static array<string, ServerDefinition> all()
 *
 * @see McpServerRegistry
 */
class FilamentMcp extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return McpServerRegistry::class;
    }
}
