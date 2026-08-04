<?php

use Filament\Facades\Filament;
use Guava\FilamentMcp\Filament\Pages\ManageMcpTokens as ManageMcpTokensPage;
use Guava\FilamentMcp\Livewire\ManageMcpTokens;
use Guava\FilamentMcp\McpPlugin;
use Guava\FilamentMcp\McpServerRegistry;
use Guava\FilamentMcp\Models\McpToken;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('registers the page on the panel', function () {
    expect(Filament::getPanel('admin')->getPages())->toContain(ManageMcpTokensPage::class);
});

it('lists only the tokens of the current user', function () {
    $user = mcpUser();
    $other = mcpUser();

    [$own] = McpToken::generate($user, 'mine', panelId: 'admin');
    McpToken::generate($other, 'theirs', panelId: 'admin');

    $this->actingAs($user);

    Livewire::test(ManageMcpTokens::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$own])
        ->assertCountTableRecords(1)
    ;
});

it('lists only the tokens of the current panel, plus panel-less ones', function () {
    $user = mcpUser();

    [$admin] = McpToken::generate($user, 'admin token', panelId: 'admin');
    [$tenant] = McpToken::generate($user, 'tenant token', panelId: 'tenant');
    [$global] = McpToken::generate($user, 'global token');

    $this->actingAs($user);

    Livewire::test(ManageMcpTokens::class)
        ->assertCanSeeTableRecords([$admin, $global])
        ->assertCanNotSeeTableRecords([$tenant])
        ->assertCountTableRecords(2)
    ;
});

it('creates a full access token through the component', function () {
    $user = mcpUser();

    $this->actingAs($user);

    Livewire::test(ManageMcpTokens::class)
        ->callAction('create', data: [
            'name' => 'From the page',
            'access' => 'full',
        ])
        ->assertHasNoActionErrors()
    ;

    $token = McpToken::query()->first();

    expect($token)->not->toBeNull()
        ->and($token->name)->toBe('From the page')
        ->and($token->abilities)->toBe(['*'])
        ->and($token->panel_id)->toBe('admin')
        ->and($token->tokenable_id)->toBe($user->getKey())
    ;
});

it('creates a limited token from the ability picker', function () {
    $user = mcpUser();

    $this->actingAs($user);

    Livewire::test(ManageMcpTokens::class)
        ->callAction('create', data: [
            'name' => 'Limited',
            'access' => 'custom',
            'abilities' => ['posts:list', 'posts:get'],
        ])
        ->assertHasNoActionErrors()
    ;

    expect(McpToken::query()->first()->abilities)->toBe(['posts:list', 'posts:get']);
});

it('offers an ability for every operation and action of the panel', function () {
    $this->actingAs(mcpUser());

    $options = (fn () => $this->getAbilityOptions())
        ->call(Livewire::test(ManageMcpTokens::class)->instance())
    ;

    expect($options)->toHaveKeys([
        'posts:list',
        'posts:get',
        'posts:create',
        'posts:update',
        'posts:delete',
        'posts:archive',
        'categories:list',
        'categories:get',
    ]);
});

it('reveals the token exactly once, with connection snippets', function () {
    $this->actingAs(mcpUser());

    $component = Livewire::test(ManageMcpTokens::class)
        ->callAction('create', data: [
            'name' => 'Revealed',
            'access' => 'full',
        ])
        // Creating swaps the create modal for the reveal modal, which is the
        // only place the plain text token is ever shown.
        ->assertActionMounted('reveal')
    ;

    $revealAction = $component->instance()->getMountedActions()[0];
    $plainTextToken = $revealAction->getArguments()['token'];

    expect($plainTextToken)->toStartWith('gmcp_');

    $schema = (fn () => $this->getMountedActionSchema())->call($component->instance());

    $entries = collect($schema->getComponents())
        ->mapWithKeys(fn ($entry): array => [$entry->getName() => $entry->getState()])
    ;

    expect($entries->get('token'))->toBe($plainTextToken);

    expect($entries->get('command'))
        ->toContain('claude mcp add --transport http')
        ->toContain(url('mcp/admin'))
        ->toContain("Bearer {$plainTextToken}")
    ;

    $config = json_decode($entries->get('config'), true);
    $server = reset($config['mcpServers']);

    expect($config)->toHaveKey('mcpServers')
        ->and($server['type'])->toBe('http')
        ->and($server['url'])->toBe(url('mcp/admin'))
        ->and($server['headers']['Authorization'])->toBe("Bearer {$plainTextToken}")
    ;

    // The secret only lives in the mounted action's arguments, so closing the
    // modal takes it out of the Livewire payload for good.
    $component->unmountAction();

    expect($component->instance()->getMountedActions())->toBeEmpty();
});

it('does not build connection snippets for a panel without a server', function () {
    $this->actingAs(mcpUser());

    $component = Livewire::test(ManageMcpTokens::class);

    app()->forgetInstance(McpServerRegistry::class);

    $command = (fn () => $this->getConnectionCommand('gmcp_test'))->call($component->instance());

    expect($command)->toBeNull();
});

it('revokes a token through the component', function () {
    $user = mcpUser();

    [$token] = McpToken::generate($user, 'revocable', panelId: 'admin');

    $this->actingAs($user);

    Livewire::test(ManageMcpTokens::class)
        ->callTableAction('revoke', $token)
        ->assertHasNoTableActionErrors()
    ;

    expect(McpToken::query()->count())->toBe(0);
});

it('denies access to guests', function () {
    Livewire::test(ManageMcpTokens::class)
        ->assertForbidden()
    ;
});

it('honours the authorization callback on both mounts', function () {
    $user = mcpUser();

    $this->actingAs($user);

    $plugin = McpPlugin::get();

    expect(ManageMcpTokensPage::canAccess())->toBeTrue()
        ->and(ManageMcpTokens::canAccess())->toBeTrue()
    ;

    $plugin->authorizeTokens(fn () => false);

    try {
        expect(ManageMcpTokensPage::canAccess())->toBeFalse()
            ->and(ManageMcpTokens::canAccess())->toBeFalse()
            ->and($plugin->hasTokensOnProfilePage())->toBeFalse()
        ;

        Livewire::test(ManageMcpTokens::class)->assertForbidden();
    } finally {
        $plugin->authorizeTokens(null);
    }
});

it('re-checks authorization after the component has already mounted', function () {
    $user = mcpUser();

    $this->actingAs($user);

    $component = Livewire::test(ManageMcpTokens::class)->assertOk();

    $plugin = McpPlugin::get();

    // Access revoked with the page already open. Opening the create modal,
    // submitting it and revoking a token all arrive as Livewire updates, which
    // hydrate and skip mount(), so without a re-check the component keeps minting
    // bearer credentials.
    $plugin->authorizeTokens(fn () => false);

    try {
        $component->call('$refresh')->assertForbidden();

        expect(McpToken::query()->count())->toBe(0);
    } finally {
        $plugin->authorizeTokens(null);
    }
});

it('appends the token manager to the profile page', function () {
    $this->actingAs(mcpUser());

    $this->get(ManageMcpTokensPage::getUrl(panel: 'admin'))
        ->assertOk()
    ;

    $this->get(Filament::getPanel('admin')->getProfileUrl())
        ->assertOk()
        ->assertSee('fi-mcp-tokens-section')
        ->assertSee(__('filament-mcp::tokens.section.heading'))
    ;
});

it('leaves the profile page alone on panels without token management', function () {
    expect(McpPlugin::get()->isProfilePageMountRegistered())->toBeTrue();

    Filament::setCurrentPanel(Filament::getPanel('tenant'));

    expect(McpPlugin::get()->isProfilePageMountRegistered())->toBeFalse()
        ->and(McpPlugin::get()->isTokensPageRegistered())->toBeFalse()
        ->and(Filament::getPanel('tenant')->getPages())->not->toContain(ManageMcpTokensPage::class)
    ;
});

it('prunes expired and stale tokens', function () {
    $user = mcpUser();

    McpToken::generate($user, 'active');
    McpToken::generate($user, 'expired', expiresAt: now()->subDay());

    [$stale] = McpToken::generate($user, 'stale');
    $stale->forceFill(['last_used_at' => now()->subDays(120)])->saveQuietly();

    $this->artisan('mcp:prune-tokens')
        ->assertSuccessful()
        ->expectsOutputToContain('Pruned 1')
    ;

    expect(McpToken::query()->pluck('name')->all())->toBe(['active', 'stale']);

    $this->artisan('mcp:prune-tokens', ['--unused-for' => 90])
        ->assertSuccessful()
    ;

    expect(McpToken::query()->pluck('name')->all())->toBe(['active']);
});
