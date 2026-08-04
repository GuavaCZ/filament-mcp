<?php

namespace Guava\FilamentMcp\Schema\FieldMappers;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\TextInput;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

class TextInputMapper implements FieldMapper
{
    public function map(Field $field, JsonSchema $schema): Type
    {
        /** @var TextInput $field */
        if ($field->isNumeric()) {
            return $schema->number()
                ->description($field->getLabel())
            ;
        }

        $type = $schema->string()
            ->description($field->getLabel())
        ;

        if ($field->isEmail()) {
            $type->format('email');
        }

        if ($field->isUrl()) {
            $type->format('uri');
        }

        return $type;
    }
}
