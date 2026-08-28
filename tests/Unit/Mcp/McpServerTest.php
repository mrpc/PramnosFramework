<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\McpServer;
use Pramnos\Mcp\McpResource;
use Pramnos\Mcp\McpToolInterface;

/**
 * Unit tests for McpServer — JSON-RPC 2.0 protocol handling and tool dispatch.
 *
 * These tests verify the MCP message exchange without a real database or file
 * system: all tools and resources are test doubles. The goal is to confirm
 * that every supported MCP method (initialize, tools/list, tools/call,
 * resources/list, resources/read, ping) produces the correct response shape
 * and that error cases (unknown method, unknown tool, bad resource URI) emit
 * the appropriate JSON-RPC error objects.
 */
class McpServerTest extends TestCase
{
    private McpServer $server;

    protected function setUp(): void
    {
        $this->server = new McpServer('TestApp', '2.0.0');
    }

    // ── initialize ───────────────────────────────────────────────────────────

    /**
     * initialize must return protocolVersion, capabilities, and serverInfo.
     *
     * This is the first message sent by any MCP client; the response tells it
     * what protocol version and capabilities are available.
     */
    public function testInitializeReturnsServerInfo(): void
    {
        // Arrange
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []];

        // Act
        $response = $this->server->dispatch($message);

        // Assert — result shape
        $this->assertNotNull($response);
        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertSame(1, $response['id']);
        $this->assertArrayHasKey('result', $response);
        $result = $response['result'];
        $this->assertArrayHasKey('protocolVersion', $result);
        $this->assertArrayHasKey('capabilities', $result);
        $this->assertArrayHasKey('serverInfo', $result);
        $this->assertSame('TestApp', $result['serverInfo']['name']);
        $this->assertSame('2.0.0', $result['serverInfo']['version']);
    }

    // ── tools/list ────────────────────────────────────────────────────────────

    /**
     * tools/list must enumerate all registered tools with name, description,
     * and inputSchema.
     *
     * The AI client calls this to discover what operations are available before
     * deciding which tool to invoke.
     */
    public function testToolsListReturnsRegisteredTools(): void
    {
        // Arrange
        $tool = $this->makeTool('my-tool', 'Does something', ['type' => 'object', 'properties' => []]);
        $this->server->addTool($tool);
        $message = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []];

        // Act
        $response = $this->server->dispatch($message);

        // Assert — the tool appears in the list
        $this->assertNotNull($response);
        $tools = $response['result']['tools'] ?? [];
        $this->assertCount(1, $tools);
        $this->assertSame('my-tool', $tools[0]['name']);
        $this->assertSame('Does something', $tools[0]['description']);
        $this->assertArrayHasKey('inputSchema', $tools[0]);
    }

    /**
     * tools/list with no tools registered must return an empty tools array.
     */
    public function testToolsListEmptyWhenNoToolsRegistered(): void
    {
        // Arrange
        $message = ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => []];

        // Act
        $response = $this->server->dispatch($message);

        // Assert
        $this->assertSame([], $response['result']['tools']);
    }

    // ── tools/call ────────────────────────────────────────────────────────────

    /**
     * tools/call with a known tool name must invoke execute() and return its
     * output wrapped in a content array.
     *
     * The 'content' key is what the AI client renders to the user.
     */
    public function testToolsCallInvokesToolAndReturnsContent(): void
    {
        // Arrange
        $tool = $this->makeTool('echo-tool', 'Echoes input', ['type' => 'object', 'properties' => []]);
        $tool->method('execute')->willReturn(['hello' => 'world']);
        $this->server->addTool($tool);

        $message = [
            'jsonrpc' => '2.0', 'id' => 4,
            'method'  => 'tools/call',
            'params'  => ['name' => 'echo-tool', 'arguments' => []],
        ];

        // Act
        $response = $this->server->dispatch($message);

        // Assert — content array present, isError false
        $this->assertNotNull($response);
        $result = $response['result'];
        $this->assertArrayHasKey('content', $result);
        $this->assertFalse($result['isError']);
        $this->assertSame('text', $result['content'][0]['type']);
    }

    /**
     * tools/call with an unknown tool name must return a JSON-RPC error.
     *
     * The client must be told that no such tool exists rather than receiving
     * a silent null response.
     */
    public function testToolsCallUnknownToolReturnsError(): void
    {
        // Arrange
        $message = [
            'jsonrpc' => '2.0', 'id' => 5,
            'method'  => 'tools/call',
            'params'  => ['name' => 'nonexistent', 'arguments' => []],
        ];

        // Act
        $response = $this->server->dispatch($message);

        // Assert — error object present, correct code
        $this->assertArrayHasKey('error', $response);
        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('nonexistent', $response['error']['message']);
    }

    /**
     * tools/call must wrap tool exceptions in an isError result rather than
     * crashing the server.
     *
     * The server loop must remain stable even if a tool throws an unexpected
     * exception — it must survive and keep processing subsequent requests.
     */
    public function testToolsCallExceptionBecomesIsErrorResponse(): void
    {
        // Arrange
        $tool = $this->makeTool('bad-tool', 'Throws', ['type' => 'object', 'properties' => []]);
        $tool->method('execute')->willThrowException(new \RuntimeException('Boom'));
        $this->server->addTool($tool);

        $message = [
            'jsonrpc' => '2.0', 'id' => 6,
            'method'  => 'tools/call',
            'params'  => ['name' => 'bad-tool', 'arguments' => []],
        ];

        // Act
        $response = $this->server->dispatch($message);

        // Assert — server returns an isError response, not an unhandled exception
        $this->assertNotNull($response);
        $this->assertTrue($response['result']['isError']);
        $this->assertStringContainsString('Boom', $response['result']['content'][0]['text']);
    }

    // ── resources/list ────────────────────────────────────────────────────────

    /**
     * resources/list must enumerate registered resources.
     *
     * The AI can call this to discover project files it may read for context.
     */
    public function testResourcesListReturnsRegisteredResources(): void
    {
        // Arrange
        $resource = new McpResource('file://test.md', 'Test file', '/nonexistent/test.md');
        $this->server->addResource($resource);
        $message = ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'resources/list', 'params' => []];

        // Act
        $response = $this->server->dispatch($message);

        // Assert
        $resources = $response['result']['resources'];
        $this->assertCount(1, $resources);
        $this->assertSame('file://test.md', $resources[0]['uri']);
        $this->assertSame('Test file', $resources[0]['name']);
    }

    // ── resources/read ────────────────────────────────────────────────────────

    /**
     * resources/read with a valid URI must return the file content.
     *
     * The contents array is how the AI receives the raw file text.
     */
    public function testResourcesReadReturnsFileContent(): void
    {
        // Arrange — use a real temp file so McpResource::read() can read it
        $tmp = tempnam(sys_get_temp_dir(), 'mcp_test_');
        file_put_contents($tmp, 'hello from file');
        $resource = new McpResource('file://temp', 'Temp', $tmp);
        $this->server->addResource($resource);

        $message = [
            'jsonrpc' => '2.0', 'id' => 8,
            'method'  => 'resources/read',
            'params'  => ['uri' => 'file://temp'],
        ];

        // Act
        $response = $this->server->dispatch($message);

        // Assert
        $this->assertArrayHasKey('result', $response);
        $contents = $response['result']['contents'];
        $this->assertSame('hello from file', $contents[0]['text']);

        // Cleanup
        unlink($tmp);
    }

    /**
     * resources/read with an unknown URI must return a JSON-RPC error.
     */
    public function testResourcesReadUnknownUriReturnsError(): void
    {
        // Arrange
        $message = [
            'jsonrpc' => '2.0', 'id' => 9,
            'method'  => 'resources/read',
            'params'  => ['uri' => 'file://does-not-exist'],
        ];

        // Act
        $response = $this->server->dispatch($message);

        // Assert
        $this->assertArrayHasKey('error', $response);
        $this->assertSame(-32602, $response['error']['code']);
    }

    // ── ping ─────────────────────────────────────────────────────────────────

    /**
     * ping must return an empty result (keepalive mechanism).
     */
    public function testPingReturnsEmptyResult(): void
    {
        // Arrange
        $message = ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'ping', 'params' => []];

        // Act
        $response = $this->server->dispatch($message);

        // Assert
        $this->assertSame([], $response['result']);
    }

    // ── unknown method ────────────────────────────────────────────────────────

    /**
     * Unknown method names must return JSON-RPC error -32601 (Method not found).
     */
    public function testUnknownMethodReturnsMethodNotFoundError(): void
    {
        // Arrange
        $message = ['jsonrpc' => '2.0', 'id' => 11, 'method' => 'magic/sparkle', 'params' => []];

        // Act
        $response = $this->server->dispatch($message);

        // Assert
        $this->assertArrayHasKey('error', $response);
        $this->assertSame(-32601, $response['error']['code']);
    }

    // ── notifications ─────────────────────────────────────────────────────────

    /**
     * Notification messages (no 'id' key) must return null — no response sent.
     *
     * The MCP spec requires that notifications are processed silently.
     */
    public function testNotificationReturnsNull(): void
    {
        // Arrange — no 'id' key = notification
        $message = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];

        // Act
        $result = $this->server->dispatch($message);

        // Assert — null means no response written to STDOUT
        $this->assertNull($result);
    }

    // ── stdio run ─────────────────────────────────────────────────────────────

    /**
     * run() must write JSON-RPC responses to the output stream for each
     * request read from the input stream.
     *
     * This verifies the full stdio loop without spawning a real process.
     */
    public function testRunWritesResponsesToOutputStream(): void
    {
        // Arrange — fake stdin with one initialize request
        $inputData = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]) . "\n";
        $in  = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        fwrite($in, $inputData);
        rewind($in);

        // Act
        $this->server->run($in, $out);

        // Assert — output stream contains a valid JSON response
        rewind($out);
        $raw      = stream_get_contents($out);
        $response = json_decode(trim($raw), true);
        $this->assertIsArray($response);
        $this->assertSame('2.0', $response['jsonrpc']);
        $this->assertArrayHasKey('result', $response);
    }

    // ── getTools / getResources ───────────────────────────────────────────────

    /**
     * getTools() returns the map of registered tools by name.
     *
     * Covers the public accessor that allows callers (e.g. McpServiceProvider)
     * to inspect what tools have been added without going through dispatch().
     */
    public function testGetToolsReturnsRegisteredTools(): void
    {
        // Arrange
        $tool = $this->makeTool('my_tool', 'A test tool', []);
        $this->server->addTool($tool);

        // Act
        $tools = $this->server->getTools();

        // Assert — map contains the tool keyed by name
        $this->assertArrayHasKey('my_tool', $tools,
            'getTools() must return all registered tools indexed by name');
        $this->assertSame($tool, $tools['my_tool']);
    }

    /**
     * getResources() returns the map of registered resources by URI.
     *
     * Covers the public accessor that allows callers to inspect what resources
     * are available without going through dispatch().
     */
    public function testGetResourcesReturnsRegisteredResources(): void
    {
        // Arrange — a resource backed by a real temp file
        $tmpFile = tempnam(sys_get_temp_dir(), 'mcp_');
        file_put_contents($tmpFile, 'resource content');

        $resource = new McpResource('file://test', 'Test', $tmpFile);
        $this->server->addResource($resource);

        try {
            // Act
            $resources = $this->server->getResources();

            // Assert — map contains the resource keyed by URI
            $this->assertArrayHasKey('file://test', $resources,
                'getResources() must return all registered resources indexed by URI');
            $this->assertSame($resource, $resources['file://test']);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * resources/read returns an error when the resource is registered but its
     * backing file does not exist (or is not readable).
     *
     * Covers lines 207-209 of McpServer.php: `if ($content === null)` branch inside
     * handleResourcesRead() — the guard that prevents serving broken resources.
     */
    public function testResourcesReadReturnsErrorWhenFileNotReadable(): void
    {
        // Arrange — resource backed by a non-existent file
        $resource = new McpResource(
            'file://missing',
            'Missing File',
            '/nonexistent/path/that/does/not/exist.txt'
        );
        $this->server->addResource($resource);

        $message = [
            'jsonrpc' => '2.0',
            'id'      => 99,
            'method'  => 'resources/read',
            'params'  => ['uri' => 'file://missing'],
        ];

        // Act
        $response = $this->server->dispatch($message);

        // Assert — error response, NOT a content response
        $this->assertNotNull($response);
        $this->assertArrayHasKey('error', $response,
            'resources/read must return an error when the backing file is missing');
        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('missing', $response['error']['message']);
    }

    /**
     * result() builds a standard JSON-RPC 2.0 success response structure.
     *
     * Covers lines 227-230: the public result() helper used by dispatch internally.
     */
    public function testResultBuildsCorrectResponseStructure(): void
    {
        // Act — call directly instead of through dispatch()
        $response = $this->server->result(42, ['answer' => 'hello']);

        // Assert — standard JSON-RPC shape
        $this->assertSame('2.0',   $response['jsonrpc']);
        $this->assertSame(42,      $response['id']);
        $this->assertSame(['answer' => 'hello'], $response['result']);
    }

    /**
     * error() builds a standard JSON-RPC 2.0 error response structure.
     *
     * Covers lines 236-243: the public error() helper used by dispatch internally.
     */
    public function testErrorBuildsCorrectResponseStructure(): void
    {
        // Act — call directly
        $response = $this->server->error(7, -32600, 'Invalid Request');

        // Assert — standard JSON-RPC error shape
        $this->assertSame('2.0',             $response['jsonrpc']);
        $this->assertSame(7,                  $response['id']);
        $this->assertSame(-32600,             $response['error']['code']);
        $this->assertSame('Invalid Request',  $response['error']['message']);
    }

    /**
     * McpResource::toListItem() returns the required MCP list-item array shape.
     *
     * Covers lines 48-54 of McpResource.php: the serialization helper used in
     * resources/list responses.
     */
    public function testMcpResourceToListItemShape(): void
    {
        // Arrange
        $resource = new McpResource('file://foo', 'Foo', '/tmp/foo.txt', 'text/plain');

        // Act
        $item = $resource->toListItem();

        // Assert — required keys for MCP resources/list
        $this->assertSame('file://foo',  $item['uri']);
        $this->assertSame('Foo',         $item['name']);
        $this->assertSame('text/plain',  $item['mimeType']);
    }

    // ── traffic log ───────────────────────────────────────────────────────────

    /**
     * With a traffic log set, every message in and out is recorded.
     *
     * This exists because `mcp:serve` cannot otherwise be watched. When a real client
     * starts the server — Claude Code, an IDE — **the client owns both pipes**: STDOUT is
     * the protocol and STDERR goes wherever the client puts it. There was no way to see
     * what a tool returned to the assistant, and piping frames in by hand answers a
     * different question: it tests the server you just started, not the one the client is
     * talking to.
     */
    public function testTheTrafficLogRecordsBothDirections(): void
    {
        // Arrange
        $log = sys_get_temp_dir() . '/mcp-traffic-' . bin2hex(random_bytes(4)) . '.log';
        $this->server->setTrafficLog($log);
        $this->server->addTool($this->makeTool('probe', 'A probe', ['type' => 'object']));

        $in  = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        fwrite($in, json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params'  => ['name' => 'probe', 'arguments' => []],
        ]) . "\n");
        rewind($in);

        try {
            // Act
            $this->server->run($in, $out);

            // Assert
            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log))));
            $this->assertCount(2, $lines, 'one line in, one line out');

            $request = json_decode($lines[0], true);
            $reply   = json_decode($lines[1], true);

            // Written in the framework's own structured-log format, so the log viewer,
            // LogAnalytics and the log-errors MCP tool all read it without knowing
            // anything about MCP.
            foreach ([$request, $reply] as $entry) {
                $this->assertArrayHasKey('timestamp', $entry);
                $this->assertArrayHasKey('level', $entry);
                $this->assertArrayHasKey('message', $entry);
            }

            // The summary line is what makes it scannable without unfolding the payload
            $this->assertSame('→ tools/call probe', $request['message']);
            $this->assertSame('← result', $reply['message']);
            $this->assertSame('info', $reply['level']);

            // …and the full payload is still there for when the summary is not enough
            $this->assertSame('probe', $request['data']['params']['name']);
            $this->assertArrayHasKey('duration_ms', $reply);
        } finally {
            @unlink($log);
        }
    }

    /**
     * A failed call is logged at `error`, including a tool that threw.
     *
     * The second half is the one that matters. A tool that throws comes back as a
     * *successful* JSON-RPC response whose content is the exception message — so a log
     * that only looked at `error` objects would file the most interesting event in the
     * session as routine, and it would be unfindable among a thousand good calls.
     */
    public function testAFailedCallIsLoggedAsAnError(): void
    {
        // Arrange
        $log = sys_get_temp_dir() . '/mcp-traffic-' . bin2hex(random_bytes(4)) . '.log';
        $this->server->setTrafficLog($log);

        $throwing = $this->createMock(McpToolInterface::class);
        $throwing->method('name')->willReturn('breaks');
        $throwing->method('description')->willReturn('Throws');
        $throwing->method('inputSchema')->willReturn(['type' => 'object']);
        $throwing->method('execute')->willThrowException(new \RuntimeException('nope'));
        $this->server->addTool($throwing);

        $in  = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        fwrite($in, json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params'  => ['name' => 'breaks', 'arguments' => []],
        ]) . "\n");
        fwrite($in, json_encode([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'nonsense/method',
        ]) . "\n");
        rewind($in);

        try {
            // Act
            $this->server->run($in, $out);

            // Assert
            $levels = array_map(
                static fn (string $line): string => (string) (json_decode($line, true)['level'] ?? ''),
                array_values(array_filter(explode("\n", (string) file_get_contents($log))))
            );

            // in, out(isError), in, out(error object)
            $this->assertSame(['info', 'error', 'info', 'error'], $levels);
        } finally {
            @unlink($log);
        }
    }

    /**
     * A malformed line is logged with the input that caused it.
     *
     * "Parse error" on its own is the least actionable message a protocol can produce:
     * the one thing needed to fix it is the line that failed.
     */
    public function testAMalformedLineIsLoggedWithTheInput(): void
    {
        // Arrange
        $log = sys_get_temp_dir() . '/mcp-traffic-' . bin2hex(random_bytes(4)) . '.log';
        $this->server->setTrafficLog($log);

        $in  = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        fwrite($in, "{not json at all\n");
        rewind($in);

        try {
            // Act
            $this->server->run($in, $out);

            // Assert
            $contents = (string) file_get_contents($log);
            $this->assertStringContainsString('not json at all', $contents);
            $this->assertStringContainsString('Parse error', $contents);
        } finally {
            @unlink($log);
        }
    }

    /**
     * Off by default, and off means nothing is written anywhere.
     *
     * The payloads are whatever a tool returned, which for some tools is table contents.
     * A debugging aid that is on unless disabled is a debugging aid that ships enabled.
     */
    public function testTheTrafficLogIsOffUnlessAskedFor(): void
    {
        // Assert
        $this->assertNull($this->server->getTrafficLog());

        // Arrange — and it can be switched back off
        $this->server->setTrafficLog('/tmp/whatever.log');
        $this->assertSame('/tmp/whatever.log', $this->server->getTrafficLog());
        $this->server->setTrafficLog(null);

        $in  = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']) . "\n");
        rewind($in);

        // Act
        $this->server->run($in, $out);

        // Assert — the exchange happened and nothing was recorded
        rewind($out);
        $this->assertNotSame('', stream_get_contents($out));
        $this->assertFileDoesNotExist('/tmp/whatever.log');
    }

    /**
     * An unwritable log path does not take the server down with it.
     *
     * A debugging aid that kills the process it is instrumenting is worse than no
     * debugging aid — and this one is switched on by somebody who is already looking at
     * a problem.
     */
    public function testAnUnwritableLogPathIsSurvivable(): void
    {
        // Arrange — a path inside a directory that does not exist
        $this->server->setTrafficLog('/nonexistent-' . bin2hex(random_bytes(4)) . '/mcp.log');

        $in  = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        fwrite($in, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']) . "\n");
        rewind($in);

        // Act
        $this->server->run($in, $out);

        // Assert — the protocol answered anyway
        rewind($out);
        $response = json_decode(trim((string) stream_get_contents($out)), true);
        $this->assertSame('2.0', $response['jsonrpc']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeTool(string $name, string $description, array $schema): McpToolInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $tool = $this->createMock(McpToolInterface::class);
        $tool->method('name')->willReturn($name);
        $tool->method('description')->willReturn($description);
        $tool->method('inputSchema')->willReturn($schema);
        $tool->method('execute')->willReturn([]);
        return $tool;
    }
}
