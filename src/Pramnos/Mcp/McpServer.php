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
 */
class McpServer
{
    /** @var array<string, McpToolInterface> */
    private array $tools = [];

    /** @var array<string, McpResource> */
    private array $resources = [];

    private bool $initialized = false;

    /**
     * Where to record the JSON-RPC traffic, or null for nowhere.
     *
     * Off by default: this is a debugging aid and the payloads contain whatever a tool
     * returned, which on some tools is table contents.
     */
    private ?string $trafficLog = null;

    public function __construct(
        private readonly string $appName    = 'Pramnos App',
        private readonly string $appVersion = '1.0.0',
    ) {}

    /**
     * The name this server reports in `serverInfo`.
     *
     * A client lists its servers by it, so it is the one string that has to identify
     * *this* project rather than the framework — and a reader (or a test) needs to be
     * able to ask what it ended up as.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->appName;
    }

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

    // ── Traffic log ──────────────────────────────────────────────────────────

    /**
     * Record every message in and out to a log file.
     *
     * The reason this exists at all: when a real client starts the server — Claude Code,
     * an IDE — **the client owns both pipes**. STDOUT is the protocol and STDERR goes to
     * a log the client may or may not surface, so there is no way to watch what a tool
     * returned. Piping JSON-RPC in by hand answers a different question: it tests the
     * server you started, not the one the client is talking to.
     *
     * Lines are written in the framework's own structured-log format — `timestamp`,
     * `level`, `message`, `data` — so the log viewer, `LogAnalytics` and the `log-errors`
     * MCP tool all read this file without knowing anything about MCP. A failed call is
     * logged at `error`, which is what makes it findable among a thousand successful ones.
     *
     * @param ?string $path Absolute path, or null to stop logging
     */
    public function setTrafficLog(?string $path): static
    {
        $this->trafficLog = $path;

        return $this;
    }

    /** Where the traffic is going, for a command that wants to say so. */
    public function getTrafficLog(): ?string
    {
        return $this->trafficLog;
    }

    /**
     * One line of the traffic log.
     *
     * Failures are never swallowed silently *and* never thrown: a debugging aid that
     * kills the server it is instrumenting is worse than no debugging aid. An
     * unwritable path degrades to no logging.
     *
     * @param array<string, mixed> $message
     */
    private function logTraffic(string $direction, array $message, ?float $seconds = null): void
    {
        if ($this->trafficLog === null) {
            return;
        }

        // A response carrying `error`, or a tool result flagged `isError`. The second is
        // the one worth catching: a tool that threw comes back as a *successful*
        // JSON-RPC response whose content is the exception message.
        $failed = isset($message['error'])
            || !empty($message['result']['isError']);

        $entry = [
            'timestamp' => date('c'),
            'level'     => $failed ? 'error' : 'info',
            'message'   => $this->describe($direction, $message),
            'data'      => $message,
        ];

        if ($seconds !== null) {
            $entry['duration_ms'] = round($seconds * 1000, 2);
        }

        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            // Unencodable payload — a resource handle, invalid UTF-8. Say that rather
            // than write nothing, because a gap in the log reads as "nothing happened".
            $line = json_encode([
                'timestamp' => date('c'),
                'level'     => 'warning',
                'message'   => $direction . ' ' . ($message['method'] ?? 'response')
                    . ' — payload could not be encoded for the log',
            ]);
        }

        @file_put_contents($this->trafficLog, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * The one-line summary that makes the log readable without unfolding the payload.
     *
     * `→ tools/call log-analytics` beats a hundred characters of JSON when you are
     * scrolling for the moment something went wrong.
     *
     * @param array<string, mixed> $message
     */
    private function describe(string $direction, array $message): string
    {
        $arrow = $direction === 'in' ? '→' : '←';

        if (isset($message['method'])) {
            $what = (string) $message['method'];

            if ($what === 'tools/call' && isset($message['params']['name'])) {
                $what .= ' ' . (string) $message['params']['name'];
            }

            return $arrow . ' ' . $what;
        }

        if (isset($message['error'])) {
            return $arrow . ' error ' . (string) ($message['error']['code'] ?? '')
                . ': ' . (string) ($message['error']['message'] ?? '');
        }

        return $arrow . ' result';
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
                $parseError = $this->error(null, -32700, 'Parse error');
                // Logged with the offending line, because "parse error" without the input
                // that caused it is the least actionable message a protocol can produce.
                $this->logTraffic('in', ['method' => 'malformed', 'raw' => trim($line)]);
                $this->logTraffic('out', $parseError);
                $this->write($out, $parseError);
                continue;
            }

            $this->logTraffic('in', $message);

            $started  = microtime(true);
            $response = $this->dispatch($message);

            if ($response !== null) {
                $this->logTraffic('out', $response, microtime(true) - $started);
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

        /*
         * Anything a tool prints is captured and kept out of the stream.
         *
         * On stdio the transport **is** STDOUT: one `echo` inside a tool and the client is
         * reading markup where a JSON-RPC frame should be, and reports the whole server as
         * broken. That is not hypothetical — `route-list` discovered routes by requiring every
         * PHP file under a namespace root, swept in the view templates, and printed a page of
         * HTML into the response.
         *
         * The root cause is fixed where it belongs, in route discovery. This is the guard at
         * the boundary, because the next tool to do it will be a different tool: a stray
         * `var_dump`, a deprecation notice from a library, a warning from a driver. The
         * protocol has to survive all of them.
         *
         * Captured rather than discarded — silence would hide the very thing somebody needs to
         * see — and reported on the result as `stray_output`.
         */
        ob_start();

        try {
            $output = $this->tools[$name]->execute($arguments);
            $stray  = (string) ob_get_clean();
            $text   = is_string($output) ? $output : json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

            $result = [
                'content' => [['type' => 'text', 'text' => $text]],
                'isError'  => false,
            ];

            if (trim($stray) !== '') {
                $result['content'][] = [
                    'type' => 'text',
                    'text' => 'This tool printed ' . strlen($stray) . ' bytes of output, which '
                        . 'would have corrupted the protocol stream and was captured instead. '
                        . 'The first 500 bytes: ' . substr($stray, 0, 500),
                ];
                $result['stray_output'] = true;
            }

            return $this->result($id, $result);
        } catch (\Throwable $e) {
            // Discarded here on purpose: the exception message is the answer, and prepending
            // half a page of markup to it helps nobody.
            ob_end_clean();

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
