<?php

namespace Guava\FilamentMcp\Schema\FieldMappers;

use Filament\Forms\Components\Field;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * A MarkdownEditor stores a plain Markdown string with no state casts, so the
 * only thing missing was telling the agent it is Markdown and not prose.
 */
class MarkdownEditorMapper implements FieldMapper
{
    public function map(Field $field, JsonSchema $schema): Type
    {
        return $schema->string()
            ->description("{$field->getLabel()}. Markdown, e.g. \"## Heading\\n\\nSome **bold** text.\"")
        ;
    }
}
