<?php

use Filament\Facades\Filament;
use Guava\FilamentMcp\McpServerRegistry;
use Guava\FilamentMcp\Support\TenancyRegistrar;
use Workbench\App\Filament\Resources\TicketResource;
use Workbench\App\Models\Team;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

/**
 * TicketResource is registered on the tenant panel's McpPlugin but not in the
 * panel's own ->resources([]). Filament registers tenancy per model while
 * iterating the panel's resource list, so without TenancyRegistrar none of these
 * records would be scoped to a tenant.
 */
function ticketTenancySetup(): array
{
    $teamA = Team::factory()->create(['slug' => 'team-a']);
    $teamB = Team::factory()->create(['slug' => 'team-b']);

    $user = User::factory()->create();
    $user->teams()->attach($teamA);

    Ticket::factory()->count(2)->for($teamA)->create();
    Ticket::factory()->count(3)->for($teamB)->create();

    return [$user, $teamA, $teamB];
}

it('scopes an MCP-only resource to the tenant', function () {
    [$user] = ticketTenancySetup();

    $data = $this->mcp('tenant')
        ->asToken(mcpToken($user))
        ->withHeader('X-Tenant', 'team-a')
        ->callJson('list_tickets')
    ;

    expect($data['pagination']['total'])->toBe(2);
});

it('cannot read another tenant\'s record through an MCP-only resource', function () {
    [$user, , $teamB] = ticketTenancySetup();

    $foreign = Ticket::query()->where('team_id', $teamB->getKey())->first();

    $user->teams()->attach($teamB);

    $response = $this->mcp('tenant')
        ->asToken(mcpToken($user))
        ->withHeader('X-Tenant', 'team-a')
        ->call('get_ticket', ['id' => (string) $foreign->getKey()])
    ;

    expect($response->json('result.isError'))->toBeTrue();
});

it('associates records created through an MCP-only resource with the tenant', function () {
    [$user, $teamA] = ticketTenancySetup();

    $this->mcp('tenant')
        ->asToken(mcpToken($user))
        ->withHeader('X-Tenant', 'team-a')
        ->callJson('create_ticket', ['subject' => 'Tenant ticket'])
    ;

    $ticket = Ticket::query()->withoutGlobalScopes()->where('subject', 'Tenant ticket')->first();

    expect($ticket->team_id)->toBe($teamA->getKey());
});

it('does not update another tenant\'s record through an MCP-only resource', function () {
    [$user, , $teamB] = ticketTenancySetup();

    $foreign = Ticket::query()->where('team_id', $teamB->getKey())->first();
    $original = $foreign->subject;

    $user->teams()->attach($teamB);

    $this->mcp('tenant')
        ->asToken(mcpToken($user))
        ->withHeader('X-Tenant', 'team-a')
        ->call('update_ticket', ['id' => (string) $foreign->getKey(), 'subject' => 'hijacked'])
    ;

    expect($foreign->fresh()->subject)->toBe($original);
});

it('registers the tenant creation observer only once per panel and model', function () {
    [$user, $teamA] = ticketTenancySetup();

    $registrar = app(TenancyRegistrar::class);
    $panel = Filament::getPanel('tenant');
    $definition = app(McpServerRegistry::class)->get('tenant');

    // Applying repeatedly must not stack `creating` listeners.
    $registrar->apply($panel, $definition);
    $registrar->apply($panel, $definition);
    $registrar->apply($panel, $definition);

    $this->mcp('tenant')
        ->asToken(mcpToken($user))
        ->withHeader('X-Tenant', 'team-a')
        ->callJson('create_ticket', ['subject' => 'Once only'])
    ;

    $ticket = Ticket::query()->withoutGlobalScopes()->where('subject', 'Once only')->first();

    expect($ticket->team_id)->toBe($teamA->getKey());
});

it('still depends on the Filament methods it calls', function () {
    // If a Filament upgrade renames these, MCP-only resources would silently
    // stop being tenant-scoped. Fail here instead of in production.
    expect(method_exists(TicketResource::class, 'registerTenancyModelGlobalScope'))->toBeTrue()
        ->and(method_exists(TicketResource::class, 'observeTenancyModelCreation'))->toBeTrue()
        ->and(method_exists(TicketResource::class, 'isScopedToTenant'))->toBeTrue()
    ;
});
