<?php

use Filament\Facades\Filament;
use Guava\FilamentMcp\Models\McpToken;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\User;

it('creates a token via the artisan command', function () {
    $user = mcpUser(['email' => 'token@example.com']);

    $this->artisan('mcp:token', [
        'user' => 'token@example.com',
        '--name' => 'Test token',
        '--abilities' => ['posts:*'],
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('MCP token created')
    ;

    $token = McpToken::query()->first();

    expect($token)->not->toBeNull()
        ->and($token->name)->toBe('Test token')
        ->and($token->abilities)->toBe(['posts:*'])
        ->and($token->tokenable_id)->toBe($user->getKey())
    ;
});

it('fails for an unknown user', function () {
    $this->artisan('mcp:token', ['user' => 'missing@example.com'])
        ->assertFailed()
    ;
});

it('looks the user up on the model behind the panel guard', function () {
    $user = mcpUser(['email' => 'guarded@example.com']);

    config()->set('auth.guards.admins', ['driver' => 'session', 'provider' => 'admins']);
    config()->set('auth.providers.admins', ['driver' => 'eloquent', 'model' => User::class]);

    // A panel authenticating its own model is the common case, and reading the
    // default provider instead would look the user up on the wrong table.
    config()->set('auth.providers.users.model', 'Workbench\App\Models\NotTheAdminModel');

    Filament::getPanel('admin')->authGuard('admins');

    $this->artisan('mcp:token', [
        'user' => 'guarded@example.com',
        '--panel' => 'admin',
    ])->assertSuccessful();

    expect(McpToken::query()->first()?->tokenable_id)->toBe($user->getKey());
});

it('prefers an explicit guard over the panel', function () {
    $user = mcpUser(['email' => 'explicit@example.com']);

    config()->set('auth.guards.secondary', ['driver' => 'session', 'provider' => 'admins']);
    config()->set('auth.providers.admins', ['driver' => 'eloquent', 'model' => User::class]);
    config()->set('auth.providers.users.model', 'Workbench\App\Models\NotTheAdminModel');

    $this->artisan('mcp:token', [
        'user' => 'explicit@example.com',
        '--guard' => 'secondary',
    ])->assertSuccessful();

    expect(McpToken::query()->first()?->tokenable_id)->toBe($user->getKey());
});

it('fails clearly when the named panel is not registered', function () {
    $this->artisan('mcp:token', [
        'user' => 'anyone@example.com',
        '--panel' => 'nope',
    ])
        ->assertFailed()
        ->expectsOutputToContain('Panel [nope] is not registered')
    ;
});

it('round-trips plain text tokens', function () {
    [$token, $plainTextToken] = McpToken::generate(mcpUser(), 'roundtrip');

    expect($plainTextToken)->toStartWith('gmcp_')
        ->and(McpToken::findByPlainTextToken($plainTextToken)?->getKey())->toBe($token->getKey())
        ->and(McpToken::findByPlainTextToken('gmcp_1|wrong'))->toBeNull()
        ->and(McpToken::findByPlainTextToken('nonsense'))->toBeNull()
    ;
});

it('rejects a malformed token id without touching the database', function () {
    // The id segment lands in a query against an integer column, and Postgres
    // throws a type error for a non-numeric value where SQLite quietly casts.
    // Asserting that no query runs proves the guard on every driver: an
    // unauthenticated caller gets a 401, never a 500.
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    expect(McpToken::findByPlainTextToken('gmcp_abc|secret'))->toBeNull()
        ->and(McpToken::findByPlainTextToken('gmcp_1e3|secret'))->toBeNull()
        ->and(McpToken::findByPlainTextToken('gmcp_-1|secret'))->toBeNull()
        ->and(McpToken::findByPlainTextToken('gmcp_1 or 1=1|secret'))->toBeNull()
        ->and($queries)->toBe([])
    ;
});

it('returns 401, not 500, for a malformed bearer token', function () {
    $this->mcp('tenant')
        ->asToken('gmcp_abc|secret')
        ->listTools()
        ->assertStatus(401)
    ;
});

it('rejects expired tokens', function () {
    [, $plainTextToken] = McpToken::generate(
        mcpUser(),
        'expired',
        expiresAt: now()->subDay(),
    );

    $response = $this->mcp('admin')
        ->asToken($plainTextToken)
        ->call('list_posts')
    ;

    // An expired token is refused outright. Serving it as an anonymous caller
    // would make expiry look like it had not taken effect.
    expect($response->status())->toBe(401);
});

it('updates last_used_at on use', function () {
    [$token, $plainTextToken] = McpToken::generate(mcpUser(), 'tracked');

    expect($token->last_used_at)->toBeNull();

    $this->mcp('admin')->asToken($plainTextToken)->listTools();

    expect($token->refresh()->last_used_at)->not->toBeNull();
});

it('matches abilities including wildcards', function () {
    [$token] = McpToken::generate(mcpUser(), 'abilities', ['posts:list', 'categories:*']);

    expect($token->can('posts:list'))->toBeTrue()
        ->and($token->can('posts:get'))->toBeFalse()
        ->and($token->can('categories:get'))->toBeTrue()
    ;

    [$wildcard] = McpToken::generate(mcpUser(), 'wildcard', ['*']);

    expect($wildcard->can('anything:list'))->toBeTrue();
});
