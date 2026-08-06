---
title: Request lifecycle
---

# Request lifecycle

Every request goes through, in order:

1. **`throttle:filament-mcp`**, keyed by client IP, before authentication.
2. **`AuthenticateMcp`**, which resolves the bearer token. It runs on every server, including public ones: `public()` means guests are welcome, not that credentials are ignored. A bearer token minted by this package that does not authenticate is a 401, even where guests would otherwise be served, because a revoked token must not quietly keep working as an anonymous caller.
3. **`throttle:filament-mcp-token`**, keyed by the now-verified token.
4. **`ApplyMcpContext`**, which sets the current panel, checks `canAccessPanel()`, checks email verification, then resolves and checks the tenant.
5. Anything you added with `McpPlugin::make()->middleware([...])`.

Then, per tool call: token abilities, your policies, the resource's own `can*()` methods, and tenant scoping on every query.

## What does not apply

MCP routes are not panel pages, so **middleware you attached to the panel does not run on them**. That includes `authMiddleware()`, `middleware()` and anything filament attaches per page, with one exception below. If a check matters to you, add it explicitly:

```php
McpPlugin::make()->middleware([EnsureSubscribed::class])
```

The exception is email verification. Filament enforces it as a `verified` middleware on each page, which MCP would otherwise miss entirely, so this package checks it itself: a panel with `requiresEmailVerification()` refuses a `MustVerifyEmail` user who hasn't verified, with a 403.

## Rate limiting

Two limits apply to every request, and it has to pass both:

| Config | Keyed by | Runs |
|---|---|---|
| `rate_limit` | client IP | before authentication |
| `token_rate_limit` | access token | after authentication |
| `uploads.rate_limit` | client IP | on the upload endpoint |

The IP limit cannot key on the token, because before authentication a bearer is unverified and free to vary. A fresh random one per request would land in a fresh bucket forever. The token limit is the specific one, and the one worth tightening.

Consider raising the IP limit if your agents call from shared infrastructure. A whole platform behind one address collapses into a single bucket, and every tenant there gets throttled by the noisiest.

> [!IMPORTANT]
> Behind a proxy or load balancer, `rate_limit` is only as good as your `TrustProxies` configuration. Without it every request appears to come from the proxy and the entire world shares one bucket.

Set any of them to `null` to disable that limit.
