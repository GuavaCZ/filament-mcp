<?php

use Workbench\App\Models\Post;

it('advertises the modal fields of an action that collects input', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->listTools()
    ;

    $tool = collect($response->json('result.tools'))->keyBy('name')->get('reject_post');

    expect($tool['inputSchema']['properties'])->toHaveKeys(['id', 'reason', 'notify'])
        ->and($tool['inputSchema']['required'])->toContain('reason')
    ;
});

it('does not advertise modal fields the panel would not submit', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->listTools()
    ;

    $tool = collect($response->json('result.tools'))->keyBy('name')->get('reject_post');

    expect($tool['inputSchema']['properties'])
        ->not->toHaveKey('ignored')
        ->not->toHaveKey('undehydrated')
    ;
});

it('passes the modal data to the action closure', function () {
    $post = Post::factory()->create(['status' => 'draft', 'is_featured' => false]);

    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('reject_post', [
            'id' => (string) $post->getKey(),
            'reason' => 'Off topic.',
            'notify' => false,
        ])
    ;

    expect($data['record']['status'])->toBe('archived')
        ->and($post->refresh()->content)->toBe('Off topic.')
        ->and($post->is_featured)->toBeFalse()
    ;
});

it('enforces the modal form validation rules', function () {
    $post = Post::factory()->create(['status' => 'draft']);

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('reject_post', ['id' => (string) $post->getKey()])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($post->refresh()->status)->toBe('draft')
    ;
});

it('rejects a modal value that breaks a field rule', function () {
    $post = Post::factory()->create(['status' => 'draft']);

    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('reject_post', [
            'id' => (string) $post->getKey(),
            'reason' => str_repeat('a', 201),
        ])
    ;

    expect($response->json('result.isError'))->toBeTrue()
        ->and($post->refresh()->status)->toBe('draft')
    ;
});

it('fills the default state of a modal field the agent left out', function () {
    $post = Post::factory()->create(['status' => 'draft', 'is_featured' => false]);

    $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('reject_post', [
            'id' => (string) $post->getKey(),
            'reason' => 'No reason to notify.',
        ])
    ;

    // `notify` defaults to true, exactly as an untouched toggle would submit.
    expect($post->refresh()->is_featured)->toBeTrue();
});

it('does not expose an action whose modal schema cannot be built headlessly', function () {
    $tools = $this->mcp('admin')
        ->asToken(mcpToken())
        ->toolNames()
    ;

    expect($tools)->not->toContain('unsafe_schema_post');
});
