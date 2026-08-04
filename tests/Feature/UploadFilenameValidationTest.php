<?php

use Guava\FilamentMcp\Models\McpUpload;

it('refuses an over-long filename instead of failing on the column', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('request_upload', ['filename' => str_repeat('a', 300) . '.png'])
    ;

    // Rejected here rather than as a 500 from a strict-mode MySQL insert.
    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and(McpUpload::query()->count())->toBe(0)
    ;
});

it('refuses a filename that is not a string', function () {
    $response = $this->mcp('admin')
        ->asToken(mcpToken())
        ->call('request_upload', ['filename' => ['not', 'a', 'string']])
    ;

    $response->assertOk();

    expect($response->json('result.isError'))->toBeTrue()
        ->and(McpUpload::query()->count())->toBe(0)
    ;
});

it('still accepts a sensible filename', function () {
    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('request_upload', ['filename' => 'holiday.png'])
    ;

    expect($data)->toHaveKey('upload_url')
        ->and(McpUpload::query()->value('original_name'))->toBe('holiday.png')
    ;
});

it('works without a filename at all', function () {
    $data = $this->mcp('admin')
        ->asToken(mcpToken())
        ->callJson('request_upload')
    ;

    expect($data)->toHaveKey('handle')
        ->and(McpUpload::query()->value('original_name'))->toBeNull()
    ;
});
