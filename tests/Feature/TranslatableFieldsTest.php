<?php

use Workbench\App\Models\Post;

it('writes a translatable attribute under the current locale', function () {
    app()->setLocale('en');

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'Localised',
            'status' => 'draft',
            'intro' => 'Hello',
        ])
    ;

    // MySQL's JSON column normalises formatting, so the raw attribute cannot be
    // compared byte-for-byte across drivers. Decode it instead.
    expect(json_decode(Post::query()->first()->getAttributes()['intro'], true))
        ->toBe(['en' => 'Hello'])
    ;
});

it('leaves the other locales alone when updating one', function () {
    $post = Post::factory()->create([
        'intro' => ['en' => 'Hello', 'de' => 'Hallo'],
    ]);

    app()->setLocale('de');

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('update_post', [
            'id' => (string) $post->getKey(),
            'intro' => 'Guten Tag',
        ])
    ;

    // toEqual, not toBe: MySQL's JSON column does not preserve key order.
    expect($post->fresh()->getTranslations('intro'))
        ->toEqual(['en' => 'Hello', 'de' => 'Guten Tag'])
    ;
});

it('drops only the current locale when a translatable value is cleared', function () {
    $post = Post::factory()->create([
        'intro' => ['en' => 'Hello', 'de' => 'Hallo'],
    ]);

    app()->setLocale('de');

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('update_post', [
            'id' => (string) $post->getKey(),
            'intro' => null,
        ])
    ;

    expect($post->fresh()->getTranslations('intro'))->toBe(['en' => 'Hello']);
});

it('stores translatable rich content as a document per locale', function () {
    app()->setLocale('cs');

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'Localised rich text',
            'status' => 'draft',
            'body_i18n' => '<p>Ahoj <strong>světe</strong></p>',
        ])
    ;

    $translations = Post::query()->first()->getTranslations('body_i18n');

    // The locale map has to survive, since a mapper returning an array is what a
    // naive setAttribute() would mistake for the map itself.
    expect(array_keys($translations))->toBe(['cs'])
        ->and($translations['cs']['type'])->toBe('doc')
        ->and($translations['cs']['content'][0]['type'])->toBe('paragraph')
    ;
});

it('hands translatable rich content back as HTML per locale', function () {
    $post = Post::factory()->create([
        'body_i18n' => [
            'en' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Hello world']],
                ]],
            ],
        ],
    ]);

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('get_post', ['id' => (string) $post->getKey()])
    ;

    expect($data['body_i18n'])->toBe(['en' => '<p>Hello world</p>']);
});

it('round trips translatable rich text through create and read', function () {
    app()->setLocale('en');

    $created = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'Round trip',
            'status' => 'draft',
            'body_i18n' => '<p>First</p><p>Second</p>',
        ])
    ;

    expect($created['body_i18n'])->toBe(['en' => '<p>First</p><p>Second</p>']);
});
