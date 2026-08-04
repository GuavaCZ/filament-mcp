<?php

use Workbench\App\Models\Category;

/**
 * CategoryResource declares no getGloballySearchableAttributes(), so these
 * exercise the fallback that derives them from the form.
 */
it('searches the text fields of a resource that declares none', function () {
    Category::factory()->create(['name' => 'Needle']);
    Category::factory()->create(['name' => 'Haystack']);

    $data = $this->mcp('admin')->callJson('list_categories', ['search' => 'Needle']);

    expect($data['pagination']['total'])->toBe(1)
        ->and($data['records'][0]['name'])->toBe('Needle')
    ;
});

it('does not search non-text columns', function () {
    // The term only appears inside the json column. Matching it would mean the
    // fallback is LIKE-ing a non-text column, which is meaningless here and an
    // error on Postgres.
    Category::factory()->create(['name' => 'Plain', 'meta' => ['secret' => 'Needle']]);

    $data = $this->mcp('admin')->callJson('list_categories', ['search' => 'Needle']);

    expect($data['pagination']['total'])->toBe(0);
});

it('returns everything when the search term matches nothing', function () {
    Category::factory()->count(3)->create();

    $data = $this->mcp('admin')->callJson('list_categories');

    expect($data['pagination']['total'])->toBe(3);
});
