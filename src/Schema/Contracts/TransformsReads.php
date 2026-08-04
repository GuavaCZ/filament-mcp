<?php

namespace Guava\FilamentMcp\Schema\Contracts;

use Filament\Forms\Components\Field;
use Illuminate\Database\Eloquent\Model;

/**
 * Implemented by a {@see FieldMapper} that needs to transform a stored value
 * before it is handed back to the agent.
 *
 * Turns storage-shaped values into something an agent can act on: a file path
 * into a URL, a rich content document into HTML.
 */
interface TransformsReads
{
    public function read(Field $field, mixed $value, Model $record): mixed;
}
