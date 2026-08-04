<?php

use Workbench\App\Models\Category;
use Workbench\App\Models\Post;

it('lists records with pagination', function () {
    Post::factory()->count(20)->create();

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('list_posts', ['per_page' => 100])
    ;

    // per_page is capped at the configured records per page (15).
    expect($data['pagination']['total'])->toBe(20)
        ->and($data['pagination']['per_page'])->toBe(15)
        ->and($data['records'])->toHaveCount(15)
    ;
});

it('searches records', function () {
    Post::factory()->create(['title' => 'A very unique needle']);
    Post::factory()->count(5)->create();

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('list_posts', ['search' => 'unique needle'])
    ;

    expect($data['pagination']['total'])->toBe(1)
        ->and($data['records'][0]['title'])->toBe('A very unique needle')
    ;
});

it('sorts records and rejects unknown sort attributes', function () {
    Post::factory()->create(['title' => 'Bravo']);
    Post::factory()->create(['title' => 'Alpha']);

    $client = $this->mcp('admin')->asToken(mcpToken());

    $data = $client->callJson('list_posts', ['sort' => 'title', 'direction' => 'asc']);

    expect($data['records'][0]['title'])->toBe('Alpha');

    $response = $client->call('list_posts', ['sort' => 'internal_notes']);

    expect($response->json('result.isError'))->toBeTrue();
});

it('hides model-hidden and form-less attributes from list output', function () {
    Post::factory()->create();

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('list_posts')
    ;

    expect($data['records'][0])->not->toHaveKey('internal_notes')
        ->and($data['records'][0])->toHaveKey('title')
        ->and($data['records'][0])->toHaveKey('id')
    ;
});

it('gets a single record', function () {
    $post = Post::factory()->create(['title' => 'Hello']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('get_post', ['id' => (string) $post->getKey()])
    ;

    expect($data['id'])->toBe($post->getKey())
        ->and($data['title'])->toBe('Hello')
    ;
});

it('returns an error for a missing record', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('get_post', ['id' => '999999'])
    ;

    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue();
});

it('creates a record through the form schema', function () {
    $category = Category::factory()->create();

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'Created via MCP',
            'status' => 'draft',
            'category_id' => $category->getKey(),
        ])
    ;

    expect($data['title'])->toBe('Created via MCP');

    $this->assertDatabaseHas('posts', ['title' => 'Created via MCP']);
});

it('rejects creating a record with missing required fields', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('create_post', ['content' => 'No title given'])
    ;

    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue();

    $this->assertDatabaseMissing('posts', ['content' => 'No title given']);
});

it('updates only the provided attributes', function () {
    $post = Post::factory()->create(['title' => 'Original', 'status' => 'draft']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('update_post', [
            'id' => (string) $post->getKey(),
            'status' => 'published',
        ])
    ;

    expect($data['status'])->toBe('published')
        ->and($data['title'])->toBe('Original')
    ;
});

it('deletes a record when the policy allows it', function () {
    $post = Post::factory()->create();

    $data = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => true])))
        ->callJson('delete_post', ['id' => (string) $post->getKey()])
    ;

    expect($data['deleted'])->toBeTrue();

    $this->assertDatabaseMissing('posts', ['id' => $post->getKey()]);
});
