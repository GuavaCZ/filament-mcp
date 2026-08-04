<?php

namespace Guava\FilamentMcp\Schema;

use Filament\Facades\Filament;
use Guava\FilamentMcp\Mcp\McpResource;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything a mapper needs to turn an incoming MCP value into a model attribute.
 *
 * The record is null on create. Mappers that need one, such as those resolving
 * rich content configuration or an upload disk from the model, should fall back
 * to a fresh instance of the resource's model.
 */
class WriteContext
{
    public function __construct(
        public readonly McpResource $mcpResource,
        public readonly ?Model $record,
        public readonly string $panelId,
    ) {}

    /**
     * The record being written, or a fresh unsaved instance on create.
     */
    public function getModel(): Model
    {
        if ($this->record !== null) {
            return $this->record;
        }

        $model = $this->mcpResource->getResource()::getModel();

        return new $model;
    }

    public function isCreating(): bool
    {
        return $this->record === null;
    }

    /**
     * The user the MCP request is acting as, if any.
     *
     * Mappers use this to check ownership of anything staged out of band, so a
     * guessed upload handle cannot be claimed.
     */
    public function getUser(): ?Model
    {
        $user = Filament::auth()->user();

        return $user instanceof Model ? $user : null;
    }
}
