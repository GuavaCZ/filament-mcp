<?php

use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\Schema\SchemaTransposer;
use Illuminate\JsonSchema\JsonSchema;
use Workbench\App\Filament\Resources\PostResource;

function transposePost(McpResource $mcpResource, bool $partial = false): array
{
    return JsonSchema::object(
        fn ($schema) => app(SchemaTransposer::class)->transpose($mcpResource, $schema, $partial),
    )->toArray();
}

it('transposes form fields to a JSON schema', function () {
    $schema = transposePost(McpResource::make(PostResource::class));

    expect($schema['properties']['title']['type'])->toBe('string')
        ->and($schema['properties']['is_featured']['type'])->toBe('boolean')
        ->and($schema['properties']['status']['enum'])->toBe(['draft', 'published', 'archived'])
        ->and($schema['properties']['published_at']['format'])->toBe('date-time')
        ->and($schema['required'])->toContain('title', 'status')
    ;
});

it('omits required in partial mode', function () {
    $schema = transposePost(McpResource::make(PostResource::class), partial: true);

    expect($schema)->not->toHaveKey('required');
});

it('excludes non-dehydrated fields', function () {
    $schema = transposePost(McpResource::make(PostResource::class));

    expect($schema['properties'])->not->toHaveKey('secret_token');
});

it('applies field allow and deny lists', function () {
    $only = transposePost(McpResource::make(PostResource::class)->fields(['title']));

    expect(array_keys($only['properties']))->toBe(['title']);

    $without = transposePost(McpResource::make(PostResource::class)->hiddenFields(['content']));

    expect($without['properties'])->not->toHaveKey('content')
        ->and($without['properties'])->toHaveKey('title')
    ;
});

it('compiles validation rules from the form', function () {
    $rules = app(SchemaTransposer::class)->getValidationRules(
        McpResource::make(PostResource::class),
        ['title' => 'x'],
    );

    expect($rules['title'])->toContain('required')
        ->and($rules['title'])->toContain('max:255')
    ;
});
