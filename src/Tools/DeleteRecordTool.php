<?php

namespace Guava\FilamentMcp\Tools;

use Guava\FilamentMcp\Enums\McpOperation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsDestructive]
#[IsIdempotent]
class DeleteRecordTool extends ResourceTool
{
    public function getOperation(): McpOperation
    {
        return McpOperation::Delete;
    }

    protected function getDefaultDescription(): string
    {
        return "Delete a {$this->mcpResource->getName()} record by its primary key.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('The primary key of the record to delete.')
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

        $record->delete();

        return $this->jsonResponse([
            'deleted' => true,
            'id' => $validated['id'],
        ]);
    }
}
