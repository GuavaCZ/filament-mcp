---
title: Introduction
---

# Introduction

Filament MCP turns your filament panel into a [Model Context Protocol](https://modelcontextprotocol.io) server. AI agents can list, read, create and update records, and call your Filament actions, through the same authorization a human gets in the panel.

It's built on top of the official [laravel/mcp](https://github.com/laravel/mcp) package, so anything you already know about writing `laravel/mcp` tools and prompts carries over.

## Features

- **Named, typed tools per resource**: `list_posts`, `get_post`, `create_post`, `update_post`, `delete_post`, generated from your existing Filament resources. Input schemas come straight from your **form schema**: required fields, select options as enums, booleans, date formats.
- **Real Filament actions as tools**: mark any table action with `->mcp()` and it becomes a callable tool (`publish_post`), executed headlessly through the actual action closure. No re-declaring your business logic somewhere else.
- **Layered security**: hashed access tokens with per-resource abilities, your model policies, filament's tenant scoping and field visibility rules all apply, in that order.
- **Public or private, per resource**: expose some resources without authentication while others require a token. Generated tools for a private resource stay invisible to guests, they won't even show up in the tool list. (Custom tool classes are yours to gate — see [custom tools](05-actions.md#custom-tool-classes).)
- **Multi-tenancy**: tenant-scoped panels work out of the box, from a header or the URL.
- **Self-service tokens**: an optional panel page where your users create and revoke their own MCP tokens.
- **Custom tools and prompts**: attach plain `laravel/mcp` tools and prompts to the server for anything beyond CRUD.

## How it fits together

Each panel gets its own MCP server, registered through the `McpPlugin`. You tell it which resources to expose with `McpResource`, and it takes care of turning your form schema into tool arguments, running validation, applying authorization and returning the result.

Actions are bridged the same way: mark an existing `Action` with `->mcp()` and it becomes a tool, executed through the real action closure instead of a hand written copy of it.

Next up, [install the package](02-installation.md).
