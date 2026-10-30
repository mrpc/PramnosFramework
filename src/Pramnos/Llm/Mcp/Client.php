<?php

declare(strict_types=1);

namespace Pramnos\Llm\Mcp;

use Pramnos\Llm\Tools;

/**
 * A client of one MCP server: its tools listed, called, and handed to a model as tools of the
 * framework's own — always marked `untrusted`, since what another service returns was written by
 * somebody else.
 *
 * ```php
 * $github = new Client(new StdioTransport(['npx', '-y', '@modelcontextprotocol/server-github'], ['GITHUB_TOKEN' => $token]));
 * $tools  = $github->tools('github');   // github__search_issues, …
 * ```
 *
 * The session starts on the first call (`initialize`, then `notifications/initialized`) and lasts
 * until {@see close()}.
 */
class Client
{
    /** The protocol version asked for; the server answers the one it speaks. */
    public const PROTOCOL = '2025-06-18';

    private int $next = 0;

    private bool $ready = false;

    /** @var array<string, mixed> What the server said of itself on `initialize` */
    private array $server = [];

    public function __construct(private readonly Transport $transport, private readonly int $timeout = 30)
    {
    }

    /**
     * The server's tools as it lists them — every page of them.
     *
     * @return list<array<string, mixed>> Each with `name`, `description`, `inputSchema`, perhaps `annotations`
     */
    public function listTools(): array
    {
        $tools  = [];
        $cursor = null;
        do {
            $page   = $this->call('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);
            $tools  = [...$tools, ...array_values(array_filter((array) ($page['tools'] ?? []), 'is_array'))];
            $cursor = isset($page['nextCursor']) && $page['nextCursor'] !== '' ? (string) $page['nextCursor'] : null;
        } while ($cursor !== null);

        return $tools;
    }

    /**
     * Call one of the server's tools; its result as the text a model reads. A result the server
     * marks as an error starts `Error:`, as a tool of the framework's own that failed.
     *
     * @param array<string, mixed> $arguments
     */
    public function callTool(string $name, array $arguments): string
    {
        $result = $this->call('tools/call', ['name' => $name, 'arguments' => $arguments === [] ? new \stdClass() : $arguments]);
        $text   = self::text($result);

        return !empty($result['isError']) ? 'Error: ' . $text : $text;
    }

    /**
     * The server's tools as the framework's: named `{prefix}__{tool}`, so two servers' tools of the
     * same name do not meet, and marked `untrusted`. A tool the server says only reads
     * (`readOnlyHint`) is idempotent; any other is taken to write, so it is held after untrusted
     * reads and gated by a grant gate, as a change somebody must want.
     *
     * @param array<string, mixed> $meta Added to every tool: `permissions`, `skills`…
     * @return array<string, array<string, mixed>>
     */
    public function tools(string $prefix, array $meta = []): array
    {
        $out = [];
        foreach ($this->listTools() as $tool) {
            $name     = (string) ($tool['name'] ?? '');
            $readOnly = !empty($tool['annotations']['readOnlyHint']);
            if ($name === '') {
                continue;
            }
            $out[self::nameFor($prefix, $name)] = [
                'description' => (string) ($tool['description'] ?? ''),
                'parameters'  => Tools::schema((array) ($tool['inputSchema'] ?? [])),
                'run'         => fn (array $arguments): string => $this->callTool($name, $arguments),
                'untrusted'   => true,
            ] + ($readOnly ? ['idempotent' => true] : ['writes' => true]) + $meta;
        }

        return $out;
    }

    /**
     * What the server said of itself — `serverInfo`, `capabilities`, `protocolVersion`.
     *
     * @return array<string, mixed>
     */
    public function server(): array
    {
        $this->start();

        return $this->server;
    }

    /** End the session and let the server go. */
    public function close(): void
    {
        $this->transport->close();
        $this->ready = false;
    }

    /**
     * A tool's name as a provider takes it: letters, digits, `_` and `-`, at most 64.
     */
    public static function nameFor(string $prefix, string $tool): string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $prefix . '__' . $tool) ?? '';

        return strlen($name) > 64 ? substr($name, 0, 55) . '_' . substr(hash('sha256', $name), 0, 8) : $name;
    }

    /**
     * One request after the session has started; an error answer is thrown.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(string $method, array $params): array
    {
        $this->start();

        return $this->send($method, $params);
    }

    private function start(): void
    {
        if ($this->ready) {
            return;
        }
        $this->server = $this->send('initialize', [
            'protocolVersion' => self::PROTOCOL,
            'capabilities'    => new \stdClass(),
            'clientInfo'      => ['name' => 'pramnos', 'version' => '1'],
        ]);
        $this->transport->notify(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->ready = true;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function send(string $method, array $params): array
    {
        $answer = $this->transport->request(['jsonrpc' => '2.0', 'id' => ++$this->next, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params], $this->timeout);

        if (isset($answer['error'])) {
            throw new McpException('The MCP server refused ' . $method . ': ' . (string) ($answer['error']['message'] ?? 'an error'));
        }

        return (array) ($answer['result'] ?? []);
    }

    /**
     * A tool result's content as text: its text parts, a resource's text or address, a note for
     * anything else; the structured content where there is no text.
     *
     * @param array<string, mixed> $result
     */
    private static function text(array $result): string
    {
        $parts = [];
        foreach ((array) ($result['content'] ?? []) as $item) {
            $parts[] = match ((string) ($item['type'] ?? '')) {
                'text'          => (string) ($item['text'] ?? ''),
                'resource'      => (string) ($item['resource']['text'] ?? $item['resource']['uri'] ?? ''),
                'resource_link' => (string) ($item['uri'] ?? ''),
                default         => '[' . ($item['type'] ?? 'content') . ']',
            };
        }
        $text = trim(implode("\n", array_filter($parts, static fn (string $p): bool => $p !== '')));

        return $text === '' && isset($result['structuredContent']) ? Tools::text($result['structuredContent']) : $text;
    }
}
