<?php

namespace Guava\FilamentMcp\Tools;

use Guava\FilamentMcp\Enums\McpOperation;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\McpServerRegistry;
use Guava\FilamentMcp\Support\RecordTransformer;
use Guava\FilamentMcp\Support\ResourceAuthorizer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsReadOnly]
#[IsIdempotent]
class ListRelatedRecordsTool extends ResourceTool
{
    public function __construct(
        McpResource $mcpResource,
        string $panelId,
        protected string $relation,
    ) {
        parent::__construct($mcpResource, $panelId);

        $this->name = "list_{$mcpResource->getName()}_{$relation}";
        $this->title = str($this->name)->headline()->toString();
    }

    public function getOperation(): McpOperation
    {
        return McpOperation::Get;
    }

    protected function getDefaultDescription(): string
    {
        return "List the [{$this->relation}] records related to a {$this->mcpResource->getName()} record.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description("The primary key of the parent {$this->mcpResource->getName()} record.")
                ->required(),
            'page' => $schema->integer()
                ->description('The page to retrieve.')
                ->default(1),
            'per_page' => $schema->integer()
                ->description('Records per page (max ' . $this->mcpResource->getRecordsPerPage() . ').'),
        ];
    }

    public function handle(Request $request): Response | ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);

        $relatedMcpResource = $this->resolveRelatedMcpResource();

        if ($relatedMcpResource === null) {
            return Response::error("The [{$this->relation}] relation is not available.");
        }

        if ($error = $this->preAuthorize()) {
            return Response::error($error);
        }

        // Listing related records requires exactly what list_* on that resource
        // requires, so a relation cannot be used to bypass its policy.
        if ($error = app(ResourceAuthorizer::class)->authorize($relatedMcpResource, McpOperation::List)) {
            return Response::error($error);
        }

        $record = $this->resolveRecord($validated['id']);

        if ($record === null) {
            return Response::error("No {$this->mcpResource->getName()} record found for key [{$validated['id']}].");
        }

        if ($error = $this->authorize($record)) {
            return Response::error($error);
        }

        if (! method_exists($record, $this->relation) || ! ($record->{$this->relation}() instanceof Relation)) {
            return Response::error("The relation [{$this->relation}] does not exist on the record.");
        }

        $perPage = min(
            (int) ($validated['per_page'] ?? $this->mcpResource->getRecordsPerPage()),
            $this->mcpResource->getRecordsPerPage(),
        );

        $paginator = $this->getRelationQuery($record, $relatedMcpResource)->paginate(
            perPage: $perPage,
            page: (int) ($validated['page'] ?? 1),
        );

        return $this->jsonResponse([
            'records' => collect($paginator->items())
                ->map(fn (Model $related) => app(RecordTransformer::class)->transform($related, $relatedMcpResource))
                ->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * The relation, narrowed to what the related resource's own query returns.
     *
     * Paginating the raw relation would skip that resource's global scopes
     * (tenancy, soft deletes) and its modifyQueryUsing() callback, surfacing
     * records its own list tool would not.
     */
    protected function getRelationQuery(Model $record, McpResource $relatedMcpResource): Relation
    {
        $relation = $record->{$this->relation}();
        $related = $relation->getRelated();

        $allowed = $relatedMcpResource->getResource()::getEloquentQuery();

        if ($callback = $relatedMcpResource->getModifyQueryUsing()) {
            $allowed = $relatedMcpResource->evaluate($callback, ['query' => $allowed]) ?? $allowed;
        }

        return $relation->whereIn(
            $related->getQualifiedKeyName(),
            $allowed->select($related->getQualifiedKeyName()),
        );
    }

    /**
     * A relation is only exposed when the related model is itself an MCP
     * resource on this panel.
     *
     * Any other serialisation would dump every non-hidden column with no field
     * visibility and no policy to consult.
     */
    public function shouldRegister(HttpRequest $request): bool
    {
        return parent::shouldRegister($request) && $this->resolveRelatedMcpResource() !== null;
    }

    protected function resolveRelatedMcpResource(): ?McpResource
    {
        $relatedClass = $this->getRelatedClass();

        if ($relatedClass === null) {
            return null;
        }

        $definition = app(McpServerRegistry::class)->get($this->panelId);

        if ($definition === null) {
            return null;
        }

        foreach ($definition->mcpResources as $mcpResource) {
            if ($mcpResource->getResource()::getModel() === $relatedClass) {
                return $mcpResource;
            }
        }

        return null;
    }

    /**
     * @return class-string<Model>|null
     */
    protected function getRelatedClass(): ?string
    {
        try {
            $model = $this->getResource()::getModel();
            $relation = (new $model)->{$this->relation}();

            return $relation instanceof Relation
                ? $relation->getRelated()::class
                : null;
        } catch (Throwable) {
            // A relation that cannot be built headlessly is not safe to expose.
            return null;
        }
    }
}
