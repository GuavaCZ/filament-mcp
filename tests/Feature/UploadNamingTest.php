<?php

use Guava\FilamentMcp\Models\McpUpload;
use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Post;
use Workbench\App\Models\User;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
});

/**
 * A real BMP header with PHP appended.
 *
 * finfo reads magic bytes from the start of the buffer and stops, so this sniffs
 * as image/bmp, satisfying an `->image()` field's `image/*` check, while staying
 * a working PHP script to anything that executes it.
 */
function bmpWithPhpAppended(): string
{
    return 'BM' . pack('V', 100) . pack('v2', 0, 0) . pack('V', 54)
        . pack('V', 40) . pack('V', 1) . pack('V', 1) . pack('v', 1) . pack('v', 24)
        . str_repeat("\0", 24)
        . '<?php echo "pwned"; ?>';
}

/**
 * The full out-of-band route an agent takes: ask for a URL, PUT the bytes, get a
 * handle back. Not a shortcut through the model, since the filename is agent
 * input and has to travel the way agent input travels.
 */
function stageBytes(User $user, string $contents, ?string $filename = null): string
{
    $data = test()->mcp('admin')
        ->asToken(mcpToken($user))
        ->callJson('request_upload', $filename === null ? [] : ['filename' => $filename])
    ;

    test()->call(
        'PUT',
        $data['upload_url'],
        server: ['CONTENT_TYPE' => 'application/octet-stream'],
        content: $contents,
    )->assertOk();

    return $data['handle'];
}

it('never stores a file under an extension the agent picked', function () {
    // The whole chain in one test: an agent names its upload `pwn.php` and sends
    // bytes that pass an ->image() field's accepted types, which would otherwise
    // land an executable file on a public disk.
    $user = mcpUser();
    $handle = stageBytes($user, bmpWithPhpAppended(), 'pwn.php');

    $this->mcp('admin')->asToken(mcpToken($user))->callJson('create_post', [
        'title' => 'Polyglot',
        'status' => 'draft',
        'thumbnail' => $handle,
    ]);

    $path = Post::query()->value('thumbnail');

    expect($path)->toEndWith('.bmp')
        ->and($path)->not->toContain('.php')
    ;

    Storage::disk('public')->assertExists($path);
});

it('stores an SVG inert rather than as a browser-renderable document', function () {
    // SVG passes an ->image() field's `image/*` check, but a stored `.svg` on a
    // public disk runs any script it carries when opened directly, giving a
    // same-origin XSS primitive. It is absent from the default extension map and
    // lands as `.bin`.
    $user = mcpUser();
    $handle = stageBytes(
        $user,
        '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        'logo.svg',
    );

    $this->mcp('admin')->asToken(mcpToken($user))->callJson('create_post', [
        'title' => 'Vector',
        'status' => 'draft',
        'thumbnail' => $handle,
    ]);

    expect(Post::query()->value('thumbnail'))->toEndWith('.bin');
});

it('stores an SVG as .svg when the app opts back in via config', function () {
    config()->set('filament-mcp.uploads.extensions', ['image/svg+xml' => 'svg']);

    $user = mcpUser();
    $handle = stageBytes(
        $user,
        '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>',
        'logo.svg',
    );

    $this->mcp('admin')->asToken(mcpToken($user))->callJson('create_post', [
        'title' => 'Vector opt-in',
        'status' => 'draft',
        'thumbnail' => $handle,
    ]);

    expect(Post::query()->value('thumbnail'))->toEndWith('.svg');
});

it('stores a type it has no name for inert', function () {
    // `gallery` sets no accepted types, so nothing gates the bytes themselves and
    // the extension is all that stands between this and execution.
    $user = mcpUser();
    $handle = stageBytes($user, '<?php echo "pwned"; ?>', 'pwn.php');

    $this->mcp('admin')->asToken(mcpToken($user))->callJson('create_post', [
        'title' => 'Source',
        'status' => 'draft',
        'gallery' => [$handle],
    ]);

    expect(Post::query()->first()->gallery[0])->toEndWith('.bin');
});

it('strips every extension from a preserved filename, not just the last', function () {
    // Apache maps each extension in a name to a handler, so `report.php.jpg`
    // executes even though it ends in something harmless.
    $user = mcpUser();
    $handle = stageBytes($user, pngBytes(), 'report.php.jpg');

    $this->mcp('admin')->asToken(mcpToken($user))->callJson('create_post', [
        'title' => 'Preserved',
        'status' => 'draft',
        'attachment' => $handle,
    ]);

    expect(Post::query()->value('attachment'))->toBe('attachments/report-php.png');
});

it('keeps a preserved filename inside its directory', function () {
    $user = mcpUser();
    $handle = stageBytes($user, pngBytes(), '../../../public/evil.png');

    $this->mcp('admin')->asToken(mcpToken($user))->callJson('create_post', [
        'title' => 'Traversal',
        'status' => 'draft',
        'attachment' => $handle,
    ]);

    expect(Post::query()->value('attachment'))->toBe('attachments/evil.png');
});

it('falls back to a generated name when nothing of the filename survives', function () {
    $user = mcpUser();
    $handle = stageBytes($user, pngBytes(), '....');

    $this->mcp('admin')->asToken(mcpToken($user))->callJson('create_post', [
        'title' => 'Dots',
        'status' => 'draft',
        'attachment' => $handle,
    ]);

    $path = Post::query()->value('attachment');

    // Never a dotfile, and never an empty name.
    expect($path)->toStartWith('attachments/')
        ->and($path)->toEndWith('.png')
        ->and(basename($path))->not->toStartWith('.')
    ;
});

it('stages an upload under an extension of its own choosing', function () {
    $user = mcpUser();
    stageBytes($user, pngBytes(), 'pwn.php');

    $path = McpUpload::query()->value('path');

    expect($path)->toEndWith('.png')
        ->and($path)->not->toContain('.php')
    ;

    // The name the agent gave is kept as metadata but never used to build a path.
    expect(McpUpload::query()->value('original_name'))->toBe('pwn.php');
});

it('stages an unrecognised type inert', function () {
    $user = mcpUser();
    stageBytes($user, '<?php echo "pwned"; ?>', 'pwn.php');

    expect(McpUpload::query()->value('path'))->toEndWith('.bin');
});

it('refuses a body larger than the staging limit', function () {
    config()->set('filament-mcp.uploads.max_size', 1);

    $user = mcpUser();

    $data = $this->mcp('admin')
        ->asToken(mcpToken($user))
        ->callJson('request_upload', ['filename' => 'big.png'])
    ;

    $this->call('PUT', $data['upload_url'], content: str_repeat('x', 2 * 1024))
        ->assertStatus(413)
    ;

    expect(McpUpload::query()->value('path'))->toBeNull();
});

it('refuses an oversized body on its declared length alone', function () {
    config()->set('filament-mcp.uploads.max_size', 1);

    $user = mcpUser();

    $data = $this->mcp('admin')
        ->asToken(mcpToken($user))
        ->callJson('request_upload', ['filename' => 'big.png'])
    ;

    // Nothing is sent, the header is the whole request. A body this size should
    // never be read in the first place.
    $this->call(
        'PUT',
        $data['upload_url'],
        server: ['CONTENT_LENGTH' => (string) (50 * 1024 * 1024)],
    )->assertStatus(413);

    expect(McpUpload::query()->value('path'))->toBeNull();
});

it('accepts a body right up to the limit', function () {
    config()->set('filament-mcp.uploads.max_size', 1);

    $user = mcpUser();

    $data = $this->mcp('admin')
        ->asToken(mcpToken($user))
        ->callJson('request_upload', ['filename' => 'ok.txt'])
    ;

    $this->call('PUT', $data['upload_url'], content: str_repeat('x', 1024))->assertOk();

    expect(McpUpload::query()->value('size'))->toBe(1024);
});

it('honours extra extensions named in config', function () {
    config()->set('filament-mcp.uploads.extensions', ['image/bmp' => 'dib']);

    $user = mcpUser();
    $handle = stageBytes($user, bmpWithPhpAppended(), 'pwn.php');

    $this->mcp('admin')->asToken(mcpToken($user))->callJson('create_post', [
        'title' => 'Configured',
        'status' => 'draft',
        'thumbnail' => $handle,
    ]);

    expect(Post::query()->value('thumbnail'))->toEndWith('.dib');
});

it('ignores a configured extension that is not a plain extension', function (string $extension) {
    config()->set('filament-mcp.uploads.extensions', ['image/bmp' => $extension]);

    $user = mcpUser();
    $handle = stageBytes($user, bmpWithPhpAppended(), 'pwn.php');

    $this->mcp('admin')->asToken(mcpToken($user))->callJson('create_post', [
        'title' => 'Bad config',
        'status' => 'draft',
        'thumbnail' => $handle,
    ]);

    expect(Post::query()->value('thumbnail'))->toEndWith('.bin');
})->with([
    'a second extension' => 'jpg.php',
    'a path' => '../../evil.php',
    'a leading dot' => '.php',
    'nothing' => '',
]);
