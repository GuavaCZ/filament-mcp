<?php

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\Schema\SchemaTransposer;
use Guava\FilamentMcp\Support\SchemaHost;
use Workbench\App\Filament\Resources\GuardedNoteResource;
use Workbench\App\Filament\Resources\PostResource;
use Workbench\App\Models\GuardedNote;
use Workbench\App\Models\Post;

it('lets an update keep its own value on a unique field', function () {
    $post = Post::factory()->create(['title' => 'Taken']);

    // Compiled without a record, unique(ignoreRecord: true) has nothing to ignore,
    // so the record collides with itself and an unchanged title is rejected.
    $rulesFor = function (?Post $record): array {
        $schema = Schema::make(app(SchemaHost::class))
            ->model($record ?? Post::class)
            ->when($record !== null, fn (Schema $schema): Schema => $schema->record($record))
            ->components([TextInput::make('title')->unique(ignoreRecord: true)])
        ;

        return $schema->getComponents()[0]->getValidationRules();
    };

    expect(validator(['title' => 'Taken'], ['title' => $rulesFor(null)])->fails())
        ->toBeTrue()
    ;

    expect(validator(['title' => 'Taken'], ['title' => $rulesFor($post)])->fails())
        ->toBeFalse()
    ;
});

it('binds the record when compiling rules for an update', function () {
    $post = Post::factory()->create(['title' => 'Taken']);

    $bound = app(SchemaTransposer::class)->getFormFields(PostResource::class, $post);
    $unbound = app(SchemaTransposer::class)->getFormFields(PostResource::class);

    // The record has to reach the field, which is what unique(ignoreRecord:) and
    // any other record-aware rule depends on.
    expect($bound['title']->getRecord()?->getKey())->toBe($post->getKey())
        ->and($unbound['title']->getRecord())->toBeNull()
    ;
});

it('does not cache record bound fields between records', function () {
    $a = Post::factory()->create();
    $b = Post::factory()->create();

    $forA = app(SchemaTransposer::class)->getFormFields(PostResource::class, $a);
    $forB = app(SchemaTransposer::class)->getFormFields(PostResource::class, $b);

    expect($forA['title']->getRecord()->getKey())->toBe($a->getKey())
        ->and($forB['title']->getRecord()->getKey())->toBe($b->getKey())
    ;
});

it('refuses to write an attribute the model would silently discard', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('create_guarded_note', [
            'title' => 'Fine',
            'secret' => 'should not be silently dropped',
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))
        ->toContain('[secret]')
        ->toContain('mass assignable')
        ->toContain('$fillable')
    ;

    expect(GuardedNote::query()->count())->toBe(0);
});

it('still writes attributes the model does allow', function () {
    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_guarded_note', ['title' => 'Fine'])
    ;

    expect(GuardedNote::query()->value('title'))->toBe('Fine');
});

it('reports the same failure on update', function () {
    $note = GuardedNote::query()->create(['title' => 'Existing']);

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('update_guarded_note', [
            'id' => $note->getKey(),
            'secret' => 'nope',
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('mass assignable')
    ;
});

it('omits a field whose rules cannot be compiled rather than writing it unvalidated', function () {
    $rules = app(SchemaTransposer::class)->getValidationRules(
        McpResource::make(GuardedNoteResource::class)->crud(),
        ['title' => 'x', 'explodes' => 'unvalidated value'],
    );

    // The field's getValidationRules() throws, so it must not appear at all. An
    // empty rule array would let the value through unchecked.
    expect($rules)->toHaveKey('title')
        ->and($rules)->not->toHaveKey('explodes')
    ;

    // Validator::validated() only returns keys that had rules, so the value never
    // reaches the model.
    expect(array_keys(validator(['title' => 'x', 'explodes' => 'boom'], $rules)->validate()))
        ->toBe(['title'])
    ;
});
