<?php

use Workbench\App\Models\Category;
use Workbench\App\Models\Post;
use Workbench\App\Models\Project;
use Workbench\App\Models\Team;
use Workbench\App\Models\Ticket;
use Workbench\App\Models\User;

it('accepts a key that exists on a singular relationship select', function () {
    $post = Post::factory()->create();
    $category = Category::factory()->create();

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('update_post', [
            'id' => (string) $post->getKey(),
            'category_id' => (string) $category->getKey(),
        ])
    ;

    expect($data['category_id'])->toEqual($category->getKey())
        ->and($post->refresh()->category_id)->toEqual($category->getKey())
    ;
});

it('rejects a key that does not exist on a singular relationship select', function () {
    $post = Post::factory()->create(['category_id' => null]);

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('update_post', [
            'id' => (string) $post->getKey(),
            'category_id' => '999999',
        ])
    ;

    // The application refuses this with a message the agent can act on, rather
    // than a foreign key violation surfacing as an opaque failure.
    expect($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))
        ->toContain('The [category_id] field could not be written')
        ->toContain('no Category exists with the key 999999')
        ->and($post->refresh()->category_id)->toBeNull()
    ;
});

it('rejects a singular relationship key belonging to another tenant', function () {
    $teamA = Team::factory()->create(['slug' => 'team-a']);
    $teamB = Team::factory()->create(['slug' => 'team-b']);

    $user = User::factory()->create();
    $user->teams()->attach($teamA);

    $ticket = Ticket::factory()->for($teamA)->create(['project_id' => null]);
    $otherTeamsProject = Project::factory()->for($teamB)->create();

    $response = $this->mcp('tenant')
        ->asToken(mcpToken($user))
        ->withHeader('X-Tenant', 'team-a')
        ->call('update_ticket', [
            'id' => (string) $ticket->getKey(),
            'project_id' => (string) $otherTeamsProject->getKey(),
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($ticket->refresh()->project_id)->toBeNull()
    ;
});

it('accepts a singular relationship key inside the current tenant', function () {
    $teamA = Team::factory()->create(['slug' => 'team-a']);

    $user = User::factory()->create();
    $user->teams()->attach($teamA);

    $ticket = Ticket::factory()->for($teamA)->create(['project_id' => null]);
    $project = Project::factory()->for($teamA)->create();

    $this->mcp('tenant')
        ->asToken(mcpToken($user))
        ->withHeader('X-Tenant', 'team-a')
        ->callJson('update_ticket', [
            'id' => (string) $ticket->getKey(),
            'project_id' => (string) $project->getKey(),
        ])
    ;

    expect($ticket->refresh()->project_id)->toEqual($project->getKey());
});
