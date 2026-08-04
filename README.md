# Filament MCP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/guava/filament-mcp.svg?style=flat-square)](https://packagist.org/packages/guava/filament-mcp)
[![Total Downloads](https://img.shields.io/packagist/dt/guava/filament-mcp.svg?style=flat-square)](https://packagist.org/packages/guava/filament-mcp)

Give your Filament panels MCP capabilities. This package turns your panel into a [Model Context Protocol](https://modelcontextprotocol.io) server, so AI agents (Claude Code, Claude Desktop, Cursor, ...) can list, read, create and update records and call your Filament actions — with the same authorization a human has in the panel.

Built on top of the official [laravel/mcp](https://github.com/laravel/mcp) package.

## Features

- **Named, typed tools per resource** — `list_posts`, `get_post`, `create_post`, `update_post`, `delete_post`, generated from your existing Filament resources. Input schemas are derived from your **form schema**: required fields, select options as enums, booleans, date formats.
- **Real Filament Actions as tools** — mark any table action with `->mcp()` and it becomes a callable tool (`publish_post`), executed headlessly through the actual action closure. No re-declaring your business logic.
- **The whole form, not just scalars** — rich text (HTML in, HTML out, sanitised through the editor's own TipTap config), markdown, repeaters, builders, key-value and file uploads. Nested fields behave exactly like top-level ones.
- **Real file uploads** — agents get a signed upload URL and pass back a handle, so image bytes never enter the model's context window. Remote URLs can be fetched instead, with SSRF, size and mime-sniffing guards on by default. Stored extensions come from the sniffed bytes, and script-capable types — SVG included — land as inert `.bin` unless you explicitly map them.
- **Layered security** — hashed access tokens with per-resource abilities, your model policies, Filament's tenant scoping, and field visibility rules all apply.
- **Public or private, per resource** — expose some resources without authentication while others require a token; private tools are invisible to guests.
- **Multi-tenancy** — tenant-scoped panels work out of the box; queries and record creation are scoped to the tenant from the `X-Tenant` header (or URL).
- **Self-service tokens** — users create and revoke their own tokens from a panel page, a section on the profile page, or both. Creating one hands back a ready-to-paste `claude mcp add` command and `mcpServers` JSON.
- **Custom tools & prompts** — attach plain `laravel/mcp` tools and prompts to the server for anything beyond CRUD.

## Requirements

- PHP 8.2+
- Laravel 12 / 13
- Filament 5

## Installation

```bash
composer require guava/filament-mcp

php artisan vendor:publish --tag="filament-mcp-migrations"

php artisan migrate
```

## Quick start

Register the plugin on your panel:

```php
use Guava\FilamentMcp\Enums\McpOperation;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\McpPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugins([
            McpPlugin::make()
                ->instructions('This server manages the Acme blog. Posts belong to categories.')
                ->resources([
                    McpResource::make(PostResource::class)
                        ->crud(),
                    McpResource::make(CategoryResource::class)
                        ->readOnly(),
                ])
                ->tokens(),
        ]);
}
```

Create a token:

```bash
php artisan mcp:token you@example.com --name="Claude"
```

Connect an MCP client to `https://your-app.test/mcp/{panel-id}`:

```bash
claude mcp add --transport http acme https://your-app.test/mcp/admin \
    --header "Authorization: Bearer gmcp_..."
```

That's it — the agent now sees `list_posts`, `get_post`, `create_post`, `update_post`, `delete_post`, `list_categories` and `get_category`.

## Configuring resources

`McpResource` is a fluent value object:

```php
McpResource::make(PostResource::class)
    // Which operations are exposed (default: List + Get)
    ->operations(McpOperation::List, McpOperation::Get, McpOperation::Create)
    ->readOnly()                       // sugar for List + Get
    ->crud()                           // all five operations
    ->except(McpOperation::Delete)     // everything but ...

    // Naming (default: derived from the model label)
    ->name('article', 'articles')      // get_article, list_articles, ...
    ->describe('Blog articles. Slugs are generated automatically.')

    // Field visibility (applies to reads AND writes)
    ->fields(['title', 'content'])     // allow-list
    ->hiddenFields(['cost_price'])     // deny-list (wins)

    // Data & querying
    ->query(fn (Builder $query) => $query->where('published', true))
    ->mutateDataUsing(fn (array $data, ?Model $record) => [...$data, 'slug' => Str::slug($data['title'] ?? '')])
    ->recordsPerPage(25)

    // Related records: exposes list_post_comments
    ->relations(['comments'])

    // Authorization override (replaces the policy check entirely)
    ->authorize(fn (McpOperation $operation, ?Model $record, ?Authenticatable $user) => $user->is_admin),
```

Notes:

- **Writes go through the form.** Only fields present in your resource's form (and not `disabled()` / `dehydrated(false)`) are writable, and their validation rules are enforced. Updates are partial: only the provided attributes change.
- **Reads are limited too.** Output contains the primary key, the form's field names and timestamps — filtered by `fields()` / `hiddenFields()` and your model's `$hidden`.
- **Field mappers bridge the wire format.** Rich text is read and written as HTML whatever the field stores, files arrive as an upload handle or an https URL, and a translatable attribute is read as a map of every locale but written one locale at a time. Unknown field types are treated as strings; teach the package about one with `->fieldMapper()`.

## Authentication & authorization

Every request passes through these layers, in order:

1. **Token** — a valid, non-expired `gmcp_...` bearer token (hashed at rest, panel-scopable).
2. **Panel access** — the user's `canAccessPanel()`, exactly as Filament checks it at login. A token is not a way into a panel its owner cannot enter.
3. **Token abilities** — `['*']` by default; restrict with e.g. `['posts:list', 'posts:get', 'categories:*']`. Action tools use the action name: `posts:publish`.
4. **Model policies** — mapped per operation (`List → viewAny`, `Get → view`, `Create → create`, `Update → update`, `Delete → delete`), consistent with what Filament enforces in the panel. Resources without a policy are allowed, exactly like in the panel. Action tools map to the policy the panel would check for that action class.
5. **Tenant scoping** — see below.
6. **Field visibility** — see above.

Tokens can be created via artisan:

```bash
php artisan mcp:token user@example.com --name="CI agent" --panel=admin --abilities="posts:*" --expires=30
php artisan mcp:prune-tokens --unused-for=90
```

Schedule both prune commands — expired tokens and abandoned staged uploads accumulate indefinitely otherwise:

```php
// routes/console.php
Schedule::daily()->command('mcp:prune-tokens --unused-for=90');
Schedule::daily()->command('mcp:prune-uploads');
```

Or self-service, mountable as a page, as a profile page section, or both:

```php
McpPlugin::make()
    ->tokens()                 // an "MCP tokens" page in the navigation
    ->tokensOnProfilePage()    // a section on the panel's profile page
    ->authorizeTokens(fn (User $user): bool => $user->can('manage-mcp-tokens'))
```

Creating a token shows it exactly once, together with a ready-to-paste `claude mcp add` command and the `mcpServers` JSON for other clients. See [authentication](docs/1.x/06-authentication.md#self-service-tokens).

### Public resources

```php
McpPlugin::make()
    ->public()                          // whole server needs no auth
    // or per resource:
    ->resources([
        McpResource::make(DocsResource::class)->readOnly()->public(),
    ]),
```

On an otherwise private server, public resources are served to guests — all other tools are completely invisible (and uncallable) without a token. Guests only ever read: writes and actions on a public resource still require authentication, so a policy always has a user to be consulted about.

### OAuth 2.1 (optional)

If your app uses [Laravel Passport](https://laravel.com/docs/passport), enable the OAuth flow supported by `laravel/mcp`:

```php
McpPlugin::make()->oauth()
```

This publishes the OAuth discovery routes (`Mcp::oauthRoutes()`) so MCP clients can register dynamically and send users through a browser login. Requests authenticated by your Passport guard (`filament-mcp.oauth_guard`, default `api`) are accepted alongside package tokens. To send guests to your panel's login page, point your unauthenticated redirect at `filament.{panel}.auth.login` — and make sure Passport's authorize endpoint uses the same guard as the panel.

## Exposing Filament Actions

Three ways, use whichever fits:

**1. Mark the real action** (recommended):

```php
// in PostResource::table()
Action::make('publish')
    ->action(fn (Post $record) => $record->publish())
    ->mcp(description: 'Publish the post.'),
```

The action becomes a `publish_post` tool. It is executed **headlessly** through the real closure — `record()`, `isHidden()`, `isDisabled()` and `isAuthorized()` are respected, `Halt`/`Cancel` are handled. Authorization requires `view` on the record plus whatever policy the panel would check for that action class; a custom action matches none, so give consequential ones an explicit `->authorize()`.

Only closures whose parameters are limited to `$record`, `$data` and `$arguments` can run headlessly. Actions requesting `$livewire`, `$table`, schema utilities, etc. are skipped at registration (with a log entry) — use a custom tool class for those.

Keep the work itself in an operation class rather than the closure, so the same code backs the button and the tool, and page or bulk actions that can't be bridged can reuse it. See [sharing the operation](docs/1.x/05-actions.md#share-the-operation-not-the-closure).

An action with a **modal form** works as-is: its fields are transposed into the tool's arguments, validated against their own rules, and handed to the closure as `$data` — the same array a submitted modal produces, defaults included. An action whose modal schema cannot be built headlessly is skipped rather than exposed with its form silently dropped. `requiresConfirmation()` has no effect over MCP.

You can also pass an input schema; those arguments are available via `$arguments`:

```php
->mcp(
    description: 'Email the invoice to the customer.',
    schema: fn (JsonSchema $schema) => [
        'note' => $schema->string()->description('Optional note to include.'),
    ],
)
```

**2. Expose by name** — for actions you cannot edit (e.g. vendor resources):

```php
McpResource::make(PostResource::class)->actions(['publish'])
```

**3. Custom tool classes** — full control, plain `laravel/mcp`:

```php
use Guava\FilamentMcp\Concerns\InteractsWithFilamentContext;
use Laravel\Mcp\Server\Tool;

class SummarizeOrdersTool extends Tool
{
    use InteractsWithFilamentContext; // panel(), tenant(), user(), token(), resolveRecordFor()

    // ...
}
```

```php
McpPlugin::make()->tools([SummarizeOrdersTool::class])->prompts([WeeklyReportPrompt::class])
```

Marked actions are only picked up when `->discoverActions()` is enabled on the plugin.

## Multi-tenancy

Tenant-scoped panels work automatically: the middleware resolves the tenant, verifies `canAccessTenant()`, and sets it on Filament — so tenant query scoping and tenant association on create behave exactly like in the panel.

Clients pass the tenant (slug or key) in a header:

```json
{ "headers": { "Authorization": "Bearer gmcp_...", "X-Tenant": "acme" } }
```

For clients that cannot send custom headers, put it in the URL instead:

```php
McpPlugin::make()->tenantInPath()   // serves mcp/{panel}/{tenant}
```

## Serving locally (stdio)

```php
McpPlugin::make()->local()
```

registers the server for `php artisan mcp:start {panel-id}` and the MCP Inspector (`php artisan mcp:inspector {panel-id}`). With multiple panels, set `FILAMENT_MCP_LOCAL_PANEL`.

## Testing your server

The package ships a small test client:

```php
use Guava\FilamentMcp\Testing\TestsMcp;

uses(TestsMcp::class);

it('lists posts', function () {
    $data = $this->mcp('admin')
        ->asToken($plainTextToken)
        ->callJson('list_posts', ['search' => 'hello']);

    expect($data['pagination']['total'])->toBe(1);
});
```

`$this->mcp('admin')` speaks real JSON-RPC over HTTP against the registered route — `initialize()`, `listTools()`, `toolNames()`, `call()`, `callJson()`, `withHeader()` are available.

## Configuration

```bash
php artisan vendor:publish --tag="filament-mcp-config"
```

```php
return [
    'tokens' => [
        'table' => 'mcp_tokens',
        'prefix' => 'gmcp_',
    ],

    // Staging area for out-of-band uploads, not where the field finally
    // stores the file — that comes from the FileUpload field itself.
    'uploads' => [
        'table' => 'mcp_uploads',
        'disk' => null,                              // null uses the default disk
        'directory' => 'mcp-uploads',
        'expires_after' => 60,                       // minutes a handle stays usable
        'max_size' => 12288,                         // kilobytes
        'rate_limit' => 30,                          // uploads/minute per IP, null to disable
        'extensions' => [],                          // extra mime => extension pairs (svg is deliberately absent)
    ],

    // Downloading a URL the agent supplied makes your server issue requests
    // on its behalf, so the SSRF guards are on by default.
    'remote_files' => [
        'enabled' => true,
        'allow_private_networks' => false,
        'allowed_hosts' => [],                       // when set, an allowlist; supports "*"
        'timeout' => 10,
        'max_redirects' => 3,
        'max_size' => 12288,                         // kilobytes
    ],

    'rate_limit' => 60,                              // requests/minute per IP, before auth
    'token_rate_limit' => 60,                        // requests/minute per token, after auth
    'local_panel' => env('FILAMENT_MCP_LOCAL_PANEL'),
    'oauth_guard' => 'api',
    'tenant_header' => 'X-Tenant',
];
```

## Limitations

- Table action discovery covers **table actions** (row, toolbar and bulk-declared flat actions); page header actions are not discovered — use a custom tool.
- Writes do not run resource page lifecycle hooks (`mutateFormDataBeforeCreate` etc.) — use `McpResource::mutateDataUsing()` instead.
- Repeaters and builders bound to a relationship are not writable — they save through `saveRelationshipsUsing`, which the write path does not run.
- Relation tools (`->relations([...])`) are read-only. A `BelongsToMany` exposed on the form as a multiple relationship `Select` **is** writable, and is synced to exactly the set of keys the agent sends.
- Relationship `Select` keys are checked against the related model's own query, so global scopes (tenancy above all) apply — but a `modifyQueryUsing` closure narrowing the option list is not replayed. See [security](docs/1.x/11-security.md#relationship-keys).
- Translatable attributes are read in full but written one locale at a time — whichever the application is running under.

## Credits

- [Lukas Frey](https://github.com/GuavaCZ)
- Built on [laravel/mcp](https://github.com/laravel/mcp)

## License

MIT. See [LICENSE.md](LICENSE.md).
