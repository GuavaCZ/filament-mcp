<?php

namespace Guava\FilamentMcp\Schema\FieldMappers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\TimePicker;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

class DateTimeMapper implements FieldMapper
{
    public function map(Field $field, JsonSchema $schema): Type
    {
        $type = $schema->string()
            ->description($field->getLabel())
        ;

        if ($field instanceof TimePicker) {
            return $type->format('time');
        }

        if ($field instanceof DatePicker && ! $field->hasTime()) {
            return $type->format('date');
        }

        return $type->format('date-time');
    }
}
