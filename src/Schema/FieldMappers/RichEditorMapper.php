<?php

namespace Guava\FilamentMcp\Schema\FieldMappers;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Guava\FilamentMcp\Schema\Contracts\TransformsReads;
use Guava\FilamentMcp\Schema\Contracts\TransformsWrites;
use Guava\FilamentMcp\Schema\WriteContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Throwable;
use Tiptap\Editor;

/**
 * The agent always speaks HTML, in both directions.
 *
 * Internally a RichEditor stores either an HTML string or a TipTap document
 * array, depending on `->json()` and the model's rich content registration.
 * This mapper converts both ways and keeps that detail server-side rather than
 * asking an agent to produce a ProseMirror document.
 */
class RichEditorMapper implements FieldMapper, TransformsReads, TransformsWrites
{
    public function map(Field $field, JsonSchema $schema): Type
    {
        return $schema->string()
            ->description(trim(sprintf(
                '%s. Rich text as an HTML fragment, e.g. "<p>Hello <strong>world</strong></p>". '
                . 'Markup the editor does not support is removed when saved.',
                $field->getLabel(),
            )))
        ;
    }

    public function write(Field $field, mixed $value, WriteContext $context): mixed
    {
        /** @var RichEditor $field */
        if (blank($value)) {
            $this->assertNotRequired($field, '');

            return null;
        }

        if (! is_string($value)) {
            throw FieldWriteException::make(
                $field->getName(),
                'expected an HTML string.',
            );
        }

        $editor = $this->getEditor($field);

        try {
            $editor->setContent($value);
        } catch (Throwable $e) {
            throw FieldWriteException::make(
                $field->getName(),
                'the HTML could not be parsed: ' . $e->getMessage(),
            );
        }

        $this->assertNotRequired($field, $editor->getText());

        // Round-tripping through the editor is the sanitisation step: nodes and
        // marks with no matching TipTap extension do not survive it, so the
        // result matches what the real editor produces.
        // Mirrors RichEditorStateCast::get().
        return $this->isJson($field, $context->getModel())
            ? $editor->getDocument()
            : $editor->getHtml();
    }

    public function read(Field $field, mixed $value, Model $record): mixed
    {
        /** @var RichEditor $field */
        if (blank($value)) {
            return $value;
        }

        // Stored as a TipTap document, handed back as HTML.
        if (is_array($value)) {
            return $this->getEditor($field)->setContent($value)->getHtml();
        }

        return $value;
    }

    /**
     * Filament's own required rule is a closure that only fails for an array
     * matching the empty-document shape, so an empty HTML string such as "" or
     * "<p></p>" passes it untouched.
     *
     * @throws FieldWriteException
     */
    protected function assertNotRequired(RichEditor $field, string $text): void
    {
        if (filled(trim($text))) {
            return;
        }

        try {
            $isRequired = $field->isRequired();
        } catch (Throwable) {
            return;
        }

        if ($isRequired) {
            throw FieldWriteException::make($field->getName(), 'it is required and the content is empty.');
        }
    }

    protected function getEditor(RichEditor $field): Editor
    {
        try {
            return $field->getTipTapEditor();
        } catch (Throwable) {
            // The field's own editor reaches for configuration that may need a
            // mounted Livewire component. A default renderer parses the same
            // core node and mark set.
            return RichContentRenderer::make()->getEditor();
        }
    }

    /**
     * Whether this field stores a TipTap document rather than HTML.
     *
     * The model is consulted as well as the field, because `RichEditor::isJson()`
     * falls back to the model's rich content registration and that lookup needs a
     * model instance the headless schema does not have. Precedence differs from
     * Filament's in one case: an explicit `->json(false)` on a field whose model
     * registers JSON content resolves to JSON here.
     */
    protected function isJson(RichEditor $field, Model $model): bool
    {
        try {
            if ($field->isJson()) {
                return true;
            }
        } catch (Throwable) {
            // Fall through to the model.
        }

        if (! $model instanceof HasRichContent) {
            return false;
        }

        try {
            return (bool) $model->getRichContentAttribute($field->getName())?->isJson();
        } catch (Throwable) {
            return false;
        }
    }
}
