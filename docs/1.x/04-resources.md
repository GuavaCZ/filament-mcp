---
title: Configuring resources
---

# Configuring resources

`McpResource` wraps a Filament resource and configures how it's exposed. It's a fluent value object, so everything below is chainable:

```php
McpResource::make(PostResource::class)
    // Which operations are exposed (default: List + Get)
    ->operations(McpOperation::List, McpOperation::Get, McpOperation::Create)
    ->readOnly()                       // shorthand for List + Get
    ->crud()                           // all five operations
    ->except(McpOperation::Delete)     // everything but ...

    // Naming (default: derived from the model label)
    ->name('article', 'articles')      // get_article, list_articles, ...
    ->describe('Blog articles. Slugs are generated automatically.')

    // Field visibility (applies to reads and writes)
    ->fields(['title', 'content'])     // allow-list
    ->hiddenFields(['cost_price'])     // deny-list, wins over the allow-list

    // Data and querying
    ->query(fn (Builder $query) => $query->where('published', true))
    ->mutateDataUsing(fn (array $data, ?Model $record) => [...$data, 'slug' => Str::slug($data['title'] ?? '')])
    ->recordsPerPage(25)

    // Related records, exposes list_post_comments
    ->relations(['comments'])

    // Authorization override, replaces the policy check entirely
    ->authorize(fn (McpOperation $operation, ?Model $record, ?Authenticatable $user) => $user->is_admin);
```

## Writes go through the form

Only fields present in your resource's form are writable, and only if they're not `disabled()` or `dehydrated(false)`. Their validation rules are enforced exactly like in the panel. Updates are partial, only the attributes the agent actually sends get changed.

A field's own `dehydrateStateUsing()` and `mutateDehydratedStateUsing()` callbacks run before the value is stored, so a field like this hashes through MCP just as it does in the panel:

```php
TextInput::make('password')
    ->password()
    ->dehydrateStateUsing(fn (string $state) => Hash::make($state)),
```

If such a callback cannot run headlessly the write is rejected rather than storing the raw value.

## Field types

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

Anything else falls back to a described string.

### Rich text

The agent always writes HTML, and always reads HTML back, whatever the field stores underneath. Incoming markup is parsed with the editor's own TipTap configuration and re-serialised, so tags the editor has no extension for — `<script>`, `<iframe>` and friends — do not survive the round trip.

JSON mode is detected from both `RichEditor::json()` and the model's rich content registration, so a `body` attribute registered with `registerRichContent('body')->json()` is stored as a document array without the agent knowing.

> [!NOTE]
> Filament's own `required` rule for a `RichEditor` only fails for an empty document array, so it never fires for an HTML string. This package enforces it separately — an empty `<p></p>` is rejected for a required field.

### Files

See [files and uploads](10-files.md).

### Relationship selects

A `Select` that is both `multiple()` and bound to a `BelongsToMany` is written after the record is saved rather than filled onto it, the same way Filament writes it in `saveRelationships()`. The agent sends the complete set of related keys and the relation is synced to exactly that list, so an update that means to add one has to include the ones already there — reads report the current keys under the same name for that reason.

Keys with no matching record are rejected before anything is written, rather than being left to fail as a foreign key violation. Other relationship types are not synced: a `HasMany` would have to null out or delete whatever it detached, which is not a decision the package should make for you.

A **singular** relationship `Select` — one bound to a `BelongsTo` — is an ordinary attribute: it writes a foreign key onto the record. Its key is checked the same way, through the related model's own query, so anything that model's global scopes exclude reads as missing. On a tenant panel that is the tenant scope, which makes another tenant's key unwritable. See [security](11-security.md#relationship-keys) for what this does and does not cover.

### Translatable attributes

An attribute the model reports as translatable — `spatie/laravel-translatable`, or anything else exposing `isTranslatableAttribute()` and `getTranslations()` — is handled per locale on both sides. Reads return the whole `locale => value` map, with each locale's value passed through its mapper, so translatable rich text comes back as HTML per locale. Writes only touch the locale the application is currently running under, and leave every other translation intact.

That means a write goes wherever `app()->getLocale()` points, which over HTTP is whatever your locale middleware resolved. If agents need to reach a specific locale, give them a custom tool that names it explicitly rather than relying on the request's locale.

### Repeaters and builders

Nested fields are mapped exactly like top-level ones, so a `FileUpload` or `RichEditor` inside a repeater behaves identically. Each entry is validated against the sub-schema's own rules before being written, and keys the sub-schema does not declare are dropped rather than stored.

`Builder` blocks arrive as `{"type": "quote", "data": {...}}`. The available types are an enum in the schema; each block's fields are named in the `data` description, because a single object schema cannot express a different shape per type.

Repeaters bound to a relationship are **not** writable — those save through `saveRelationshipsUsing`, which the MCP write path does not run.

## Reads are limited too

The output contains the primary key, the form's field names and timestamps, filtered by `fields()` / `hiddenFields()` and your model's `$hidden`. Fields the panel would not render are left out too, so a `->visible(fn ($user) => $user->isAdmin())` field is not serialised for anyone else — but that check runs without a record, so record-dependent visibility is not a read control. See [security](11-security.md#field-visibility).

## Listing, searching and sorting

`list_*` takes `page`, `per_page`, `search`, `sort` and `direction`. `per_page` is capped at `recordsPerPage()`.

`search` matches against the resource's `getGloballySearchableAttributes()`. When a resource declares none, it falls back to the form's plain text fields — `TextInput` and `Textarea` on attributes your model does not cast. Json, boolean, date and file columns are deliberately left out: matching them with `LIKE` means nothing on MySQL and is an error on Postgres. Declare `getGloballySearchableAttributes()` when you want something more specific.

Both `search` and `sort` are narrowed to the fields the caller may read, so neither reports on a value that `get_*` would have withheld. See [security](11-security.md#field-visibility).

`sort` accepts the primary key, any non-nested form field and the timestamp columns; anything else is refused with the list of what is allowed.

## Custom field types

Teach the package about a component it doesn't know, or replace a built-in mapper:

```php
McpPlugin::make()->fieldMapper(ColorPicker::class, ColorPickerMapper::class)
```

The registration belongs to that panel, so two panels can map the same component differently. To map one everywhere, register it on the `FieldMapperRegistry` singleton directly.

A mapper implements `FieldMapper` and, optionally:

- `TransformsWrites` — convert an incoming value before it reaches the model
- `TransformsReads` — convert a stored value on the way back out
- `ProvidesValidationRules` — replace the field's own rules when they describe Livewire's state shape rather than what an agent can send

Mappers are resolved by walking up the component's class hierarchy, so registering a base class covers its subclasses.

## Relations

`->relations(['comments'])` exposes a read only tool for the relation, `list_post_comments` in this example. Relation tools can't write yet, see [limitations](09-configuration.md#limitations).

The related model has to be an MCP resource on the same panel. That is what supplies the field visibility, read transformations and policy the related records are served through — reading them here requires exactly what `list_comments` requires, and the relation is scoped to that resource's own query. A relation whose model is not exposed produces no tool at all, so a public parent can never become a way to read a private table.
