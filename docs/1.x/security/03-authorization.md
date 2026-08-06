---
title: Authorization
---

# Authorization

Model policies are mapped per operation, the same as filament enforces in the panel:

| Operation | Policy ability |
|---|---|
| `List` | `viewAny` |
| `Get` | `view` |
| `Create` | `create` |
| `Update` | `update` |
| `Delete` | `delete` |

A resource without a policy is allowed, just like it is in the panel. That's fine in a browser, where the panel itself is behind a login you control. It's worth a second look when the caller is an agent.

Write policies for anything exposed over MCP, and use the panel's `strictAuthorization()` if you'd rather a missing policy be a loud error than a silent yes.

> [!IMPORTANT]
> `McpResource::authorize()` **replaces** the policy check rather than adding to it. When the callback is set, the model policy is never consulted for that operation. Use `->query()` when you want to narrow what's reachable while keeping your policies in play.

## Actions

A bridged action always requires `view` on the record, and then the policy the panel would check for that action class, so a `DeleteAction` needs `delete`. A custom action matches no class and so requires only `view`. See the warning in [bridging actions](../actions/01-bridging-actions.md).

`requiresConfirmation()` is a UI affordance and does not survive the trip. An action that is dangerous enough to want a confirmation dialog wants an explicit `->authorize()` and its own token ability more.

When you write a custom tool, authorize the **record**, not the resource:

```php
// Checks "can this user update posts in general".
ArticleResource::can('update');

// Checks "can this user update *this* post".
ArticleResource::can('update', $record);
```

The first passes for a record the user must never touch, as long as they may update something.

## Listing versus reading

`list_*` checks `viewAny`, and `get_*` checks `view` on the record it was asked for. That's the panel's own split, where a table renders on `viewAny` and the row actions gate per record, but the two callers are not alike. A person scrolls a table; an agent pages through every record it can reach and keeps the lot.

So if `view` is where your per-record rule lives, put it in the query as well:

```php
McpResource::make(DocumentResource::class)
    ->query(fn (Builder $query) => $query->whereBelongsTo(auth()->user(), 'owner'))
```

A policy that only narrows `view` still lets `list_*` return the rows.

## Relationship keys

A relationship `Select` is a closed list in the panel, and closed lists do not survive the trip to an agent: over MCP the value is a bare key in a JSON argument. Both shapes are therefore checked against the related model's own query before anything is written, the multiple `BelongsToMany` case and the singular `BelongsTo` case alike. Global scopes apply to that query, so on a tenant panel a key belonging to another tenant reads as missing and the write is refused.

What this does **not** replicate is the narrowing a `modifyQueryUsing` closure applies to the option list:

```php
Select::make('author_id')
    ->relationship('author', 'name', fn (Builder $query) => $query->where('is_active', true))
```

The tenant scope holds, but `is_active` does not, so an agent may name an inactive author where the panel would not have offered one. Put anything load-bearing in a global scope or a policy rather than in the option query.
