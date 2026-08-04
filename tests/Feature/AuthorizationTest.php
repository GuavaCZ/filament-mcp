<?php

use Workbench\App\Models\Category;
use Workbench\App\Models\Post;

it('denies operations the token abilities do not grant', function () {
    $post = Post::factory()->create();

    $client = $this->mcp('admin')->asToken(mcpToken(abilities: ['posts:list']));

    $data = $client->callJson('list_posts');

    expect($data['pagination']['total'])->toBe(1);

    $response = $client->call('get_post', ['id' => (string) $post->getKey()]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('posts:get')
    ;
});

it('grants all resource operations with a wildcard resource ability', function () {
    // The key is explicit because MySQL does not rewind auto-increment on the
    // transaction rollback between tests, so "the first record is id 1" only
    // holds on SQLite.
    $post = Post::factory()->create();

    $data = $this->mcp('admin')
        ->asToken(mcpToken(abilities: ['posts:*']))
        ->callJson('get_post', ['id' => (string) $post->getKey()])
    ;

    expect($data)->toHaveKey('title');
});

it('enforces the model policy per record', function () {
    $post = Post::factory()->create();

    // The workbench PostPolicy only allows delete for admins.
    $response = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => false])))
        ->call('delete_post', ['id' => (string) $post->getKey()])
    ;

    expect($response->json('result.isError'))->toBeTrue();

    $this->assertDatabaseHas('posts', ['id' => $post->getKey()]);
});

it('cannot call private tools as a guest', function () {
    Post::factory()->create();

    $response = $this->mcp('admin')->call('list_posts');

    $response->assertOk();

    expect($response->json('error.message'))->toContain('not found');
});

it('allows public resource tools as a guest', function () {
    Category::factory()->count(2)->create();

    $data = $this->mcp('admin')->callJson('list_categories');

    expect($data['pagination']['total'])->toBe(2);
});

it('rejects panel-scoped tokens on other panels', function () {
    Post::factory()->create();

    $response = $this->mcp('admin')
        ->asToken(mcpToken(panelId: 'other-panel'))
        ->call('list_posts')
    ;

    // The token is not valid for this panel. Even though this panel serves
    // guests, presenting a package token that does not authenticate is a 401
    // rather than a silent downgrade to anonymous access.
    expect($response->status())->toBe(401);
});
