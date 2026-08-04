<?php

namespace Guava\FilamentMcp\Support;

use finfo;

/**
 * Determines a mime type from the bytes themselves.
 *
 * A Content-Type header or file extension is not usable here, since both are
 * caller-controlled and the result decides what lands on a disk that may be
 * served publicly.
 */
class MimeSniffer
{
    /**
     * What a file is stored as when its type has no conventional extension.
     *
     * Inert by design: nothing a web server hands to an interpreter, and nothing
     * a browser renders as a document.
     */
    public const FALLBACK_EXTENSION = 'bin';

    public static function sniff(string $contents): ?string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        return $mime === false ? null : $mime;
    }

    public static function sniffFile(string $path): ?string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return $mime === false ? null : $mime;
    }

    /**
     * A conventional extension for a mime type, without the leading dot.
     *
     * Null for an unrecognised type. See `storedExtensionFor()` for what a file
     * is actually written as.
     */
    public static function extensionFor(string $mimeType): ?string
    {
        $mimeType = strtolower(trim($mimeType));

        /** @var array<string, mixed> $configured */
        $configured = (array) config('filament-mcp.uploads.extensions', []);

        $extension = $configured[$mimeType] ?? static::extensions()[$mimeType] ?? null;

        // A configured entry is app-supplied and could name anything, including
        // an extension with a dot or a slash in it. Only a plain one is used.
        return is_string($extension) && preg_match('/^[a-z0-9]{1,8}$/', strtolower($extension)) === 1
            ? strtolower($extension)
            : null;
    }

    /**
     * The extension a file with these bytes is stored under.
     *
     * Decided by the sniffed type and never by a filename, since on the MCP path
     * the filename is a tool argument the agent chose and the extension decides
     * whether a web server hands the file to an interpreter. An unrecognised type
     * is stored inert.
     */
    public static function storedExtensionFor(string $mimeType): string
    {
        return static::extensionFor($mimeType) ?? static::FALLBACK_EXTENSION;
    }

    /**
     * Types common enough to be worth naming, kept clear of anything executable.
     * No php, html, shell or script types, so those fall through to the inert
     * extension. SVG is excluded too: it matches an `->image()` field's `image/*`,
     * but a browser opening a stored `.svg` runs any script it carries, so it
     * stays `.bin` unless the app maps it back via
     * `filament-mcp.uploads.extensions`.
     *
     * @return array<string, string>
     */
    protected static function extensions(): array
    {
        return [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/bmp' => 'bmp',
            'image/x-ms-bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',

            'application/pdf' => 'pdf',
            'application/json' => 'json',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.oasis.opendocument.text' => 'odt',
            'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
            'application/rtf' => 'rtf',

            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'text/markdown' => 'md',

            'application/zip' => 'zip',
            'application/gzip' => 'gz',
            'application/x-tar' => 'tar',
            'application/x-7z-compressed' => '7z',
            'application/vnd.rar' => 'rar',

            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'weba',

            'font/woff' => 'woff',
            'font/woff2' => 'woff2',
            'font/ttf' => 'ttf',
            'font/otf' => 'otf',
        ];
    }

    /**
     * Whether a sniffed type satisfies an accepted-types list, honouring the
     * `image/*` wildcard form Filament allows.
     *
     * @param  array<int, string>  $accepted
     */
    public static function matches(string $mimeType, array $accepted): bool
    {
        foreach ($accepted as $candidate) {
            $candidate = strtolower(trim($candidate));

            if ($candidate === '' || $candidate === '*/*') {
                return true;
            }

            if ($candidate === strtolower($mimeType)) {
                return true;
            }

            if (str_ends_with($candidate, '/*')
                && str_starts_with(strtolower($mimeType), substr($candidate, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
