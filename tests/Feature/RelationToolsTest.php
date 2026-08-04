<?php

use Workbench\App\Models\Category;
use Workbench\App\Models\Post;

it('registers a relation tool', function () {
    $tools = $this->mcp('admin')->asToken(mcpToken())->toolNames();

    expect($tools)->toContain('list_category_posts');
});

it('does not register a relation whose model is not an mcp resource', function () {
    // Posts declare a `tags` relation, but Tag is not exposed on this panel, so
    // there is no field visibility or policy to serialise it through.
    $tools = $this->mcp('admin')->asToken(mcpToken())->toolNames();

    expect($tools)->not->toContain('list_post_tags');
});

it('lists related records with pagination', function () {
    $category = Category::factory()
        ->has(Post::factory()->count(3))
        ->create()
    ;

    Post::factory()->count(2)->create();

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('list_category_posts', [
            'id' => (string) $category->getKey(),
        ])
    ;

    expect($data['pagination']['total'])->toBe(3)
        ->and($data['records'])->toHaveCount(3)
        ->and($data['records'][0])->toHaveKey('title')
        ->and($data['records'][0])->not->toHaveKey('internal_notes')
    ;
});

it('does not let a public parent expose a private relation to guests', function () {
    // Categories are public, posts are not. Reading posts through the relation
    // has to require what list_posts requires.
    $category = Category::factory()
        ->has(Post::factory()->count(3))
        ->create()
    ;

    $response = $this->mcp('admin')->call('list_category_posts', [
        'id' => (string) $category->getKey(),
    ]);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('Unauthenticated')
    ;
});

it('requires the related resources own ability, not just the parents', function () {
    $category = Category::factory()
        ->has(Post::factory()->count(3))
        ->create()
    ;

    $response = $this->mcp('admin')
        ->asToken(mcpToken(abilities: ['categories:*']))
        ->call('list_category_posts', ['id' => (string) $category->getKey()])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('posts:list')
    ;
});

it('errors on a missing parent record', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('list_category_posts', ['id' => '999'])
    ;

    expect($response->json('result.isError'))->toBeTrue();
});
