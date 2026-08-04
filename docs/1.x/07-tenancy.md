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

If your client can't send custom headers, put the tenant in the URL instead:

```php
McpPlugin::make()->tenantInPath()   // serves mcp/{panel}/{tenant}
```
