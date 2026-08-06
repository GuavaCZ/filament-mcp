---
title: Relations
---

# Relations

`->relations(['comments'])` exposes a read only tool for the relation, `list_post_comments` in this example:

```php
McpResource::make(PostResource::class)
    ->crud()
    ->relations(['comments'])
```

The related model has to be an MCP resource on the same panel. That's what supplies the field visibility, read transformations and policy the related records are served through, so reading them here requires exactly what `list_comments` requires, and the relation is scoped to that resource's own query.

A relation whose model is not exposed produces no tool at all, so a public parent can never become a way to read a private table.

## Writing through a relation

Relation tools can't write yet. The one relationship an agent can change is a `BelongsToMany` exposed on the form as a multiple relationship `Select`, which is synced to exactly the set of keys it sends. See [relationship selects](03-field-types.md#relationship-selects).
