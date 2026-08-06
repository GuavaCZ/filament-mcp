<!-- Add .github/banner.png, then uncomment:
![filament-mcp Banner](https://github.com/GuavaCZ/filament-mcp/raw/main/.github/banner.png)
-->

# Give your Filament panels MCP capabilities.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/guava/filament-mcp.svg?style=flat-square)](https://packagist.org/packages/guava/filament-mcp)
[![Total Downloads](https://img.shields.io/packagist/dt/guava/filament-mcp.svg?style=flat-square)](https://packagist.org/packages/guava/filament-mcp)

This package turns your filament panel into a [Model Context Protocol](https://modelcontextprotocol.io) server, so AI agents (Claude Code, Claude Desktop, Cursor, ...) can list, read, create and update records and call your filament actions, with the same authorization a human has in the panel.

Tools are generated from your existing resources: the input schemas come straight from your form schema, and your model policies, tenant scoping and field visibility rules all still apply. It's built on top of the official [laravel/mcp](https://github.com/laravel/mcp) package.

## Documentation

The full documentation is available at [guava.cz](https://guava.cz/developers/packages/filament-mcp?ref=github&utm_campaign=filament-mcp).

## Version compatibility

| Filament version | Plugin version | Min. PHP version |
| ---------------- |:--------------:|:----------------:|
| 5.x              |      1.x       |       8.2        |

## Support us

Your support is key to the continual advancement of our plugin. We appreciate every user who has contributed to our journey so far.

While our plugin is available for all to use, if you are utilizing it for commercial purposes and believe it adds significant value to your business, we kindly ask you to consider supporting us through GitHub Sponsors. This sponsorship will assist us in continuous development and maintenance to keep our plugin robust and up-to-date. Any amount you contribute will greatly help towards reaching our goals. Join us in making this plugin even better and driving further innovation.

## Installation

You can install the package via composer:

```bash
composer require guava/filament-mcp
```

Then publish and run the migrations, which create the tables used for authentication and for staging file uploads:

```bash
php artisan vendor:publish --tag="filament-mcp-migrations"
php artisan migrate
```

Publishing the config file is optional, see the [installation docs](https://guava.cz/developers/packages/filament-mcp/1.x/installation?ref=github&utm_campaign=filament-mcp) for the remaining setup steps.

## Usage

Register the plugin on your panel and list the resources you want to expose:

```php
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\McpPlugin;

$panel->plugin(
    McpPlugin::make()
        ->instructions('This server manages the Acme blog. Posts belong to categories.')
        ->resources([
            McpResource::make(PostResource::class)->crud(),
            McpResource::make(CategoryResource::class)->readOnly(),
        ])
        ->tokens()
);
```

Create a token:

```bash
php artisan mcp:token you@example.com --name="Claude"
```

And point an MCP client at the panel's endpoint:

```bash
claude mcp add --transport http acme https://your-app.test/mcp/admin \
    --header "Authorization: Bearer gmcp_..."
```

That's it, the agent now sees `list_posts`, `get_post`, `create_post`, `update_post`, `delete_post`, `list_categories` and `get_category`.

For everything else, including field types and file uploads, exposing your filament actions as tools, self-service tokens, multi-tenancy and the security model, see the full documentation at [guava.cz](https://guava.cz/developers/packages/filament-mcp?ref=github&utm_campaign=filament-mcp).

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](.github/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Lukas Frey](https://github.com/GuavaCZ)
- [All Contributors](../../contributors)
- [laravel/mcp](https://github.com/laravel/mcp) - the MCP server this package builds on
- Spatie - Our package skeleton is a modified version of [Spatie's Package Skeleton](https://github.com/spatie/package-skeleton-laravel)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
