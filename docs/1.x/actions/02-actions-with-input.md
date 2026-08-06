---
title: Actions with input
---

# Actions with input

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

The agent sees `reason` and `notify` alongside `id`, `reason` is required of it, and a field the agent leaves out arrives carrying its default. It's the same array the closure gets when a person submits the modal. Disabled and `dehydrated(false)` fields are not declared, because the panel does not submit those either.

## Two channels

`->mcp(schema: ...)` is a separate channel and still reaches the closure as `$arguments`. Use the modal form for input the panel already collects, and `schema:` for arguments that only make sense to an agent.

## When a modal can't be built

> [!WARNING]
> An action whose modal schema cannot be built headlessly, whether a `schema()` closure needing `$livewire` or a field whose rules cannot be compiled without a live component, is **skipped at registration**, with a log entry naming the failure. It is not exposed with its form quietly dropped: a tool that asks for nothing, validates nothing and calls the closure with an empty `$data` is worse than no tool.

## Confirmation

> [!NOTE]
> `requiresConfirmation()` has no effect over MCP, since there is no one to confirm to. Treat the ability gating the action as the real control, and let the MCP client do its own approval prompting.
