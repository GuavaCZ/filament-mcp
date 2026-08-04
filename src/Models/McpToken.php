<?php

namespace Guava\FilamentMcp\Models;

use Guava\FilamentMcp\Contracts\GrantsAbilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $token
 * @property array<int, string> $abilities
 * @property ?string $panel_id
 * @property ?Carbon $last_used_at
 * @property ?Carbon $expires_at
 */
class McpToken extends Model implements GrantsAbilities
{
    protected $guarded = [];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return config('filament-mcp.tokens.table', 'mcp_tokens');
    }

    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Create a token for the given user and return [model, plainTextToken].
     *
     * @param  array<int, string>  $abilities
     * @return array{0: self, 1: string}
     */
    public static function generate(
        Model $tokenable,
        string $name,
        array $abilities = ['*'],
        ?string $panelId = null,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $secret = Str::random(40);

        $token = static::query()->create([
            'tokenable_type' => $tokenable->getMorphClass(),
            'tokenable_id' => $tokenable->getKey(),
            'name' => $name,
            'token' => hash('sha256', $secret),
            'abilities' => $abilities,
            'panel_id' => $panelId,
            'expires_at' => $expiresAt,
        ]);

        $prefix = config('filament-mcp.tokens.prefix', 'gmcp_');

        return [$token, "{$prefix}{$token->getKey()}|{$secret}"];
    }

    public static function findByPlainTextToken(string $plainTextToken): ?self
    {
        $prefix = config('filament-mcp.tokens.prefix', 'gmcp_');

        if (! str_starts_with($plainTextToken, $prefix)) {
            return null;
        }

        [$id, $secret] = array_pad(
            explode('|', Str::after($plainTextToken, $prefix), 2),
            2,
            null,
        );

        // The id segment comes straight from the Authorization header. Postgres
        // raises a type error for a non-numeric value against the integer key
        // column, so a malformed token must never reach the query.
        if (blank($id) || blank($secret) || ! ctype_digit($id)) {
            return null;
        }

        $token = static::query()->find($id);

        if ($token === null || ! hash_equals($token->token, hash('sha256', $secret))) {
            return null;
        }

        return $token;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function can(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        if (in_array('*', $abilities)) {
            return true;
        }

        if (in_array($ability, $abilities)) {
            return true;
        }

        [$resource] = explode(':', $ability, 2);

        return in_array("{$resource}:*", $abilities);
    }
}
