---
title: Field types
---

# Field types

Each form component is transposed by a **field mapper**, which decides the JSON schema type the agent sees and, where the wire format differs from what the model stores, converts between them.

| Component | The agent sends | Stored as |
| --- | --- | --- |
| `TextInput` | string, or number when `numeric()` | as sent |
| `Textarea` | string | as sent |
| `Select` | string, enum of the options | as sent |
| `Select` (multiple, relationship) | array of related keys | synced onto the relation |
| `Toggle`, `Checkbox` | boolean | as sent |
| `DatePicker`, `DateTimePicker`, `TimePicker` | string, `date` / `date-time` / `time` | as sent |
| `RichEditor` | HTML string | HTML, or a TipTap document in JSON mode |
| `MarkdownEditor`, `CodeEditor` | string | as sent |
| `FileUpload` | upload handle or https URL | the stored path |
| `Repeater` | array of objects | a plain list |
| `Builder` | array of `{type, data}` | a plain list |
| `KeyValue` | object | object of strings |

Anything else falls back to a described string. See [custom field types](06-custom-field-types.md) to teach the package about one.

## Rich text

The agent always writes HTML, and always reads HTML back, whatever the field stores underneath. Incoming markup is parsed with the editor's own TipTap configuration and re-serialised, so tags the editor has no extension for (`<script>`, `<iframe>` and friends) do not survive the round trip.

JSON mode is detected from both `RichEditor::json()` and the model's rich content registration, so a `body` attribute registered with `registerRichContent('body')->json()` is stored as a document array without the agent knowing.

> [!NOTE]
> Filament's own `required` rule for a `RichEditor` only fails for an empty document array, so it never fires for an HTML string. This package enforces it separately, and an empty `<p></p>` is rejected for a required field.

## Files

See [files](../files/_index.md).

## Relationship selects

A `Select` that is both `multiple()` and bound to a `BelongsToMany` is written after the record is saved rather than filled onto it, the same way filament writes it in `saveRelationships()`. The agent sends the complete set of related keys and the relation is synced to exactly that list, so an update meaning to add one has to include the ones already there. Reads report the current keys under the same name for that reason.

Keys with no matching record are rejected before anything is written, rather than being left to fail as a foreign key violation. Other relationship types are not synced: a `HasMany` would have to null out or delete whatever it detached, which is not a decision the package should make for you.

A **singular** relationship `Select`, one bound to a `BelongsTo`, is an ordinary attribute: it writes a foreign key onto the record. Its key is checked the same way, through the related model's own query, so anything that model's global scopes exclude reads as missing. On a tenant panel that is the tenant scope, which makes another tenant's key unwritable. See [relationship keys](../security/03-authorization.md#relationship-keys) for what this does and does not cover.

## Translatable attributes

An attribute the model reports as translatable, whether through `spatie/laravel-translatable` or anything else exposing `isTranslatableAttribute()` and `getTranslations()`, is handled per locale on both sides. Reads return the whole `locale => value` map, with each locale's value passed through its mapper, so translatable rich text comes back as HTML per locale. Writes only touch the locale the application is currently running under, and leave every other translation intact.

That means a write goes wherever `app()->getLocale()` points, which over HTTP is whatever your locale middleware resolved. If agents need to reach a specific locale, give them a [custom tool](../actions/04-custom-tools.md) that names it explicitly rather than relying on the request's locale.

## Repeaters and builders

Nested fields are mapped exactly like top-level ones, so a `FileUpload` or `RichEditor` inside a repeater behaves identically. Each entry is validated against the sub-schema's own rules before being written, and keys the sub-schema does not declare are dropped rather than stored.

`Builder` blocks arrive as `{"type": "quote", "data": {...}}`. The available types are an enum in the schema, and each block's fields are named in the `data` description, because a single object schema cannot express a different shape per type.

Repeaters bound to a relationship are **not** writable, since those save through `saveRelationshipsUsing`, which the MCP write path does not run.
