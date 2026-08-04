<?php

namespace Guava\FilamentMcp\Tools;

use Guava\FilamentMcp\Enums\McpOperation;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class CreateRecordTool extends ResourceTool
{
    public function getOperation(): McpOperation
    {
        return McpOperation::Create;
    }

    protected function getDefaultDescription(): string
    {
        return "Create a new {$this->mcpResource->getName()} record.";
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->transposer()->transpose($this->mcpResource, $schema);
    }

    public function handle(Request $request): Response | ResponseFactory
    {
        if ($error = $this->authorize()) {
            return Response::error($error);
        }

        $data = $request->validate(
            $this->transposer()->getValidationRules($this->mcpResource, $request->all()),
        );

        try {
            $prepared = $this->prepareData($data);
        } catch (FieldWriteException $e) {
            return Response::error($e->getMessage());
        }

        $record = $this->getQuery()->getModel()->newInstance();
        $record->fill($prepared->attributes);
        $record->save();

        $this->syncRelationships($record, $prepared->relationships);

        return $this->jsonResponse($this->transform($record->refresh()));
    }
}
