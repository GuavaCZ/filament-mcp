<?php

namespace Guava\FilamentMcp\Schema\FieldMappers;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Guava\FilamentMcp\Schema\Contracts\ProvidesValidationRules;
use Guava\FilamentMcp\Schema\Contracts\TransformsWrites;
use Guava\FilamentMcp\Schema\WriteContext;
use Guava\FilamentMcp\Support\RelationshipField;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Throwable;

class SelectMapper implements FieldMapper, ProvidesValidationRules, TransformsWrites
{
    public function map(Field $field, JsonSchema $schema): Type
    {
        /** @var Select $field */
        $options = $this->getOptions($field);

        $type = $schema->string()
            ->description($field->getLabel())
        ;

        if ($options !== []) {
            $type->enum(array_map('strval', array_keys($options)));
        }

        if ($field->isMultiple()) {
            return $schema->array()
                ->items($type)
                ->description($this->describeMultiple($field))
            ;
        }

        return $type;
    }

    /**
     * A multiple select's value is a list, but Filament's own rules describe the
     * single-value case. A relationship select contributes nothing beyond
     * `nullable`, since its options come from a query that returns nothing
     * headlessly.
     *
     * @return array<int, mixed>
     */
    public function rules(Field $field): array
    {
        try {
            $rules = $field->getValidationRules();
        } catch (Throwable) {
            $rules = ['nullable'];
        }

        if (! $field instanceof Select || ! $this->isMultiple($field)) {
            return $rules;
        }

        return [...$rules, 'array'];
    }

    /**
     * Vet the related record keys a relationship select was given.
     *
     * A syncable field's value never reaches `fill()`, since the tool syncs it
     * onto the relation after the save, so this only checks it. A singular
     * relationship select writes a foreign key onto the record and is checked
     * the same way. Every other select is an ordinary attribute whose options
     * Filament already turns into an `in` rule.
     */
    public function write(Field $field, mixed $value, WriteContext $context): mixed
    {
        $relation = RelationshipField::syncable($field);

        if ($relation === null) {
            return $this->writeSingular($field, $value);
        }

        if (blank($value)) {
            return [];
        }

        if (! is_array($value)) {
            throw FieldWriteException::make($field->getName(), 'expected a list of related record keys.');
        }

        $keys = [];

        foreach ($value as $key) {
            if (! is_scalar($key)) {
                throw FieldWriteException::make($field->getName(), 'every related record key must be a scalar.');
            }

            $keys[] = (string) $key;
        }

        $keys = array_values(array_unique($keys));

        $this->assertKeysExist($field, $relation->getRelated(), $keys);

        return $keys;
    }

    /**
     * Check the foreign key a singular relationship select is about to write.
     *
     * Filament adds no `exists` rule for one of these, because in the panel the
     * option list is the constraint. Headlessly the agent sends a bare key, so
     * without this check any key the database accepts is writable, including one
     * belonging to another tenant.
     *
     * The check runs through `newQuery()` on the related model, where Filament's
     * tenant global scope lives, so a key outside the current tenant reads as
     * missing exactly as it would in the panel.
     *
     * @throws FieldWriteException
     */
    protected function writeSingular(Field $field, mixed $value): mixed
    {
        $related = RelationshipField::singular($field);

        if ($related === null || blank($value)) {
            return $value;
        }

        if (! is_scalar($value)) {
            throw FieldWriteException::make($field->getName(), 'expected a single related record key.');
        }

        $this->assertKeysExist($field, $related, [(string) $value]);

        return $value;
    }

    /**
     * Syncing an unknown key either violates a foreign key or writes a pivot row
     * pointing at nothing, and neither tells the agent what it got wrong.
     *
     * @param  array<int, string>  $keys
     *
     * @throws FieldWriteException
     */
    protected function assertKeysExist(Field $field, Model $related, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $existing = $related->newQuery()
            ->whereIn($related->getKeyName(), $keys)
            ->pluck($related->getKeyName())
            ->map(fn (mixed $key): string => (string) $key)
            ->all()
        ;

        $missing = array_diff($keys, $existing);

        if ($missing === []) {
            return;
        }

        throw FieldWriteException::make($field->getName(), sprintf(
            'no %s exists with the key %s.',
            class_basename($related),
            implode(', ', $missing),
        ));
    }

    protected function describeMultiple(Select $field): string
    {
        $relation = RelationshipField::syncable($field);

        if ($relation === null) {
            return (string) $field->getLabel();
        }

        return sprintf(
            '%s. The complete set of related %s keys: the record is synced to exactly this list, '
            . 'so include the ones it already has.',
            $field->getLabel(),
            class_basename($relation->getRelated()),
        );
    }

    protected function isMultiple(Select $field): bool
    {
        try {
            return $field->isMultiple();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function getOptions(Select $field): array
    {
        try {
            return $field->getOptions();
        } catch (Throwable) {
            // Closure-based options may need runtime context that headless
            // introspection does not have.
            return [];
        }
    }
}
