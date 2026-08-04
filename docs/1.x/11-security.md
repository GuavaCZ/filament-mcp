---
title: Security
---

# Security

An MCP server is a different kind of caller than a browser. The request arrives with a bearer token instead of a session, it never passes through a panel page, and — most of it — the thing composing the request is a language model that reads untrusted text all day.

That last part is the one worth sitting with. An agent holding a legitimate token can be talked into using it. A prompt injection in an issue comment, a customer email or a PDF it was asked to summarise becomes an instruction, and the token does the rest. So the useful question is not *"do I trust this user"* but *"what can this token do if the agent behind it is following someone else's instructions"*.

## What applies to an MCP request

Every request goes through, in order:

1. **`throttle:filament-mcp`** — keyed by client IP, before authentication.
2. **`AuthenticateMcp`** — resolves the bearer token. It runs on every server, including public ones: `public()` means guests are welcome, not that credentials are ignored. A bearer token minted by this package that does not authenticate is a 401, even where guests would otherwise be served — a revoked token must not quietly keep working as an anonymous caller.
3. **`throttle:filament-mcp-token`** — keyed by the now-verified token.
4. **`ApplyMcpContext`** — sets the current panel, checks `canAccessPanel()`, checks email verification, resolves and checks the tenant.
5. Anything you added with `McpPlugin::make()->middleware([...])`.

Then, per tool call: token abilities, your policies, the resource's own `can*()` methods, and tenant scoping on every query.

### What does not apply

MCP routes are not panel pages, so **middleware you attached to the panel does not run on them**. That includes `authMiddleware()`, `middleware()` and anything Filament attaches per page — with one exception, below. If a check matters to you, add it explicitly:

```php
McpPlugin::make()->middleware([EnsureSubscribed::class])
```

The exception is email verification. Filament enforces it as a `verified` middleware on each page, which MCP would otherwise miss entirely, so this package checks it itself: a panel with `requiresEmailVerification()` refuses a `MustVerifyEmail` user who hasn't verified, with a 403.

Multi-factor authentication is **not** re-checked. An access token is its own credential, minted by a user who had already satisfied MFA; treating the token as a second factor forever is the deliberate reading here. If you disagree, revoke tokens when MFA changes.

## Tokens

Tokens are stored hashed, so a leaked database gives up no usable credential. Beyond that:

- **Scope them to a panel.** A token minted without `--panel` reaches every MCP-enabled panel the user can access. `panel_id` pins it to one.
- **Scope the abilities.** `*` is convenient and almost never what you want. `posts:update` is a sentence you can reason about; `*` is not.
- **Set an expiry**, and prune with `php artisan mcp:prune-tokens`.

Panel access is re-checked on every request, not frozen at mint time, so revoking a user's access to a panel takes effect immediately without touching their tokens.

## Authorization

The package calls the same authorization your panel does — which means if a resource has no policy, Filament's default is to **allow**. That is fine in a browser where the panel itself is behind a login you control. It is worth a second look when the caller is an agent.

Write policies for anything exposed over MCP, and use the panel's `strictAuthorization()` if you'd rather a missing policy be a loud error than a silent yes.

### Actions

A bridged action runs through the same gates as the CRUD tools, plus its own modal form: the fields are declared to the agent, validated against their own rules, and passed to the closure as `$data`. An action whose modal schema cannot be built headlessly is not exposed at all — see [actions](05-actions.md#actions-that-collect-input).

`requiresConfirmation()` is a UI affordance and does not survive the trip. An action that is dangerous enough to want a confirmation dialog wants an explicit `->authorize()` and its own token ability more.

When you write a custom tool, authorize the **record**, not the resource:

```php
// Checks "can this user update posts in general".
ArticleResource::can('update');

// Checks "can this user update *this* post".
ArticleResource::can('update', $record);
```

The first passes for a record the user must never touch, as long as they may update something.

### Listing versus reading

`list_*` checks `viewAny`, and `get_*` checks `view` on the record it was asked for. That is the panel's own split — a table renders on `viewAny` and the row actions gate per record — but the two callers are not alike. A person scrolls a table; an agent pages through every record it can reach and keeps the lot.

So if `view` is where your per-record rule lives, put it in the query as well:

```php
McpResource::make(DocumentResource::class)
    ->query(fn (Builder $query) => $query->whereBelongsTo(auth()->user(), 'owner'))
```

A policy that only narrows `view` still lets `list_*` return the rows.

### Relationship keys

A relationship `Select` is a closed list in the panel, and closed lists do not survive the trip to an agent: over MCP the value is a bare key in a JSON argument. Both shapes are therefore checked against the related model's own query before anything is written — the multiple `BelongsToMany` case and the singular `BelongsTo` case alike. Global scopes apply to that query, so on a tenant panel a key belonging to another tenant reads as missing and the write is refused.

What this does **not** replicate is the narrowing a `modifyQueryUsing` closure applies to the option list:

```php
Select::make('author_id')
    ->relationship('author', 'name', fn (Builder $query) => $query->where('is_active', true))
```

The tenant scope holds, but `is_active` does not — an agent may name an inactive author where the panel would not have offered one. Put anything load-bearing in a global scope or a policy rather than in the option query.

## Uploads

The upload path is the part of this package with the most attacker-reachable surface, so it is worth knowing what it does.

**The extension of a stored file always comes from its sniffed type.** Never from the filename, because on this path the filename is a tool argument the agent chose. A type the package has no name for is stored as `.bin` rather than under a name it asked for.

This matters more than it sounds. A file called `photo.php` containing a valid BMP header followed by PHP code sniffs as `image/bmp`, satisfies an `->image()` field's accepted types, and — stored under the name it came with, on a public disk, behind a stock nginx config that hands anything ending in `.php` to FPM — is remote code execution. Accepted-types checks do not prevent this; only the extension does.

If your app handles a format the package does not name, add it:

```php
// config/filament-mcp.php
'uploads' => [
    'extensions' => ['application/x-sqlite3' => 'sqlite'],
],
```

**`preserveFilenames()` keeps the stem, not the extension.** The name is reduced to `[A-Za-z0-9_-]`, which also removes inner dots — `report.php.jpg` becomes `report-php.png` if the bytes are a PNG. Inner dots matter because Apache maps *every* extension in a name to a handler, so a name ending in something harmless can still execute.

**Mime types are sniffed from the bytes**, never read from a `Content-Type` header or a filename, at both the staging endpoint and the field write.

**Sizes are capped while reading**, not after. A body is rejected on its declared `Content-Length` before a byte is read, and the read itself stops the moment it passes the limit.

**The staging endpoint is authorised by URL signature** — bound to one upload id, expiring with it, single use, and claimable only by the user who requested it. It carries no token, so an agent can pass it to `curl` without leaking a credential into a subprocess. It is throttled by IP, which is all there is to key on when there is no token.

### SVG

`->image()` accepts `image/*`, and that includes `image/svg+xml`. An SVG is a document: it can carry script, and it runs when someone opens the stored file directly. On a public disk that is a same-origin XSS primitive.

So the package does not put SVG in its default extension map: bytes that sniff as `image/svg+xml` pass an `->image()` field's validation, but are stored as `.bin`, which no browser renders as a document. If your app genuinely needs stored SVGs — logo uploads are the classic case — opt back in, knowingly:

```php
// config/filament-mcp.php
'uploads' => [
    'extensions' => ['image/svg+xml' => 'svg'],
],
```

If the field writes to a public disk, prefer naming the types you actually want instead:

```php
FileUpload::make('image')
    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
```

The accepted-types concern applies to Filament generally, not just to MCP — but an agent is a much more willing uploader than a person.

### Why there is no file path parameter

See [files](10-files.md#why-not-a-file-path). Briefly: an agent can only ever send bytes it already had. A path parameter would make your server read on its behalf, which turns a prompt injection into a local file read with a public sink.

## Fetching URLs

A file field also accepts an https URL for the server to download, which is server-side request forgery by construction. It is guarded by default: private, loopback, link-local and reserved addresses are refused, every redirect hop is re-checked, and the connection is pinned to the address that was checked so DNS cannot change under it. See [files](10-files.md#fetching-a-url).

Set `remote_files.enabled` to `false` if you would rather require uploads.

## Rate limiting

Two limits, and a request passes both:

| Config | Keyed by | Runs |
|---|---|---|
| `rate_limit` | client IP | before authentication |
| `token_rate_limit` | access token | after authentication |
| `uploads.rate_limit` | client IP | on the upload endpoint |

The IP limit cannot key on the token, because before authentication a bearer is unverified and free to vary — a fresh random one per request would land in a fresh bucket forever. The token limit is the specific one, and the one worth tightening.

Consider raising the IP limit if your agents call from shared infrastructure. A whole platform behind one address collapses into a single bucket, and every tenant there gets throttled by the noisiest.

> [!IMPORTANT]
> Behind a proxy or load balancer, `rate_limit` is only as good as your `TrustProxies` configuration. Without it every request appears to come from the proxy and the entire world shares one bucket.

Set any of them to `null` to disable that limit.

## Field visibility

The fields a record is serialised with default to the resource's form fields, and a field the panel would not render is not serialised — so `->visible(fn ($user) => $user->isAdmin())` keeps that column out of `get_*` and `list_*` for everyone else. A field that is merely `->disabled()` stays readable, because disabled means "you may not write this", not "you may not see it".

One limit worth knowing: visibility is evaluated without a record, so a closure that branches on the *record* (`->visible(fn ($record) => $record->owner_id === auth()->id())`) is not a read control. Use `hiddenFields()`, `fields()` or `query()` for anything that depends on which row it is.

```php
McpResource::make(UserResource::class)
    ->hiddenFields(['two_factor_secret'])
```

A field kept out of the output is also kept out of `list_*`'s `sort` and `search`. Neither prints a value, and both report on one anyway — an ordering is a comparison against every other row, and `search` matched against a hidden column would answer "does it contain this?" once per character. Sorting by such a field is refused, and it is dropped from the searched set even when the resource names it in `getGloballySearchableAttributes()`: `hiddenFields()` is a deny-list, so it wins there too.

This is evaluated per caller, so an admin still sorts and searches by a field only admins may read.

## Tenancy

Filament wires tenant scoping per model while iterating the panel's own `->resources([])`. A resource exposed only over MCP is not in that list, so this package applies the same wiring itself and then verifies it took, raising an exception rather than serving unscoped records if it ever cannot.

Nothing is required of you. The one thing to know: `Resource::scopeToTenant(false)` is still the opt-out, and it means the same thing here as in the panel — that resource's records are readable across every tenant, deliberately.

## Public servers

`McpResource::make(...)->public()` exposes a resource to unauthenticated callers. There is no user, so there are no policies and no ownership checks — only tenant scoping, if the panel has tenancy. Treat a public resource as world-readable, because it is, and keep `readOnly()` on it unless you have a specific reason not to.

## Reporting a vulnerability

Please don't open a public issue. Email **lukas.frey@guava.cz** and we'll get back to you.
