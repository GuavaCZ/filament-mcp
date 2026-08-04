<?php

use Workbench\App\Models\Post;

it('exposes marked and explicitly listed actions as tools', function () {
    $tools = $this->mcp('admin')
        ->asToken(mcpToken())
        ->toolNames()
    ;

    expect($tools)->toContain('publish_post', 'archive_post', 'feature_post')
        ->not->toContain('unsafe_action_post')
    ;
});

it('executes a marked action headlessly', function () {
    $post = Post::factory()->create(['status' => 'draft']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('publish_post', ['id' => (string) $post->getKey()])
    ;

    expect($data['record']['status'])->toBe('published')
        ->and($post->refresh()->status)->toBe('published')
    ;
});

it('executes an explicitly listed unmarked action', function () {
    $post = Post::factory()->create(['status' => 'draft']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('archive_post', ['id' => (string) $post->getKey()])
    ;

    expect($data['record']['status'])->toBe('archived');
});

it('denies actions the action itself does not authorize', function () {
    $post = Post::factory()->create(['is_featured' => false]);

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('feature_post', ['id' => (string) $post->getKey()])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($post->refresh()->is_featured)->toBeFalse()
    ;
});

it('denies actions the token abilities do not grant', function () {
    $post = Post::factory()->create(['status' => 'draft']);

    $response = $this->mcp('admin')
        ->asToken(mcpToken(abilities: ['posts:list']))
        ->call('publish_post', ['id' => (string) $post->getKey()])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('posts:publish')
        ->and($post->refresh()->status)->toBe('draft')
    ;
});

it('uses the mcp() description on the tool', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->listTools()
    ;

    $tool = collect($response->json('result.tools'))->keyBy('name')->get('publish_post');

    expect($tool['description'])->toContain('Publish the post.');
});
