<?php

use Workbench\App\Models\Project;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

function tenancySetup(): array
{
    $teamA = Team::factory()->create(['slug' => 'team-a']);
    $teamB = Team::factory()->create(['slug' => 'team-b']);

    $user = User::factory()->create();
    $user->teams()->attach($teamA);

    Project::factory()->count(2)->for($teamA)->create();
    Project::factory()->count(3)->for($teamB)->create();

    return [$user, $teamA, $teamB];
}

it('requires a tenant on tenant-scoped servers', function () {
    [$user] = tenancySetup();

    $response = $this->mcp('tenant')
        ->asToken(mcpToken($user))
        ->call('list_projects')
    ;

    $response->assertStatus(400);
});

it('scopes queries to the requested tenant', function () {
    [$user] = tenancySetup();

    $data = $this->mcp('tenant')
        ->asToken(mcpToken($user))
        ->withHeader('X-Tenant', 'team-a')
        ->callJson('list_projects')
    ;

    expect($data['pagination']['total'])->toBe(2);
});

it('denies access to tenants the user does not belong to', function () {
    [$user] = tenancySetup();

    $response = $this->mcp('tenant')
        ->asToken(mcpToken($user))
        ->withHeader('X-Tenant', 'team-b')
        ->call('list_projects')
    ;

    $response->assertStatus(403);
});

it('returns 404 for unknown tenants', function () {
    [$user] = tenancySetup();

    $response = $this->mcp('tenant')
        ->asToken(mcpToken($user))
        ->withHeader('X-Tenant', 'nope')
        ->call('list_projects')
    ;

    $response->assertStatus(404);
});

it('associates created records with the tenant', function () {
    [$user, $teamA] = tenancySetup();

    $data = $this->mcp('tenant')
        ->asToken(mcpToken($user))
        ->withHeader('X-Tenant', 'team-a')
        ->callJson('create_project', ['name' => 'Tenant project'])
    ;

    expect($data['name'])->toBe('Tenant project');

    $project = Project::query()->where('name', 'Tenant project')->first();

    expect($project->team_id)->toBe($teamA->getKey());
});

it('cannot read records of another tenant by id', function () {
    [$user, , $teamB] = tenancySetup();

    $foreign = Project::query()->where('team_id', $teamB->getKey())->first();

    $user->teams()->attach($teamB);

    // Accessing team-b's record while scoped to team-a fails, even though
    // the user belongs to both teams.
    $response = $this->mcp('tenant')
        ->asToken(mcpToken($user))
        ->withHeader('X-Tenant', 'team-a')
        ->call('get_project', ['id' => (string) $foreign->getKey()])
    ;

    expect($response->json('result.isError'))->toBeTrue();
});
