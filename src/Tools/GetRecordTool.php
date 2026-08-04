<?php

namespace Guava\FilamentMcp\Tools;

use Guava\FilamentMcp\Enums\McpOperation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class GetRecordTool extends ResourceTool
{
    public function getOperation(): McpOperation
    {
        return McpOperation::Get;
    }

    protected function getDefaultDescription(): string
    {
        return "Get a single {$this->mcpResource->getName()} record by its primary key.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('The primary key of the record.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response | ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required'],
        ]);

        if ($error = $this->preAuthorize()) {
            return Response::error($error);
        }

        $record = $this->resolveRecord($validated['id']);

        if ($record === null) {
            return Response::error("No {$this->mcpResource->getName()} record found for key [{$validated['id']}].");
        }

        if ($error = $this->authorize($record)) {
            return Response::error($error);
        }

        return $this->jsonResponse($this->transform($record));
    }
}
