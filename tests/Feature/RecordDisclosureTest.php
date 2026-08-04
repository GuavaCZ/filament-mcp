<?php

use Workbench\App\Models\Post;

/**
 * A caller who cannot use a tool must not be able to tell an existing record
 * apart from a missing one, or the tool becomes a way to enumerate keys.
 */
dataset('record tools', [
    'get' => ['get_post', 'posts:get'],
    'update' => ['update_post', 'posts:update'],
    'delete' => ['delete_post', 'posts:delete'],
    'action' => ['publish_post', 'posts:publish'],
]);

it('gives the same answer for a real and a missing record', function (string $tool, string $ability) {
    $post = Post::factory()->create();

    // A token lacking the ability this tool needs.
    $client = $this->mcp('admin')->asToken(mcpToken(abilities: ['posts:list']));

    $existing = $client->call($tool, ['id' => (string) $post->getKey()]);
    $missing = $client->call($tool, ['id' => '999999']);

    expect($existing->json('result.isError'))->toBeTrue()
        ->and($missing->json('result.isError'))->toBeTrue()
        ->and($existing->json('result.content.0.text'))
        ->toBe($missing->json('result.content.0.text'))
        ->and($existing->json('result.content.0.text'))->toContain($ability)
    ;
})->with('record tools');

it('does not reveal existence through an action a token cannot call', function () {
    $post = Post::factory()->create(['status' => 'draft']);

    $client = $this->mcp('admin')->asToken(mcpToken(abilities: ['posts:get']));

    $existing = $client->call('publish_post', ['id' => (string) $post->getKey()]);
    $missing = $client->call('publish_post', ['id' => '999999']);

    expect($existing->json('result.content.0.text'))
        ->toBe($missing->json('result.content.0.text'))
        // Refused before the lookup, so the action never ran either.
        ->and($post->refresh()->status)->toBe('draft')
    ;
});

it('still reports a missing record to a caller who may read it', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('get_post', ['id' => '999999'])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('No post record found')
    ;
});
