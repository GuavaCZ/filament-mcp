<?php

it('lists only public tools for guests', function () {
    $tools = $this->mcp('admin')->toolNames();

    expect($tools)->toContain('list_categories', 'get_category')
        ->not->toContain('list_posts', 'get_post', 'create_post', 'update_post', 'delete_post')
    ;
});

it('lists all tools for authenticated users', function () {
    $tools = $this->mcp('admin')
        ->asToken(mcpToken())
        ->toolNames()
    ;

    expect($tools)->toContain(
        'list_posts',
        'get_post',
        'create_post',
        'update_post',
        'delete_post',
        'list_categories',
        'get_category',
    );
});

it('exposes correct annotations on the tools', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->listTools()
    ;

    $tools = collect($response->json('result.tools'))->keyBy('name');

    expect($tools['list_posts']['annotations']['readOnlyHint'] ?? null)->toBeTrue()
        ->and($tools['delete_post']['annotations']['destructiveHint'] ?? null)->toBeTrue()
    ;
});

it('describes the create_post input schema from the form', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->listTools()
    ;

    $schema = collect($response->json('result.tools'))
        ->keyBy('name')
        ->get('create_post')['inputSchema']
    ;

    expect($schema['properties'])->toHaveKeys(['title', 'content', 'status', 'is_featured', 'published_at'])
        ->and($schema['properties'])->not->toHaveKey('secret_token')
        ->and($schema['required'])->toContain('title', 'status')
        ->and($schema['properties']['status']['enum'])->toBe(['draft', 'published', 'archived'])
        ->and($schema['properties']['is_featured']['type'])->toBe('boolean')
    ;
});
