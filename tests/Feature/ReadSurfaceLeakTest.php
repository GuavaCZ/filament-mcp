<?php

use Workbench\App\Models\Post;

/**
 * A field kept out of the output has to be kept out of `ORDER BY` and `WHERE` as
 * well. Neither prints the value, but both answer questions about it: a sort is a
 * comparison against every other row and a `LIKE` is a lookup one character at a
 * time. `admin_note` is role-hidden by PostResource's form and declared globally
 * searchable, so a non-admin exercises both here.
 */
it('refuses to sort by a field the caller may not read', function () {
    Post::factory()->create(['title' => 'A', 'admin_note' => 'zzz']);
    Post::factory()->create(['title' => 'B', 'admin_note' => 'aaa']);

    $response = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => false])))
        ->call('list_posts', ['sort' => 'admin_note', 'direction' => 'asc'])
    ;

    // The refusal lists what may be sorted by, so it must not name the field it
    // just refused. The message echoes the requested name once and nothing more.
    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('Cannot sort by [admin_note]')
        ->and(substr_count((string) $response->json('result.content.0.text'), 'admin_note'))->toBe(1)
    ;
});

it('still sorts by a field the caller may read', function () {
    Post::factory()->create(['title' => 'B']);
    Post::factory()->create(['title' => 'A']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => false])))
        ->callJson('list_posts', ['sort' => 'title', 'direction' => 'asc'])
    ;

    expect(array_column($data['records'], 'title'))->toBe(['A', 'B']);
});

it('lets an admin sort by the field they may read', function () {
    Post::factory()->create(['title' => 'A', 'admin_note' => 'zzz']);
    Post::factory()->create(['title' => 'B', 'admin_note' => 'aaa']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => true])))
        ->callJson('list_posts', ['sort' => 'admin_note', 'direction' => 'asc'])
    ;

    expect(array_column($data['records'], 'title'))->toBe(['B', 'A']);
});

it('does not match a search against a field the caller may not read', function () {
    Post::factory()->create(['title' => 'Alpha', 'admin_note' => 'Needle']);
    Post::factory()->create(['title' => 'Beta', 'admin_note' => 'Haystack']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => false])))
        ->callJson('list_posts', ['search' => 'Needle'])
    ;

    // Narrowing to one record would confirm which row holds the term. Matching
    // neither is the correct answer.
    expect($data['pagination']['total'])->toBe(0);
});

it('still matches a search against a field the caller may read', function () {
    Post::factory()->create(['title' => 'Needle']);
    Post::factory()->create(['title' => 'Haystack']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => false])))
        ->callJson('list_posts', ['search' => 'Needle'])
    ;

    expect($data['pagination']['total'])->toBe(1)
        ->and($data['records'][0]['title'])->toBe('Needle')
    ;
});

it('matches a search against the field for a caller who may read it', function () {
    Post::factory()->create(['title' => 'Alpha', 'admin_note' => 'Needle']);
    Post::factory()->create(['title' => 'Beta', 'admin_note' => 'Haystack']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['is_admin' => true])))
        ->callJson('list_posts', ['search' => 'Needle'])
    ;

    expect($data['pagination']['total'])->toBe(1)
        ->and($data['records'][0]['title'])->toBe('Alpha')
    ;
});
