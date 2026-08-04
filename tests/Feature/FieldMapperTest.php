<?php

use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Guava\FilamentMcp\Mcp\McpResource;
use Guava\FilamentMcp\McpPlugin;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Guava\FilamentMcp\Schema\Contracts\TransformsWrites;
use Guava\FilamentMcp\Schema\FieldMapperRegistry;
use Guava\FilamentMcp\Schema\SchemaTransposer;
use Guava\FilamentMcp\Schema\WriteContext;
use Guava\FilamentMcp\Tools\CreateRecordTool;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Workbench\App\Filament\Resources\PostResource;
use Workbench\App\Mcp\ShoutingTextMapper;
use Workbench\App\Models\Post;

it('keeps the mapper registry shared across resolutions', function () {
    app(FieldMapperRegistry::class)->register(TextInput::class, ShoutingTextMapper::class);

    expect(app(FieldMapperRegistry::class)->mapperFor(TextInput::make('x')))
        ->toBeInstanceOf(ShoutingTextMapper::class)
    ;
});

it('registers a mapper through the plugin', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    McpPlugin::get()->fieldMapper(TextInput::class, ShoutingTextMapper::class);

    expect(app(FieldMapperRegistry::class)->mapperFor(TextInput::make('x')))
        ->toBeInstanceOf(ShoutingTextMapper::class)
    ;
});

it('lets a mapper describe the field in the tool schema', function () {
    app(FieldMapperRegistry::class)->register(TextInput::class, ShoutingTextMapper::class);

    $schema = JsonSchema::object(fn ($schema) => app(SchemaTransposer::class)->transpose(
        McpResource::make(PostResource::class)->crud(),
        $schema,
    ))->toArray();

    expect($schema['properties']['title']['description'])->toStartWith('SHOUTED:');
});

it('runs write coercion before the record is filled', function () {
    app(FieldMapperRegistry::class)->register(TextInput::class, ShoutingTextMapper::class);

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('create_post', [
            'title' => 'quietly written',
            'status' => 'draft',
        ])
    ;

    expect(Post::query()->value('title'))->toBe('QUIETLY WRITTEN');
});

it('runs read transformation on the way back out', function () {
    app(FieldMapperRegistry::class)->register(TextInput::class, ShoutingTextMapper::class);

    $post = Post::factory()->create(['title' => 'stored']);

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('get_post', ['id' => $post->getKey()])
    ;

    expect($data['title'])->toBe('read:stored');
});

it('applies write coercion before mutateDataUsing sees the data', function () {
    app(FieldMapperRegistry::class)->register(TextInput::class, ShoutingTextMapper::class);

    $seen = null;

    // Rebuild the server with a resource that records what the callback got.
    Filament::setCurrentPanel($panel = Filament::getPanel('admin'));

    $mcpResource = McpResource::make(PostResource::class)
        ->crud()
        ->mutateDataUsing(function (array $data) use (&$seen): array {
            $seen = $data['title'];

            return $data;
        })
    ;

    $tool = new CreateRecordTool($mcpResource, 'admin');

    (fn () => $this->prepareData(['title' => 'lowercase']))->call($tool);

    expect($seen)->toBe('LOWERCASE');
});

it('reports a field write failure to the agent as a tool error', function () {
    app(FieldMapperRegistry::class)->register(TextInput::class, ExplodingMapper::class);

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('create_post', [
            'title' => 'anything',
            'status' => 'draft',
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toContain('deliberately refused')
    ;

    expect(Post::query()->count())->toBe(0);
});

class ExplodingMapper implements FieldMapper, TransformsWrites
{
    public function map(Field $field, Illuminate\Contracts\JsonSchema\JsonSchema $schema): Type
    {
        return $schema->string();
    }

    public function write(Field $field, mixed $value, WriteContext $context): mixed
    {
        throw FieldWriteException::make($field->getName(), 'deliberately refused');
    }
}
