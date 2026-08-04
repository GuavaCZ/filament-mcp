<?php

use Guava\FilamentMcp\Facades\FilamentMcp;
use Illuminate\Support\Facades\Route;
use Workbench\App\Filament\Resources\CategoryResource;
use Workbench\App\Filament\Resources\GuardedNoteResource;
use Workbench\App\Filament\Resources\PostResource;

it('registers an MCP route for the panel', function () {
    expect(Route::has('filament-mcp.admin'))->toBeTrue();

    $route = Route::getRoutes()->getByName('filament-mcp.admin');

    expect($route->uri())->toBe('mcp/admin')
        ->and($route->defaults['mcpPanel'] ?? null)->toBe('admin')
    ;
});

it('compiles a server definition for the panel', function () {
    $definition = FilamentMcp::get('admin');

    expect($definition)->not->toBeNull()
        ->and($definition->panelId)->toBe('admin')
        ->and(array_map(fn ($mcpResource) => $mcpResource->getResource(), $definition->mcpResources))
        ->toBe([
            PostResource::class,
            CategoryResource::class,
            GuardedNoteResource::class,
        ])
    ;
});

it('responds to initialize as a guest because public resources exist', function () {
    $response = $this->mcp('admin')->initialize();

    $response->assertOk();

    expect($response->json('result.serverInfo.name'))->not->toBeEmpty();
});

it('rejects tool calls with an invalid token', function () {
    $response = $this->mcp('admin')
        ->asToken('gmcp_999|invalid-secret')
        ->call('list_posts')
    ;

    // An invalid token is refused rather than downgraded to guest, even though
    // this panel has a public resource.
    $response->assertStatus(401);
});
