<?php

use Filament\Facades\Filament;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\McpPlugin;
use Guava\FilamentMcp\McpServerRegistry;
use Workbench\App\Filament\Resources\PostResource;
use Workbench\App\Mcp\PostPeekTool;
use Workbench\App\Models\Post;

it('registers custom tool classes on the server', function () {
    $tools = $this->mcp('admin')
        ->asToken(mcpToken())
        ->toolNames()
    ;

    expect($tools)->toContain('post_stats');
});

it('runs a custom tool with Filament context available', function () {
    Post::factory()->count(2)->create(['status' => 'draft']);
    Post::factory()->create(['status' => 'published']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('post_stats')
    ;

    expect($data['panel'])->toBe('admin')
        ->and($data['counts']['draft'])->toBe(2)
        ->and($data['counts']['published'])->toBe(1)
    ;
});

it('authorizes a custom tool through the resource authorizer', function () {
    $post = Post::factory()->create(['status' => 'published']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('post_peek', ['id' => (string) $post->getKey()])
    ;

    expect($data['title'])->toBe($post->title)
        ->and($data['status'])->toBe('published')
    ;
});

it('denies a custom tool the same ability the generated get tool needs', function () {
    $post = Post::factory()->create();

    $response = $this->mcp('admin')
        ->asToken(mcpToken(abilities: ['posts:list']))
        ->call('post_peek', ['id' => (string) $post->getKey()])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('posts:get')
    ;
});

it('denies a custom tool to guests before any record is resolved', function () {
    $post = Post::factory()->create();

    $response = $this->mcp('admin')->call('post_peek', ['id' => (string) $post->getKey()]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('Unauthenticated')
    ;
});

it('resolves records in a custom tool through McpResource::query() scoping', function () {
    $published = Post::factory()->create(['status' => 'published']);
    $draft = Post::factory()->create(['status' => 'draft']);

    // Re-register the admin server with a scoped PostResource, so the trait's
    // resolveRecordFor() has a query() callback to honour.
    app(McpServerRegistry::class)->compile(
        Filament::getPanel('admin'),
        McpPlugin::make()
            ->resources([
                McpResource::make(PostResource::class)
                    ->crud()
                    ->query(fn ($query) => $query->where('status', 'published')),
            ])
            ->tools([PostPeekTool::class]),
    );

    $client = $this->mcp('admin')->asToken(mcpToken());

    expect($client->callJson('post_peek', ['id' => (string) $published->getKey()])['title'])
        ->toBe($published->title)
    ;

    // A record outside the scope reads as missing rather than denied, since the
    // scoped query is the record's visibility boundary.
    $response = $client->call('post_peek', ['id' => (string) $draft->getKey()]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('No post found')
    ;
});

it('hides a tool using RequiresAuthentication from guests', function () {
    // Custom tools are otherwise listed to anonymous callers on any server
    // with a public resource, since nothing gates their registration.
    $guestTools = $this->mcp('admin')->toolNames();

    expect($guestTools)->not->toContain('post_stats')
        // post_peek does not use the trait, so it is still listed, and still
        // refuses a guest in handle(), which is the check that matters.
        ->and($guestTools)->toContain('post_peek')
    ;
});

it('still lists a RequiresAuthentication tool to an authenticated caller', function () {
    expect($this->mcp('admin')->asToken(mcpToken())->toolNames())
        ->toContain('post_stats')
    ;
});
