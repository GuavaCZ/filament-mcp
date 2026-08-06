---
title: Exposing resources
---

# Exposing resources

`McpResource` is a fluent value object, so everything below is chainable:

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

## Naming and descriptions

Tool names come from the model label, so a `PostResource` produces `list_posts` and `get_post`. Override both forms with `->name()` when the label reads badly as a tool name, or when two panels expose the same model under different words.

`->describe()` is the description the agent sees next to every tool for that resource. It's worth writing: an agent picking between `list_posts` and `list_articles` has only this to go on.

## Narrowing the data

`->query()` runs on every list, get, update and delete, so it's the one place that narrows all of them at once:

```php
->query(fn (Builder $query) => $query->where('published', true))
```

`->mutateDataUsing()` is where the resource page lifecycle hooks would have gone. Writes over MCP don't run `mutateFormDataBeforeCreate` and friends, so put anything those did here instead. It receives the validated data and the record, which is `null` on create.

`->recordsPerPage()` caps what a single `list_*` call can pull back. The agent may ask for less, never more.

> [!IMPORTANT]
> `->authorize()` **replaces** the policy check rather than adding to it. A callback that returns `true` means the model policy is never consulted for that operation. Use `->query()` when you want to narrow what's reachable while keeping your policies in play.
