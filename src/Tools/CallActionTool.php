<?php

namespace Guava\FilamentMcp\Tools;

use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Exceptions\Halt;
use Guava\FilamentMcp\Enums\McpOperation;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Guava\FilamentMcp\Mcp\McpActionConfig;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\Schema\SchemaTransposer;
use Guava\FilamentMcp\Schema\WriteContext;
use Guava\FilamentMcp\Support\ActionSchema;
use Guava\FilamentMcp\Support\ResourceAuthorizer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Throwable;

class CallActionTool extends ResourceTool
{
    /**
     * @var array<string, Field>|null
     */
    protected ?array $modalFields = null;

    public function __construct(
        McpResource $mcpResource,
        string $panelId,
        protected string $actionName,
        protected McpActionConfig $config,
        protected Action $action,
    ) {
        parent::__construct($mcpResource, $panelId);

        $this->name = $config->name
            ?? Str::snake($actionName) . '_' . $mcpResource->getName();
        $this->title = Str::headline($this->name);
        $this->description = implode(' ', array_filter([
            $config->description ?? "Call the [{$actionName}] action on a {$mcpResource->getName()} record.",
            $mcpResource->getDescription(),
        ]));
    }

    public function getOperation(): McpOperation
    {
        return McpOperation::Update;
    }

    protected function getDefaultDescription(): string
    {
        return '';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description("The primary key of the {$this->mcpResource->getName()} record to call the action on.")
                ->required(),
            // The action's own modal form, so the agent is asked for the same
            // input a person would be. ActionDiscoverer has already proven every
            // one of these fields transposes, so this cannot throw.
            ...app(SchemaTransposer::class)->transposeFields($this->getModalFields(), $schema),
            ...($this->config->schema !== null ? ($this->config->schema)($schema) : []),
        ];
    }

    public function handle(Request $request): Response | ResponseFactory
    {
        $validated = $request->validate([
            'id' => ['required'],
        ]);

        if ($error = app(ResourceAuthorizer::class)->preAuthorizeAction($this->mcpResource, $this->actionName)) {
            return Response::error($error);
        }

        $record = $this->resolveRecord($validated['id']);

        if ($record === null) {
            return Response::error("No {$this->mcpResource->getName()} record found for key [{$validated['id']}].");
        }

        if ($error = $this->authorizeAction($record)) {
            return Response::error($error);
        }

        // Request::all() is the raw JSON-RPC argument map, so narrow it to the
        // declared names. Otherwise an agent could set arguments the action
        // branches on but never advertised.
        $arguments = collect($request->all())
            ->only($this->getDeclaredArgumentNames())
            ->except('id')
            ->all()
        ;

        try {
            $data = $this->prepareModalData($request, $record);
        } catch (FieldWriteException $e) {
            return Response::error($e->getMessage());
        }

        try {
            $result = $this->action
                ->record($record)
                // The same setter the panel uses on submit, so the action closure
                // sees the array it was written against.
                ->data($data)
                ->arguments($arguments)
                ->call()
            ;
        } catch (Halt) {
            return Response::error('The action was halted.');
        } catch (Cancel) {
            return Response::error('The action was cancelled.');
        } catch (Throwable $e) {
            report($e);

            // Not $e->getMessage(): a raw exception message hands the agent SQL
            // fragments, file paths and internal class names.
            return Response::error("The [{$this->actionName}] action failed.");
        }

        return $this->jsonResponse([
            'result' => $result,
            'record' => $this->transform($record->refresh()),
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function getDeclaredArgumentNames(): array
    {
        if ($this->config->schema === null) {
            return [];
        }

        $schema = JsonSchemaFactory::object(fn (JsonSchema $schema): array => ($this->config->schema)($schema))
            ->toArray()
        ;

        return array_keys((array) ($schema['properties'] ?? []));
    }

    /**
     * The action's modal fields as advertised, built without a record because
     * `tools/list` has none.
     *
     * @return array<string, Field>
     */
    protected function getModalFields(): array
    {
        return $this->modalFields ??= ActionSchema::fields($this->action);
    }

    /**
     * Validate and prepare the modal form input for this call.
     *
     * @return array<string, mixed>
     *
     * @throws FieldWriteException
     * @throws ValidationException
     */
    protected function prepareModalData(Request $request, Model $record): array
    {
        $declared = $this->getModalFields();

        if ($declared === []) {
            return [];
        }

        $fields = $this->getRecordBoundFields($record, $declared);

        $transposer = app(SchemaTransposer::class);

        // Validated against the field's own rules, so a required modal field is
        // required here too, and only advertised names are read out of the
        // argument map.
        $data = $request->validate($transposer->getValidationRulesForFields($fields));

        $data = collect($data)->only(array_keys($fields))->all();

        return $transposer->applyWritesToFields(
            $fields,
            ActionSchema::withDefaults($fields, $data),
            new WriteContext($this->mcpResource, $record, $this->panelId),
        );
    }

    /**
     * The advertised fields, rebuilt against the record where that is possible.
     *
     * Rules routinely consult the record (`unique(ignoreRecord: true)` above all)
     * and a schema built without one cannot answer them. Where the rebuild fails
     * the advertised field stands in, carrying the same rules minus the record.
     *
     * @param  array<string, Field>  $declared
     * @return array<string, Field>
     */
    protected function getRecordBoundFields(Model $record, array $declared): array
    {
        try {
            $bound = ActionSchema::fields($this->action, $record);
        } catch (Throwable $e) {
            report($e);

            return $declared;
        }

        $fields = [];

        foreach ($declared as $name => $field) {
            $fields[$name] = $bound[$name] ?? $field;
        }

        return $fields;
    }

    protected function authorizeAction(Model $record): ?string
    {
        // Bind the record first, since both the policy mapping below and the
        // action's own visibility closures are evaluated against it.
        $this->action->record($record);

        if ($error = app(ResourceAuthorizer::class)->authorizeAction(
            $this->mcpResource,
            $this->actionName,
            $this->action,
            $record,
        )) {
            return $error;
        }

        // An action the panel would render hidden or greyed out is not callable
        // here either. isAuthorized() covers actions declaring their own
        // ->authorize(); the default-policy case is handled above.
        if ($this->action->isHidden() || $this->action->isDisabled() || ! $this->action->isAuthorized()) {
            return 'You are not authorized to perform this action.';
        }

        return null;
    }
}
