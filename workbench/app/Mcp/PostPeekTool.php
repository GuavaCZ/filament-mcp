<?php

namespace Workbench\App\Mcp;

use Guava\FilamentMcp\Concerns\InteractsWithFilamentContext;
use Guava\FilamentMcp\Enums\McpOperation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Workbench\App\Filament\Resources\PostResource;

/**
 * The reference shape for a custom tool that touches a resource: pre-authorize
 * before resolving, resolve through the resource's scoped query, then authorize
 * against the record, in the order the generated tools use.
 */
#[IsReadOnly]
class PostPeekTool extends Tool
{
    use InteractsWithFilamentContext;

    protected string $name = 'post_peek';

    protected string $description = 'Get the title and status of a post.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The primary key of the post.')->required(),
        ];
    }

    public function handle(Request $request): Response | ResponseFactory
    {
        $validated = $request->validate(['id' => ['required']]);

        if ($error = $this->preAuthorizeResource(PostResource::class, McpOperation::Get)) {
            return Response::error($error);
        }

        $post = $this->resolveRecordFor(PostResource::class, $validated['id']);

        if ($post === null) {
            return Response::error("No post found for key [{$validated['id']}].");
        }

        if ($error = $this->authorizeResource(PostResource::class, McpOperation::Get, $post)) {
            return Response::error($error);
        }

        return Response::structured([
            'title' => $post->title,
            'status' => $post->status,
        ]);
    }
}
