<?php

namespace Workbench\App\Providers;

use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\McpPlugin;
use Workbench\App\Filament\Resources\CategoryResource;
use Workbench\App\Filament\Resources\PostResource;

/**
 * A server marked public at the plugin level.
 *
 * public() means "guests are welcome", not "credentials are ignored": a token
 * holder still authenticates here and reaches the resources a guest cannot.
 */
class PublicPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('public')
            ->path('public')
            ->colors(['primary' => Color::Amber])
            ->resources([
                PostResource::class,
                CategoryResource::class,
            ])
            ->plugins([
                McpPlugin::make()
                    ->public()
                    ->resources([
                        McpResource::make(CategoryResource::class)
                            ->readOnly()
                            ->public(),
                        // Not public: reachable only with a token.
                        McpResource::make(PostResource::class)
                            ->crud(),
                    ]),
            ])
        ;
    }
}
