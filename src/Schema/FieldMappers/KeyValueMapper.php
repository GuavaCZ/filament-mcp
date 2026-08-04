<?php

namespace Guava\FilamentMcp\Schema\FieldMappers;

use Filament\Forms\Components\Field;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Guava\FilamentMcp\Schema\Contracts\ProvidesValidationRules;
use Guava\FilamentMcp\Schema\Contracts\TransformsWrites;
use Guava\FilamentMcp\Schema\WriteContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Throwable;

/**
 * A key-value field is a flat string-to-string map.
 */
class KeyValueMapper implements FieldMapper, ProvidesValidationRules, TransformsWrites
{
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
        return $schema->object([])
            ->description("{$field->getLabel()}. An object of string keys to string values.")
        ;
    }

    public function write(Field $field, mixed $value, WriteContext $context): mixed
    {
        if (blank($value)) {
            return null;
        }

        if (! is_array($value)) {
            throw FieldWriteException::make($field->getName(), 'expected an object of key/value pairs.');
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_array($item) || is_object($item)) {
                throw FieldWriteException::make(
                    $field->getName(),
                    "the value for [{$key}] must be a scalar, not a nested structure.",
                );
            }

            // Keys arrive as JSON object keys, and PHP casts an all-digits key
            // to an int. Store them as strings.
            $result[(string) $key] = $item === null ? null : (string) $item;
        }

        return $result;
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
