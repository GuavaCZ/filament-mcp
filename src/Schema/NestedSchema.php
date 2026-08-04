<?php

namespace Guava\FilamentMcp\Schema;

use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Guava\FilamentMcp\Schema\Contracts\ProvidesValidationRules;
use Guava\FilamentMcp\Schema\Contracts\TransformsReads;
use Guava\FilamentMcp\Schema\Contracts\TransformsWrites;
use Guava\FilamentMcp\Support\SchemaHost;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Transposes the child components of a structured field.
 *
 * Repeaters, builders and key-value fields hold a sub-schema whose fields need
 * the same treatment as top-level ones: a JSON schema type, validation and
 * mapper-driven read/write coercion. Sharing this keeps a `FileUpload` nested
 * in a repeater behaving like one at the top level.
 */
class NestedSchema
{
    public function __construct(
        protected FieldMapperRegistry $mappers,
    ) {}

    /**
     * Flatten a component tree down to its writable fields, keyed by name.
     *
     * Layout components (grids, sections, fieldsets) are transparent here, since
     * they arrange the form but contribute nothing to the data shape.
     *
     * @param  array<Component>|Schema|null  $components
     * @return array<string, Field>
     */
    public function fields(array | Schema | null $components, ?string $model = null): array
    {
        if ($components === null) {
            return [];
        }

        // A repeater or block's child components arrive without a container,
        // since Filament only instantiates one per item. Every evaluated getter
        // (isDisabled(), isRequired(), getValidationRules()) throws without one,
        // so give them a headless container before reading them.
        if (is_array($components)) {
            $components = Schema::make(app(SchemaHost::class))
                ->when($model !== null, fn (Schema $schema): Schema => $schema->model($model))
                ->components($components)
            ;
        }

        $components = $components->getComponents(withHidden: true);

        $fields = [];

        foreach ($components as $component) {
            if ($component instanceof Field) {
                if ($this->isWritable($component)) {
                    $fields[$component->getName()] = $component;
                }

                continue;
            }

            if ($component instanceof Component) {
                try {
                    $fields = [...$fields, ...$this->fields($component->getDefaultChildComponents(), $model)];
                } catch (Throwable) {
                    // A layout component whose children need runtime context.
                    // Skip it.
                }
            }
        }

        return $fields;
    }

    /**
     * The model a component's own container is bound to, so that child fields
     * built here resolve relationships against the same model.
     *
     * @return class-string<Model>|null
     */
    public function modelFor(Component $component): ?string
    {
        try {
            $model = $component->getContainer()->getModel();
        } catch (Throwable) {
            return null;
        }

        return is_string($model) && class_exists($model) ? $model : null;
    }

    /**
     * @param  array<string, Field>  $fields
     * @return array<string, Type>
     */
    public function properties(array $fields, JsonSchema $schema, bool $partial = false): array
    {
        $properties = [];

        foreach ($fields as $name => $field) {
            try {
                $type = $this->mappers->mapperFor($field)->map($field, $schema);

                if ((! $partial) && $field->isRequired()) {
                    $type->required();
                }

                $properties[$name] = $type;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $properties;
    }

    /**
     * Validate and coerce one item of a structured field.
     *
     * @param  array<string, Field>  $fields
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     *
     * @throws FieldWriteException
     */
    public function write(array $fields, array $item, WriteContext $context, string $path): array
    {
        // Only the fields that could be validated survive; see validate().
        $fields = $this->validate($fields, $item, $path);

        foreach ($fields as $name => $field) {
            if (! array_key_exists($name, $item)) {
                continue;
            }

            $mapper = $this->mappers->mapperFor($field);

            if ($mapper instanceof TransformsWrites) {
                $item[$name] = $mapper->write($field, $item[$name], $context);
            }
        }

        // Anything the sub-schema does not declare is dropped, so an agent cannot
        // smuggle extra keys into a JSON column the panel would never show.
        return array_intersect_key($item, $fields);
    }

    /**
     * @param  array<string, Field>  $fields
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function read(array $fields, array $item, Model $record): array
    {
        foreach ($item as $name => $value) {
            $field = $fields[$name] ?? null;

            if ($field === null) {
                continue;
            }

            $mapper = $this->mappers->mapperFor($field);

            if (! $mapper instanceof TransformsReads) {
                continue;
            }

            try {
                $item[$name] = $mapper->read($field, $value, $record);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $item;
    }

    /**
     * Validate the item and return the fields that may be written.
     *
     * @param  array<string, Field>  $fields
     * @param  array<string, mixed>  $item
     * @return array<string, Field>
     *
     * @throws FieldWriteException
     */
    protected function validate(array $fields, array $item, string $path): array
    {
        $rules = [];

        foreach ($fields as $name => $field) {
            try {
                $mapper = $this->mappers->mapperFor($field);

                $rules[$name] = $mapper instanceof ProvidesValidationRules
                    ? $mapper->rules($field)
                    : $field->getValidationRules();
            } catch (Throwable $e) {
                report($e);

                // Fail closed, as the top-level path does. An empty rule set would
                // leave the value in $item for write() to carry through to the
                // column unvalidated.
                unset($rules[$name], $fields[$name]);
            }
        }

        $validator = Validator::make($item, array_filter($rules));

        if ($validator->fails()) {
            throw new FieldWriteException(sprintf(
                'The [%s] entry is invalid: %s',
                $path,
                implode(' ', $validator->errors()->all()),
            ));
        }

        return $fields;
    }

    protected function isWritable(Field $field): bool
    {
        try {
            return ! $field->isDisabled() && $field->isDehydrated();
        } catch (Throwable) {
            return false;
        }
    }
}
