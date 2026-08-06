---
title: Introduction
---

# Introduction

Filament MCP turns your filament panel into a [Model Context Protocol](https://modelcontextprotocol.io) server. AI agents can list, read, create and update records, and call your filament actions, through the same authorization a human gets in the panel.

It's built on top of the official [laravel/mcp](https://github.com/laravel/mcp) package, so anything you already know about writing `laravel/mcp` tools and prompts carries over.

Each panel becomes its own MCP server, registered through the `McpPlugin`:

```php
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\McpPlugin;

$panel->plugin(
    McpPlugin::make()
        ->resources([
            McpResource::make(PostResource::class)->crud(),
        ])
);
```

That is enough to give an agent `list_posts`, `get_post`, `create_post`, `update_post` and `delete_post`.

## How it fits together

You tell the plugin which resources to expose with `McpResource`, and it turns your **form schema** into tool arguments: required fields, select options as enums, booleans, date formats. The same schema decides what may be written, so validation rules and field visibility carry over without being redeclared.

Actions are bridged the same way. Mark an existing `Action` with `->mcp()` and it becomes a tool, executed headlessly through the real action closure instead of a hand written copy of it.

Everything an agent does passes through the layers the panel already has: your model policies, filament's tenant scoping and its field visibility rules, with hashed access tokens and per-resource abilities on top.

## Version compatibility

| Filament version | Plugin version | Min. PHP version |
|------------------|:--------------:|:----------------:|
| 5.x              |      1.x       |       8.2        |

Next up, [install the package](02-installation.md).
