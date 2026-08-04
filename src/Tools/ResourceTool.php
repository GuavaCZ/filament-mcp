<?php

namespace Guava\FilamentMcp\Tools;

use Filament\Resources\Resource;
use Guava\FilamentMcp\Enums\McpOperation;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\Schema\PreparedWrite;
use Guava\FilamentMcp\Schema\SchemaTransposer;
use Guava\FilamentMcp\Schema\WriteContext;
use Guava\FilamentMcp\Support\RecordTransformer;
use Guava\FilamentMcp\Support\ResourceAuthorizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class ResourceTool extends Tool
{
    public function __construct(
        protected McpResource $mcpResource,
        protected string $panelId,
    ) {
        $this->name = $mcpResource->getToolName($this->getOperation());
        $this->title = Str::headline($this->name);
        $this->description = implode(' ', array_filter([
            $this->getDefaultDescription(),
            $mcpResource->getDescription(),
        ]));
    }

    abstract public function getOperation(): McpOperation;

    abstract protected function getDefaultDescription(): string;

    public function shouldRegister(Request $request): bool
    {
        return $request->user() !== null || $this->mcpResource->isPublic();
    }

    /**
     * @return class-string<resource>
     */
    protected function getResource(): string
    {
        return $this->mcpResource->getResource();
    }

    protected function getQuery(): Builder
    {
        $query = $this->getResource()::getEloquentQuery();

        // Every read reports the related keys of syncable fields, so eager load
        // them instead of paying a query per record.
        $relations = array_keys($this->transposer()->getRelationshipFields($this->mcpResource));

        if ($relations !== []) {
            $query->with($relations);
        }

        if ($callback = $this->mcpResource->getModifyQueryUsing()) {
            $query = $this->mcpResource->evaluate($callback, ['query' => $query]) ?? $query;
        }

        return $query;
    }

    protected function resolveRecord(mixed $key): ?Model
    {
        return $this->getQuery()->whereKey($key)->first();
    }

    protected function authorize(?Model $record = null): ?string
    {
        return app(ResourceAuthorizer::class)->authorize($this->mcpResource, $this->getOperation(), $record);
    }

    /**
     * The record-independent half of authorization, for tools that take an id.
     *
     * Call this before resolving the record, so a denied caller cannot tell
     * "no such record" apart from "not allowed" and enumerate primary keys.
     */
    protected function preAuthorize(): ?string
    {
        return app(ResourceAuthorizer::class)->preAuthorize($this->mcpResource, $this->getOperation());
    }

    /**
     * @return array<string, mixed>
     */
    protected function transform(Model $record): array
    {
        return app(RecordTransformer::class)->transform($record, $this->mcpResource);
    }

    /**
     * Turn validated MCP input into model attributes and relationship sets.
     *
     * Field mappers run first so that resource-level `mutateDataUsing()` sees
     * storage-shaped values (a stored path, not an upload handle) rather than
     * the MCP wire format.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws FieldWriteException
     */
    protected function prepareData(array $data, ?Model $record = null): PreparedWrite
    {
        $data = $this->transposer()->applyWrites(
            $this->mcpResource,
            $data,
            new WriteContext($this->mcpResource, $record, $this->panelId),
        );

        if ($callback = $this->mcpResource->getMutateDataUsing()) {
            $data = $this->mcpResource->evaluate($callback, [
                'data' => $data,
                'record' => $record,
            ]);
        }

        $relationships = array_intersect_key(
            $data,
            $this->transposer()->getRelationshipFields($this->mcpResource, $record),
        );

        $attributes = array_diff_key($data, $relationships);

        $this->assertAttributesAreFillable($attributes, $record);

        return new PreparedWrite($attributes, $relationships);
    }

    /**
     * Write the relationship sets the save itself could not carry.
     *
     * @param  array<string, array<int, mixed>>  $relationships
     */
    protected function syncRelationships(Model $record, array $relationships): void
    {
        foreach ($relationships as $name => $keys) {
            $relation = $record->{$name}();

            if ($relation instanceof BelongsToMany) {
                $relation->sync($keys);
            }
        }
    }

    /**
     * Refuse to write attributes the model would silently discard.
     *
     * The write path is `$record->fill($data)`, which drops anything outside
     * `$fillable`. Reporting success while the value never reaches the database
     * gives the agent no way to notice.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws FieldWriteException
     */
    protected function assertAttributesAreFillable(array $data, ?Model $record): void
    {
        $model = $record ?? $this->getQuery()->getModel();

        $unfillable = array_keys(array_filter(
            $data,
            fn (string $key): bool => ! $model->isFillable($key),
            ARRAY_FILTER_USE_KEY,
        ));

        if ($unfillable === []) {
            return;
        }

        throw new FieldWriteException(sprintf(
            'The [%s] %s not mass assignable on [%s]. Add %s to the model\'s $fillable, or exclude %s with McpResource::hiddenFields().',
            implode('], [', $unfillable),
            count($unfillable) === 1 ? 'attribute is' : 'attributes are',
            class_basename($model),
            count($unfillable) === 1 ? 'it' : 'them',
            count($unfillable) === 1 ? 'it' : 'them',
        ));
    }

    protected function transposer(): SchemaTransposer
    {
        return app(SchemaTransposer::class);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function jsonResponse(array $data): Response | ResponseFactory
    {
        return $data === []
            ? Response::text('{}')
            : Response::structured($data);
    }
}
