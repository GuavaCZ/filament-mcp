<?php

namespace Guava\FilamentMcp\Schema\FieldMappers;

use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\Field;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Throwable;

/**
 * A CodeEditor stores a plain string. The configured language is named in the
 * description so the agent does not have to guess the syntax.
 */
class CodeEditorMapper implements FieldMapper
{
    public function map(Field $field, JsonSchema $schema): Type
    {
        return $schema->string()
            ->description(trim("{$field->getLabel()}. " . $this->describeLanguage($field)))
        ;
    }

    protected function describeLanguage(Field $field): string
    {
        if (! $field instanceof CodeEditor) {
            return 'Source code.';
        }

        try {
            $language = $field->getLanguage();
        } catch (Throwable) {
            return 'Source code.';
        }

        return $language === null
            ? 'Source code.'
            : "Source code written in {$language->value}.";
    }
}
