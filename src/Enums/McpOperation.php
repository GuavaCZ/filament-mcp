<?php

namespace Guava\FilamentMcp\Enums;

enum McpOperation: string
{
    case List = 'list';
    case Get = 'get';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    public function getPolicyAbility(): string
    {
        return match ($this) {
            self::List => 'viewAny',
            self::Get => 'view',
            self::Create => 'create',
            self::Update => 'update',
            self::Delete => 'delete',
        };
    }

    public function isReadOnly(): bool
    {
        return in_array($this, [self::List, self::Get]);
    }

    /**
     * Whether this operation's policy ability is asked about a single record.
     *
     * `viewAny` and `create` are class-level questions, the rest are not. Given
     * no record, Filament's `Resource::can()` substitutes the model class and
     * Laravel calls the policy method with the user alone, so a policy typed
     * `delete(User $user, Post $post)` raises an ArgumentCountError.
     */
    public function requiresRecord(): bool
    {
        return in_array($this, [self::Get, self::Update, self::Delete]);
    }
}
