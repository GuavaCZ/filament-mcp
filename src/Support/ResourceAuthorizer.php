<?php

namespace Guava\FilamentMcp\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Guava\FilamentMcp\Enums\McpOperation;
use Guava\FilamentMcp\Mcp\McpResource;
use Illuminate\Database\Eloquent\Model;

/**
 * The single authorization path for every tool.
 *
 * CRUD tools and action tools differ only in which ability the token needs and
 * which policy method stands in for "the panel would have allowed this". Both
 * share one code path so action authorization cannot drift out of step with the
 * resource's policy and its authorizeUsing() callback.
 */
class ResourceAuthorizer
{
    /**
     * Authorize the given operation. Returns null when allowed, or a
     * human-readable denial reason.
     */
    public function authorize(McpResource $mcpResource, McpOperation $operation, ?Model $record = null): ?string
    {
        // A record-level policy question without a record would either crash or
        // quietly check something else. Refuse and say what to do instead.
        if ($record === null && $operation->requiresRecord()) {
            return "The [{$operation->value}] operation must be authorized against a record. Pass the record, "
                . 'or use preAuthorize() for the checks that do not need one.';
        }

        return $this->check(
            mcpResource: $mcpResource,
            operation: $operation,
            abilitySuffix: $operation->value,
            record: $record,
            action: null,
            policyAllows: fn (): bool => $mcpResource->getResource()::can($operation->getPolicyAbility(), $record),
        );
    }

    /**
     * Authorize a Filament action exposed as a tool.
     */
    public function authorizeAction(McpResource $mcpResource, string $actionName, Action $action, Model $record): ?string
    {
        return $this->check(
            mcpResource: $mcpResource,
            operation: McpOperation::Update,
            abilitySuffix: $actionName,
            record: $record,
            action: $actionName,
            // Two stages, because the panel has two. Seeing the record is the
            // precondition for every action, since a button cannot be clicked on
            // a row that was never rendered, and the action's own policy sits on
            // top of that.
            policyAllows: fn (): bool => $mcpResource->getResource()::can('view', $record)
                && $this->actionPolicyAllows($mcpResource, $action, $record),
        );
    }

    /**
     * The gates that do not depend on a particular record: authentication and
     * the token's abilities.
     *
     * Tools run this before looking a record up. Reporting "no such record" to a
     * caller who could not have acted on it either way turns every record-specific
     * tool into an oracle for which primary keys exist.
     */
    public function preAuthorize(McpResource $mcpResource, McpOperation $operation): ?string
    {
        return $this->checkAccess($mcpResource, $operation, $operation->value, null);
    }

    public function preAuthorizeAction(McpResource $mcpResource, string $actionName): ?string
    {
        return $this->checkAccess($mcpResource, McpOperation::Update, $actionName, $actionName);
    }

    /**
     * @param  Closure(): bool  $policyAllows
     */
    protected function check(
        McpResource $mcpResource,
        McpOperation $operation,
        string $abilitySuffix,
        ?Model $record,
        ?string $action,
        Closure $policyAllows,
    ): ?string {
        if ($error = $this->checkAccess($mcpResource, $operation, $abilitySuffix, $action)) {
            return $error;
        }

        $user = Filament::auth()->user();

        if ($callback = $mcpResource->getAuthorizeUsing()) {
            $allowed = (bool) $mcpResource->evaluate($callback, [
                'operation' => $operation,
                'action' => $action,
                'record' => $record,
                'user' => $user,
            ]);

            return $allowed ? null : 'You are not authorized to perform this operation.';
        }

        if ($user !== null && ! $policyAllows()) {
            return 'You are not authorized to perform this operation.';
        }

        return null;
    }

    protected function checkAccess(
        McpResource $mcpResource,
        McpOperation $operation,
        string $abilitySuffix,
        ?string $action,
    ): ?string {
        if (Filament::auth()->user() === null) {
            if (! $mcpResource->isPublic()) {
                return 'Unauthenticated.';
            }

            // A public resource is an anonymous read surface. Writes stay behind
            // authentication, since with no user there is nobody for a policy to
            // be consulted about.
            if ($action !== null || ! $operation->isReadOnly()) {
                return 'Unauthenticated.';
            }

            // An anonymous caller holds no credential, so there is nothing for
            // abilities to scope. public() is the whole gate here.
            return null;
        }

        $ability = $this->getTokenAbility($mcpResource, $abilitySuffix);
        $grant = CurrentMcpGrant::get();

        // Fail closed. Authenticating says who the caller is, not what they may
        // do, so a request with nothing declaring its abilities is refused rather
        // than handed all of them.
        if ($grant === null) {
            return 'No MCP abilities were granted to this request. Authenticate with an MCP token, or set '
                . 'a grant with CurrentMcpGrant::set() if you authenticate MCP requests yourself.';
        }

        if (! $grant->can($ability)) {
            return 'The current token does not grant the [' . $ability . '] ability.';
        }

        return null;
    }

    /**
     * The policy check the panel itself would apply to this action.
     *
     * Filament resolves this through the Livewire component hosting the action
     * (`getDefaultActionAuthorizationResponse()`). Headlessly there is no such
     * host, so `Action::isAuthorized()` falls back to an unconditional true and
     * a DeleteAction would run with no `delete` check at all.
     *
     * A custom action maps to no policy, as in the panel, where it is allowed
     * unless it declares its own ->authorize(). This is the second of two stages:
     * authorizeAction() has already required `view` on the record, so "no policy"
     * means no elevation beyond reading it.
     */
    protected function actionPolicyAllows(McpResource $mcpResource, Action $action, Model $record): bool
    {
        $resource = $mcpResource->getResource();

        return match (true) {
            $action instanceof CreateAction => $resource::can('create'),
            $action instanceof DeleteAction => $resource::can('delete', $record),
            $action instanceof EditAction => $resource::can('update', $record),
            $action instanceof ForceDeleteAction => $resource::can('forceDelete', $record),
            $action instanceof ReplicateAction => $resource::can('replicate', $record),
            $action instanceof RestoreAction => $resource::can('restore', $record),
            $action instanceof ViewAction => $resource::can('view', $record),
            default => true,
        };
    }

    protected function getTokenAbility(McpResource $mcpResource, string $abilitySuffix): string
    {
        return "{$mcpResource->getPluralName()}:{$abilitySuffix}";
    }
}
