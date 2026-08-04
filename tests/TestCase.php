<?php

namespace Guava\FilamentMcp\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Guava\FilamentMcp\FilamentMcpServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Workbench\App\Providers\AdminPanelProvider;
use Workbench\App\Providers\PublicPanelProvider;
use Workbench\App\Providers\TenantPanelProvider;
use Workbench\App\Providers\WorkbenchServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [
            // Filament's support provider has to register before Livewire. It binds
            // a DataStore override that Livewire's mechanism registration then pins
            // as a singleton. The other way round, the bind() drops Livewire's
            // instance and every resolution gets a fresh, empty store.
            SupportServiceProvider::class,
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            ActionsServiceProvider::class,
            SchemasServiceProvider::class,
            FormsServiceProvider::class,
            TablesServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            McpServiceProvider::class,
            FilamentMcpServiceProvider::class,
            WorkbenchServiceProvider::class,
            AdminPanelProvider::class,
            TenantPanelProvider::class,
            PublicPanelProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__) . '/workbench/database/migrations');
    }

    public function getEnvironmentSetUp($app)
    {
        // CI runs the suite against Postgres and MySQL as well, since SQLite's
        // permissive casting hides driver-specific failures (a non-numeric id
        // against an integer column is a type error on Postgres and a silent miss
        // on SQLite).
        config()->set('database.default', env('DB_CONNECTION', 'testing'));
        // MySQL validates timestamp columns against the session timezone and the
        // app writes UTC, so pin the session to UTC. Otherwise a faker datetime
        // falling into the local zone's DST gap fails the insert.
        config()->set('database.connections.mysql.timezone', '+00:00');
        // Stay independent of the workbench skeleton's .env, which may point
        // cache/session/queue at database tables that don't exist in tests.
        config()->set('cache.default', 'array');
        config()->set('session.driver', 'array');
        config()->set('queue.default', 'sync');
        config()->set('app.key', 'base64:' . base64_encode('guava-filament-mcp-testing-key32'));
    }
}
