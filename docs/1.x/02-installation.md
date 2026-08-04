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

Publish and run the migrations, they create the `mcp_tokens` table used for authentication and the `mcp_uploads` table used to stage [file uploads](10-files.md):

```bash
php artisan vendor:publish --tag="filament-mcp-migrations"
php artisan migrate
```

## Config file

Publishing the config file is optional, you only need it if you want to change one of the defaults:

```bash
php artisan vendor:publish --tag="filament-mcp-config"
```

```php
return [
    'tokens' => [
        'table' => 'mcp_tokens',
        'prefix' => 'gmcp_',
    ],

    // Staging area for out-of-band uploads. See the files chapter.
    'uploads' => [
        'table' => 'mcp_uploads',
        'disk' => null, // null uses the default disk
        'directory' => 'mcp-uploads',
        'expires_after' => 60, // minutes a handle stays usable
        'max_size' => 12288, // kilobytes
    ],

    // Guards for downloading a URL the agent supplied.
    'remote_files' => [
        'enabled' => true,
        'allow_private_networks' => false,
        'allowed_hosts' => [], // when set, an allowlist; supports "*"
        'timeout' => 10,
        'max_redirects' => 3,
        'max_size' => 12288, // kilobytes
    ],

    'rate_limit' => 60, // requests per minute per IP, null to disable
    'local_panel' => env('FILAMENT_MCP_LOCAL_PANEL'),
    'tenant_header' => 'X-Tenant',
];
```

Next up, [register the plugin on your panel](03-usage.md).
