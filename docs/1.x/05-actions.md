---
title: Exposing actions
---

# Exposing actions

Beyond CRUD, you can expose your existing Filament actions as tools. There are three ways to do it, pick whichever fits — plus one habit worth adopting whichever you pick.

> [!IMPORTANT]
> Marked actions are only picked up when `->discoverActions()` is enabled on the plugin.

## Mark the real action

The recommended way, mark an action in your resource's `table()` with `->mcp()`:

```php
Action::make('publish')
    ->action(fn (Post $record) => $record->publish())
    ->mcp(description: 'Publish the post.'),
```

This becomes a `publish_post` tool, executed **headlessly** through the real action closure. `record()`, `isHidden()`, `isDisabled()` and `isAuthorized()` are respected, and `Halt` / `Cancel` are handled for you.

Actions go through the same gates as the CRUD tools: the token ability (`posts:publish`), the resource's `authorize()` callback, and the model policy. Authorization happens in two stages, mirroring the panel:

1. **`view` on the record.** In the panel you can only click a button on a row that was rendered for you, so being able to read the record is the precondition for every action.
2. **The action's own policy.** An action that declares no `->authorize()` falls back to the policy the panel would check for that action class, so a `DeleteAction` needs `delete` and a `ViewAction` needs `view`.

A custom action like `publish` matches no built-in class, so stage 2 is a no-op for it and `view` is all it requires.

> [!WARNING]
> That makes a custom action *less* protected than the CRUD tools sitting beside it in the same tool list. `update_post` needs the `update` policy; a `publish_post` that quietly does more than an update needs only `view`. This mirrors the panel, where such a button renders unless you say otherwise, but a human clicks one row at a time and an agent can work through every record it can read. Give any consequential custom action an explicit `->authorize()`.

You can also pass an input schema, the arguments are then available in the closure through `$arguments`. Only the keys you declare are passed through, so an agent cannot set an argument the tool never advertised:

```php
->mcp(
    description: 'Email the invoice to the customer.',
    schema: fn (JsonSchema $schema) => [
        'note' => $schema->string()->description('Optional note to include.'),
    ],
)
```

> [!IMPORTANT]
> Only closures whose parameters are limited to `$record`, `$data` and `$arguments` can run headlessly. An action requesting `$livewire`, `$table` or other schema utilities is skipped at registration (with a log entry telling you why), reach for a custom tool class instead.

### Actions that collect input

An action with a modal form works without any extra configuration. Its fields are transposed into the tool's arguments the same way a resource form is, validated against their own rules, and handed to the closure as `$data`:

```php
Action::make('reject')
    ->schema([
        Textarea::make('reason')->required()->maxLength(200),
        Toggle::make('notify')->default(true),
    ])
    ->action(fn (Post $record, array $data) => $record->reject($data['reason'], $data['notify']))
    ->mcp(),
```

The agent sees `reason` and `notify` alongside `id`, `reason` is required of it, and a field the agent leaves out arrives carrying its default — the same array the closure gets when a person submits the modal. Disabled and `dehydrated(false)` fields are not declared, because the panel does not submit those either.

`->mcp(schema: ...)` is a separate channel and still reaches the closure as `$arguments`. Use the modal form for input the panel already collects, and `schema:` for arguments that only make sense to an agent.

> [!WARNING]
> An action whose modal schema cannot be built headlessly — a `schema()` closure needing `$livewire`, or a field whose rules cannot be compiled without a live component — is **skipped at registration**, with a log entry naming the failure. It is not exposed with its form quietly dropped: a tool that asks for nothing, validates nothing and calls the closure with an empty `$data` is worse than no tool.

> [!NOTE]
> `requiresConfirmation()` has no effect over MCP — there is no one to confirm to. Treat the ability gating the action as the real control, and let the MCP client do its own approval prompting.

## Share the operation, not the closure

An action closure is a UI adapter, not the operation itself. Logic that lives inside one is reachable from exactly one place, which is what makes an action awkward to expose later. Put the work in its own class and the closure becomes a single line:

```php
// app/Operations/SyncInventory.php
class SyncInventory
{
    /**
     * @return array{synced: int, skipped: int}
     */
    public function __invoke(?CarbonInterface $since = null): array
    {
        // the actual work
    }
}
```

This is the action pattern, sometimes called invokable classes or interactors. It is named `Operations` here purely so `App\Actions` doesn't collide with `Filament\Actions` in your head or your imports; the directory name carries no meaning beyond that.

The Filament action now keeps only what is about the UI, and stays bridgeable:

```php
Action::make('syncInventory')
    ->requiresConfirmation()
    ->action(function (): void {
        $result = app(SyncInventory::class)();

        Notification::make()->title("Synced {$result['synced']} items")->success()->send();
    })
    ->mcp(description: 'Sync inventory from the supplier feed.'),
```

> [!IMPORTANT]
> Resolve the operation with `app()` rather than as a closure parameter. Filament happily injects `fn (SyncInventory $sync) => $sync()` from the container, but the headless safety check only accepts parameters named `$record`, `$data` and `$arguments`, so that form is skipped at registration and you get no tool.

Nothing is duplicated, and there is still no tool class to write. **Extract the operation, then pick the thinnest adapter that reaches it** — the bridge when the action can be bridged, a custom tool when it can't. What varies is the adapter, not the logic.

Reach for a [custom tool](#custom-tool-classes) when the bridge doesn't reach:

- page header actions, relation manager actions and bulk actions, none of which are discovered
- any action whose closure needs `$livewire`, `$table` or other page state
- anything that isn't scoped to a single record
- anything whose argument schema is substantial enough that declaring it inside `table()` obscures more than it saves

In each case the tool calls the same operation, so the two surfaces cannot drift apart. As a bonus the operation is testable without Livewire, queueable, and callable from a command.

## Expose by name

For actions you can't edit, on a vendor resource for example:

```php
McpResource::make(PostResource::class)->actions(['publish'])
```

Same headless execution as above, same closure safety constraint. This just lets you opt an existing action in without touching its resource class.

## Custom tool classes

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

- **Context** — `panel()`, `tenant()`, `user()`, `token()`.
- **Authorization** — `preAuthorizeResource()` and `authorizeResource()` run the *same* authorizer the generated CRUD tools run: authentication, token abilities, the resource's `authorize()` callback, and the model policy, in that order. Both return `null` when allowed or a denial reason to hand back to the agent.
- **Records** — `resolveRecordFor()` looks a record up through the resource's Eloquent query — tenant scoping and soft deletes — plus its `McpResource::query()` callback when the resource is registered on the current server.

When your tool touches a resource, use them in the order the generated tools do — pre-authorize, resolve, authorize with the record:

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

Keep the body thin, exactly as with a bridged action:

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

> [!WARNING]
> **Nothing authorizes a custom tool for you.** The CRUD and bridged-action tools run through the package's authorizer; a custom tool does not, so an unauthenticated caller, a token whose abilities exclude this operation, and a user your policies would refuse all reach `handle()` unless you stop them. When the tool acts on a resource, call `preAuthorizeResource()` / `authorizeResource()` as shown above. When it doesn't map to a resource at all — like this sync tool — check `$this->user()` and `$this->token()?->can('...')` yourself, then apply whatever policy fits:

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

A null token means the request carries no MCP token — a guest on a public server, or an app authenticating MCP itself. Treat a present token as the thing that narrows access rather than the thing that grants it, and check `$this->user()` separately for whether there is a caller at all.

### Keeping a custom tool away from guests

A custom tool is a plain `laravel/mcp` tool, so it is listed to anonymous callers on any server with a `public()` resource. Add the trait to hide it:

```php
use Guava\FilamentMcp\Concerns\RequiresAuthentication;

class SyncInventoryTool extends Tool
{
    use RequiresAuthentication;
}
```

This controls listing and dispatch, not authorization — keep the checks in `handle()` as well.
