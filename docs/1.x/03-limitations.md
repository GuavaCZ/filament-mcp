---
title: Limitations
---

# Limitations

The things this package deliberately does not do, in one place. Each one links to the chapter that explains why.

## Actions

- Action discovery covers the resource's **table** only: row actions, table header actions, toolbar actions, empty state actions and column actions. Bulk actions aren't discovered, since they act on a record set while an action tool takes a single `id`, and neither are page header actions or relation manager actions. Extract the work into an operation and expose it as a custom tool, see [sharing the operation](actions/03-sharing-operations.md).
- Only closures whose parameters are limited to `$record`, `$data` and `$arguments` can run headlessly. Anything asking for `$livewire`, `$table` or other page state is skipped at registration, see [bridging actions](actions/01-bridging-actions.md).
- `requiresConfirmation()` does nothing over MCP, and an action whose modal schema cannot be built headlessly is skipped rather than exposed without its form, see [actions with input](actions/02-actions-with-input.md).

## Writes

- Writes don't run resource page lifecycle hooks (`mutateFormDataBeforeCreate` and friends), use `McpResource::mutateDataUsing()` instead, see [exposing resources](resources/01-exposing-resources.md).
- Repeaters and builders bound to a **relationship** aren't writable, they save through `saveRelationshipsUsing` which the write path doesn't run.
- Relation tools are read only. A `BelongsToMany` exposed on the form as a multiple relationship `Select` *is* writable, see [relations](resources/05-relations.md).
- A relationship `Select`'s `modifyQueryUsing` narrowing is not replayed when its keys are checked. Global scopes are, see [relationship keys](security/03-authorization.md#relationship-keys).
- Translatable attributes are written one locale at a time, whichever the application is running under, see [field types](resources/03-field-types.md#translatable-attributes).

## Fields and files

- Unknown field types are treated as strings. Teach the package about one with `fieldMapper()`, see [custom field types](resources/06-custom-field-types.md).
- Image cropping and resizing aren't applied to uploads, those are client-side only in filament, see [stored files](files/03-stored-files.md).

## Authentication

- OAuth 2.1 is not supported yet, `McpPlugin::make()->oauth()` throws. Use [MCP tokens](security/01-authentication.md) in the meantime.
