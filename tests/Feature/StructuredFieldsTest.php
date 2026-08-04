<?php

use Illuminate\Support\Facades\Storage;
use Workbench\App\Models\Post;

beforeEach(function () {
    Storage::fake('public');
});

function postSchema(): array
{
    $tools = test()->mcp('admin')->asToken(mcpToken())->listTools();

    return collect($tools->json('result.tools'))
        ->firstWhere('name', 'create_post')['inputSchema']
    ;
}

it('transposes a repeater into an array of objects', function () {
    $links = postSchema()['properties']['links'];

    expect($links['type'])->toBe('array')
        ->and($links['items']['type'])->toBe('object')
        ->and($links['items']['properties'])->toHaveKeys(['label', 'url', 'external'])
        ->and($links['items']['properties']['external']['type'])->toBe('boolean')
        ->and($links['items']['required'])->toContain('label', 'url')
    ;
});

it('transposes a builder into typed blocks', function () {
    $blocks = postSchema()['properties']['blocks'];

    expect($blocks['type'])->toBe('array')
        ->and($blocks['items']['properties']['type']['enum'])->toBe(['paragraph', 'quote'])
        // The per-block shapes cannot be expressed in a single object schema, so
        // they are named in the description instead.
        ->and($blocks['items']['properties']['data']['description'])
        ->toContain('paragraph')
        ->toContain('attribution')
    ;
});

it('transposes a key value field into an object', function () {
    expect(postSchema()['properties']['meta']['type'])->toBe('object');
});

it('stores repeater entries as a plain list', function () {
    $this->mcp('admin')->asToken(mcpToken())->callJson('create_post', [
        'title' => 'With links',
        'status' => 'draft',
        'links' => [
            ['label' => 'Docs', 'url' => 'https://example.com/docs', 'external' => true],
            ['label' => 'Home', 'url' => 'https://example.com'],
        ],
    ]);

    $links = Post::query()->first()->links;

    expect(array_keys($links))->toBe([0, 1])
        ->and($links[0]['label'])->toBe('Docs')
        ->and($links[0]['external'])->toBeTrue()
        ->and($links[1]['url'])->toBe('https://example.com')
    ;
});

it('validates each repeater entry against the sub-schema', function () {
    $response = $this->mcp('admin')->asToken(mcpToken())->call('create_post', [
        'title' => 'Bad link',
        'status' => 'draft',
        'links' => [['label' => 'No url']],
    ]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('links[0]')
    ;

    expect(Post::query()->count())->toBe(0);
});

it('rejects a url that is not a url inside a repeater', function () {
    $response = $this->mcp('admin')->asToken(mcpToken())->call('create_post', [
        'title' => 'Bad url',
        'status' => 'draft',
        'links' => [['label' => 'Nope', 'url' => 'not-a-url']],
    ]);

    expect($response->json('result.isError'))->toBeTrue();
});

it('enforces the repeater max item count', function () {
    $response = $this->mcp('admin')->asToken(mcpToken())->call('create_post', [
        'title' => 'Too many',
        'status' => 'draft',
        'links' => array_fill(0, 5, ['label' => 'x', 'url' => 'https://example.com']),
    ]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('at most 4')
    ;
});

it('drops keys the repeater sub-schema does not declare', function () {
    $this->mcp('admin')->asToken(mcpToken())->callJson('create_post', [
        'title' => 'Extra keys',
        'status' => 'draft',
        'links' => [[
            'label' => 'Docs',
            'url' => 'https://example.com',
            'is_admin' => true,
            'injected' => 'nope',
        ]],
    ]);

    expect(Post::query()->first()->links[0])
        ->toHaveKeys(['label', 'url'])
        ->not->toHaveKey('is_admin')
        ->not->toHaveKey('injected')
    ;
});

it('stores builder blocks with their type and data', function () {
    $this->mcp('admin')->asToken(mcpToken())->callJson('create_post', [
        'title' => 'Blocks',
        'status' => 'draft',
        'blocks' => [
            ['type' => 'paragraph', 'data' => ['content' => '<p>Hello</p>']],
            ['type' => 'quote', 'data' => ['text' => 'To be', 'attribution' => 'Hamlet']],
        ],
    ]);

    $blocks = Post::query()->first()->blocks;

    expect(array_keys($blocks))->toBe([0, 1])
        ->and($blocks[0]['type'])->toBe('paragraph')
        ->and($blocks[1]['data']['attribution'])->toBe('Hamlet')
    ;
});

it('runs nested mappers inside a builder block', function () {
    $this->mcp('admin')->asToken(mcpToken())->callJson('create_post', [
        'title' => 'Nested rich text',
        'status' => 'draft',
        'blocks' => [[
            'type' => 'paragraph',
            'data' => ['content' => '<p>Safe</p><script>alert(1)</script>'],
        ]],
    ]);

    // The RichEditor mapper applies inside the block as it does at the top level.
    $content = Post::query()->first()->blocks[0]['data']['content'];

    expect($content)->toContain('Safe')->not->toContain('<script');
});

it('rejects an unknown block type', function () {
    $response = $this->mcp('admin')->asToken(mcpToken())->call('create_post', [
        'title' => 'Unknown',
        'status' => 'draft',
        'blocks' => [['type' => 'video', 'data' => []]],
    ]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))
        ->toContain('unknown type')
        ->toContain('paragraph')
    ;
});

it('validates a block against its own sub-schema', function () {
    $response = $this->mcp('admin')->asToken(mcpToken())->call('create_post', [
        'title' => 'Missing text',
        'status' => 'draft',
        'blocks' => [['type' => 'quote', 'data' => ['attribution' => 'Nobody']]],
    ]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('blocks[0].data')
    ;
});

it('stores key value pairs as strings', function () {
    $this->mcp('admin')->asToken(mcpToken())->callJson('create_post', [
        'title' => 'Meta',
        'status' => 'draft',
        'meta' => ['og:title' => 'Hello', 'reading_time' => 5],
    ]);

    expect(Post::query()->first()->meta)
        ->toBe(['og:title' => 'Hello', 'reading_time' => '5'])
    ;
});

it('rejects a nested structure in a key value field', function () {
    $response = $this->mcp('admin')->asToken(mcpToken())->call('create_post', [
        'title' => 'Nested meta',
        'status' => 'draft',
        'meta' => ['nested' => ['too' => 'deep']],
    ]);

    expect($response->json('result.isError'))->toBeTrue();
});

it('round trips structured fields through get', function () {
    $this->mcp('admin')->asToken(mcpToken())->callJson('create_post', [
        'title' => 'Round trip',
        'status' => 'draft',
        'links' => [['label' => 'Docs', 'url' => 'https://example.com']],
        'meta' => ['a' => 'b'],
    ]);

    $post = Post::query()->first();

    $data = $this->mcp('admin')->asToken(mcpToken())
        ->callJson('get_post', ['id' => $post->getKey()])
    ;

    expect($data['links'][0]['label'])->toBe('Docs')
        ->and($data['meta'])->toBe(['a' => 'b'])
    ;
});
