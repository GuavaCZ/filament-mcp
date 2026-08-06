---
title: Uploads
---

# Uploads

The upload path is the part of this package with the most attacker-reachable surface, so it's worth knowing what it does.

**The extension of a stored file always comes from its sniffed type.** Never from the filename, because on this path the filename is a tool argument the agent chose. A type the package has no name for is stored as `.bin` rather than under a name it asked for.

This matters more than it sounds. A file called `photo.php` containing a valid BMP header followed by PHP code sniffs as `image/bmp`, satisfies an `->image()` field's accepted types, and, stored under the name it came with on a public disk behind a stock nginx config that hands anything ending in `.php` to FPM, is remote code execution. Accepted-types checks do not prevent this; only the extension does.

If your app handles a format the package does not name, add it:

```php
// config/filament-mcp.php
'uploads' => [
    'extensions' => ['application/x-sqlite3' => 'sqlite'],
],
```

**`preserveFilenames()` keeps the stem, not the extension.** The name is reduced to `[A-Za-z0-9_-]`, which also removes inner dots, so `report.php.jpg` becomes `report-php.png` if the bytes are a PNG. Inner dots matter because Apache maps *every* extension in a name to a handler, so a name ending in something harmless can still execute.

**Mime types are sniffed from the bytes**, never read from a `Content-Type` header or a filename, at both the staging endpoint and the field write.

**Sizes are capped while reading**, not after. A body is rejected on its declared `Content-Length` before a byte is read, and the read itself stops the moment it passes the limit.

**The staging endpoint is authorised by URL signature**, bound to one upload id, expiring with it, single use, and claimable only by the user who requested it. It carries no token, so an agent can pass it to `curl` without leaking a credential into a subprocess. It's throttled by IP, which is all there is to key on when there is no token.

## SVG

`->image()` accepts `image/*`, and that includes `image/svg+xml`. An SVG is a document: it can carry script, and it runs when someone opens the stored file directly. On a public disk that's a same-origin XSS primitive.

So the package does not put SVG in its default extension map. Bytes that sniff as `image/svg+xml` pass an `->image()` field's validation, but are stored as `.bin`, which no browser renders as a document. If your app genuinely needs stored SVGs, and logo uploads are the classic case, opt back in knowingly:

```php
// config/filament-mcp.php
'uploads' => [
    'extensions' => ['image/svg+xml' => 'svg'],
],
```

If the field writes to a public disk, prefer naming the types you actually want instead:

```php
FileUpload::make('image')
    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
```

The accepted-types concern applies to filament generally, not just to MCP, but an agent is a much more willing uploader than a person.

## Fetching URLs

A file field also accepts an https URL for the server to download, which is server-side request forgery by construction. It's guarded by default: private, loopback, link-local and reserved addresses are refused, every redirect hop is re-checked, and the connection is pinned to the address that was checked so DNS cannot change under it. See [fetching a URL](../files/02-remote-urls.md).

Set `remote_files.enabled` to `false` if you would rather require uploads.

## Why there is no file path parameter

See [stored files](../files/03-stored-files.md#why-not-a-file-path). Briefly: an agent can only ever send bytes it already had. A path parameter would make your server read on its behalf, which turns a prompt injection into a local file read with a public sink.
