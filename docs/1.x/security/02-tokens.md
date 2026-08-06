---
title: Self-service tokens
---

# Self-service tokens

Users can create and revoke their own tokens from inside the panel. The token manager is a single Livewire component that you can mount in two places, together or separately.

**As a standalone page**, which registers an **MCP tokens** page in the navigation:

```php
McpPlugin::make()->tokens()
```

**On the profile page**, which appends a section to whatever page the panel's `->profile()` points at, including your own `EditProfile` subclass:

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

## Restricting who can mint tokens

An MCP token carries the full authority its owner has in the panel, so gate it. `authorizeTokens()` applies to every mount, including the Livewire component itself:

```php
McpPlugin::make()
    ->tokens()
    ->tokensOnProfilePage()
    ->authorizeTokens(fn (User $user): bool => $user->can('manage-mcp-tokens'))
```

Without a callback, any authenticated panel user may manage their own tokens.

> [!NOTE]
> `tokens()` and `tokensOnProfilePage()` also accept a `bool | Closure`, but those decide whether the mount exists at all. Use `authorizeTokens()` for per-user checks.

## Customising the page

```php
McpPlugin::make()
    ->tokens()
    ->tokensNavigationGroup('Settings')
    ->tokensNavigationSort(99)
    ->tokensPage(YourOwnPage::class)   // extend Guava\FilamentMcp\Filament\Pages\ManageMcpTokens
```

Tokens created through the panel are scoped to the panel they were created in, and each panel's manager lists only its own tokens plus any panel-less ones, meaning those created with `mcp:token` without `--panel`.

Every string comes from `filament-mcp::tokens`, publishable with:

```bash
php artisan vendor:publish --tag="filament-mcp-translations"
```
