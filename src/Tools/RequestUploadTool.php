<?php

namespace Guava\FilamentMcp\Tools;

use Filament\Facades\Filament;
use Guava\FilamentMcp\Models\McpUpload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Hands back a short-lived URL the agent can upload bytes to.
 *
 * Tool arguments are model output tokens, so a base64 file is unworkable at any
 * realistic size (a 200 KB image runs to roughly 70k tokens). Uploading out of
 * band keeps the bytes out of the context window; only the handle travels back.
 */
class RequestUploadTool extends Tool
{
    protected string $name = 'request_upload';

    protected string $title = 'Request Upload';

    protected string $description = 'Get a short-lived URL for uploading a file, and the handle to pass to a file field afterwards. Upload the bytes with an HTTP PUT to the returned url, then use the returned handle as the value of the file field. Do not read the file into the conversation.';

    public function __construct(
        protected string $panelId,
    ) {}

    public function shouldRegister(Request $request): bool
    {
        // Uploads are always tied to a user, so a guessed handle cannot be
        // claimed out of an anonymous staging area.
        return $request->user() !== null;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filename' => $schema->string()
                ->description('The original filename, used to pick a sensible extension. Optional.'),
        ];
    }

    public function handle(Request $request): Response | ResponseFactory
    {
        $user = Filament::auth()->user();

        if (! $user instanceof Model) {
            return Response::error('Uploading requires an authenticated user.');
        }

        // The filename is agent-chosen input going into a string column. An
        // over-long one is a 500 on strict-mode MySQL and an array value is a
        // type error.
        $validated = $request->validate([
            'filename' => ['nullable', 'string', 'max:255'],
        ]);

        $minutes = (int) config('filament-mcp.uploads.expires_after', 60);

        $upload = McpUpload::query()->create([
            'uploadable_type' => $user->getMorphClass(),
            'uploadable_id' => $user->getKey(),
            'panel_id' => $this->panelId,
            'original_name' => filled($validated['filename'] ?? null)
                ? Str::limit($validated['filename'], 250, '')
                : null,
            'expires_at' => now()->addMinutes($minutes),
        ]);

        return Response::structured([
            'upload_url' => URL::temporarySignedRoute(
                'filament-mcp.uploads.store',
                $upload->expires_at,
                ['upload' => $upload->getKey()],
            ),
            'handle' => $upload->getHandle(),
            'expires_at' => $upload->expires_at->toIso8601String(),
            'method' => 'PUT',
            'instructions' => 'PUT the raw file bytes to upload_url, then pass handle as the value of the file field. The bytes must not be read into the conversation.',
        ]);
    }
}
