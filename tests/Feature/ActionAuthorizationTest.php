<?php

use Workbench\App\Models\Post;

it('enforces the model policy on an action that declares no authorize()', function () {
    // PostPolicy::delete() allows admins only. Headlessly there is no Livewire
    // host to resolve Filament's default authorization, so without an explicit
    // policy check DeleteAction would run for anyone.
    $post = Post::factory()->create();

    $response = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => false])))
        ->call('remove_post', ['id' => (string) $post->getKey()])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('not authorized')
        ->and(Post::query()->whereKey($post->getKey())->exists())->toBeTrue()
    ;
});

it('allows the same action when the policy allows it', function () {
    $post = Post::factory()->create();

    $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => true])))
        ->callJson('remove_post', ['id' => (string) $post->getKey()])
    ;

    expect(Post::query()->whereKey($post->getKey())->exists())->toBeFalse();
});

it('passes only declared arguments to the action', function () {
    $post = Post::factory()->create(['status' => 'draft', 'is_featured' => false]);

    // publish_post declares no schema beyond `id`, so nothing else may reach
    // the action's $arguments.
    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('publish_post', [
            'id' => (string) $post->getKey(),
            'is_featured' => true,
        ])
    ;

    expect($post->refresh()->is_featured)->toBeFalse();
});

it('does not leak the exception message when an action throws', function () {
    $post = Post::factory()->create();

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('explode_post', ['id' => (string) $post->getKey()])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->not->toContain('database credentials')
    ;
});
