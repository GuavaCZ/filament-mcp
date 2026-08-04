<?php

use Filament\Facades\Filament;
use Guava\FilamentMcp\Contracts\GrantsAbilities;
use Guava\FilamentMcp\Enums\McpOperation;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\McpPlugin;
use Guava\FilamentMcp\Support\CurrentMcpGrant;
use Guava\FilamentMcp\Support\ResourceAuthorizer;
use Workbench\App\Filament\Resources\PostResource;
use Workbench\App\Models\Category;
use Workbench\App\Models\Post;

it('refuses an authenticated caller that carries no ability grant', function () {
    // The state an auth path lands in when it authenticates a user but never
    // says what they may do. Granting everything here would hand out full access
    // regardless of scope.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::auth()->setUser(mcpUser(['is_admin' => true]));
    CurrentMcpGrant::set(null);

    $error = app(ResourceAuthorizer::class)->authorize(
        McpResource::make(PostResource::class)->crud(),
        McpOperation::Delete,
        Post::factory()->create(),
    );

    expect($error)->toContain('No MCP abilities were granted');
});

it('honours a grant set by something other than an MCP token', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::auth()->setUser(mcpUser(['is_admin' => true]));

    CurrentMcpGrant::set(new class implements GrantsAbilities
    {
        public function can(string $ability): bool
        {
            return $ability === 'posts:delete';
        }
    });

    $authorizer = app(ResourceAuthorizer::class);
    $mcpResource = McpResource::make(PostResource::class)->crud();
    $post = Post::factory()->create();

    expect($authorizer->authorize($mcpResource, McpOperation::Delete, $post))->toBeNull()
        ->and($authorizer->authorize($mcpResource, McpOperation::Update, $post))->toContain('posts:update')
    ;
});

it('still serves guests on a public resource, which carry no grant at all', function () {
    // Abilities scope a credential and an anonymous caller has none, so public()
    // is the whole gate and failing closed must not break it.
    Category::factory()->count(2)->create();

    $data = $this->mcp('admin')->callJson('list_categories');

    expect($data['pagination']['total'])->toBe(2);
});

it('refuses a record-level operation authorized without a record', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::auth()->setUser(mcpUser(['is_admin' => true]));

    // PostPolicy::delete() is typed against the record, so asking the class-level
    // question would raise an ArgumentCountError and 500.
    $error = app(ResourceAuthorizer::class)->authorize(
        McpResource::make(PostResource::class)->crud(),
        McpOperation::Delete,
    );

    expect($error)->toContain('must be authorized against a record');
});

it('refuses to enable oauth', function () {
    expect(fn () => McpPlugin::make()->oauth())
        ->toThrow(LogicException::class, 'OAuth is not supported yet')
    ;
});
