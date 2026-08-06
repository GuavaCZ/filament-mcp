---
title: Custom tools
---

# Custom tools

Full control, this is a plain `laravel/mcp` tool:

```php
use Guava\FilamentMcp\Concerns\InteractsWithFilamentContext;
use Laravel\Mcp\Server\Tool;

class SummarizeOrdersTool extends Tool
{
    use InteractsWithFilamentContext;

    // ...
}
```

```php
McpPlugin::make()->tools([SummarizeOrdersTool::class])->prompts([WeeklyReportPrompt::class])
```

`InteractsWithFilamentContext` gives you three groups of helpers, all protected, so you call them as `$this->user()` from inside the tool:

- **Context**: `panel()`, `tenant()`, `user()`, `token()`.
- **Authorization**: `preAuthorizeResource()` and `authorizeResource()` run the *same* authorizer the generated CRUD tools run. That means authentication and the token's abilities first, then either the resource's `authorize()` callback or, when there isn't one, the model policy. Both return `null` when allowed, or a denial reason to hand back to the agent.
- **Records**: `resolveRecordFor()` looks a record up through the resource's Eloquent query, so tenant scoping and soft deletes apply, plus its `McpResource::query()` callback when the resource is registered on the current server.

## Authorizing a resource tool

When your tool touches a resource, use the helpers in the order the generated tools do: pre-authorize, resolve, authorize with the record.

```php
public function handle(Request $request): Response | ResponseFactory
{
    $validated = $request->validate(['id' => ['required']]);

    // Before resolving: a caller who fails this must not learn which keys exist.
    if ($error = $this->preAuthorizeResource(PostResource::class, McpOperation::Update)) {
        return Response::error($error);
    }

    $post = $this->resolveRecordFor(PostResource::class, $validated['id']);

    if ($post === null) {
        return Response::error('No post found.');
    }

    // With the record: `update` is a question about this row, not the class.
    if ($error = $this->authorizeResource(PostResource::class, McpOperation::Update, $post)) {
        return Response::error($error);
    }

    // ...
}
```

## Keeping the body thin

Exactly as with a bridged action, the tool should reach for [an operation](03-sharing-operations.md) rather than carry the logic:

```php
class SyncInventoryTool extends Tool
{
    use InteractsWithFilamentContext;

    protected string $name = 'sync_inventory';

    protected string $description = 'Sync inventory from the supplier feed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'since' => $schema->string()->description('Only sync items changed after this date.'),
        ];
    }

    public function handle(Request $request): Response | ResponseFactory
    {
        $validated = $request->validate(['since' => ['nullable', 'date']]);

        if ($error = $this->authorizeSync()) {
            return Response::error($error);
        }

        return Response::structured(app(SyncInventory::class)(
            isset($validated['since']) ? Carbon::parse($validated['since']) : null,
        ));
    }
}
```

## Authorizing everything else

> [!WARNING]
> **Nothing authorizes a custom tool for you.** The CRUD and bridged-action tools run through the package's authorizer; a custom tool does not, so an unauthenticated caller, a token whose abilities exclude this operation, and a user your policies would refuse all reach `handle()` unless you stop them.

When the tool acts on a resource, call `preAuthorizeResource()` / `authorizeResource()` as shown above. When it doesn't map to a resource at all, like this sync tool, check `$this->user()` and `$this->token()?->can('...')` yourself, then apply whatever policy fits:

```php
protected function authorizeSync(): ?string
{
    if ($this->user() === null) {
        return 'Unauthenticated.';
    }

    if (($token = $this->token()) && ! $token->can('inventory:sync')) {
        return 'The current token does not grant the [inventory:sync] ability.';
    }

    return Gate::allows('syncInventory') ? null : 'You are not authorized to perform this operation.';
}
```

A null token means the request carries no MCP token, so either a guest on a public server or an app authenticating MCP itself. Treat a present token as the thing that narrows access rather than the thing that grants it, and check `$this->user()` separately for whether there is a caller at all.

## Keeping a custom tool away from guests

A custom tool is a plain `laravel/mcp` tool, so it's listed to anonymous callers on any server with a `public()` resource. Add the trait to hide it:

```php
use Guava\FilamentMcp\Concerns\RequiresAuthentication;

class SyncInventoryTool extends Tool
{
    use RequiresAuthentication;
}
```

This controls listing and dispatch, not authorization. Keep the checks in `handle()` as well.
