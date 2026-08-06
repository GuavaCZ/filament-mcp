---
title: Installation
---

# Installation

## Requirements

- PHP 8.2 or higher
- Laravel 12 or 13
- Filament 5.x

## Installing the package

Install it via composer:

```bash
composer require guava/filament-mcp
```

Then publish and run the migrations. They create the `mcp_tokens` table used for authentication and the `mcp_uploads` table used to stage [file uploads](files/01-uploads.md):

```bash
php artisan vendor:publish --tag="filament-mcp-migrations"
php artisan migrate
```

## Config file

Publishing the config file is optional, you only need it if you want to change one of the defaults:

```bash
php artisan vendor:publish --tag="filament-mcp-config"
```

Every option is documented in the file itself, and the ones worth knowing about are covered in [configuration](server/02-configuration.md).

## Translations

The strings used by the token management page are publishable too:

```bash
php artisan vendor:publish --tag="filament-mcp-translations"
```

Next up, [register the plugin on your panel](server/01-registering-the-plugin.md).
