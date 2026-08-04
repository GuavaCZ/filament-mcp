<?php

namespace Workbench\App\Providers;

use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
use Workbench\App\Filament\Resources\ProjectResource;
use Workbench\App\Filament\Resources\TicketResource;
use Workbench\App\Models\Team;

class TenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('tenant')
            ->path('tenant')
            ->login()
            ->colors(['primary' => Color::Sky])
            ->tenant(Team::class, slugAttribute: 'slug')
            ->resources([
                ProjectResource::class,
            ])
            ->plugins([
                McpPlugin::make()
                    ->resources([
                        McpResource::make(ProjectResource::class)
                            ->crud(),
                        // Absent from ->resources([]) above: an MCP-only resource
                        // on a tenant panel, which Filament's own tenancy wiring
                        // never sees.
                        McpResource::make(TicketResource::class)
                            ->crud(),
                    ]),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                ConvertEmptyStringsToNull::class,
                DispatchServingFilamentEvent::class,
                DisableBladeIconComponents::class,
                SubstituteBindings::class,
            ], isPersistent: true)
        ;
    }
}
