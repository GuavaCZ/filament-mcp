<?php

use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('filament-mcp.rate_limit', 3);
});

it('throttles regardless of the bearer token sent', function () {
    // The throttle runs before authentication, so keying on the bearer value
    // would hand a fresh bucket to every randomly generated token.
    $statuses = collect(range(1, 5))
        ->map(fn (): int => $this->mcp('admin')
            ->asToken('gmcp_' . Str::random(40))
            ->listTools()
            ->status())
        ->all()
    ;

    expect($statuses)->toContain(429);
});

it('throttles unauthenticated requests too', function () {
    $statuses = collect(range(1, 5))
        ->map(fn (): int => $this->mcp('admin')->listTools()->status())
        ->all()
    ;

    expect($statuses)->toContain(429);
});

it('throttles a single token even when the ip limit is generous', function () {
    // The case the IP limiter cannot cover: one busy token among many callers
    // arriving from one address.
    config()->set('filament-mcp.rate_limit', 1000);
    config()->set('filament-mcp.token_rate_limit', 3);

    $token = mcpToken();

    $statuses = collect(range(1, 5))
        ->map(fn (): int => $this->mcp('admin')->asToken($token)->listTools()->status())
        ->all()
    ;

    expect($statuses)->toContain(429);
});

it('gives each token its own bucket', function () {
    config()->set('filament-mcp.rate_limit', 1000);
    config()->set('filament-mcp.token_rate_limit', 3);

    $exhausted = mcpToken();

    collect(range(1, 4))->each(fn () => $this->mcp('admin')->asToken($exhausted)->listTools());

    // A second token shares the IP but not the bucket, so it is unaffected.
    expect($this->mcp('admin')->asToken(mcpToken())->listTools()->status())->toBe(200);
});

it('does not apply the token limit when it is disabled', function () {
    config()->set('filament-mcp.rate_limit', 1000);
    config()->set('filament-mcp.token_rate_limit', null);

    $token = mcpToken();

    $statuses = collect(range(1, 5))
        ->map(fn (): int => $this->mcp('admin')->asToken($token)->listTools()->status())
        ->all()
    ;

    expect($statuses)->not->toContain(429);
});

it('throttles the upload endpoint', function () {
    config()->set('filament-mcp.uploads.rate_limit', 2);

    $user = mcpUser();

    $url = $this->mcp('admin')
        ->asToken(mcpToken($user))
        ->callJson('request_upload', ['filename' => 'photo.png'])['upload_url']
    ;

    // The signature is valid every time, so the throttle is what has to stop it.
    $statuses = collect(range(1, 4))
        ->map(fn (): int => $this->call('PUT', $url, content: pngBytes())->status())
        ->all()
    ;

    expect($statuses)->toContain(429);
});
