---
title: Authentication and authorization
---

# Authentication and authorization

Every request passes through these layers, in order:

1. **Token**, a valid, non-expired `gmcp_...` bearer token, hashed at rest and scopable to a panel.
2. **Panel access**, the user's `canAccessPanel()`, exactly as filament checks it when they log in. A token is not a way into a panel its owner cannot enter, and access revoked in the panel takes effect immediately rather than when the token expires.
3. **Token abilities**, `['*']` by default. Restrict with something like `['posts:list', 'posts:get', 'categories:*']`. Action tools use the action name, `posts:publish`.
4. **Model policies**, mapped per operation (`List → viewAny`, `Get → view`, `Create → create`, `Update → update`, `Delete → delete`), the same as filament enforces in the panel. A resource without a policy is allowed, just like it is in the panel. Action tools always require `view` on the record and then the policy the panel would check for that action class, so a `DeleteAction` needs `delete`. A custom action matches no class and so requires only `view` — see the warning in [actions](05-actions.md#mark-the-real-action).
5. **Tenant scoping**, see [multi-tenancy](07-tenancy.md).
6. **Field visibility**, see [resources](04-resources.md).

If your user model does not implement `FilamentUser`, step 2 only passes in a local environment — the same rule filament applies to the panel itself.

A panel with `requiresEmailVerification()` is honoured too: an unverified `MustVerifyEmail` user is refused with a 403. Filament enforces that as a middleware on each panel page, and MCP routes are not panel pages, so it is checked here explicitly.

## Adding your own middleware

Middleware attached to the **panel** does not run on MCP routes — they never pass through a panel page. Add anything you need to the plugin instead:

```php
McpPlugin::make()->middleware([EnsureSubscribed::class])
```

It runs after authentication and after panel access has been checked. See [security](11-security.md#what-applies-to-an-mcp-request) for the full middleware stack.

## Creating tokens

```bash
php artisan mcp:token user@example.com --name="CI agent" --panel=admin --abilities="posts:*" --expires=30
```

The user is looked up on the model behind the panel's own auth guard, so a panel
authenticating something other than your default `User` — an admin panel backed
by an `Admin` model, say — needs nothing beyond `--panel`. Without it the default
guard is used, and `--guard=admin` overrides both.

Prune old, unused tokens on a schedule — nothing deletes them for you:

```php
// routes/console.php
Schedule::daily()->command('mcp:prune-tokens --unused-for=90');
```

If your server accepts [file uploads](10-files.md), schedule `mcp:prune-uploads` alongside it.

## Self-service tokens

Users can create and revoke their own tokens from inside the panel. The token manager is a single Livewire component that you can mount in two places, together or separately.

**As a standalone page** — registers an **MCP tokens** page in the navigation:

```php
McpPlugin::make()->tokens()
```

**On the profile page** — appends a section to whatever page the panel's `->profile()` points at, including your own `EditProfile` subclass:

```php
McpPlugin::make()->tokensOnProfilePage()
```

If you build your profile page's schema yourself, place the section exactly where you want it instead:

```php
use Guava\FilamentMcp\Filament\Schemas\Components\McpTokensSection;

public function content(Schema $schema): Schema
{
    return $schema->components([
        $this->getFormContentComponent(),
        ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
        McpTokensSection::make(),
    ]);
}
```

Whichever mount is used, creating a token opens a one-time modal with the plain text token, a ready-to-paste `claude mcp add` command and the equivalent `mcpServers` JSON for Claude Desktop, Cursor and friends. The token is hashed at rest and never shown again.

### Restricting who can mint tokens

An MCP token carries the full authority its owner has in the panel, so gate it. `authorizeTokens()` applies to every mount, including the Livewire component itself:

```php
McpPlugin::make()
    ->tokens()
    ->tokensOnProfilePage()
    ->authorizeTokens(fn (User $user): bool => $user->can('manage-mcp-tokens'))
```

Without a callback, any authenticated panel user may manage their own tokens. Note that `tokens()` and `tokensOnProfilePage()` also accept a `bool | Closure`, but those decide whether the mount exists at all — use `authorizeTokens()` for per-user checks.

### Customising the page

```php
McpPlugin::make()
    ->tokens()
    ->tokensNavigationGroup('Settings')
    ->tokensNavigationSort(99)
    ->tokensPage(YourOwnPage::class)   // extend Guava\FilamentMcp\Filament\Pages\ManageMcpTokens
```

Tokens created through the panel are scoped to the panel they were created in, and each panel's manager lists only its own tokens plus any panel-less ones (those created with `mcp:token` without `--panel`).

Every string comes from `filament-mcp::tokens`, publishable with:

```bash
php artisan vendor:publish --tag="filament-mcp-translations"
```

## Public resources

```php
McpPlugin::make()
    ->public()                          // whole server needs no auth
    // or per resource:
    ->resources([
        McpResource::make(DocsResource::class)->readOnly()->public(),
    ]),
```

On an otherwise private server, public resources are served to guests, every other tool stays completely invisible (and uncallable) without a token.

Guests only ever get to read. `list` and `get` are served anonymously; `create`, `update`, `delete` and action tools on a public resource still require authentication, because with no user there is nobody for a policy to be consulted about. Pair `public()` with `readOnly()` to make that explicit in the tool list.

## Authenticating MCP requests yourself

If you authenticate MCP requests through your own middleware, authenticating the user is only half the job — you also have to say what that request may do. Abilities scope a credential, and a request that arrives with no credential this package understands is refused rather than granted everything:

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

Abilities are `resource:operation` strings — the same ones a token carries, and the same ones the token page lists.

## OAuth 2.1

Not supported yet; `McpPlugin::make()->oauth()` throws.

An earlier implementation authenticated through a Passport guard but never consulted the token's scopes, so every OAuth caller ran with full abilities no matter how narrowly their token was scoped. It was also untested — Passport is not a dependency of this package. Rather than ship an authentication path with a silent hole in it, it has been removed until it can be built against real scopes and covered by tests.

Use [MCP tokens](#tokens) in the meantime.
