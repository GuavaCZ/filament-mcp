<?php

namespace Guava\FilamentMcp\Schema\FieldMappers;

use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\Field;
use Guava\FilamentMcp\Exceptions\FieldWriteException;
use Guava\FilamentMcp\Exceptions\RemoteFetchException;
use Guava\FilamentMcp\Models\McpUpload;
use Guava\FilamentMcp\Schema\Contracts\FieldMapper;
use Guava\FilamentMcp\Schema\Contracts\ProvidesValidationRules;
use Guava\FilamentMcp\Schema\Contracts\TransformsReads;
use Guava\FilamentMcp\Schema\Contracts\TransformsWrites;
use Guava\FilamentMcp\Schema\WriteContext;
use Guava\FilamentMcp\Support\FetchedFile;
use Guava\FilamentMcp\Support\MimeSniffer;
use Guava\FilamentMcp\Support\RemoteFileFetcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lets an agent put a file on a `FileUpload` field.
 *
 * Two things Filament normally does never happen on the MCP write path.
 * `saveUploadedFiles()` is a `beforeStateDehydrated` callback that only runs
 * inside Livewire, and the field's own file validation rules are filtered to
 * `TemporaryUploadedFile` instances only (BaseFileUpload.php:753), so a plain
 * string would be stored with no mime or size checking. Both jobs are done here.
 *
 * The agent never passes a filesystem path, only an upload handle from
 * `request_upload` or an https URL, so it can supply bytes it already had rather
 * than making the server read on its behalf.
 */
class FileUploadMapper implements FieldMapper, ProvidesValidationRules, TransformsReads, TransformsWrites
{
    /**
     * The field's own rules require an array of TemporaryUploadedFile keyed by
     * uuid, which is Livewire's state shape and nothing an agent can produce.
     * Only shape is checked here; mime, size and count are enforced in write()
     * where the bytes exist.
     *
     * @return array<int, mixed>
     */
    public function rules(Field $field): array
    {
        $isRequired = (bool) $this->call($field, 'isRequired', false);

        return [
            $isRequired ? 'required' : 'nullable',
            $this->isMultiple($field) ? 'array' : 'string',
        ];
    }

    public function map(Field $field, JsonSchema $schema): Type
    {
        $item = $schema->string()
            ->description($this->describe($field))
        ;

        if ($this->isMultiple($field)) {
            return $schema->array()
                ->items($item)
                ->description($this->describe($field))
            ;
        }

        return $item;
    }

    public function write(Field $field, mixed $value, WriteContext $context): mixed
    {
        /** @var BaseFileUpload $field */
        $multiple = $this->isMultiple($field);

        if (blank($value)) {
            $this->assertFileCount($field, 0);

            return $multiple ? [] : null;
        }

        $values = is_array($value) ? array_values($value) : [$value];

        if (! $multiple && count($values) > 1) {
            throw FieldWriteException::make($field->getName(), 'it accepts a single file.');
        }

        $this->assertFileCount($field, count($values));

        $existing = $this->getExistingPaths($field, $context->record);

        $paths = [];

        foreach ($values as $item) {
            if (! is_string($item)) {
                throw FieldWriteException::make(
                    $field->getName(),
                    'each file must be an upload handle or an https URL.',
                );
            }

            // An agent writing back a value it just read should not trigger a
            // re-fetch. On a local install the URL it was handed would resolve
            // to a private address and be refused anyway.
            if (($kept = $this->matchExisting($item, $existing)) !== null) {
                $paths[] = $kept;

                continue;
            }

            $paths[] = $this->store($field, $this->resolve($field, $item, $context), $context);
        }

        // Mirrors FileUploadStateCast::get(): a 0-indexed list when multiple,
        // the bare path otherwise.
        return $multiple ? $paths : ($paths[0] ?? null);
    }

    public function read(Field $field, mixed $value, Model $record): mixed
    {
        /** @var BaseFileUpload $field */
        if (blank($value)) {
            return $value;
        }

        if (is_array($value)) {
            return array_values(array_map(
                fn (mixed $path): mixed => is_string($path) ? $this->toUrl($field, $path) : $path,
                $value,
            ));
        }

        return is_string($value) ? $this->toUrl($field, $value) : $value;
    }

    /**
     * Turn one agent-supplied value into vetted bytes.
     *
     * @throws FieldWriteException
     */
    protected function resolve(BaseFileUpload $field, string $value, WriteContext $context): FetchedFile
    {
        $file = McpUpload::isHandle($value)
            ? $this->resolveHandle($field, $value, $context)
            : $this->resolveUrl($field, $value);

        $this->assertMimeIsAccepted($field, $file);
        $this->assertSizeIsAllowed($field, $file);

        return $file;
    }

    protected function resolveHandle(BaseFileUpload $field, string $handle, WriteContext $context): FetchedFile
    {
        $upload = McpUpload::findByHandle($handle);

        if ($upload === null || ! $upload->hasFile()) {
            throw FieldWriteException::make($field->getName(), 'that upload handle is not recognised.');
        }

        // The same message for wrong-owner, expired and already used, so the
        // handle space cannot be probed.
        if (! $upload->isOwnedBy($context->getUser()) || $upload->isExpired() || $upload->isConsumed()) {
            throw FieldWriteException::make(
                $field->getName(),
                'that upload handle is no longer usable. Request a new upload URL.',
            );
        }

        try {
            $contents = Storage::disk($upload->disk)->get($upload->path);
        } catch (Throwable) {
            $contents = null;
        }

        if ($contents === null) {
            throw FieldWriteException::make($field->getName(), 'the uploaded file could not be read.');
        }

        $upload->forceFill(['consumed_at' => now()])->save();

        return new FetchedFile(
            contents: $contents,
            mimeType: $upload->mime_type ?? MimeSniffer::sniff($contents) ?? 'application/octet-stream',
            originalName: $upload->original_name,
        );
    }

    protected function resolveUrl(BaseFileUpload $field, string $url): FetchedFile
    {
        try {
            return app(RemoteFileFetcher::class)->fetch($url);
        } catch (RemoteFetchException $e) {
            throw FieldWriteException::make($field->getName(), $e->getMessage());
        }
    }

    /**
     * Store vetted bytes exactly where the field itself would have put them.
     */
    protected function store(BaseFileUpload $field, FetchedFile $file, WriteContext $context): string
    {
        $disk = $this->getDiskName($field);
        $directory = trim((string) $this->call($field, 'getDirectory', ''), '/');
        $visibility = $this->call($field, 'getVisibility', 'private');

        $path = trim($directory . '/' . $this->getStoredName($field, $file), '/');

        $stored = Storage::disk($disk)->put($path, $file->contents, $visibility);

        if ($stored === false) {
            throw FieldWriteException::make($field->getName(), 'the file could not be stored.');
        }

        return $path;
    }

    /**
     * Mirrors Filament's default naming (BaseFileUpload.php:136-138): a ULID
     * plus the extension, or the original filename when the field asks for it.
     *
     * One difference: Filament takes the extension from the uploaded file's
     * client-supplied name, while here it always comes from the sniffed type,
     * because on this path the name is a tool argument the agent chose.
     */
    protected function getStoredName(BaseFileUpload $field, FetchedFile $file): string
    {
        $extension = MimeSniffer::storedExtensionFor($file->mimeType);

        if ($this->call($field, 'shouldPreserveFilenames', false) && filled($file->originalName)) {
            return $this->getPreservedName($file->originalName, $extension);
        }

        return (string) Str::ulid() . '.' . $extension;
    }

    /**
     * The agent's filename reduced to a stem, with the sniffed extension on it.
     *
     * Every dot has to go, not just the last one: Apache maps each extension in
     * a name to a handler, so `report.php.jpg` is executed despite the harmless
     * ending.
     */
    protected function getPreservedName(string $originalName, string $extension): string
    {
        $stem = pathinfo(basename($originalName), PATHINFO_FILENAME);
        $stem = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $stem), '-');

        // An empty stem would leave a dotfile behind.
        return (filled($stem) ? Str::limit($stem, 100, '') : (string) Str::ulid()) . '.' . $extension;
    }

    /**
     * @throws FieldWriteException
     */
    protected function assertMimeIsAccepted(BaseFileUpload $field, FetchedFile $file): void
    {
        $accepted = $this->call($field, 'getAcceptedFileTypes', null);

        if (blank($accepted)) {
            return;
        }

        if (! MimeSniffer::matches($file->mimeType, (array) $accepted)) {
            throw FieldWriteException::make($field->getName(), sprintf(
                'the file is a %s, but this field accepts %s.',
                $file->mimeType,
                implode(', ', (array) $accepted),
            ));
        }
    }

    /**
     * @throws FieldWriteException
     */
    protected function assertSizeIsAllowed(BaseFileUpload $field, FetchedFile $file): void
    {
        $size = $file->getSizeInKilobytes();

        $max = $this->call($field, 'getMaxSize', null);

        if (filled($max) && $size > (int) $max) {
            throw FieldWriteException::make(
                $field->getName(),
                "the file is {$size} KB, but the maximum is {$max} KB.",
            );
        }

        $min = $this->call($field, 'getMinSize', null);

        if (filled($min) && $size < (int) $min) {
            throw FieldWriteException::make(
                $field->getName(),
                "the file is {$size} KB, but the minimum is {$min} KB.",
            );
        }
    }

    /**
     * @throws FieldWriteException
     */
    protected function assertFileCount(BaseFileUpload $field, int $count): void
    {
        $max = $this->call($field, 'getMaxFiles', null);

        if (filled($max) && $count > (int) $max) {
            throw FieldWriteException::make($field->getName(), "it accepts at most {$max} file(s).");
        }

        $min = $this->call($field, 'getMinFiles', null);

        if (filled($min) && $count < (int) $min) {
            throw FieldWriteException::make($field->getName(), "it requires at least {$min} file(s).");
        }
    }

    /**
     * Paths already stored on the record, keyed by both the path and the URL
     * the agent would have been shown for it.
     *
     * @return array<string, string>
     */
    protected function getExistingPaths(BaseFileUpload $field, ?Model $record): array
    {
        if ($record === null) {
            return [];
        }

        $current = $record->getAttribute($field->getName());

        if (blank($current)) {
            return [];
        }

        $paths = is_array($current) ? $current : [$current];
        $map = [];

        foreach ($paths as $path) {
            if (! is_string($path)) {
                continue;
            }

            $map[$path] = $path;

            $url = $this->toUrl($field, $path);

            if (is_string($url)) {
                $map[$url] = $path;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $existing
     */
    protected function matchExisting(string $value, array $existing): ?string
    {
        return $existing[$value] ?? null;
    }

    protected function toUrl(BaseFileUpload $field, string $path): mixed
    {
        $disk = $this->getDiskName($field);

        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                return $path;
            }

            if ($this->call($field, 'getVisibility', 'public') === 'private') {
                return $this->absolute($storage->temporaryUrl(
                    $path,
                    now()->addMinutes((int) config('filament.temporary_file_url_expiry_minutes', 30)),
                ));
            }

            return $this->absolute($storage->url($path));
        } catch (Throwable) {
            // A disk with no URL or no temporary URL support. The raw path is
            // more useful to the agent than an error.
            return $path;
        }
    }

    /**
     * Disks configured with a relative root return a root-relative URL, which
     * is no use to an MCP client on another host.
     */
    protected function absolute(string $url): string
    {
        return str_starts_with($url, '/') ? url($url) : $url;
    }

    protected function getDiskName(BaseFileUpload $field): string
    {
        $disk = $this->call($field, 'getDiskName', null);

        return filled($disk)
            ? (string) $disk
            : (string) config('filament.default_filesystem_disk', config('filesystems.default', 'local'));
    }

    protected function isMultiple(Field $field): bool
    {
        return (bool) $this->call($field, 'isMultiple', false);
    }

    /**
     * Field getters are evaluated closures that may reach for state a headless
     * schema does not have, so fall back rather than fail the whole write.
     */
    protected function call(Field $field, string $method, mixed $default): mixed
    {
        if (! method_exists($field, $method)) {
            return $default;
        }

        try {
            return $field->{$method}() ?? $default;
        } catch (Throwable) {
            return $default;
        }
    }

    protected function describe(Field $field): string
    {
        $parts = [$field->getLabel() . '.'];

        $parts[] = 'Either an upload handle from the "request_upload" tool, or a publicly reachable https URL to fetch.';

        $accepted = $this->call($field, 'getAcceptedFileTypes', null);

        if (filled($accepted)) {
            $parts[] = 'Accepted types: ' . implode(', ', (array) $accepted) . '.';
        }

        $max = $this->call($field, 'getMaxSize', null);

        if (filled($max)) {
            $parts[] = "Maximum size: {$max} KB.";
        }

        return implode(' ', $parts);
    }
}
