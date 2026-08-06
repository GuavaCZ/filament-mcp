---
title: Public resources
---

# Public resources

```php
McpPlugin::make()
    ->public()                          // whole server needs no auth
    // or per resource:
    ->resources([
        McpResource::make(DocsResource::class)->readOnly()->public(),
    ]),
```

On an otherwise private server, public resources are served to guests, and every other tool stays completely invisible and uncallable without a token.

Guests only ever get to read. `list` and `get` are served anonymously, while `create`, `update`, `delete` and action tools on a public resource still require authentication, because with no user there is nobody for a policy to be consulted about. Pair `public()` with `readOnly()` to make that explicit in the tool list.

> [!WARNING]
> There is no user, so there are no policies and no ownership checks, only tenant scoping if the panel has tenancy. Treat a public resource as world-readable, because it is.

## Custom tools are not covered

A [custom tool](../actions/04-custom-tools.md) is a plain `laravel/mcp` tool, so it's listed to anonymous callers on any server with a public resource. Add `RequiresAuthentication` to hide it, and keep the checks in `handle()` regardless.
