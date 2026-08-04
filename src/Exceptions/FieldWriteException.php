<?php

namespace Guava\FilamentMcp\Exceptions;

use Exception;

/**
 * Thrown by a field mapper when an incoming value cannot be written.
 *
 * The message is returned to the agent verbatim, so it must be safe to disclose
 * and specific enough for the agent to correct itself. Never put a filesystem
 * path, a token or an internal hostname in one.
 */
final class FieldWriteException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }

    public static function make(string $field, string $message): self
    {
        return new self("The [{$field}] field could not be written: {$message}", $field);
    }
}
