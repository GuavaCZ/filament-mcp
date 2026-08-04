<?php

namespace Guava\FilamentMcp\Schema\FieldMappers;

use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\Field;
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
 * A builder becomes a list of `{type, data}` objects, one per block.
 *
 * JSON Schema could express this as a discriminated union, but tool-calling
 * models handle a flat `type` enum plus a free-form `data` object more reliably.
 * The block's own sub-schema is enforced in write() instead.
 */
class BuilderMapper implements FieldMapper, ProvidesValidationRules, TransformsReads, TransformsWrites
{
    public function __construct(
        protected NestedSchema $nested,
    ) {}

    /**
     * @return array<int, mixed>
     */
    public function rules(Field $field): array
    {
        return [
            $this->isRequired($field) ? 'required' : 'nullable',
            'array',
        ];
    }

    public function map(Field $field, JsonSchema $schema): Type
    {
        /** @var Builder $field */
        $names = array_keys($this->getBlocks($field));

        return $schema->array()
            ->items($schema->object([
                'type' => $schema->string()
                    ->enum($names)
                    ->description('Which kind of block this is.')
                    ->required(),
                'data' => $schema->object([])
                    ->description($this->describeBlocks($field))
                    ->required(),
            ]))
            ->description("{$field->getLabel()}. An ordered list of content blocks.")
        ;
    }

    public function write(Field $field, mixed $value, WriteContext $context): mixed
    {
        /** @var Builder $field */
        if (blank($value)) {
            return [];
        }

        if (! is_array($value)) {
            throw FieldWriteException::make($field->getName(), 'expected a list of blocks.');
        }

        $blocks = $this->getBlocks($field);
        $result = [];

        foreach (array_values($value) as $index => $item) {
            $path = "{$field->getName()}[{$index}]";

            if (! is_array($item)) {
                throw new FieldWriteException("The [{$path}] block must be an object.");
            }

            $type = $item['type'] ?? null;

            if (! is_string($type) || ! isset($blocks[$type])) {
                throw new FieldWriteException(sprintf(
                    'The [%s] block has an unknown type. Available types: %s.',
                    $path,
                    implode(', ', array_keys($blocks)) ?: 'none',
                ));
            }

            $data = $item['data'] ?? [];

            if (! is_array($data)) {
                throw new FieldWriteException("The [{$path}] block's data must be an object.");
            }

            $result[] = [
                'type' => $type,
                'data' => $this->nested->write(
                    $this->getBlockFields($blocks[$type]),
                    $data,
                    $context,
                    "{$path}.data",
                ),
            ];
        }

        // Mirrors Builder's own dehydration: a 0-indexed list.
        return $result;
    }

    public function read(Field $field, mixed $value, Model $record): mixed
    {
        /** @var Builder $field */
        if (! is_array($value)) {
            return $value;
        }

        $blocks = $this->getBlocks($field);

        return array_values(array_map(function (mixed $item) use ($blocks, $record): mixed {
            if (! is_array($item)) {
                return $item;
            }

            $type = $item['type'] ?? null;

            if (! is_string($type) || ! isset($blocks[$type]) || ! is_array($item['data'] ?? null)) {
                return $item;
            }

            $item['data'] = $this->nested->read($this->getBlockFields($blocks[$type]), $item['data'], $record);

            return $item;
        }, $value));
    }

    /**
     * @return array<string, Block>
     */
    protected function getBlocks(Builder $field): array
    {
        try {
            $blocks = [];

            foreach ($field->getBlocks() as $block) {
                $blocks[$block->getName()] = $block;
            }

            return $blocks;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, Field>
     */
    protected function getBlockFields(Block $block): array
    {
        try {
            return $this->nested->fields(
                $block->getDefaultChildComponents(),
                $this->nested->modelFor($block),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The per-block shapes described in prose, since they live under a single
     * free-form `data` object in the schema.
     */
    protected function describeBlocks(Builder $field): string
    {
        $parts = [];

        foreach ($this->getBlocks($field) as $name => $block) {
            $fields = array_keys($this->getBlockFields($block));

            $parts[] = $fields === []
                ? "\"{$name}\": no fields"
                : "\"{$name}\": " . implode(', ', $fields);
        }

        return 'The block\'s fields, which depend on its type. ' . implode('; ', $parts) . '.';
    }

    protected function isRequired(Field $field): bool
    {
        try {
            return $field->isRequired();
        } catch (Throwable) {
            return false;
        }
    }
}
