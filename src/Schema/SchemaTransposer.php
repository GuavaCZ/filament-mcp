<?php

namespace Guava\FilamentMcp\Schema;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\Schema\Contracts\ProvidesValidationRules;
use Guava\FilamentMcp\Schema\Contracts\TransformsReads;
use Guava\FilamentMcp\Schema\Contracts\TransformsWrites;
use Guava\FilamentMcp\Support\RelationshipField;
use Guava\FilamentMcp\Support\SchemaHost;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use ReflectionProperty;
use Throwable;

class SchemaTransposer
{
    /**
     * @var array<class-string<resource>, array<string, Field>>
     */
    protected array $fieldsCache = [];

    public function __construct(
        protected FieldMapperRegistry $mappers,
    ) {}

    /**
     * Get the resource's form fields, keyed by name, built headlessly.
     *
     * @param  class-string<resource>  $resource
     * @return array<string, Field>
     */
    public function getFormFields(string $resource, ?Model $record = null): array
    {
        // Record-bound fields are never cached, since the next request is about
        // a different record.
        if ($record !== null) {
            return $this->buildFormFields($resource, $record);
        }

        return $this->fieldsCache[$resource] ??= $this->buildFormFields($resource);
    }

    /**
     * Get the form fields that may be written through MCP, applying the
     * configured allow/deny lists and skipping fields that never dehydrate.
     *
     * @return array<string, Field>
     */
    public function getWritableFields(McpResource $mcpResource, ?Model $record = null): array
    {
        $fields = [];

        foreach ($this->getFormFields($mcpResource->getResource(), $record) as $name => $field) {
            if (! $this->isVisibleField($mcpResource, $name)) {
                continue;
            }

            // A syncable relationship field is dehydrated(false) by design, since
            // Filament writes it after the save rather than through the model,
            // so the usual dehydration check would hide it from the agent.
            if ($this->isStaticallyDisabled($field) && ! RelationshipField::isSyncable($field)) {
                continue;
            }

            $fields[$name] = $field;
        }

        return $fields;
    }

    /**
     * The writable fields whose value is synced onto a relation after the save
     * instead of being filled onto the record.
     *
     * @return array<string, Field>
     */
    public function getRelationshipFields(McpResource $mcpResource, ?Model $record = null): array
    {
        return array_filter(
            $this->getWritableFields($mcpResource, $record),
            fn (Field $field): bool => RelationshipField::isSyncable($field),
        );
    }

    /**
     * Transpose the resource's form into a JSON schema property map.
     *
     * @return array<string, Type>
     */
    public function transpose(McpResource $mcpResource, JsonSchema $schema, bool $partial = false): array
    {
        $properties = [];

        foreach ($this->getWritableFields($mcpResource) as $name => $field) {
            // Nested (dotted) field names are not supported yet.
            if (str_contains($name, '.')) {
                continue;
            }

            try {
                $type = $this->mappers->mapperFor($field)->map($field, $schema);

                if (! $partial && $field->isRequired()) {
                    $type->required();
                }

                $properties[$name] = $type;
            } catch (Throwable $e) {
                // A user closure may need runtime context that headless
                // introspection does not have. Skip the field.
                report($e);
            }
        }

        return $properties;
    }

    /**
     * Compile Laravel validation rules for the writable fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, mixed>>
     */
    public function getValidationRules(McpResource $mcpResource, array $data, bool $partial = false, ?Model $record = null): array
    {
        $rules = [];

        foreach ($this->getWritableFields($mcpResource, $record) as $name => $field) {
            if (str_contains($name, '.')) {
                continue;
            }

            if ($partial && ! array_key_exists($name, $data)) {
                continue;
            }

            try {
                $mapper = $this->mappers->mapperFor($field);

                // A field whose Livewire state shape differs from its MCP wire
                // format supplies its own rules. The field's own rules would
                // reject the agent's value before the mapper sees it.
                $rules[$name] = $mapper instanceof ProvidesValidationRules
                    ? $mapper->rules($field)
                    : $field->getValidationRules();
            } catch (Throwable $e) {
                report($e);

                // Fail closed. An empty rule set would let the value through
                // unvalidated, whereas omitting the field makes
                // Validator::validated() drop it before it reaches the model.
                unset($rules[$name]);
            }
        }

        return $rules;
    }

    /**
     * Transpose an arbitrary field list, such as an action's modal form, into a
     * JSON schema property map.
     *
     * Strict where `transpose()` is lenient. A resource form that loses one field
     * to an unevaluable closure still lists the rest usefully, but an action's
     * modal form is its input contract, and dropping a field there runs the action
     * without a value it declared required. The failure is left to the caller;
     * ActionDiscoverer skips the action entirely.
     *
     * @param  array<string, Field>  $fields
     * @return array<string, Type>
     */
    public function transposeFields(array $fields, JsonSchema $schema): array
    {
        $properties = [];

        foreach ($fields as $name => $field) {
            $type = $this->mappers->mapperFor($field)->map($field, $schema);

            if ($field->isRequired()) {
                $type->required();
            }

            $properties[$name] = $type;
        }

        return $properties;
    }

    /**
     * Compile validation rules for an arbitrary field list.
     *
     * Strict, for the same reason as {@see transposeFields()}.
     *
     * @param  array<string, Field>  $fields
     * @return array<string, array<int, mixed>>
     */
    public function getValidationRulesForFields(array $fields): array
    {
        $rules = [];

        foreach ($fields as $name => $field) {
            $mapper = $this->mappers->mapperFor($field);

            $rules[$name] = $mapper instanceof ProvidesValidationRules
                ? $mapper->rules($field)
                : $field->getValidationRules();
        }

        return $rules;
    }

    /**
     * Run an arbitrary field list's incoming values through their mappers.
     *
     * Unlike the resource equivalent, the result is not shaped for `fill()`,
     * because the values go to an action closure rather than a model column.
     * Dehydration callbacks still run, as they do on submit in the panel.
     *
     * @param  array<string, Field>  $fields
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws FieldWriteException
     */
    public function applyWritesToFields(array $fields, array $data, WriteContext $context): array
    {
        foreach ($fields as $name => $field) {
            if (! array_key_exists($name, $data)) {
                continue;
            }

            $mapper = $this->mappers->mapperFor($field);

            $value = $mapper instanceof TransformsWrites
                ? $mapper->write($field, $data[$name], $context)
                : $data[$name];

            $data[$name] = $this->applyDehydration($field, $name, $value);
        }

        return $data;
    }

    /**
     * Run every writable field's incoming value through its mapper.
     *
     * The MCP write path fills the model directly, so Filament's state casts and
     * dehydration callbacks never run. Their headless equivalents live here.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws FieldWriteException
     */
    public function applyWrites(McpResource $mcpResource, array $data, WriteContext $context): array
    {
        foreach ($this->getWritableFields($mcpResource) as $name => $field) {
            if (! array_key_exists($name, $data)) {
                continue;
            }

            $mapper = $this->mappers->mapperFor($field);

            $value = $mapper instanceof TransformsWrites
                ? $mapper->write($field, $data[$name], $context)
                : $data[$name];

            $value = $this->applyDehydration($field, $name, $value);

            $data[$name] = $this->prepareTranslatableWrite($context->getModel(), $name, $value);
        }

        return $data;
    }

    /**
     * Apply a field's own dehydration callbacks to the value about to be stored.
     *
     * Filament runs these on every save, and the MCP write path fills the model
     * directly, so a field declaring
     * `dehydrateStateUsing(fn ($state) => Hash::make($state))` would otherwise
     * store the plaintext the agent sent.
     *
     * Only fields that declare a callback go through this. Running it
     * unconditionally would re-apply the state casts on Select, Radio and
     * ToggleButtons, whose headless equivalents the mappers already handled.
     *
     * @throws FieldWriteException
     */
    protected function applyDehydration(Field $field, string $name, mixed $value): mixed
    {
        if (! $this->declaresDehydration($field)) {
            return $value;
        }

        try {
            $dehydrated = $field->getStateToDehydrate($value);
            $statePath = $field->getStatePath();

            if (array_key_exists($statePath, $dehydrated)) {
                $value = $dehydrated[$statePath];
            }

            return $field->mutateDehydratedState($value);
        } catch (Throwable $e) {
            report($e);

            // Fail closed. The callback exists because the raw value must not
            // reach the column, so storing it anyway is not an option.
            throw new FieldWriteException(
                "The [{$name}] field could not be prepared for storage on this server.",
            );
        }
    }

    /**
     * Filament exposes a hasser for one of the two callbacks but not the other,
     * so the second is read directly off the component.
     */
    protected function declaresDehydration(Field $field): bool
    {
        if ($field->mutatesDehydratedState()) {
            return true;
        }

        try {
            return (new ReflectionProperty($field, 'dehydrateStateUsing'))
                ->getValue($field) instanceof Closure
            ;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Run stored values through their mapper on the way back to the agent.
     *
     * Uses the full field list rather than the writable one, since a field can
     * be read-only in the panel and still worth returning.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function applyReads(McpResource $mcpResource, array $data, Model $record): array
    {
        $fields = $this->getFormFields($mcpResource->getResource());

        foreach ($data as $name => $value) {
            $field = $fields[$name] ?? null;

            if ($field === null) {
                continue;
            }

            $mapper = $this->mappers->mapperFor($field);

            if (! $mapper instanceof TransformsReads) {
                continue;
            }

            try {
                $data[$name] = $this->applyReadTransform($mapper, $field, $name, $value, $record);
            } catch (Throwable $e) {
                // Never let a presentation concern break a read. The raw stored
                // value is more useful to the agent than an error.
                report($e);
            }
        }

        return $data;
    }

    /**
     * Run a mapper's read transform over a value that may be a translation map.
     *
     * A translatable attribute serialises to `locale => value`, and the mapper
     * only knows how to transform each locale's value. Handing it the whole map
     * would make a `RichEditor` parse `['en' => ..., 'de' => ...]` as a document.
     */
    protected function applyReadTransform(
        TransformsReads $mapper,
        Field $field,
        string $name,
        mixed $value,
        Model $record,
    ): mixed {
        if (! is_array($value) || ! $this->isTranslatableAttribute($record, $name)) {
            return $mapper->read($field, $value, $record);
        }

        return array_map(
            fn (mixed $translation): mixed => $mapper->read($field, $translation, $record),
            $value,
        );
    }

    /**
     * Shape a written value so that `fill()` updates one locale of a
     * translatable attribute rather than replacing every translation.
     *
     * `HasTranslations::setAttribute()` only handles a scalar correctly. Given an
     * array it calls `setTranslations()`, which reads the array's own keys as
     * locales, which is what a mapper returning a document (a `RichEditor` under
     * `->json()`) or a list (a multiple `FileUpload`) produces. So the locale map
     * is built here instead.
     */
    protected function prepareTranslatableWrite(Model $model, string $name, mixed $value): mixed
    {
        $translations = $this->getStoredTranslations($model, $name);

        if ($translations === null) {
            return $value;
        }

        $locale = app()->getLocale();

        // Clearing the field drops the locale rather than storing a null against
        // it, so `getTranslations()` keeps reporting only what exists.
        if ($value === null) {
            unset($translations[$locale]);

            return $translations;
        }

        return [...$translations, $locale => $value];
    }

    /**
     * The translations already stored for an attribute, or null when the model
     * does not treat it as translatable.
     *
     * @return array<string, mixed>|null
     */
    protected function getStoredTranslations(Model $model, string $name): ?array
    {
        if (! method_exists($model, 'getTranslations') || ! $this->isTranslatableAttribute($model, $name)) {
            return null;
        }

        try {
            return $model->getTranslations($name);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether the model treats this attribute as translatable.
     *
     * Duck-typed, since spatie/laravel-translatable is not a dependency and an
     * application may implement the same contract itself.
     */
    protected function isTranslatableAttribute(Model $model, string $name): bool
    {
        if (! method_exists($model, 'isTranslatableAttribute')) {
            return false;
        }

        try {
            return (bool) $model->isTranslatableAttribute($name);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  class-string<resource>  $resource
     * @return array<string, Field>
     */
    protected function buildFormFields(string $resource, ?Model $record = null): array
    {
        $schema = $resource::form(
            Schema::make(app(SchemaHost::class))
                ->model($record ?? $resource::getModel())
                ->when($record !== null, fn (Schema $schema): Schema => $schema->record($record)),
        );

        return $schema->getFlatFields(withHidden: true);
    }

    protected function isVisibleField(McpResource $mcpResource, string $name): bool
    {
        if (in_array($name, $mcpResource->getHiddenFields())) {
            return false;
        }

        $allowed = $mcpResource->getFields();

        return $allowed === [] || in_array($name, $allowed);
    }

    protected function isStaticallyDisabled(Field $field): bool
    {
        try {
            return $field->isDisabled() || ! $field->isDehydrated();
        } catch (Throwable) {
            return true;
        }
    }
}
