<?php

namespace Guava\FilamentMcp\Schema\FieldMappers;

use Filament\Forms\Components\Field;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

class BooleanMapper implements FieldMapper
{
    public function map(Field $field, JsonSchema $schema): Type
    {
        return $schema->boolean()
            ->description($field->getLabel())
        ;
    }
}
