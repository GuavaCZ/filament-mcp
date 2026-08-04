<?php

namespace Guava\FilamentMcp\Support;

/**
 * Bytes that have been fetched and vetted, but not yet stored anywhere.
 */
class FetchedFile
{
    public function __construct(
        public readonly string $contents,
        public readonly string $mimeType,
        public readonly ?string $originalName = null,
    ) {}

    public function getSize(): int
    {
        return strlen($this->contents);
    }

    /**
     * Kilobytes, matching Laravel's `max:`/`min:` validation units.
     */
    public function getSizeInKilobytes(): int
    {
        return (int) ceil($this->getSize() / 1024);
    }
}
