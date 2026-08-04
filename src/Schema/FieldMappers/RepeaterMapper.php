<?php

namespace Guava\FilamentMcp\Schema\FieldMappers;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Guava\FilamentMcp\Schema\Contracts\ProvidesValidationRules;
use Guava\FilamentMcp\Schema\Contracts\TransformsReads;
use Guava\FilamentMcp\Schema\Contracts\TransformsWrites;
use Guava\FilamentMcp\Schema\NestedSchema;
use Guava\FilamentMcp\Schema\WriteContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Throwable;

/**
 * A repeater becomes a JSON array of objects shaped by its sub-schema.
 *
 * A `->simple()` repeater is the exception: Filament stores a flat list of that
 * single field's values, so the agent sees a flat list too.
 */
class RepeaterMapper implements FieldMapper, ProvidesValidationRules, TransformsReads, TransformsWrites
{
    public function __construct(
        protected NestedSchema $nested,
    ) {}

    /**
     * @return array<int, mixed>
     */
    public function rules(Field $field): array
    {
        // Per-item rules come from the sub-schema and are applied in write().
        // Only the outer shape is checked here.
        return [
            $this->isRequired($field) ? 'required' : 'nullable',
            'array',
        ];
    }

    public function map(Field $field, JsonSchema $schema): Type
    {
        /** @var Repeater $field */
        $simple = $this->getSimpleField($field);

        $item = $simple !== null
            ? $this->nested->properties(['value' => $simple], $schema)['value'] ?? $schema->string()
            : $schema->object($this->nested->properties($this->getFields($field), $schema));

        $type = $schema->array()
            ->items($item)
            ->description($this->describe($field))
        ;

        return $type;
    }

    public function write(Field $field, mixed $value, WriteContext $context): mixed
    {
        /** @var Repeater $field */
        if (blank($value)) {
            return [];
        }

        if (! is_array($value)) {
            throw FieldWriteException::make($field->getName(), 'expected a list of entries.');
        }

        $items = array_values($value);

        $this->assertItemCount($field, count($items));

        $simple = $this->getSimpleField($field);
        $fields = $simple !== null ? [$simple->getName() => $simple] : $this->getFields($field);

        $result = [];

        foreach ($items as $index => $item) {
            $path = "{$field->getName()}[{$index}]";

            if ($simple !== null) {
                $result[] = $this->nested->write($fields, [$simple->getName() => $item], $context, $path)[$simple->getName()] ?? null;

                continue;
            }

            if (! is_array($item)) {
                throw new FieldWriteException("The [{$path}] entry must be an object.");
            }

            $result[] = $this->nested->write($fields, $item, $context, $path);
        }

        // Mirrors Repeater::dehydrateItems(): a 0-indexed list, not the
        // uuid-keyed map the Livewire component uses internally.
        return $result;
    }

    public function read(Field $field, mixed $value, Model $record): mixed
    {
        /** @var Repeater $field */
        if (! is_array($value) || $this->getSimpleField($field) !== null) {
            return $value;
        }

        $fields = $this->getFields($field);

        return array_values(array_map(
            fn (mixed $item): mixed => is_array($item) ? $this->nested->read($fields, $item, $record) : $item,
            $value,
        ));
    }

    /**
     * @return array<string, Field>
     */
    protected function getFields(Repeater $field): array
    {
        try {
            return $this->nested->fields(
                $field->getDefaultChildComponents(),
                $this->nested->modelFor($field),
            );
        } catch (Throwable) {
            return [];
        }
    }

    protected function getSimpleField(Repeater $field): ?Field
    {
        try {
            return $field->getSimpleField();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @throws FieldWriteException
     */
    protected function assertItemCount(Repeater $field, int $count): void
    {
        foreach ([['getMaxItems', 'at most', '>'], ['getMinItems', 'at least', '<']] as [$method, $word, $operator]) {
            try {
                $limit = $field->{$method}();
            } catch (Throwable) {
                continue;
            }

            if (blank($limit)) {
                continue;
            }

            $exceeded = $operator === '>' ? $count > (int) $limit : $count < (int) $limit;

            if ($exceeded) {
                throw FieldWriteException::make($field->getName(), "it accepts {$word} {$limit} entries.");
            }
        }
    }

    protected function isRequired(Field $field): bool
    {
        try {
            return $field->isRequired();
        } catch (Throwable) {
            return false;
        }
    }

    protected function describe(Field $field): string
    {
        return "{$field->getLabel()}. A list of entries, in display order.";
    }
}
