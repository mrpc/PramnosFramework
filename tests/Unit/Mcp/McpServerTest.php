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
