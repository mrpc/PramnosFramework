<?php

declare(strict_types=1);

namespace Pramnos\Llm\Mcp;

/** How JSON-RPC messages reach an MCP server and its answers come back — {@see StdioTransport}, {@see HttpTransport}. */
interface Transport
{
    /**
     * Send a request and wait for its response — the message with the same `id`.
     *
     * @param array<string, mixed> $message A JSON-RPC request
     * @return array<string, mixed> The response: `result`, or `error`
     * @throws McpException When nothing answers in time, or the server cannot be reached
     */
    public function request(array $message, int $timeout): array;

    /**
     * Send a notification, which has no response.
     *
     * @param array<string, mixed> $message
     */
    public function notify(array $message): void;

    /** Let the server go. */
    public function close(): void;
}
