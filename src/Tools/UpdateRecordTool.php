<?php

namespace Guava\FilamentMcp\Tools;

use Guava\FilamentMcp\Enums\McpOperation;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateRecordTool extends ResourceTool
{
    public function getOperation(): McpOperation
    {
        return McpOperation::Update;
    }

    protected function getDefaultDescription(): string
    {
        return "Update an existing {$this->mcpResource->getName()} record. Only the provided attributes are changed.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('The primary key of the record to update.')
                ->required(),
            ...$this->transposer()->transpose($this->mcpResource, $schema, partial: true),
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

        $data = $request->validate(
            // The record is passed so rules that consult it resolve correctly.
            // `unique(ignoreRecord: true)` otherwise compares the record against
            // itself and rejects an unchanged value.
            $this->transposer()->getValidationRules($this->mcpResource, $request->all(), partial: true, record: $record),
        );

        unset($data['id']);

        try {
            $prepared = $this->prepareData($data, $record);
        } catch (FieldWriteException $e) {
            return Response::error($e->getMessage());
        }

        $record->fill($prepared->attributes);
        $record->save();

        $this->syncRelationships($record, $prepared->relationships);

        return $this->jsonResponse($this->transform($record->refresh()));
    }
}
