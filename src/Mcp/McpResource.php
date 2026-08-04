<?php

namespace Guava\FilamentMcp\Mcp;

use Closure;
use Filament\Resources\Resource;
use Filament\Support\Concerns\EvaluatesClosures;
use Guava\FilamentMcp\Enums\McpOperation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class McpResource
{
    use EvaluatesClosures;

    /**
     * @var array<int, McpOperation>
     */
    protected array $operations = [McpOperation::List, McpOperation::Get];

    protected bool | Closure $public = false;

    protected ?string $name = null;

    protected ?string $pluralName = null;

    protected ?string $description = null;

    /**
     * @var array<int, string>
     */
    protected array $fields = [];

    /**
     * @var array<int, string>
     */
    protected array $hiddenFields = [];

    /**
     * @var array<int, string>
     */
    protected array $relations = [];

    /**
     * @var array<int, string>
     */
    protected array $actions = [];

    protected ?Closure $authorizeUsing = null;

    protected ?Closure $modifyQueryUsing = null;

    protected ?Closure $mutateDataUsing = null;

    protected int $recordsPerPage = 15;

    /**
     * @param  class-string<resource>  $resource
     */
    final public function __construct(
        protected string $resource,
    ) {}

    /**
     * @param  class-string<resource>  $resource
     */
    public static function make(string $resource): static
    {
        return new static($resource);
    }

    public function operations(McpOperation ...$operations): static
    {
        $this->operations = array_values($operations);

        return $this;
    }

    public function readOnly(): static
    {
        return $this->operations(McpOperation::List, McpOperation::Get);
    }

    public function crud(): static
    {
        return $this->operations(...McpOperation::cases());
    }

    public function except(McpOperation ...$operations): static
    {
        $this->operations = array_values(array_filter(
            McpOperation::cases(),
            fn (McpOperation $operation) => ! in_array($operation, $operations),
        ));

        return $this;
    }

    public function public(bool | Closure $condition = true): static
    {
        $this->public = $condition;

        return $this;
    }

    public function name(string $name, ?string $pluralName = null): static
    {
        $this->name = $name;
        $this->pluralName = $pluralName;

        return $this;
    }

    public function describe(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @param  array<int, string>  $fields
     */
    public function fields(array $fields): static
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * @param  array<int, string>  $fields
     */
    public function hiddenFields(array $fields): static
    {
        $this->hiddenFields = $fields;

        return $this;
    }

    /**
     * @param  array<int, string>  $relations
     */
    public function relations(array $relations): static
    {
        $this->relations = $relations;

        return $this;
    }

    /**
     * @param  array<int, string>  $actions
     */
    public function actions(array $actions): static
    {
        $this->actions = $actions;

        return $this;
    }

    /**
     * @param  Closure(McpOperation $operation, ?Model $record, ?Authenticatable $user): bool  $callback
     */
    public function authorize(Closure $callback): static
    {
        $this->authorizeUsing = $callback;

        return $this;
    }

    /**
     * @param  Closure(Builder $query): Builder  $callback
     */
    public function query(Closure $callback): static
    {
        $this->modifyQueryUsing = $callback;

        return $this;
    }

    /**
     * @param  Closure(array $data, ?Model $record): array  $callback
     */
    public function mutateDataUsing(Closure $callback): static
    {
        $this->mutateDataUsing = $callback;

        return $this;
    }

    public function recordsPerPage(int $records): static
    {
        $this->recordsPerPage = $records;

        return $this;
    }

    /**
     * @return class-string<resource>
     */
    public function getResource(): string
    {
        return $this->resource;
    }

    /**
     * @return array<int, McpOperation>
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    public function hasOperation(McpOperation $operation): bool
    {
        return in_array($operation, $this->operations);
    }

    public function isPublic(): bool
    {
        return (bool) $this->evaluate($this->public);
    }

    public function getName(): string
    {
        return $this->name ?? Str::slug($this->resource::getModelLabel(), '_');
    }

    public function getPluralName(): string
    {
        return $this->pluralName ?? Str::slug($this->resource::getPluralModelLabel(), '_');
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getToolName(McpOperation $operation): string
    {
        $name = $operation === McpOperation::List
            ? $this->getPluralName()
            : $this->getName();

        return "{$operation->value}_{$name}";
    }

    /**
     * @return array<int, string>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * @return array<int, string>
     */
    public function getHiddenFields(): array
    {
        return $this->hiddenFields;
    }

    /**
     * @return array<int, string>
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * @return array<int, string>
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    public function getAuthorizeUsing(): ?Closure
    {
        return $this->authorizeUsing;
    }

    public function getModifyQueryUsing(): ?Closure
    {
        return $this->modifyQueryUsing;
    }

    public function getMutateDataUsing(): ?Closure
    {
        return $this->mutateDataUsing;
    }

    public function getRecordsPerPage(): int
    {
        return $this->recordsPerPage;
    }
}
