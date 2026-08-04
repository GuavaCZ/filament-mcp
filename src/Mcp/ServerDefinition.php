<?php

namespace Guava\FilamentMcp\Mcp;

use Filament\Forms\Components\BaseFileUpload;
use Guava\FilamentMcp\Enums\McpOperation;
use Guava\FilamentMcp\Schema\SchemaTransposer;
use Guava\FilamentMcp\Support\ActionDiscoverer;
use Guava\FilamentMcp\Tools\CallActionTool;
use Guava\FilamentMcp\Tools\CreateRecordTool;
use Guava\FilamentMcp\Tools\DeleteRecordTool;
use Guava\FilamentMcp\Tools\GetRecordTool;
use Guava\FilamentMcp\Tools\ListRecordsTool;
use Guava\FilamentMcp\Tools\ListRelatedRecordsTool;
use Guava\FilamentMcp\Tools\RequestUploadTool;
use Guava\FilamentMcp\Tools\UpdateRecordTool;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;
use Throwable;

class ServerDefinition
{
    /**
     * @param  array<int, McpResource>  $mcpResources
     * @param  array<int, class-string<Tool>>  $tools
     * @param  array<int, class-string<Prompt>>  $prompts
     * @param  array<int, class-string|string>  $middleware
     */
    public function __construct(
        public readonly string $panelId,
        public readonly string $path,
        public readonly string $serverName,
        public readonly string $instructions,
        public readonly array $mcpResources = [],
        public readonly array $tools = [],
        public readonly array $prompts = [],
        public readonly bool $public = false,
        public readonly array $middleware = [],
        public readonly bool $discoverActions = false,
        public readonly bool $tenantInPath = false,
    ) {}

    public function hasPublicResources(): bool
    {
        if ($this->public) {
            return true;
        }

        foreach ($this->mcpResources as $mcpResource) {
            if ($mcpResource->isPublic()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, Tool|class-string<Tool>>
     */
    public function makeTools(): array
    {
        $tools = [];

        foreach ($this->mcpResources as $mcpResource) {
            foreach ($mcpResource->getOperations() as $operation) {
                $tools[] = $this->makeResourceTool($mcpResource, $operation);
            }

            foreach ($mcpResource->getRelations() as $relation) {
                $tools[] = new ListRelatedRecordsTool($mcpResource, $this->panelId, $relation);
            }

            if ($this->discoverActions || $mcpResource->getActions() !== []) {
                $actions = app(ActionDiscoverer::class)->discover($mcpResource, $this->discoverActions);

                foreach ($actions as $name => [$action, $config]) {
                    $tools[] = new CallActionTool($mcpResource, $this->panelId, $name, $config, $action);
                }
            }
        }

        if ($this->hasWritableFileFields()) {
            $tools[] = new RequestUploadTool($this->panelId);
        }

        return [...$tools, ...$this->tools];
    }

    /**
     * Only offer the upload tool when something on this server can receive
     * a file.
     */
    public function hasWritableFileFields(): bool
    {
        $transposer = app(SchemaTransposer::class);

        foreach ($this->mcpResources as $mcpResource) {
            $writes = array_filter(
                $mcpResource->getOperations(),
                fn (McpOperation $operation): bool => ! $operation->isReadOnly(),
            );

            if ($writes === []) {
                continue;
            }

            try {
                $fields = $transposer->getWritableFields($mcpResource);
            } catch (Throwable) {
                continue;
            }

            foreach ($fields as $field) {
                if ($field instanceof BaseFileUpload) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function makeResourceTool(McpResource $mcpResource, McpOperation $operation): Tool
    {
        $class = match ($operation) {
            McpOperation::List => ListRecordsTool::class,
            McpOperation::Get => GetRecordTool::class,
            McpOperation::Create => CreateRecordTool::class,
            McpOperation::Update => UpdateRecordTool::class,
            McpOperation::Delete => DeleteRecordTool::class,
        };

        return new $class($mcpResource, $this->panelId);
    }
}
