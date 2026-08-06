---
title: Sharing the operation
---

# Sharing the operation

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

This is the action pattern, sometimes called invokable classes or interactors. It's named `Operations` here purely so `App\Actions` doesn't collide with `Filament\Actions` in your head or your imports; the directory name carries no meaning beyond that.

The filament action now keeps only what is about the UI, and stays bridgeable:

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

Nothing is duplicated, and there is still no tool class to write. **Extract the operation, then pick the thinnest adapter that reaches it:** the bridge when the action can be bridged, a custom tool when it can't. What varies is the adapter, not the logic.

## When to reach for a custom tool

Use a [custom tool](04-custom-tools.md) when the bridge doesn't reach:

- page header actions, relation manager actions and bulk actions, none of which are discovered
- any action whose closure needs `$livewire`, `$table` or other page state
- anything that isn't scoped to a single record
- anything whose argument schema is substantial enough that declaring it inside `table()` obscures more than it saves

In each case the tool calls the same operation, so the two surfaces cannot drift apart. As a bonus the operation is testable without Livewire, queueable, and callable from a command.
