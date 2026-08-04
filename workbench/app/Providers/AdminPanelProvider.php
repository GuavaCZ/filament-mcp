<?php

namespace Workbench\App\Providers;

use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\McpPlugin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Workbench\App\Filament\Resources\CategoryResource;
use Workbench\App\Filament\Resources\GuardedNoteResource;
use Workbench\App\Filament\Resources\PostResource;
use Workbench\App\Http\Middleware\AutoLogin;
use Workbench\App\Mcp\PostPeekTool;
use Workbench\App\Mcp\PostStatsTool;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->colors(['primary' => Color::Emerald])
            ->resources([
                PostResource::class,
                CategoryResource::class,
                GuardedNoteResource::class,
            ])
            ->pages([
                Dashboard::class,
            ])
            ->plugins([
                McpPlugin::make()
                    ->instructions('This server manages the workbench blog. Posts belong to categories.')
                    ->resources([
                        McpResource::make(PostResource::class)
                            ->crud()
                            // Tag is not an MCP resource on this panel, so this
                            // relation must not produce a tool.
                            ->relations(['tags'])
                            ->actions(['archive']),
                        McpResource::make(CategoryResource::class)
                            ->readOnly()
                            ->relations(['posts'])
                            ->public(),
                        McpResource::make(GuardedNoteResource::class)
                            ->crud(),
                    ])
                    ->discoverActions()
                    ->tools([
                        PostStatsTool::class,
                        PostPeekTool::class,
                    ])
                    ->tokens()
                    ->tokensOnProfilePage()
                    ->local(),
            ])
            // After ->plugins(), so the profile page still has to be picked up
            // by the plugin's render hook.
            ->profile()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                ConvertEmptyStringsToNull::class,
                DispatchServingFilamentEvent::class,
                DisableBladeIconComponents::class,
                SubstituteBindings::class,
                AutoLogin::class,
            ], isPersistent: true)
        ;
    }
}
