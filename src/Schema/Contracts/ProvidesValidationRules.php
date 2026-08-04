<?php

namespace Guava\FilamentMcp\Schema\Contracts;

use Filament\Forms\Components\Field;

/**
 * Implemented by a {@see FieldMapper} whose field's own validation rules do
 * not describe what an agent is allowed to send.
 *
 * `BaseFileUpload` is the motivating case. Its rules assume Livewire's state
 * shape (an array of `TemporaryUploadedFile` keyed by uuid) and so reject the
 * upload handle or URL an agent sends before it can be resolved. The mapper
 * substitutes rules matching the wire format and enforces the real constraints
 * once it has the bytes.
 */
interface ProvidesValidationRules
{
    /**
     * @return array<int, mixed>
     */
    public function rules(Field $field): array;
}
