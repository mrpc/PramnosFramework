<?php

declare(strict_types=1);

namespace Pramnos\Mcp;

/**
 * Stdio-based MCP (Model Context Protocol) server for Pramnos applications.
 *
 * Implements the JSON-RPC 2.0 message exchange required by the MCP spec:
 *   - initialize / initialized notification
 *   - tools/list — enumerate registered tools
 *   - tools/call — invoke a tool by name
 *   - resources/list — enumerate registered file resources
 *   - resources/read — return the content of a resource
 *
 * Usage (via `pramnos mcp:serve`):
 *
 *   $server = new McpServer('MyApp', '1.0.0');
 *   $server->addTool(new ListTablesTool($db));
 *   $server->addResource(new McpResource('file://CLAUDE.md', 'Project guide', ROOT.'/CLAUDE.md'));
 *   $server->run();
 *
 * @package PramnosFramework
 */
class McpServer
{
    /** @var array<string, McpToolInterface> */
    private array $tools = [];

    /** @var array<string, McpResource> */
    private array $resources = [];

    private bool $initialized = false;

    public function __construct(
        private readonly string $appName    = 'Pramnos App',
        private readonly string $appVersion = '1.0.0',
    ) {}

    // ── Tool / Resource Registration ─────────────────────────────────────────

    public function addTool(McpToolInterface $tool): static
    {
        $this->tools[$tool->name()] = $tool;
        return $this;
    }

    public function addResource(McpResource $resource): static
    {
        $this->resources[$resource->uri] = $resource;
        return $this;
    }

    /** @return array<string, McpToolInterface> */
    public function getTools(): array
    {
        return $this->tools;
    }

    /** @return array<string, McpResource> */
    public function getResources(): array
    {
        return $this->resources;
    }

    // ── Main Loop ────────────────────────────────────────────────────────────

    /**
     * Enter the stdio message loop.
     *
     * Reads newline-delimited JSON from STDIN, dispatches each request, and
     * writes the JSON-RPC response to STDOUT. Exits when STDIN is closed.
     *
     * @param resource|null $in   Input stream  (default: STDIN)
     * @param resource|null $out  Output stream (default: STDOUT)
     */
    public function run($in = null, $out = null): void
    {
        $in  = $in  ?? STDIN;
        $out = $out ?? STDOUT;

        while (!feof($in)) {
            $line = fgets($in);
            if ($line === false || trim($line) === '') {
                continue;
            }

            $message = json_decode(trim($line), true);
            if (!is_array($message)) {
                $this->write($out, $this->error(null, -32700, 'Parse error'));
                continue;
            }

            $response = $this->dispatch($message);
            if ($response !== null) {
                $this->write($out, $response);
            }
        }
    }

    // ── Dispatch ─────────────────────────────────────────────────────────────

    /**
     * Handle a single JSON-RPC message and return the response array (or null
     * for notifications that require no response).
     *
     * @param  array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    public function dispatch(array $message): ?array
    {
        $id     = $message['id']     ?? null;
        $method = $message['method'] ?? '';
        $params = $message['params'] ?? [];

        // Notifications (no id) — acknowledged but no response
        if (!array_key_exists('id', $message)) {
            if ($method === 'notifications/initialized') {
                $this->initialized = true;
            }
            return null;
        }

        return match ($method) {
            'initialize'      => $this->handleInitialize($id, $params),
            'tools/list'      => $this->handleToolsList($id),
            'tools/call'      => $this->handleToolsCall($id, $params),
            'resources/list'  => $this->handleResourcesList($id),
            'resources/read'  => $this->handleResourcesRead($id, $params),
            'ping'            => $this->result($id, []),
            default           => $this->error($id, -32601, "Method not found: {$method}"),
        };
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    private function handleInitialize(mixed $id, array $params): array
    {
        return $this->result($id, [
            'protocolVersion' => '2024-11-05',
            'capabilities'    => [
                'tools'     => ['listChanged' => false],
                'resources' => ['listChanged' => false, 'subscribe' => false],
            ],
            'serverInfo' => [
                'name'    => $this->appName,
                'version' => $this->appVersion,
            ],
        ]);
    }

    private function handleToolsList(mixed $id): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[] = [
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }
        return $this->result($id, ['tools' => $tools]);
    }

    private function handleToolsCall(mixed $id, array $params): array
    {
        $name      = $params['name']      ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$name])) {
            return $this->error($id, -32602, "Unknown tool: {$name}");
        }

        try {
            $output = $this->tools[$name]->execute($arguments);
            $text   = is_string($output) ? $output : json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => $text]],
                'isError'  => false,
            ]);
        } catch (\Throwable $e) {
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError'  => true,
            ]);
        }
    }

    private function handleResourcesList(mixed $id): array
    {
        $resources = [];
        foreach ($this->resources as $resource) {
            $resources[] = $resource->toListItem();
        }
        return $this->result($id, ['resources' => $resources]);
    }

    private function handleResourcesRead(mixed $id, array $params): array
    {
        $uri = $params['uri'] ?? '';

        if (!isset($this->resources[$uri])) {
            return $this->error($id, -32602, "Unknown resource: {$uri}");
        }

        $content = $this->resources[$uri]->read();
        if ($content === null) {
            return $this->error($id, -32602, "Resource not readable: {$uri}");
        }

        return $this->result($id, [
            'contents' => [[
                'uri'      => $uri,
                'mimeType' => $this->resources[$uri]->mimeType,
                'text'     => $content,
            ]],
        ]);
    }

    // ── JSON-RPC Helpers ──────────────────────────────────────────────────────

    /**
     * @param  mixed $id
     * @param  array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function result(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param  mixed $id
     * @return array<string, mixed>
     */
    public function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ];
    }

    private function write($stream, array $message): void
    {
        fwrite($stream, json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
