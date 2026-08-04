<?php

use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Post;
use Workbench\App\Policies\DenyEverythingPostPolicy;
use Workbench\App\Policies\ViewOnlyPostPolicy;

/**
 * In the panel, an action is reached by clicking a button on a rendered row, so
 * being able to see the record is the precondition for every action. Nothing
 * replays that precondition over MCP unless the action tool checks it itself.
 */
it('refuses every action on a record the user cannot view', function (string $tool) {
    Gate::policy(Post::class, DenyEverythingPostPolicy::class);

    $post = Post::factory()->create(['status' => 'draft', 'is_featured' => false]);

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call($tool, ['id' => (string) $post->getKey()])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('not authorized')
    ;

    $post->refresh();

    expect($post->status)->toBe('draft')
        ->and($post->is_featured)->toBeFalse()
    ;
})->with([
    // A custom action maps to no policy of its own, which means no elevation
    // beyond reading the record rather than no authorization at all.
    'custom action' => 'publish_post',
    'listed action' => 'archive_post',
    'built-in delete action' => 'remove_post',
]);

it('still allows an action on a record the user can view', function () {
    Gate::policy(Post::class, ViewOnlyPostPolicy::class);

    $post = Post::factory()->create(['status' => 'draft']);

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('publish_post', ['id' => (string) $post->getKey()])
    ;

    expect($post->refresh()->status)->toBe('published');
});

it('does not let a viewable record make a delete action bypass its own policy', function () {
    // view() allows and delete() does not, so the baseline passes and the
    // action's own mapping still has to hold.
    Gate::policy(Post::class, ViewOnlyPostPolicy::class);

    $post = Post::factory()->create();

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('remove_post', ['id' => (string) $post->getKey()])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and(Post::query()->whereKey($post->getKey())->exists())->toBeTrue()
    ;
});
