---
title: Serving locally
---

# Serving locally

If you'd rather run the server locally over stdio instead of over HTTP:

```php
McpPlugin::make()->local()
```

This registers the server for two artisan commands:

```bash
php artisan mcp:start {panel-id}
php artisan mcp:inspector {panel-id}
```

`mcp:start` speaks stdio, which is what a desktop client launches directly. `mcp:inspector` opens the MCP Inspector against the same server, which is the quickest way to see the tool list your configuration actually produces.

If you have more than one local panel, set `FILAMENT_MCP_LOCAL_PANEL` to pick which one the commands use when you don't name a panel:

```dotenv
FILAMENT_MCP_LOCAL_PANEL=admin
```

> [!NOTE]
> A local server still authenticates. Running over stdio changes the transport, not the authorization layers, so a tool that needs a token needs one here too.
