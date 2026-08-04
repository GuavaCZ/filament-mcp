<?php

namespace Guava\FilamentMcp\Schema\Contracts;

use Filament\Forms\Components\Field;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

interface FieldMapper
{
    public function map(Field $field, JsonSchema $schema): Type;
}
