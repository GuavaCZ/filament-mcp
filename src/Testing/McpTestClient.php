<?php

namespace Guava\FilamentMcp\Testing;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

class McpTestClient
{
    protected ?string $token = null;

    /**
     * @var array<string, string>
     */
    protected array $headers = [];

    protected int $requestId = 0;

    /**
     * @param  object  $test  A test case with Laravel's MakesHttpRequests capabilities.
     */
    public function __construct(
        protected object $test,
        protected string $path,
    ) {}

    public function asToken(?string $plainTextToken): static
    {
        $this->token = $plainTextToken;

        return $this;
    }

    public function withHeader(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function initialize(): TestResponse
    {
        return $this->send('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'pest', 'version' => '1.0.0'],
        ]);
    }

    public function listTools(?string $cursor = null): TestResponse
    {
        return $this->send('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
    }

    /**
     * Every tool the server exposes, across all pages of `tools/list`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allTools(): array
    {
        $tools = [];
        $cursor = null;
        $seen = [];

        do {
            $response = $this->listTools($cursor);
            $this->assertOk($response, 'tools/list');

            $tools = [...$tools, ...($response->json('result.tools') ?? [])];

            $cursor = $response->json('result.nextCursor');

            // A server repeating a cursor would loop forever, so stop rather
            // than hang the test suite.
            if ($cursor !== null && in_array($cursor, $seen, true)) {
                break;
            }

            $seen[] = $cursor;
        } while (filled($cursor));

        return $tools;
    }

    /**
     * @return array<int, string>
     */
    public function toolNames(): array
    {
        return collect($this->allTools())
            ->pluck('name')
            ->all()
        ;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function call(string $tool, array $arguments = []): TestResponse
    {
        return $this->send('tools/call', [
            'name' => $tool,
            'arguments' => $arguments,
        ]);
    }

    /**
     * The decoded data of a successful tool call that returned JSON content.
     *
     * Fails loudly when the call did not succeed. A denial travels as HTTP 200
     * with an error payload, which decoded as data would show up several asserts
     * later as a missing key instead of the actual reason.
     *
     * @return array<string, mixed>
     */
    public function callJson(string $tool, array $arguments = []): array
    {
        $response = $this->call($tool, $arguments);

        $this->assertOk($response, "tools/call [{$tool}]");

        if (($error = $response->json('error.message')) !== null) {
            Assert::fail("Tool call [{$tool}] failed: {$error}");
        }

        if ($response->json('result.isError') === true) {
            $reason = $response->json('result.content.0.text') ?? (string) $response->getContent();

            Assert::fail("Tool call [{$tool}] returned an error: {$reason}");
        }

        if (is_array($structured = $response->json('result.structuredContent'))) {
            return $structured;
        }

        $content = $response->json('result.content.0.text');

        return is_string($content)
            ? (json_decode($content, true) ?? [])
            : [];
    }

    /**
     * Fail with the response body in the message. A bare status assertion turns
     * an auth denial into "expected 200, got 403" and throws away the reason.
     */
    protected function assertOk(TestResponse $response, string $context): void
    {
        if ($response->getStatusCode() === 200) {
            return;
        }

        Assert::fail(sprintf(
            'MCP request [%s] returned HTTP %d. Response: %s',
            $context,
            $response->getStatusCode(),
            (string) $response->getContent(),
        ));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function send(string $method, array $params = []): TestResponse
    {
        $headers = [
            'Accept' => 'application/json, text/event-stream',
            ...$this->headers,
        ];

        if ($this->token !== null) {
            $headers['Authorization'] = "Bearer {$this->token}";
        }

        return $this->test->postJson($this->path, [
            'jsonrpc' => '2.0',
            'id' => ++$this->requestId,
            'method' => $method,
            'params' => $params,
        ], $headers);
    }
}
