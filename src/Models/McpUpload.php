<?php

namespace Guava\FilamentMcp\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A file staged by an agent, waiting to be claimed by a field.
 *
 * MCP has no client-to-server binary channel and base64 in a tool argument is
 * not viable at real image sizes, so bytes travel out of band to a signed URL
 * and the agent passes back only this row's handle.
 *
 * @property string $id
 * @property ?string $uploadable_type
 * @property int|string|null $uploadable_id
 * @property ?string $panel_id
 * @property ?string $disk
 * @property ?string $path
 * @property ?string $mime_type
 * @property ?int $size
 * @property ?string $original_name
 * @property ?Carbon $uploaded_at
 * @property ?Carbon $consumed_at
 * @property Carbon $expires_at
 */
class McpUpload extends Model
{
    use HasUlids;

    public const HANDLE_PREFIX = 'mcp-upload:';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_at' => 'datetime',
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return config('filament-mcp.uploads.table', 'mcp_uploads');
    }

    public function uploadable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getHandle(): string
    {
        return static::HANDLE_PREFIX . $this->getKey();
    }

    public static function isHandle(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, static::HANDLE_PREFIX);
    }

    public static function findByHandle(string $handle): ?self
    {
        if (! static::isHandle($handle)) {
            return null;
        }

        return static::query()->find(substr($handle, strlen(static::HANDLE_PREFIX)));
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function hasFile(): bool
    {
        return $this->uploaded_at !== null && filled($this->path);
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /**
     * Whether this upload belongs to the given user.
     *
     * A null owner is never claimable. The staging row is created against the
     * authenticated user, so an absent one means something went wrong.
     */
    public function isOwnedBy(?Model $user): bool
    {
        if ($user === null || $this->uploadable_type === null) {
            return false;
        }

        return $this->uploadable_type === $user->getMorphClass()
            && (string) $this->uploadable_id === (string) $user->getKey();
    }

    public function readStream(): mixed
    {
        return Storage::disk($this->disk)->readStream($this->path);
    }

    public function deleteFile(): void
    {
        if (filled($this->path) && Storage::disk($this->disk)->exists($this->path)) {
            Storage::disk($this->disk)->delete($this->path);
        }
    }
}
