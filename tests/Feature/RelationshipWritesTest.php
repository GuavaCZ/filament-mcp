<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\Post;
use Workbench\App\Models\Tag;

it('offers a syncable relationship field in the write schema', function () {
    $schema = collect($this->mcp('admin')->asToken(mcpToken())->listTools()->json('result.tools'))
        ->keyBy('name')
        ->get('create_post')['inputSchema']
    ;

    expect($schema['properties'])->toHaveKey('tags')
        ->and($schema['properties']['tags']['type'])->toBe('array')
        ->and($schema['properties']['tags']['description'])->toContain('Tag')
    ;
});

it('syncs a relationship on create', function () {
    $tags = Tag::factory()->count(3)->create();

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'Tagged',
            'status' => 'draft',
            'tags' => [(string) $tags[0]->getKey(), (string) $tags[2]->getKey()],
        ])
    ;

    expect(Post::query()->first()->tags->modelKeys())
        ->toBe([$tags[0]->getKey(), $tags[2]->getKey()])
    ;
});

it('syncs to exactly the given set on update', function () {
    $tags = Tag::factory()->count(3)->create();
    $post = Post::factory()->create();
    $post->tags()->sync([$tags[0]->getKey(), $tags[1]->getKey()]);

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('update_post', [
            'id' => (string) $post->getKey(),
            'tags' => [(string) $tags[2]->getKey()],
        ])
    ;

    expect($post->fresh()->tags->modelKeys())->toBe([$tags[2]->getKey()]);
});

it('detaches everything when given an empty list', function () {
    $tag = Tag::factory()->create();
    $post = Post::factory()->create();
    $post->tags()->sync([$tag->getKey()]);

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('update_post', [
            'id' => (string) $post->getKey(),
            'tags' => [],
        ])
    ;

    expect($post->fresh()->tags)->toBeEmpty();
});

it('leaves a relationship alone when it is not provided', function () {
    $tag = Tag::factory()->create();
    $post = Post::factory()->create();
    $post->tags()->sync([$tag->getKey()]);

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('update_post', [
            'id' => (string) $post->getKey(),
            'title' => 'Renamed',
        ])
    ;

    expect($post->fresh()->tags->modelKeys())->toBe([$tag->getKey()]);
});

it('rejects a key with no related record rather than writing a dangling pivot', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('create_post', [
            'title' => 'Bad tags',
            'status' => 'draft',
            'tags' => ['9999'],
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('no Tag exists with the key 9999')
        ->and(Post::query()->count())->toBe(0)
    ;
});

it('reports related keys on read', function () {
    $tags = Tag::factory()->count(2)->create();
    $post = Post::factory()->create();
    $post->tags()->sync($tags->modelKeys());

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('get_post', ['id' => (string) $post->getKey()])
    ;

    expect($data['tags'])->toBe($tags->modelKeys());
});

it('does not query the relation once per record when listing', function () {
    Post::factory()->count(5)->create()->each(
        fn (Post $post) => $post->tags()->sync([Tag::factory()->create()->getKey()]),
    );

    $pivotQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$pivotQueries): void {
        if (str_contains($query->sql, 'post_tag')) {
            $pivotQueries++;
        }
    });

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('list_posts', [])
    ;

    // One eager load for the whole page, not one lookup per record.
    expect($data['pagination']['total'])->toBe(5)
        ->and($pivotQueries)->toBe(1)
    ;
});
