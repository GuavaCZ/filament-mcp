<?php

namespace Workbench\App\Mcp;

use Filament\Forms\Components\Field;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Guava\FilamentMcp\Schema\Contracts\TransformsReads;
use Guava\FilamentMcp\Schema\Contracts\TransformsWrites;
use Guava\FilamentMcp\Schema\WriteContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;

/**
 * Exercises all three mapper contracts at once. Only used by the test suite.
 */
class ShoutingTextMapper implements FieldMapper, TransformsReads, TransformsWrites
{
    public function map(Field $field, JsonSchema $schema): Type
    {
        return $schema->string()
            ->description('SHOUTED: ' . $field->getLabel())
        ;
    }

    public function write(Field $field, mixed $value, WriteContext $context): mixed
    {
        return is_string($value) ? strtoupper($value) : $value;
    }

    public function read(Field $field, mixed $value, Model $record): mixed
    {
        return is_string($value) ? "read:{$value}" : $value;
    }
}
