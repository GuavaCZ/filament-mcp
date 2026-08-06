---
title: Registering the plugin
---

# Registering the plugin

Add the `McpPlugin` to every panel you want to turn into an MCP server:

```php
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\McpPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugins([
            McpPlugin::make()
                ->instructions('This server manages the Acme blog. Posts belong to categories.')
                ->resources([
                    McpResource::make(PostResource::class)
                        ->crud(),
                    McpResource::make(CategoryResource::class)
                        ->readOnly(),
                ])
                ->tokens(),
        ]);
}
```

That's already a working server, served at `/mcp/{panel-id}`.

- `->instructions()` is handed to the agent when it connects. Use it for the things a tool description can't carry, like how your models relate to each other.
- `->resources()` lists the resources you want to expose, each one wrapped in `McpResource`. See [exposing resources](../resources/01-exposing-resources.md).
- `->tokens()` registers a self-service page where users manage their own tokens. See [tokens](../security/02-tokens.md).

## Create a token

```bash
php artisan mcp:token you@example.com --name="Claude"
```

This prints the plain text token once, it's hashed in the database from then on. Keep it somewhere safe, you can't retrieve it again afterwards.

## Connect a client

Point any MCP client at the panel's endpoint, with the token as a bearer:

```bash
claude mcp add --transport http acme https://your-app.test/mcp/admin \
    --header "Authorization: Bearer gmcp_..."
```

That's it, the agent now sees `list_posts`, `get_post`, `create_post`, `update_post`, `delete_post`, `list_categories` and `get_category`.

Next up, [configuration](02-configuration.md).
