<?php

use Guava\FilamentMcp\Models\McpToken;
use Guava\FilamentMcp\Testing\TestsMcp;
use Guava\FilamentMcp\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;

uses(TestCase::class, RefreshDatabase::class, TestsMcp::class)->in(__DIR__);

function mcpUser(array $attributes = []): User
{
    return User::factory()->create($attributes);
}

/** A real 1x1 PNG, so mime sniffing has real bytes to work with. */
function pngBytes(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    );
}

/**
 * Create a user (unless given) plus a token and return the plain text token.
 */
function mcpToken(?User $user = null, array $abilities = ['*'], ?string $panelId = null): string
{
    [, $plainTextToken] = McpToken::generate(
        tokenable: $user ?? mcpUser(),
        name: 'test',
        abilities: $abilities,
        panelId: $panelId,
    );

    return $plainTextToken;
}
