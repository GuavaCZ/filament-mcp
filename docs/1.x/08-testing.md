---
title: Testing
---

# Testing

The package ships a small test client, so you can assert against your MCP server the same way you'd test a controller.

```php
use Guava\FilamentMcp\Testing\TestsMcp;

uses(TestsMcp::class);

it('lists posts', function () {
    $data = $this->mcp('admin')
        ->asToken($plainTextToken)
        ->callJson('list_posts', ['search' => 'hello']);

    expect($data['pagination']['total'])->toBe(1);
});
```

`$this->mcp('admin')` speaks real JSON-RPC over HTTP against the registered route for the `admin` panel. `initialize()`, `listTools()`, `toolNames()`, `call()`, `callJson()` and `withHeader()` are all available on it.

`callJson()` asserts the call succeeded and fails with the actual denial reason in the message — a non-200 status, a JSON-RPC error, and a tool error result all surface their body instead of decoding to an empty array. Use `call()` when the error itself is what you are testing.
