<?php

namespace Guava\FilamentMcp\Concerns;

use Filament\Forms\Components\Field;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Guava\FilamentMcp\Schema\FieldMapperRegistry;

trait HasFieldMappers
{
    /**
     * @var array<class-string<Field>, class-string<FieldMapper>>
     */
    protected array $fieldMappers = [];

    protected ?string $fieldMapperPanelId = null;

    /**
     * Teach the package how to transpose a field type it does not know.
     *
     * Registrations take precedence over the built-in ones, so this also replaces
     * a shipped mapper. The registry resolves by walking up the field's class
     * hierarchy, so registering a base class covers its subclasses.
     *
     * The mapping applies to this panel only.
     *
     * @param  class-string<Field>  $field
     * @param  class-string<FieldMapper>  $mapper
     */
    public function fieldMapper(string $field, string $mapper): static
    {
        $this->fieldMappers[$field] = $mapper;

        // During panel configuration the plugin has no panel yet, so the
        // registration waits until register() knows the id to file it under.
        // Afterwards it applies at once, so calling this on an already-registered
        // plugin still takes effect.
        if ($this->fieldMapperPanelId !== null) {
            app(FieldMapperRegistry::class)->register($field, $mapper, $this->fieldMapperPanelId);
        }

        return $this;
    }

    /**
     * @param  array<class-string<Field>, class-string<FieldMapper>>  $mappers
     */
    public function fieldMappers(array $mappers): static
    {
        foreach ($mappers as $field => $mapper) {
            $this->fieldMapper($field, $mapper);
        }

        return $this;
    }

    protected function registerFieldMappers(string $panelId): void
    {
        $this->fieldMapperPanelId = $panelId;

        foreach ($this->fieldMappers as $field => $mapper) {
            app(FieldMapperRegistry::class)->register($field, $mapper, $panelId);
        }
    }
}
