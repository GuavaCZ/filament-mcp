<?php

namespace Guava\FilamentMcp\Tools;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Guava\FilamentMcp\Enums\McpOperation;
use Guava\FilamentMcp\Support\RecordTransformer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ListRecordsTool extends ResourceTool
{
    public function getOperation(): McpOperation
    {
        return McpOperation::List;
    }

    protected function getDefaultDescription(): string
    {
        return "List {$this->mcpResource->getPluralName()} records with pagination, search and sorting.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()
                ->description('The page to retrieve.')
                ->default(1),
            'per_page' => $schema->integer()
                ->description('Records per page (max ' . $this->mcpResource->getRecordsPerPage() . ').'),
            'search' => $schema->string()
                ->description('Search term matched against the searchable attributes.'),
            'sort' => $schema->string()
                ->description('Attribute to sort by.'),
            'direction' => $schema->string()
                ->enum(['asc', 'desc'])
                ->description('Sort direction.'),
        ];
    }

    public function handle(Request $request): Response | ResponseFactory
    {
        if ($error = $this->authorize()) {
            return Response::error($error);
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', 'string', 'max:255'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $query = $this->getQuery();

        if (filled($validated['search'] ?? null)) {
            $this->applySearch($query, $validated['search']);
        }

        if (filled($validated['sort'] ?? null)) {
            if ($error = $this->applySort($query, $validated['sort'], $validated['direction'] ?? 'asc')) {
                return Response::error($error);
            }
        }

        $perPage = min(
            (int) ($validated['per_page'] ?? $this->mcpResource->getRecordsPerPage()),
            $this->mcpResource->getRecordsPerPage(),
        );

        $paginator = $query->paginate(
            perPage: $perPage,
            page: (int) ($validated['page'] ?? 1),
        );

        return $this->jsonResponse([
            'records' => collect($paginator->items())
                ->map(fn ($record) => $this->transform($record))
                ->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    protected function applySearch(Builder $query, string $search): void
    {
        $attributes = $this->getSearchableAttributes($query);

        if ($attributes === []) {
            return;
        }

        $query->where(function (Builder $query) use ($attributes, $search) {
            foreach ($attributes as $attribute) {
                // A dotted name addresses a relationship, which would need a
                // join this tool does not build.
                if (str_contains($attribute, '.')) {
                    continue;
                }

                $query->orWhere($attribute, 'like', "%{$search}%");
            }
        });
    }

    /**
     * @return array<int, string>
     */
    protected function getSearchableAttributes(Builder $query): array
    {
        $attributes = $this->getResource()::getGloballySearchableAttributes();

        if ($attributes === []) {
            $attributes = $this->getSearchableFormFields($query);
        }

        // A field kept out of the output stays out of the WHERE clause too. A
        // `LIKE '%term%'` over a hidden column never prints it, but the match
        // count answers "does it contain this?" once per character. hiddenFields()
        // is a deny-list, so it also overrides getGloballySearchableAttributes().
        return $this->onlyReadable($query, $attributes);
    }

    /**
     * Text-like form fields, used when the resource declares no globally
     * searchable attributes of its own.
     *
     * Kept narrow: `LIKE` against a json or boolean column is meaningless on
     * MySQL and an error on Postgres. A cast on the attribute is the tell that
     * the column holds something other than text.
     *
     * @return array<int, string>
     */
    protected function getSearchableFormFields(Builder $query): array
    {
        $casts = $query->getModel()->getCasts();

        $fields = array_filter(
            $this->transposer()->getFormFields($this->getResource()),
            function (Field $field, string $name) use ($casts): bool {
                if (! $field instanceof TextInput && ! $field instanceof Textarea) {
                    return false;
                }

                return ! isset($casts[$name]);
            },
            ARRAY_FILTER_USE_BOTH,
        );

        return array_keys($fields);
    }

    protected function applySort(Builder $query, string $sort, string $direction): ?string
    {
        $sortable = $this->getSortableAttributes($query);

        if (! in_array($sort, $sortable)) {
            return "Cannot sort by [{$sort}]. Sortable attributes: " . implode(', ', $sortable) . '.';
        }

        $query->orderBy($sort, $direction);

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected function getSortableAttributes(Builder $query): array
    {
        $model = $query->getModel();

        $attributes = array_values(array_unique(array_filter([
            $model->getKeyName(),
            ...array_filter(
                array_keys($this->transposer()->getFormFields($this->getResource())),
                fn (string $name) => ! str_contains($name, '.'),
            ),
            $model->getCreatedAtColumn(),
            $model->getUpdatedAtColumn(),
        ])));

        // ORDER BY over a field the caller may not read still reports on it: the
        // returned order compares it against every other row, and per_page=1 turns
        // that into a binary search. Sorting is limited to readable attributes.
        return $this->onlyReadable($query, $attributes);
    }

    /**
     * The given attributes, minus the ones this caller may not read.
     *
     * @param  array<int, string>  $attributes
     * @return array<int, string>
     */
    protected function onlyReadable(Builder $query, array $attributes): array
    {
        $readable = app(RecordTransformer::class)->getReadableAttributes(
            $query->getModel(),
            $this->mcpResource,
        );

        return array_values(array_intersect($attributes, $readable));
    }
}
