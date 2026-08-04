<?php

namespace Guava\FilamentMcp\Mcp;

use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class McpActionConfig
{
    /**
     * @param  Closure(JsonSchema $schema): array<string, mixed>|null  $schema
     */
    public function __construct(
        public readonly ?string $description = null,
        public readonly ?Closure $schema = null,
        public readonly ?string $name = null,
    ) {}
}
