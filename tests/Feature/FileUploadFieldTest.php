<?php

use Guava\FilamentMcp\McpServerRegistry;
use Guava\FilamentMcp\Models\McpUpload;
use Guava\FilamentMcp\Support\RemoteFileFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

function stageUpload(?User $user = null, ?string $contents = null): McpUpload
{
    $user ??= mcpUser();

    $upload = McpUpload::query()->create([
        'uploadable_type' => $user->getMorphClass(),
        'uploadable_id' => $user->getKey(),
        'panel_id' => 'admin',
        'expires_at' => now()->addHour(),
    ]);

    Storage::disk('local')->put("mcp-uploads/{$upload->getKey()}.png", $contents ?? pngBytes());

    $upload->forceFill([
        'disk' => 'local',
        'path' => "mcp-uploads/{$upload->getKey()}.png",
        'mime_type' => 'image/png',
        'size' => strlen($contents ?? pngBytes()),
        'uploaded_at' => now(),
    ])->save();

    return $upload;
}

it('offers the upload tool only when a writable file field exists', function () {
    expect($this->mcp('admin')->asToken(mcpToken())->toolNames())
        ->toContain('request_upload')
    ;

    // The tenant panel exposes ProjectResource, which has no file field, so
    // its agents should never be shown an upload tool they cannot use.
    $registry = app(McpServerRegistry::class);

    expect($registry->get('admin')->hasWritableFileFields())->toBeTrue()
        ->and($registry->get('tenant')->hasWritableFileFields())->toBeFalse()
    ;
});

it('hides the upload tool from guests', function () {
    expect($this->mcp('admin')->toolNames())->not->toContain('request_upload');
});

it('describes a file field as a handle or URL, not a bare string', function () {
    $tools = $this->mcp('admin')->asToken(mcpToken())->listTools();

    $schema = collect($tools->json('result.tools'))
        ->firstWhere('name', 'create_post')['inputSchema']
    ;

    expect($schema['properties']['thumbnail']['description'])
        ->toContain('request_upload')
        ->toContain('https')
    ;

    // A multiple() field must be an array, not a string.
    expect($schema['properties']['gallery']['type'])->toBe('array');
});

it('returns a signed upload url and handle', function () {
    $user = mcpUser();

    $data = $this->mcp('admin')
        ->asToken(mcpToken($user))
        ->callJson('request_upload', ['filename' => 'photo.png'])
    ;

    expect($data['handle'])->toStartWith('mcp-upload:')
        ->and($data['upload_url'])->toContain('signature=')
        ->and($data['method'])->toBe('PUT')
    ;

    $upload = McpUpload::query()->first();

    expect($upload->uploadable_id)->toBe($user->getKey())
        ->and($upload->original_name)->toBe('photo.png')
        ->and($upload->hasFile())->toBeFalse()
    ;
});

it('accepts bytes at the signed url and refuses an unsigned one', function () {
    $user = mcpUser();

    $data = $this->mcp('admin')
        ->asToken(mcpToken($user))
        ->callJson('request_upload', ['filename' => 'photo.png'])
    ;

    $this->call('PUT', $data['upload_url'], server: ['CONTENT_TYPE' => 'application/octet-stream'], content: pngBytes())
        ->assertOk()
    ;

    $upload = McpUpload::query()->first();

    expect($upload->hasFile())->toBeTrue()
        ->and($upload->mime_type)->toBe('image/png')
    ;

    Storage::disk('local')->assertExists($upload->path);

    // Strip the signature and it must be refused.
    $unsigned = strtok($data['upload_url'], '?');

    $this->call('PUT', $unsigned, content: pngBytes())->assertForbidden();
});

it('stores an uploaded file on the field configured disk and directory', function () {
    $user = mcpUser();
    $upload = stageUpload($user);

    $this->mcp('admin')
        ->asToken(mcpToken($user))
        ->callJson('create_post', [
            'title' => 'With thumbnail',
            'status' => 'draft',
            'thumbnail' => $upload->getHandle(),
        ])
    ;

    $path = Post::query()->value('thumbnail');

    expect($path)->toStartWith('thumbnails/')
        ->and($path)->toEndWith('.png')
    ;

    Storage::disk('public')->assertExists($path);
    expect(Storage::disk('public')->get($path))->toBe(pngBytes());
});

it('hands a stored file back to the agent as a url', function () {
    Storage::disk('public')->put('thumbnails/existing.png', pngBytes());

    $post = Post::factory()->create(['thumbnail' => 'thumbnails/existing.png']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('get_post', ['id' => $post->getKey()])
    ;

    expect($data['thumbnail'])->toContain('thumbnails/existing.png')
        ->and($data['thumbnail'])->toStartWith('http')
    ;
});

it('marks a handle consumed so it cannot be replayed', function () {
    $user = mcpUser();
    $upload = stageUpload($user);

    $this->mcp('admin')->asToken(mcpToken($user))->callJson('create_post', [
        'title' => 'First', 'status' => 'draft', 'thumbnail' => $upload->getHandle(),
    ]);

    expect($upload->refresh()->isConsumed())->toBeTrue();

    $response = $this->mcp('admin')->asToken(mcpToken($user))->call('create_post', [
        'title' => 'Second', 'status' => 'draft', 'thumbnail' => $upload->getHandle(),
    ]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('no longer usable')
    ;
});

it('refuses a handle belonging to another user', function () {
    $owner = mcpUser();
    $attacker = mcpUser();

    $upload = stageUpload($owner);

    $response = $this->mcp('admin')
        ->asToken(mcpToken($attacker))
        ->call('create_post', [
            'title' => 'Stolen', 'status' => 'draft', 'thumbnail' => $upload->getHandle(),
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue();
    expect($upload->refresh()->isConsumed())->toBeFalse();
    expect(Post::query()->count())->toBe(0);
});

it('fetches an allowed remote url', function () {
    Http::fake(['https://cdn.example.com/*' => Http::response(pngBytes(), 200)]);

    $fetcher = Mockery::mock(RemoteFileFetcher::class)->makePartial();
    $fetcher->shouldAllowMockingProtectedMethods();
    $fetcher->shouldReceive('resolve')->andReturn(['93.184.216.34']);
    app()->instance(RemoteFileFetcher::class, $fetcher);

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'Remote',
            'status' => 'draft',
            'thumbnail' => 'https://cdn.example.com/pic.png',
        ])
    ;

    $path = Post::query()->value('thumbnail');

    expect($path)->toStartWith('thumbnails/');
    Storage::disk('public')->assertExists($path);
});

it('refuses a url resolving to a private address', function (string $url) {
    Http::fake();

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('create_post', [
            'title' => 'SSRF', 'status' => 'draft', 'thumbnail' => $url,
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('private or reserved')
    ;

    Http::assertNothingSent();
    expect(Post::query()->count())->toBe(0);
})->with([
    'loopback' => 'http://127.0.0.1/pic.png',
    'private range' => 'http://192.168.1.10/pic.png',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
    'ipv6 loopback' => 'http://[::1]/pic.png',
]);

it('refuses a non-http scheme', function () {
    $response = $this->mcp('admin')->asToken(mcpToken())->call('create_post', [
        'title' => 'File scheme', 'status' => 'draft', 'thumbnail' => 'file:///etc/passwd',
    ]);

    expect($response->json('result.isError'))->toBeTrue();
    expect(Post::query()->count())->toBe(0);
});

it('refuses a file whose sniffed type the field does not accept', function () {
    $user = mcpUser();
    // Staged as image/png, but the bytes are plain text, so the sniffed type wins.
    $upload = stageUpload($user, 'just some text, definitely not a png');
    $upload->forceFill(['mime_type' => null])->save();

    $response = $this->mcp('admin')
        ->asToken(mcpToken($user))
        ->call('create_post', [
            'title' => 'Wrong type', 'status' => 'draft', 'thumbnail' => $upload->getHandle(),
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('accepts')
    ;
});

it('refuses a file larger than the field allows', function () {
    $user = mcpUser();
    // thumbnail is maxSize(1024) KB.
    $upload = stageUpload($user, str_repeat('x', 1025 * 1024));
    $upload->forceFill(['mime_type' => 'image/png'])->save();

    $response = $this->mcp('admin')
        ->asToken(mcpToken($user))
        ->call('create_post', [
            'title' => 'Too big', 'status' => 'draft', 'thumbnail' => $upload->getHandle(),
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('maximum')
    ;
});

it('enforces the field max file count', function () {
    $user = mcpUser();
    $handles = collect(range(1, 4))->map(fn () => stageUpload($user)->getHandle())->all();

    $response = $this->mcp('admin')
        ->asToken(mcpToken($user))
        ->call('create_post', [
            'title' => 'Too many', 'status' => 'draft', 'gallery' => $handles,
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('at most 3')
    ;
});

it('stores multiple files as a list', function () {
    $user = mcpUser();
    $handles = [stageUpload($user)->getHandle(), stageUpload($user)->getHandle()];

    $this->mcp('admin')->asToken(mcpToken($user))->callJson('create_post', [
        'title' => 'Gallery', 'status' => 'draft', 'gallery' => $handles,
    ]);

    $gallery = Post::query()->first()->gallery;

    expect($gallery)->toBeArray()->toHaveCount(2)
        ->and($gallery[0])->toStartWith('gallery/')
        ->and(array_keys($gallery))->toBe([0, 1])
    ;
});

it('keeps an unchanged file when the agent writes back the url it was given', function () {
    Storage::disk('public')->put('thumbnails/keep.png', pngBytes());

    $post = Post::factory()->create(['thumbnail' => 'thumbnails/keep.png']);

    $data = $this->mcp('admin')->asToken(mcpToken())
        ->callJson('get_post', ['id' => $post->getKey()])
    ;

    Http::fake();

    $this->mcp('admin')->asToken(mcpToken())->callJson('update_post', [
        'id' => $post->getKey(),
        'title' => 'Renamed',
        'thumbnail' => $data['thumbnail'],
    ]);

    expect($post->refresh()->thumbnail)->toBe('thumbnails/keep.png');
    Http::assertNothingSent();
});

it('prunes consumed and expired uploads along with their files', function () {
    $user = mcpUser();

    $consumed = stageUpload($user);
    $consumed->forceFill(['consumed_at' => now()])->save();

    $expired = stageUpload($user);
    $expired->forceFill(['expires_at' => now()->subHour()])->save();

    $live = stageUpload($user);

    $this->artisan('mcp:prune-uploads')
        ->assertSuccessful()
        ->expectsOutputToContain('Pruned 2')
    ;

    expect(McpUpload::query()->pluck('id')->all())->toBe([$live->getKey()]);
    Storage::disk('local')->assertMissing($consumed->path);
    Storage::disk('local')->assertExists($live->path);
});
