<?php

namespace Guava\FilamentMcp\Schema;

use Filament\Facades\Filament;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Guava\FilamentMcp\Schema\FieldMappers\BooleanMapper;
use Guava\FilamentMcp\Schema\FieldMappers\BuilderMapper;
use Guava\FilamentMcp\Schema\FieldMappers\CodeEditorMapper;
use Guava\FilamentMcp\Schema\FieldMappers\DateTimeMapper;
use Guava\FilamentMcp\Schema\FieldMappers\DefaultMapper;
use Guava\FilamentMcp\Schema\FieldMappers\FileUploadMapper;
use Guava\FilamentMcp\Schema\FieldMappers\KeyValueMapper;
use Guava\FilamentMcp\Schema\FieldMappers\MarkdownEditorMapper;
use Guava\FilamentMcp\Schema\FieldMappers\RepeaterMapper;
use Guava\FilamentMcp\Schema\FieldMappers\RichEditorMapper;
use Guava\FilamentMcp\Schema\FieldMappers\SelectMapper;
use Guava\FilamentMcp\Schema\FieldMappers\TextInputMapper;

class FieldMapperRegistry
{
    /**
     * @var array<class-string<Field>, class-string<FieldMapper>>
     */
    protected array $mappers = [
        TextInput::class => TextInputMapper::class,
        Select::class => SelectMapper::class,
        Toggle::class => BooleanMapper::class,
        Checkbox::class => BooleanMapper::class,
        DateTimePicker::class => DateTimeMapper::class,
        TimePicker::class => DateTimeMapper::class,
        BaseFileUpload::class => FileUploadMapper::class,
        Repeater::class => RepeaterMapper::class,
        Builder::class => BuilderMapper::class,
        KeyValue::class => KeyValueMapper::class,
        RichEditor::class => RichEditorMapper::class,
        MarkdownEditor::class => MarkdownEditorMapper::class,
        CodeEditor::class => CodeEditorMapper::class,
    ];

    /**
     * Registrations made by a panel's plugin, keyed by panel id.
     *
     * @var array<string, array<class-string<Field>, class-string<FieldMapper>>>
     */
    protected array $panelMappers = [];

    /**
     * @param  class-string<Field>  $fieldClass
     * @param  class-string<FieldMapper>  $mapperClass
     */
    public function register(string $fieldClass, string $mapperClass, ?string $panelId = null): void
    {
        // Plain assignment, not a spread. A spread would overwrite the string
        // key with the built-in mapper and the registration would do nothing.
        if ($panelId === null) {
            $this->mappers[$fieldClass] = $mapperClass;

            return;
        }

        $this->panelMappers[$panelId][$fieldClass] = $mapperClass;
    }

    public function mapperFor(Field $field): FieldMapper
    {
        // The current panel's registrations win, then application-wide ones, then
        // the built-ins, so two panels can map the same field type differently.
        $panelId = Filament::getCurrentPanel()?->getId();

        $mappers = [
            ...$this->mappers,
            ...($panelId === null ? [] : ($this->panelMappers[$panelId] ?? [])),
        ];

        $class = $field::class;

        while ($class !== false) {
            if (isset($mappers[$class])) {
                return app($mappers[$class]);
            }

            $class = get_parent_class($class);
        }

        return app(DefaultMapper::class);
    }
}
