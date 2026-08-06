---
title: Authentication
---

# Authentication

Every request passes through these layers, in order:

1. **Token**, a valid, non-expired `gmcp_...` bearer token, hashed at rest and scopable to a panel.
2. **Panel access**, the user's `canAccessPanel()`, exactly as filament checks it when they log in. A token is not a way into a panel its owner cannot enter, and access revoked in the panel takes effect immediately rather than when the token expires.
3. **Token abilities**, `['*']` by default. Restrict with something like `['posts:list', 'posts:get', 'categories:*']`. Action tools use the action name, `posts:publish`.
4. **Model policies**, see [authorization](03-authorization.md).
5. **Tenant scoping**, see [multi-tenancy](../server/03-multi-tenancy.md).
6. **Field visibility**, see [field visibility](07-field-visibility.md).

If your user model does not implement `FilamentUser`, step 2 only passes in a local environment, the same rule filament applies to the panel itself.

A panel with `requiresEmailVerification()` is honoured too: an unverified `MustVerifyEmail` user is refused with a 403. Filament enforces that as a middleware on each panel page, and MCP routes are not panel pages, so it's checked here explicitly.

## Creating tokens

```bash
php artisan mcp:token user@example.com --name="CI agent" --panel=admin --abilities="posts:*" --expires=30
```

The user is looked up on the model behind the panel's own auth guard, so a panel authenticating something other than your default `User`, an admin panel backed by an `Admin` model for instance, needs nothing beyond `--panel`. Without it the default guard is used, and `--guard=admin` overrides both.

Prune old, unused tokens on a schedule, since nothing deletes them for you:

```php
// routes/console.php
Schedule::daily()->command('mcp:prune-tokens --unused-for=90');
```

If your server accepts [file uploads](../files/01-uploads.md), schedule `mcp:prune-uploads` alongside it.

## Hardening

Tokens are stored hashed, so a leaked database gives up no usable credential. Beyond that:

- **Scope them to a panel.** A token minted without `--panel` reaches every MCP-enabled panel the user can access. `panel_id` pins it to one.
- **Scope the abilities.** `*` is convenient and almost never what you want. `posts:update` is a sentence you can reason about; `*` is not.
- **Set an expiry**, and prune with `php artisan mcp:prune-tokens`.

Panel access is re-checked on every request rather than frozen at mint time, so revoking a user's access to a panel takes effect immediately without touching their tokens.

> [!NOTE]
> Multi-factor authentication is **not** re-checked. An access token is its own credential, minted by a user who had already satisfied MFA, and treating the token as a second factor forever is the deliberate reading here. If you disagree, revoke tokens when MFA changes.

## Authenticating MCP requests yourself

If you authenticate MCP requests through your own middleware, authenticating the user is only half the job. You also have to say what that request may do. Abilities scope a credential, and a request that arrives with no credential this package understands is refused rather than granted everything:

```php
use Guava\FilamentMcp\Contracts\GrantsAbilities;
use Guava\FilamentMcp\Support\CurrentMcpGrant;

CurrentMcpGrant::set(new class implements GrantsAbilities
{
    public function can(string $ability): bool
    {
        return in_array($ability, ['posts:list', 'posts:get']);
    }
});
```

Abilities are `resource:operation` strings, the same ones a token carries and the same ones the token page lists.

## OAuth 2.1

Not supported yet, `McpPlugin::make()->oauth()` throws.

An earlier implementation authenticated through a Passport guard but never consulted the token's scopes, so every OAuth caller ran with full abilities no matter how narrowly their token was scoped. It was also untested, since Passport is not a dependency of this package. Rather than ship an authentication path with a silent hole in it, it has been removed until it can be built against real scopes and covered by tests.

Use MCP tokens in the meantime.
