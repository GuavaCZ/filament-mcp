<?php

namespace Guava\FilamentMcp\Schema\Contracts;

use Filament\Forms\Components\Field;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Guava\FilamentMcp\Schema\WriteContext;

/**
 * Implemented by a {@see FieldMapper} that needs to transform an incoming
 * value before it is written to the model.
 *
 * The MCP write path is `$record->fill($data)`, so Filament's own state casts,
 * dehydration callbacks and Livewire-bound upload handling never run. Whatever
 * that pipeline would have done happens here instead.
 *
 * Throw {@see FieldWriteException} to reject a value; the message is returned
 * to the agent.
 */
interface TransformsWrites
{
    public function write(Field $field, mixed $value, WriteContext $context): mixed;
}
