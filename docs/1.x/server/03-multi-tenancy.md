---
title: Multi-tenancy
---

# Multi-tenancy

Tenant-scoped panels work automatically. The middleware resolves the tenant, verifies `canAccessTenant()`, and sets it on filament, so query scoping and tenant association on create behave exactly like they do in the panel.

An authenticated user whose model does not implement `HasTenants` is refused outright rather than waved through, since such a user cannot express membership of anything.

Clients pass the tenant, either its slug or key, in a header:

```json
{ "headers": { "Authorization": "Bearer gmcp_...", "X-Tenant": "acme" } }
```

The header name is configurable through `filament-mcp.tenant_header`.

If your client can't send custom headers, put the tenant in the URL instead:

```php
McpPlugin::make()->tenantInPath()   // serves mcp/{panel}/{tenant}
```

## How the scoping is applied

Filament wires tenant scoping per model while iterating the panel's own `->resources([])`. A resource exposed only over MCP is not in that list, so this package applies the same wiring itself and then verifies it took, raising an exception rather than serving unscoped records if it ever cannot.

Nothing is required of you. The one thing to know is that `Resource::scopeToTenant(false)` is still the opt-out, and it means the same thing here as in the panel: that resource's records are readable across every tenant, deliberately.
