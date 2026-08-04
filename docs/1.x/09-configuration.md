---
title: Configuration and limitations
---

# Configuration

See [installation](02-installation.md#config-file) for how to publish the config file, and what each option does. The options that decide what an agent can reach are covered in [security](11-security.md).

## Rate limiting

Two limits apply to every MCP request, and a request has to pass both:

| Option | Keyed by | Runs | Default |
|---|---|---|---|
| `rate_limit` | client IP | before authentication | 60/min |
| `token_rate_limit` | access token | after authentication | 60/min |
| `uploads.rate_limit` | client IP | on the upload endpoint | 30/min |

Set any of them to `null` to disable that limit. See [security](11-security.md#rate-limiting) for why there are two, and for the `TrustProxies` caveat.

## Serving locally over stdio

If you'd rather run the server locally instead of over HTTP:

```php
McpPlugin::make()->local()
```

This registers the server for `php artisan mcp:start {panel-id}` and the MCP Inspector through `php artisan mcp:inspector {panel-id}`. If you have more than one local panel, set `FILAMENT_MCP_LOCAL_PANEL` to pick which one `mcp:start` uses by default.

## Limitations

- Action discovery covers the resource's **table** only: row actions, table header actions, toolbar actions, empty state actions and column actions. Bulk actions aren't discovered (they act on a record set, while an action tool takes a single `id`), and neither are page header actions or relation manager actions. Extract the work into an operation and expose it as a custom tool, see [actions](05-actions.md#share-the-operation-not-the-closure).
- Writes don't run resource page lifecycle hooks (`mutateFormDataBeforeCreate` and friends), use `McpResource::mutateDataUsing()` instead.
- Repeaters and builders bound to a **relationship** aren't writable, they save through `saveRelationshipsUsing` which the write path doesn't run.
- Image cropping and resizing aren't applied to uploads, those are client-side only in Filament. See [files](10-files.md).
- Unknown field types are treated as strings. Teach the package about one with `fieldMapper()`, see [resources](04-resources.md#custom-field-types).
- Relation tools are read only. A `BelongsToMany` exposed on the form as a multiple relationship `Select` *is* writable, see [resources](04-resources.md#relationship-selects).
- A relationship `Select`'s `modifyQueryUsing` narrowing is not replayed when its keys are checked — global scopes are, see [security](11-security.md#relationship-keys).
- `requiresConfirmation()` on a bridged action does nothing over MCP, and an action whose modal schema cannot be built headlessly is skipped, see [actions](05-actions.md#actions-that-collect-input).
- Translatable attributes are written one locale at a time, whichever the application is running under. See [resources](04-resources.md#translatable-attributes).
