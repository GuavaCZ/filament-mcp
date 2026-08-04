<?php

namespace Guava\FilamentMcp\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * The fields of an action's modal form.
 *
 * An action with `->schema([...])` collects input before it runs and its closure
 * reads that input as `$data`. The modal form is transposed like a resource form,
 * with the same field mappers and validation rules, and the result is handed back
 * through `Action::data()`, the setter the panel uses on submit.
 */
class ActionSchema
{
    /**
     * The action's modal fields, keyed by name. Empty when it has no modal.
     *
     * Failures are not caught here: a schema that cannot be resolved headlessly
     * must make the action unavailable rather than available with no fields.
     * ActionDiscoverer catches this and skips the action with a log line.
     *
     * @return array<string, Field>
     */
    public static function fields(Action $action, ?Model $record = null): array
    {
        $schema = Schema::make(app(SchemaHost::class));

        if ($record !== null) {
            $schema = $schema->model($record)->record($record);
        }

        $resolved = $action->getSchema($schema);

        if ($resolved === null) {
            return [];
        }

        $fields = [];

        // Hidden fields are excluded, since the panel does not submit one either
        // and a field gated on a role should not become writable over MCP.
        foreach ($resolved->getFlatFields() as $name => $field) {
            // Nested (dotted) names address a sub-schema the write path does not
            // support, as on the resource form side.
            if (str_contains($name, '.')) {
                continue;
            }

            // Disabled and non-dehydrated fields are not input. Filament drops
            // them from the submitted data, so declaring them would invite a
            // value the panel would have ignored.
            if ($field->isDisabled() || ! $field->isDehydrated()) {
                continue;
            }

            $fields[$name] = $field;
        }

        return $fields;
    }

    /**
     * The state Filament would have submitted for fields the agent left out.
     *
     * An untouched modal field still reaches the closure carrying its default, so
     * `$data['notify']` is set in the panel whether or not anyone touched the
     * toggle. Omitting the key would hand the closure a differently shaped array.
     *
     * @param  array<string, Field>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withDefaults(array $fields, array $data): array
    {
        foreach ($fields as $name => $field) {
            if (array_key_exists($name, $data)) {
                continue;
            }

            $data[$name] = $field->getDefaultState();
        }

        return $data;
    }
}
