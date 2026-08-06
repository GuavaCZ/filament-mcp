---
title: Writing records
---

# Writing records

Writes go through your resource's form. Only fields present in it are writable, and only if they're not `disabled()` or `dehydrated(false)`. Their validation rules are enforced exactly like in the panel.

Updates are partial. Only the attributes the agent actually sends get changed, so an agent can read a record, change one field and send that field back on its own.

## Dehydration runs

A field's own `dehydrateStateUsing()` and `mutateDehydratedStateUsing()` callbacks run before the value is stored, so a field like this hashes through MCP just as it does in the panel:

```php
TextInput::make('password')
    ->password()
    ->dehydrateStateUsing(fn (string $state) => Hash::make($state)),
```

If such a callback cannot run headlessly the write is rejected rather than storing the raw value.

## Lifecycle hooks don't run

Writes don't go through a resource page, so `mutateFormDataBeforeCreate()`, `mutateFormDataBeforeSave()` and the other page hooks never fire. Move anything they did into `mutateDataUsing()` on the resource:

```php
McpResource::make(PostResource::class)
    ->mutateDataUsing(fn (array $data, ?Model $record) => [
        ...$data,
        'slug' => Str::slug($data['title'] ?? ''),
    ])
```

`$record` is `null` on create, so the same callback can serve both operations.

> [!WARNING]
> A hook that enforced something rather than merely filling something in is a gap until you move it. A `mutateFormDataBeforeCreate()` that pinned `team_id` to the current user's team stops running the moment the resource is exposed over MCP.
