---
title: Configuration
---

# Configuration

Publish the config file if you want to change any of the defaults:

```bash
php artisan vendor:publish --tag="filament-mcp-config"
```

```php
return [
    'tokens' => [
        'table' => 'mcp_tokens',
        'prefix' => 'gmcp_',
    ],

    // Staging area for out-of-band uploads, not where the field finally stores
    // the file. That comes from the FileUpload field itself.
    'uploads' => [
        'table' => 'mcp_uploads',
        'disk' => null,           // null uses the default disk
        'directory' => 'mcp-uploads',
        'expires_after' => 60,    // minutes a handle stays usable
        'max_size' => 12288,      // kilobytes
        'rate_limit' => 30,       // uploads per minute per IP, null to disable
        'extensions' => [],       // extra mime => extension pairs
    ],

    // Downloading a URL the agent supplied makes your server issue requests on
    // its behalf, so the SSRF guards are on by default.
    'remote_files' => [
        'enabled' => true,
        'allow_private_networks' => false,
        'allowed_hosts' => [],    // when set, an allowlist; supports "*"
        'timeout' => 10,
        'max_redirects' => 3,
        'max_size' => 12288,      // kilobytes
    ],

    'rate_limit' => 60,           // requests per minute per IP, before auth
    'token_rate_limit' => 60,     // requests per minute per token, after auth
    'local_panel' => env('FILAMENT_MCP_LOCAL_PANEL'),
    'tenant_header' => 'X-Tenant',
];
```

The options that decide what an agent can reach are covered in [security](../security/_index.md). The upload options are explained in [files](../files/_index.md).

## Rate limiting

Three limits apply, and a request has to pass every one that's relevant to it:

| Option | Keyed by | Runs | Default |
|---|---|---|---|
| `rate_limit` | client IP | before authentication | 60/min |
| `token_rate_limit` | access token | after authentication | 60/min |
| `uploads.rate_limit` | client IP | on the upload endpoint | 30/min |

Set any of them to `null` to disable that limit. See [request lifecycle](../security/05-request-lifecycle.md#rate-limiting) for why there are two request limits rather than one, and for the `TrustProxies` caveat.

## Adding your own middleware

Middleware attached to the **panel** does not run on MCP routes, since they never pass through a panel page. Add anything you need to the plugin instead:

```php
McpPlugin::make()->middleware([EnsureSubscribed::class])
```

It runs after authentication and after panel access has been checked. See [request lifecycle](../security/05-request-lifecycle.md) for the full middleware stack.
