<?php

use Guava\FilamentMcp\Support\RemoteFileFetcher;
use Illuminate\Support\Facades\Http;
use Workbench\App\Models\Post;

/**
 * Resolve public for the CDN host and private for the attacker's host, so a
 * redirect between them is the only thing under test.
 */
function fetcherResolving(array $map): void
{
    $fetcher = Mockery::mock(RemoteFileFetcher::class)->makePartial();
    $fetcher->shouldAllowMockingProtectedMethods();
    $fetcher->shouldReceive('resolve')->andReturnUsing(
        fn (string $host): array => $map[$host] ?? ['93.184.216.34'],
    );

    app()->instance(RemoteFileFetcher::class, $fetcher);
}

it('refuses a redirect to a private address', function () {
    fetcherResolving([
        'cdn.example.com' => ['93.184.216.34'],
        'internal.example.com' => ['169.254.169.254'],
    ]);

    Http::fake([
        'https://cdn.example.com/*' => Http::response('', 302, [
            'Location' => 'http://internal.example.com/latest/meta-data/',
        ]),
        'http://internal.example.com/*' => Http::response('secrets', 200),
    ]);

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('create_post', [
            'title' => 'Redirect SSRF',
            'status' => 'draft',
            'thumbnail' => 'https://cdn.example.com/pic.png',
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('private or reserved')
        ->and(Post::query()->count())->toBe(0)
    ;

    // The hop that mattered was never issued.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'internal.example.com'));
});

it('follows an allowed redirect', function () {
    fetcherResolving([
        'cdn.example.com' => ['93.184.216.34'],
        'files.example.com' => ['93.184.216.35'],
    ]);

    Http::fake([
        'https://cdn.example.com/*' => Http::response('', 301, [
            'Location' => 'https://files.example.com/pic.png',
        ]),
        'https://files.example.com/*' => Http::response(pngBytes(), 200),
    ]);

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'Redirected',
            'status' => 'draft',
            'thumbnail' => 'https://cdn.example.com/pic.png',
        ])
    ;

    expect(Post::query()->value('thumbnail'))->toStartWith('thumbnails/');
});

it('stops after too many redirects', function () {
    config()->set('filament-mcp.remote_files.max_redirects', 2);

    fetcherResolving([]);

    Http::fake([
        'https://cdn.example.com/*' => Http::response('', 302, [
            'Location' => 'https://cdn.example.com/again',
        ]),
    ]);

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('create_post', [
            'title' => 'Loop',
            'status' => 'draft',
            'thumbnail' => 'https://cdn.example.com/pic.png',
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('redirected too many times')
        ->and(Post::query()->count())->toBe(0)
    ;
});

it('refuses a response larger than the configured limit', function () {
    config()->set('filament-mcp.remote_files.max_size', 1);

    fetcherResolving([]);

    Http::fake([
        'https://cdn.example.com/*' => Http::response(str_repeat('a', 4096), 200),
    ]);

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('create_post', [
            'title' => 'Too big',
            'status' => 'draft',
            'thumbnail' => 'https://cdn.example.com/pic.png',
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('larger than')
        ->and(Post::query()->count())->toBe(0)
    ;
});

it('refuses an empty response', function () {
    fetcherResolving([]);

    Http::fake([
        'https://cdn.example.com/*' => Http::response('', 200),
    ]);

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('create_post', [
            'title' => 'Empty',
            'status' => 'draft',
            'thumbnail' => 'https://cdn.example.com/pic.png',
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and(Post::query()->count())->toBe(0)
    ;
});

it('refuses a non-2xx response', function () {
    fetcherResolving([]);

    Http::fake([
        'https://cdn.example.com/*' => Http::response('nope', 404),
    ]);

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('create_post', [
            'title' => 'Missing',
            'status' => 'draft',
            'thumbnail' => 'https://cdn.example.com/pic.png',
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('status 404')
        ->and(Post::query()->count())->toBe(0)
    ;
});

it('refuses remote fetching entirely when disabled', function () {
    config()->set('filament-mcp.remote_files.enabled', false);

    Http::fake();

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('create_post', [
            'title' => 'Disabled',
            'status' => 'draft',
            'thumbnail' => 'https://cdn.example.com/pic.png',
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('disabled')
    ;

    Http::assertNothingSent();
});
