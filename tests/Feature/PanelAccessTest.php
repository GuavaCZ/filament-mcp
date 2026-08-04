<?php

use Filament\Facades\Filament;
use Workbench\App\Models\Post;

it('refuses a token whose user cannot access the panel', function () {
    $user = mcpUser(['blocked_panels' => ['admin']]);

    $response = $this->mcp('admin')
        ->asToken(mcpToken($user))
        ->listTools()
    ;

    expect($response->status())->toBe(403)
        ->and($response->json('error'))->toContain('admin')
    ;
});

it('still serves a token whose user can access the panel', function () {
    $user = mcpUser(['blocked_panels' => ['tenant']]);

    $tools = $this->mcp('admin')
        ->asToken(mcpToken($user))
        ->toolNames()
    ;

    expect($tools)->toContain('list_posts');
});

it('does not let an unscoped token reach a panel the user is barred from', function () {
    // A token minted without --panel carries panel_id = null and is otherwise
    // accepted everywhere, so panel access is what has to stop it.
    $user = mcpUser(['blocked_panels' => ['admin']]);

    $response = $this->mcp('admin')
        ->asToken(mcpToken($user, panelId: null))
        ->call('list_posts')
    ;

    expect($response->status())->toBe(403)
        ->and(Post::query()->count())->toBe(0)
    ;
});

it('refuses an unverified user when the panel requires verification', function () {
    Filament::getPanel('admin')->requiresEmailVerification();

    $user = mcpUser(['email_verified_at' => null]);

    $response = $this->mcp('admin')
        ->asToken(mcpToken($user))
        ->listTools()
    ;

    expect($response->status())->toBe(403)
        ->and($response->json('error'))->toContain('verified')
    ;
});

it('serves a verified user on a panel that requires verification', function () {
    Filament::getPanel('admin')->requiresEmailVerification();

    $tools = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['email_verified_at' => now()])))
        ->toolNames()
    ;

    expect($tools)->toContain('list_posts');
});

it('does not require verification of a panel that never asked for it', function () {
    $tools = $this->mcp('admin')
        ->asToken(mcpToken(mcpUser(['email_verified_at' => null])))
        ->toolNames()
    ;

    expect($tools)->toContain('list_posts');
});

it('rejects a token whose user has been deleted', function () {
    $user = mcpUser();
    $token = mcpToken($user);

    $user->delete();

    // The tenant panel exposes no public resources, so there is no guest fallback:
    // a 401, not a 500 from handing null to the guard.
    $response = $this->mcp('tenant')
        ->asToken($token)
        ->listTools()
    ;

    expect($response->status())->toBe(401);
});

it('does not honour a deleted users token on a panel that allows guests', function () {
    $user = mcpUser();
    $token = mcpToken($user);

    $user->delete();

    $response = $this->mcp('admin')
        ->asToken($token)
        ->listTools()
    ;

    // Refused, rather than authenticated as the deleted user or quietly
    // downgraded to guest on a panel that happens to serve guests.
    expect($response->status())->toBe(401);
});
