---
title: Bridging actions
---

# Bridging actions

The recommended way, mark an action in your resource's `table()` with `->mcp()`:

```php
Action::make('publish')
    ->action(fn (Post $record) => $record->publish())
    ->mcp(description: 'Publish the post.'),
```

This becomes a `publish_post` tool, executed **headlessly** through the real action closure. `record()`, `isHidden()`, `isDisabled()` and `isAuthorized()` are respected, and `Halt` / `Cancel` are handled for you.

## Authorization

Actions go through the same gates as the CRUD tools: the token ability (`posts:publish`), the resource's `authorize()` callback, and the model policy. The policy side happens in two stages, mirroring the panel:

1. **`view` on the record.** In the panel you can only click a button on a row that was rendered for you, so being able to read the record is the precondition for every action.
2. **The action's own policy.** An action that declares no `->authorize()` falls back to the policy the panel would check for that action class, so a `DeleteAction` needs `delete` and a `ViewAction` needs `view`.

A custom action like `publish` matches no built-in class, so stage 2 is a no-op for it and `view` is all it requires.

> [!WARNING]
> That makes a custom action *less* protected than the CRUD tools sitting beside it in the same tool list. `update_post` needs the `update` policy; a `publish_post` that quietly does more than an update needs only `view`. This mirrors the panel, where such a button renders unless you say otherwise, but a human clicks one row at a time and an agent can work through every record it can read. Give any consequential custom action an explicit `->authorize()`.

## Extra arguments

You can pass an input schema, and the arguments are then available in the closure through `$arguments`. Only the keys you declare are passed through, so an agent cannot set an argument the tool never advertised:

```php
->mcp(
    description: 'Email the invoice to the customer.',
    schema: fn (JsonSchema $schema) => [
        'note' => $schema->string()->description('Optional note to include.'),
    ],
)
```

## Closures that can't be bridged

> [!IMPORTANT]
> Only closures whose parameters are limited to `$record`, `$data` and `$arguments` can run headlessly. An action requesting `$livewire`, `$table` or other schema utilities is skipped at registration, with a log entry telling you why. Reach for a [custom tool](04-custom-tools.md) instead.

## Exposing an action by name

For actions you can't edit, on a vendor resource for example:

```php
McpResource::make(PostResource::class)->actions(['publish'])
```

Same headless execution, same closure safety constraint. This just lets you opt an existing action in without touching its resource class.

Next up, [actions with input](02-actions-with-input.md).
