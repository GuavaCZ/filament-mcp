<?php

namespace Guava\FilamentMcp\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Tables\Table;
use Guava\FilamentMcp\Mcp\McpActionConfig;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\Schema\SchemaTransposer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\Support\Facades\Log;
use ReflectionFunction;
use Throwable;

class ActionDiscoverer
{
    /**
     * Closure parameter names that can be resolved safely without a Livewire
     * component. Everything else makes an action ineligible for MCP.
     */
    protected const SAFE_PARAMETERS = ['record', 'data', 'arguments'];

    /**
     * @var array<string, array<string, array{Action, McpActionConfig}>>
     */
    protected array $cache = [];

    public function __construct(
        protected ActionMarkRegistry $registry,
    ) {}

    /**
     * Discover the MCP-exposable actions of the resource's table.
     *
     * @return array<string, array{Action, McpActionConfig}> keyed by action name
     */
    public function discover(McpResource $mcpResource, bool $includeMarked = true): array
    {
        $key = $mcpResource->getResource() . ':' . ($includeMarked ? 1 : 0);

        return $this->cache[$key] ??= $this->build($mcpResource, $includeMarked);
    }

    /**
     * @return array<string, array{Action, McpActionConfig}>
     */
    protected function build(McpResource $mcpResource, bool $includeMarked): array
    {
        $resource = $mcpResource->getResource();

        try {
            $table = $resource::table(
                Table::make(app(TableHost::class))
                    ->query($resource::getEloquentQuery()),
            );

            $actions = $table->getFlatActions();
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $discovered = [];

        foreach ($actions as $name => $action) {
            $config = $this->registry->configFor($action);

            if ($config === null && ! in_array($name, $mcpResource->getActions())) {
                continue;
            }

            if ($config !== null && ! $includeMarked && ! in_array($name, $mcpResource->getActions())) {
                continue;
            }

            if ($reason = $this->getUnsafeReason($action)) {
                Log::info("Filament MCP: skipping action [{$name}] on [{$resource}]: {$reason}");

                continue;
            }

            $discovered[$name] = [$action, $config ?? new McpActionConfig];
        }

        return $discovered;
    }

    /**
     * Returns a reason when the action cannot be executed headlessly, or null
     * when it is safe.
     */
    protected function getUnsafeReason(Action $action): ?string
    {
        $closure = $action->getActionFunction();

        if (! $closure instanceof Closure) {
            return 'its action is not a closure (Livewire method names cannot run headlessly)';
        }

        foreach ((new ReflectionFunction($closure))->getParameters() as $parameter) {
            if (! in_array($parameter->getName(), self::SAFE_PARAMETERS)) {
                return "its action closure requests [\${$parameter->getName()}], which is not available headlessly"
                    . ' (only $record, $data and $arguments are)';
            }
        }

        return $this->getUnsafeSchemaReason($action);
    }

    /**
     * Whether the action's modal form can be put in front of an agent.
     *
     * An action that collects input before it runs is only exposable if every one
     * of its fields can be described to the agent and validated. Otherwise the
     * tool would ask for nothing, validate nothing and hand the closure an empty
     * `$data`, so the action is skipped and the log line names the field.
     */
    protected function getUnsafeSchemaReason(Action $action): ?string
    {
        try {
            $fields = ActionSchema::fields($action);

            if ($fields === []) {
                return null;
            }

            $transposer = app(SchemaTransposer::class);

            // Serialised, not just built. A type that maps cleanly can still fail
            // on the way to JSON, which the agent would see as a broken tool list
            // rather than one missing action.
            JsonSchemaFactory::object(
                fn (JsonSchema $schema): array => $transposer->transposeFields($fields, $schema),
            )->toArray();

            $transposer->getValidationRulesForFields($fields);
        } catch (Throwable $e) {
            return 'its modal schema cannot be resolved headlessly (' . $e->getMessage() . ')';
        }

        return null;
    }
}
