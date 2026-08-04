---
title: Usage
---

# Usage

## Register the plugin

Add the `McpPlugin` to every panel you want to turn into an MCP server:

```php
use Guava\FilamentMcp\Enums\McpOperation;
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

That's already a working server. `->resources()` is where you list the resources you want to expose, each one wrapped in `McpResource` (more on configuring these in [resources](04-resources.md)). `->tokens()` registers a self-service page where users manage their own tokens, see [authentication](06-authentication.md).

The server is served at `/mcp/{panel-id}`, one server per panel.

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
