<?php

use Workbench\App\Models\Post;

it('applies the fields dehydrateStateUsing callback before storing', function () {
    // The panel would not store this value as sent, and neither may MCP. This is
    // the ->password()->dehydrateStateUsing(Hash::make) shape in miniature.
    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'Dehydrated',
            'status' => 'draft',
            'sealed_note' => 'plaintext',
        ])
    ;

    expect(Post::query()->value('sealed_note'))->toBe('sealed:plaintext');
});

it('applies dehydration on update as well as create', function () {
    $post = Post::factory()->create(['sealed_note' => null]);

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('update_post', [
            'id' => (string) $post->getKey(),
            'sealed_note' => 'changed',
        ])
    ;

    expect($post->refresh()->sealed_note)->toBe('sealed:changed');
});

it('drops a nested value whose rules cannot be compiled', function () {
    // `volatile` throws while its rules are built, so it can never be validated.
    // The nested path has to drop it like the top-level one does, rather than
    // writing it into the JSON column unchecked.
    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'Nested',
            'status' => 'draft',
            'annotations' => [
                ['note' => 'kept', 'volatile' => 'must not be stored'],
            ],
        ])
    ;

    $annotations = Post::query()->value('annotations');

    expect($annotations)->toBe([['note' => 'kept']]);
});
