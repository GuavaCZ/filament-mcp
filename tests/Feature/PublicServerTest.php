<?php

use Workbench\App\Models\Category;
use Workbench\App\Models\Post;

/**
 * The 'public' panel marks the whole server public at the plugin level, meaning
 * guests are welcome, not that credentials stop being read.
 */
it('serves public resources to a guest', function () {
    Category::factory()->count(2)->create();

    $data = $this->mcp('public')->callJson('list_categories');

    expect($data['pagination']['total'])->toBe(2);
});

it('hides non-public resources from a guest', function () {
    $tools = $this->mcp('public')->toolNames();

    expect($tools)->toContain('list_categories')
        ->not->toContain('list_posts')
    ;
});

it('still authenticates a token on a public server', function () {
    $tools = $this->mcp('public')
        ->asToken(mcpToken(panelId: 'public'))
        ->toolNames()
    ;

    // Without authentication running here, a valid token would be invisible
    // and every private resource on this panel unreachable.
    expect($tools)->toContain('list_posts')
        ->and($tools)->toContain('list_categories')
    ;
});

it('allows a token holder to write on a public server', function () {
    $data = $this->mcp('public')
        ->asToken(mcpToken(panelId: 'public'))
        ->callJson('create_post', ['title' => 'Written with a token', 'status' => 'draft'])
    ;

    expect($data['title'])->toBe('Written with a token')
        ->and(Post::query()->where('title', 'Written with a token')->exists())->toBeTrue()
    ;
});

it('refuses an invalid token on a public server rather than downgrading it', function () {
    $response = $this->mcp('public')
        ->asToken('gmcp_999|nope')
        ->listTools()
    ;

    expect($response->status())->toBe(401);
});
