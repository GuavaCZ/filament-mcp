<?php

namespace Guava\FilamentMcp\Http\Controllers;

use Guava\FilamentMcp\Models\McpUpload;
use Guava\FilamentMcp\Support\MimeSniffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Receives the bytes for an upload staged by `request_upload`.
 *
 * Authorisation is the signature on the URL, which is bound to the upload id
 * and expires with it. No session or bearer token is involved, so the agent
 * can hand the URL straight to curl without leaking a credential.
 */
class McpUploadController
{
    public function __invoke(Request $request, string $upload): JsonResponse
    {
        $record = McpUpload::query()->find($upload);

        if ($record === null || $record->isExpired() || $record->isConsumed()) {
            return response()->json(['message' => 'This upload URL is no longer valid.'], 410);
        }

        if ($record->hasFile()) {
            return response()->json(['message' => 'This upload URL has already been used.'], 409);
        }

        $maxBytes = ((int) config('filament-mcp.uploads.max_size', 12288)) * 1024;

        // Cheapest check first, so a client declaring an oversized body is turned
        // away before a byte of it is read.
        if ($this->declaresMoreThan($request, $maxBytes)) {
            return $this->tooLarge();
        }

        $contents = $this->getContents($request, $maxBytes);

        if ($contents === false) {
            return $this->tooLarge();
        }

        if ($contents === null || $contents === '') {
            return response()->json(['message' => 'No file contents were received.'], 422);
        }

        // Sniffed from the bytes, never from the request's Content-Type, which
        // the uploader controls. The field's accepted-types check depends on it.
        $mimeType = MimeSniffer::sniff($contents) ?? 'application/octet-stream';

        $disk = config('filament-mcp.uploads.disk') ?: config('filesystems.default', 'local');
        $directory = trim((string) config('filament-mcp.uploads.directory', 'mcp-uploads'), '/');

        // Both halves of the staged name are ours: the id, and the extension from
        // the sniffed type. The original filename stays metadata and never
        // reaches the disk.
        $path = trim(
            $directory . '/' . $record->getKey() . '.' . MimeSniffer::storedExtensionFor($mimeType),
            '/',
        );

        Storage::disk($disk)->put($path, $contents, ['visibility' => 'private']);

        $record->forceFill([
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => strlen($contents),
            'original_name' => $record->original_name ?? $this->getClientName($request),
            'uploaded_at' => now(),
        ])->save();

        return response()->json([
            'handle' => $record->getHandle(),
            'mime_type' => $mimeType,
            'size' => strlen($contents),
        ]);
    }

    /**
     * Accepts both a raw body (PUT, what the tool tells the agent to do) and a
     * conventional multipart form upload, since some clients only do the latter.
     *
     * @return string|false|null false when the body is over the limit.
     */
    protected function getContents(Request $request, int $maxBytes): string | false | null
    {
        if ($request->hasFile('file')) {
            $file = $request->file('file');

            if (! $file->isValid()) {
                return null;
            }

            // PHP already wrote this one to disk under its own limits, so the
            // size is known without reading it.
            if ($file->getSize() > $maxBytes) {
                return false;
            }

            return (string) file_get_contents($file->getRealPath());
        }

        return $this->readBody($request, $maxBytes);
    }

    /**
     * Read the body a chunk at a time, stopping the moment it goes over.
     *
     * `$request->getContent()` would pull the whole thing into a string before the
     * size check runs, by which point the memory is spent. PHP bounds a POST body
     * with `post_max_size`, but a raw PUT body reaches `php://input` unmetered,
     * leaving `memory_limit` and a 500 as the only backstop.
     *
     * @return string|false false when the body is over the limit.
     */
    protected function readBody(Request $request, int $maxBytes): string | false
    {
        // Symfony hands back php://input in production and a stream over the
        // already-set content under test, which read the same way.
        $stream = $request->getContent(asResource: true);

        if (! is_resource($stream)) {
            return '';
        }

        $contents = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $contents .= $chunk;

                if (strlen($contents) > $maxBytes) {
                    return false;
                }
            }
        } finally {
            fclose($stream);
        }

        return $contents;
    }

    /**
     * Whether the client's own Content-Length says the body is too big.
     *
     * Only a hint: absent on a chunked body and a lie on a hostile one, which is
     * why the read is capped as well.
     */
    protected function declaresMoreThan(Request $request, int $maxBytes): bool
    {
        $length = $request->server('CONTENT_LENGTH');

        return is_numeric($length) && (int) $length > $maxBytes;
    }

    protected function tooLarge(): JsonResponse
    {
        return response()->json(['message' => 'The file exceeds the maximum upload size.'], 413);
    }

    protected function getClientName(Request $request): ?string
    {
        if ($request->hasFile('file')) {
            return Str::limit($request->file('file')->getClientOriginalName(), 250, '');
        }

        return null;
    }
}
