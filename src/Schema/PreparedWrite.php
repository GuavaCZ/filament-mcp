<?php

namespace Guava\FilamentMcp\Schema;

/**
 * Validated MCP input, split by how it reaches the database.
 *
 * Attributes are filled onto the record; relationships can only be synced once
 * the record has a key, so they are carried past the save.
 */
class PreparedWrite
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<int, mixed>>  $relationships
     */
    public function __construct(
        public readonly array $attributes,
        public readonly array $relationships,
    ) {}
}
