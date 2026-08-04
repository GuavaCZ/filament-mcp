<?php

namespace Workbench\App\Mcp;

use Guava\FilamentMcp\Concerns\InteractsWithFilamentContext;
use Guava\FilamentMcp\Concerns\RequiresAuthentication;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Workbench\App\Models\Post;

#[IsReadOnly]
class PostStatsTool extends Tool
{
    use InteractsWithFilamentContext;

    // Keeps the tool out of a guest's tool list. The check in handle() stays,
    // since this controls visibility rather than authorization.
    use RequiresAuthentication;

    protected string $name = 'post_stats';

    protected string $description = 'Get post counts grouped by status.';

    public function handle(Request $request): Response | ResponseFactory
    {
        if ($this->user() === null) {
            return Response::error('Unauthenticated.');
        }

        return Response::structured([
            'panel' => $this->panel()?->getId(),
            'counts' => Post::query()
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
        ]);
    }
}
