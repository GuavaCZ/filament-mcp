<?php

use Filament\Forms\Components\RichEditor;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\Schema\FieldMappers\RichEditorMapper;
use Guava\FilamentMcp\Schema\SchemaTransposer;
use Guava\FilamentMcp\Schema\WriteContext;
use Illuminate\JsonSchema\JsonSchema;
use Workbench\App\Filament\Resources\PostResource;
use Workbench\App\Models\Post;

function transposePostSchema(bool $partial = false): array
{
    return JsonSchema::object(fn ($schema) => app(SchemaTransposer::class)->transpose(
        McpResource::make(PostResource::class)->crud(),
        $schema,
        $partial,
    ))->toArray();
}

it('describes rich text as HTML rather than a bare string', function () {
    $schema = transposePostSchema();

    expect($schema['properties']['body']['type'])->toBe('string')
        ->and($schema['properties']['body']['description'])->toContain('HTML')
    ;
});

it('describes a markdown editor as markdown', function () {
    expect(transposePostSchema()['properties']['summary']['description'])
        ->toContain('Markdown')
    ;
});

it('stores rich text as HTML', function () {
    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'Rich',
            'status' => 'draft',
            'body' => '<p>Hello <strong>world</strong></p>',
        ])
    ;

    expect(Post::query()->value('body'))->toBe('<p>Hello <strong>world</strong></p>');
});

it('strips markup the editor does not support', function () {
    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'Nasty',
            'status' => 'draft',
            'body' => '<p>Safe</p><script>alert(1)</script><iframe src="https://evil.test"></iframe>',
        ])
    ;

    $body = Post::query()->value('body');

    expect($body)->toContain('Safe')
        ->and($body)->not->toContain('<script')
        ->and($body)->not->toContain('<iframe')
    ;
});

it('stores a tiptap document when the model registers the attribute as json', function () {
    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'Json body',
            'status' => 'draft',
            'body_json' => '<p>Structured</p>',
        ])
    ;

    $stored = Post::query()->value('body_json');

    // The model registers body_json as JSON rich content, so the stored value is
    // a ProseMirror document even though the agent sent HTML.
    expect($stored)->toBeArray()
        ->and($stored['type'])->toBe('doc')
        ->and(json_encode($stored))->toContain('Structured')
    ;
});

it('hands json rich content back to the agent as HTML', function () {
    $post = Post::factory()->create([
        'body_json' => [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'Round tripped']],
            ]],
        ],
    ]);

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('get_post', ['id' => $post->getKey()])
    ;

    expect($data['body_json'])->toBeString()
        ->toContain('Round tripped')
        ->toContain('<p>')
    ;
});

it('rejects a non-string value for rich text', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('create_post', [
            'title' => 'Bad',
            'status' => 'draft',
            'body' => ['not' => 'html'],
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue();
    expect(Post::query()->count())->toBe(0);
});

it('leaves rich text untouched when it is not provided', function () {
    $post = Post::factory()->create(['body' => '<p>Existing</p>']);

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('update_post', [
            'id' => $post->getKey(),
            'title' => 'Renamed only',
        ])
    ;

    expect($post->refresh()->body)->toBe('<p>Existing</p>');
});

it('enforces required itself, since filament cannot for an HTML string', function (string $html) {
    $field = RichEditor::make('body')->required();
    $context = new WriteContext(McpResource::make(PostResource::class), null, 'admin');

    expect(fn () => app(RichEditorMapper::class)->write($field, $html, $context))
        ->toThrow(FieldWriteException::class, 'required')
    ;

    // Filament's own rule passes these, which is why the mapper checks them.
    expect($field->getValidationRules())->not->toContain('required');
})->with(['', '<p></p>', '<p>   </p>']);

it('accepts required rich text that has actual content', function () {
    $field = RichEditor::make('body')->required();
    $context = new WriteContext(McpResource::make(PostResource::class), null, 'admin');

    expect(app(RichEditorMapper::class)->write($field, '<p>Real</p>', $context))
        ->toContain('Real')
    ;
});

it('stores markdown verbatim', function () {
    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'Markdown',
            'status' => 'draft',
            'summary' => "## Heading\n\nSome **bold** text.",
        ])
    ;

    expect(Post::query()->value('summary'))->toBe("## Heading\n\nSome **bold** text.");
});
