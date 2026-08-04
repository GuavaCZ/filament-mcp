<?php

namespace Guava\FilamentMcp\Support;

use Filament\Forms\Components\Field;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\Schema\SchemaTransposer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Throwable;

class RecordTransformer
{
    public function __construct(
        protected SchemaTransposer $transposer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function transform(Model $record, McpResource $mcpResource): array
    {
        $data = Arr::only(
            $record->attributesToArray(),
            $this->getReadableAttributes($record, $mcpResource),
        );

        return [
            ...$this->transposer->applyReads($mcpResource, $data, $record),
            ...$this->getRelationshipValues($record, $mcpResource),
        ];
    }

    /**
     * Related keys for the fields that are synced rather than filled.
     *
     * They are not attributes, so `attributesToArray()` never sees them, but an
     * agent asked to send back the complete set needs to know what the record
     * already has.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function getRelationshipValues(Model $record, McpResource $mcpResource): array
    {
        $values = [];

        foreach (array_keys($this->transposer->getRelationshipFields($mcpResource)) as $name) {
            try {
                $relation = $record->{$name}();
                $keyName = $relation->getRelated()->qualifyColumn($relation->getRelated()->getKeyName());

                $values[$name] = $record->relationLoaded($name)
                    ? $record->getRelation($name)->modelKeys()
                    : $relation->pluck($keyName)->all();
            } catch (Throwable $e) {
                // A read must not fail over a relation that cannot be resolved.
                report($e);
            }
        }

        return $values;
    }

    /**
     * The attribute names this resource may disclose.
     *
     * Serialising a value is not the only way to disclose it. Ordering a list by
     * a column reports on it, and matching one with `LIKE` reports on it a
     * character at a time. The set is defined once here and every read surface
     * narrows itself to it.
     *
     * @param  Model  $record  Only the model's key name and timestamp columns are
     *                         read, so any instance of the resource's model will do.
     * @return array<int, string>
     */
    public function getReadableAttributes(Model $record, McpResource $mcpResource): array
    {
        $allowed = $mcpResource->getFields();

        if ($allowed === []) {
            $fieldNames = array_keys(array_filter(
                $this->transposer->getFormFields($mcpResource->getResource()),
                fn (Field $field, string $name): bool => ! str_contains($name, '.')
                    && $this->isReadable($field),
                ARRAY_FILTER_USE_BOTH,
            ));

            $allowed = [
                $record->getKeyName(),
                ...$fieldNames,
                ...array_filter([
                    $record->getCreatedAtColumn(),
                    $record->getUpdatedAtColumn(),
                ]),
            ];
        }

        return array_values(array_diff($allowed, $mcpResource->getHiddenFields()));
    }

    /**
     * Whether a field's value may be sent back to the agent.
     *
     * A field the panel would not render is not serialised either. Apps gate
     * fields on a role with `->visible(fn ($user) => $user->isAdmin())`, and
     * without this check everybody reads them.
     *
     * Keyed on `isHidden()` alone. A disabled field is still readable, since
     * disabled means "you may not write this", which is how the panel renders it.
     *
     * Visibility is evaluated without a record, because the field list is built
     * and cached per resource rather than per row. Record-dependent visibility is
     * therefore not a read control; `hiddenFields()` and `query()` are.
     */
    protected function isReadable(Field $field): bool
    {
        try {
            return ! $field->isHidden();
        } catch (Throwable) {
            // A visibility closure that cannot run headlessly says nothing about
            // whether the value is safe to send, so it is not sent.
            return false;
        }
    }
}
