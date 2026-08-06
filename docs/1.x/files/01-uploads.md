---
title: Uploading a file
---

# Uploading a file

MCP has no channel for binary data in a tool call, and inlining a file as base64 is not workable: tool arguments are model output tokens, so a 200 KB image is on the order of 70 to 90k tokens. That's beyond what a model can reliably emit, and expensive when it can.

So bytes travel out of band. The agent asks for a URL, uploads to it directly, and passes back only a short handle:

```
1. call request_upload            -> { upload_url, handle, expires_at }
2. PUT the bytes to upload_url    (curl, out of band, costs no tokens)
3. call create_post with          { "thumbnail": "mcp-upload:01J..." }
```

The upload URL is a laravel signed URL bound to that one upload and expiring with it. It carries no session and no bearer token, so an agent can hand it straight to `curl` without leaking a credential into a subprocess.

The `request_upload` tool only appears when the server actually has a writable file field, and never for unauthenticated requests.

## Handles

Handles are single use, owned by the user who requested them, and expire after `filament-mcp.uploads.expires_after` minutes.

Nothing prunes the staging area for you, so put it on a schedule:

```php
// routes/console.php
Schedule::daily()->command('mcp:prune-uploads');
```

## Validation

Filament's own file rules only ever run against `TemporaryUploadedFile` instances, and plain strings are filtered out and validated not at all. This package therefore enforces the field's constraints itself, once it has the bytes: `acceptedFileTypes()` against the sniffed mime, `maxSize()` / `minSize()`, and `maxFiles()` / `minFiles()`.

See [uploads](../security/06-uploads.md) for why this path is stricter than filament's own.
