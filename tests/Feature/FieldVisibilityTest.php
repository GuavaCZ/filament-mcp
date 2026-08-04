<?php

use Workbench\App\Models\Post;

/**
 * `admin_note` is gated on `is_admin` by PostResource's form. The write path
 * keeps hidden fields out; these cover the read path doing the same.
 */
it('does not serialise a role-hidden field to a non-admin', function () {
    $post = Post::factory()->create(['admin_note' => 'eyes only']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => false])))
        ->callJson('get_post', ['id' => (string) $post->getKey()])
    ;

    expect($data)->not->toHaveKey('admin_note')
        ->and($data)->toHaveKey('title')
    ;
});

it('does not serialise a role-hidden field to a non-admin when listing', function () {
    Post::factory()->create(['admin_note' => 'eyes only']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => false])))
        ->callJson('list_posts')
    ;

    expect($data['records'][0])->not->toHaveKey('admin_note');
});

it('serialises the field to a user who may see it', function () {
    $post = Post::factory()->create(['admin_note' => 'eyes only']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => true])))
        ->callJson('get_post', ['id' => (string) $post->getKey()])
    ;

    expect($data['admin_note'])->toBe('eyes only');
});

it('keeps a disabled but visible field readable', function () {
    // Disabled means "you may not write this", not "you may not see it", and the
    // read path must not conflate the two.
    $post = Post::factory()->create(['title' => 'Readable']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => true])))
        ->callJson('get_post', ['id' => (string) $post->getKey()])
    ;

    expect($data['title'])->toBe('Readable');
});
