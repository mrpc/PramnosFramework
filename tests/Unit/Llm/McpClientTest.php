<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Http\Client as Http;
use Pramnos\Http\ClientResponse;
use Pramnos\Llm\Llm;
use Pramnos\Llm\Mcp\Client;
use Pramnos\Llm\Mcp\HttpTransport;
use Pramnos\Llm\Mcp\McpException;
use Pramnos\Llm\Mcp\Servers;
use Pramnos\Llm\Mcp\StdioTransport;
use Pramnos\Llm\ToolRegistry;

/**
 * The client of external MCP servers: over stdio against a real process (a small PHP server), and
 * over HTTP against faked responses — JSON and server-sent events.
 *
 * What has to hold: a session starts with `initialize` and its notification; every page of tools
 * is listed; another service's tools reach the model marked untrusted, and only the ones the
 * server says only read are taken as reads; a server that is slow, gone or refusing is said, and
 * one that cannot be reached costs its own tools and not the answer.
 */
#[CoversClass(Client::class)]
#[CoversClass(StdioTransport::class)]
#[CoversClass(HttpTransport::class)]
#[CoversClass(Servers::class)]
class McpClientTest extends LlmTestCase
{
    private const SERVER = ROOT . '/tests/fixtures/mcp/server.php';

    protected function tearDown(): void
    {
        Servers::closeAll();
        parent::tearDown();
    }

    /** The fixture server as a process. */
    private static function stdio(array $env = ['MCP_GREETING' => 'hello'], int $timeout = 5): Client
    {
        return new Client(new StdioTransport([PHP_BINARY, self::SERVER], $env), $timeout);
    }

    /**
     * Over stdio: the session starts, both pages of tools are listed, a call's text comes back
     * past the server's ping, notification and stderr — with the environment it was given — and
     * an error result reads as an error.
     */
    public function testAServerOverStdio(): void
    {
        // Arrange
        $client = self::stdio();

        // Act
        $names  = array_column($client->listTools(), 'name');
        $echo   = $client->callTool('echo', ['say' => 'hi']);
        $failed = $client->callTool('fail', []);

        // Assert
        $this->assertSame(['echo', 'fail', 'slow', 'quit'], $names, 'both pages');
        $this->assertSame('{"say":"hi"} hello', $echo);
        $this->assertSame('Error: no such record', $failed);
        $this->assertSame('fixture', $client->server()['serverInfo']['name']);
    }

    /**
     * Its tools as the framework's: prefixed, untrusted always, a read-only one idempotent and the
     * rest taken to write; the server's metadata on each; and they run through the client.
     */
    public function testTheServersToolsAsTheFrameworks(): void
    {
        // Arrange
        $client = self::stdio();

        // Act
        $tools = $client->tools('fx', ['skills' => ['fixture']]);

        // Assert
        $this->assertSame(['fx__echo', 'fx__fail', 'fx__slow', 'fx__quit'], array_keys($tools));
        $this->assertTrue($tools['fx__echo']['untrusted']);
        $this->assertTrue($tools['fx__echo']['idempotent'], 'readOnlyHint');
        $this->assertArrayNotHasKey('writes', $tools['fx__echo']);
        $this->assertTrue($tools['fx__fail']['writes'], 'no hint: taken to change something');
        $this->assertSame(['fixture'], $tools['fx__fail']['skills']);
        $this->assertStringStartsWith('{"say":"x"}', ($tools['fx__echo']['run'])(['say' => 'x']));
        $this->assertSame('object', $tools['fx__slow']['parameters']['type']);
        $long = Client::nameFor(str_repeat('server', 10), 'tool');
        $this->assertSame(64, strlen($long), 'a name a provider takes');
        $this->assertSame('my_server__a_b', Client::nameFor('my server', 'a.b'));
    }

    /**
     * A server that does not answer in time, one that stops, one that cannot start, and one that
     * refuses a method are each said as an MCP error — never a hang.
     */
    public function testAServerThatFailsIsSaid(): void
    {
        // Arrange
        $slow = self::stdio(timeout: 1);
        $gone = self::stdio();
        $none = new Client(new StdioTransport([]));
        $cant = new Client(new StdioTransport(['/no/such/program']));

        // Act / Assert
        $this->assertThrows(static fn () => $slow->callTool('slow', []), 'did not answer tools/call within 1 seconds');
        $this->assertThrows(static fn () => $gone->callTool('quit', []), 'stopped');
        $this->assertThrows(static fn () => $none->listTools(), 'needs a command');
        $this->assertThrows(static fn () => $cant->listTools(), '');
        $this->assertThrows(static fn () => (new Client(new FakeTransport(['error' => ['message' => 'nope']])))->listTools(), 'refused initialize: nope');
    }

    /**
     * Over HTTP: the session the server names on `initialize` goes with every later message, with
     * the protocol version agreed; a response may come as a stream of events, of which only the
     * one with the request's id is the answer.
     */
    public function testAServerOverHttp(): void
    {
        // Arrange
        $seen = [];
        Http::fake(['*' => static function (Http $request) use (&$seen): ClientResponse {
            $body   = (array) json_decode((string) $request->requestBody(), true);
            $seen[] = [$body['method'] ?? '', $request->requestHeaders()];
            $id     = $body['id'] ?? null;

            return match ($body['method'] ?? '') {
                'initialize' => ClientResponse::make(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['protocolVersion' => Client::PROTOCOL]], 200, ['mcp-session-id' => 's-1']),
                'notifications/initialized' => ClientResponse::make('', 202),
                'tools/list' => ClientResponse::make(
                    "event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\n\n"
                    . "data: {\"jsonrpc\":\"2.0\",\"id\":" . $id . ",\"result\":{\"tools\":[{\"name\":\"search\",\"inputSchema\":{}}]}}\n\n",
                    200,
                    ['content-type' => 'text/event-stream']
                ),
                default => ClientResponse::make(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [], 'structuredContent' => ['hits' => 2]]]),
            };
        }]);
        $client = new Client(new HttpTransport('https://mcp.example.test/mcp', ['Authorization' => 'Bearer t']));

        // Act
        $tools  = $client->listTools();
        $result = $client->callTool('search', []);

        // Assert
        $this->assertSame(['search'], array_column($tools, 'name'));
        $this->assertSame('{"hits":2}', $result, 'structured content where there is no text');
        $this->assertSame(['initialize', 'notifications/initialized', 'tools/list', 'tools/call'], array_column($seen, 0));
        $headers = array_change_key_case($seen[2][1]);
        $this->assertSame('s-1', $headers['mcp-session-id'] ?? null, 'the session goes with later messages');
        $this->assertSame(Client::PROTOCOL, $headers['mcp-protocol-version'] ?? null);
        $this->assertSame('Bearer t', $headers['authorization'] ?? null);
        $this->assertArrayNotHasKey('mcp-session-id', array_change_key_case($seen[0][1]), 'none before the server names one');
    }

    /**
     * Over HTTP, a server that answers an error status, answers with nothing that is the response,
     * or cannot be reached is said.
     */
    public function testAnHttpServerThatFailsIsSaid(): void
    {
        // Arrange / Act / Assert
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make('down', 503)]);
        $this->assertThrows(static fn () => (new Client(new HttpTransport('https://a.test/mcp')))->listTools(), 'answered HTTP 503');
        Http::fake(['*' => static fn (): ClientResponse => ClientResponse::make(['jsonrpc' => '2.0', 'id' => 99, 'result' => []])]);
        $this->assertThrows(static fn () => (new Client(new HttpTransport('https://a.test/mcp')))->listTools(), 'sent no answer to initialize');
        Http::fake(['*' => static fn (): ClientResponse => throw new \RuntimeException('refused')]);
        $this->assertThrows(static fn () => (new Client(new HttpTransport('https://a.test/mcp')))->listTools(), 'could not be reached: refused');
    }

    /**
     * Configured servers, in the application's `llm` section or in an `mcp.json` file: their tools
     * together, through a registry with their metadata; one that cannot be reached is logged and
     * left out; and the clients are reused, then let go.
     */
    public function testConfiguredServers(): void
    {
        // Arrange
        $this->configure(['mcp_servers' => [
            'fx'   => ['command' => PHP_BINARY, 'args' => [self::SERVER], 'env' => ['MCP_GREETING' => 'hi'], 'skills' => ['fixture']],
            'dead' => ['command' => ['/no/such/program']],
            'bad'  => 'not a server',
        ]]);

        // Act
        $tools    = Servers::tools();
        $same     = Servers::client('fx') === Servers::client('fx');
        $registry = Servers::register(new ToolRegistry(static fn (): bool => true));
        $file     = tempnam(sys_get_temp_dir(), 'mcp');
        file_put_contents($file, json_encode(['mcpServers' => ['web' => ['url' => 'https://a.test/mcp', 'headers' => ['X' => '1']]]]));
        $this->configure(['mcp_servers' => $file]);
        $fromFile = Servers::config();
        unlink($file);

        // Assert
        $this->assertSame(['fx__echo', 'fx__fail', 'fx__slow', 'fx__quit'], array_keys($tools), 'the dead one left out');
        $this->assertTrue($same, 'one client a process');
        $this->assertSame(['fixture'], $registry->metadata('fx__echo')['skills']);
        $this->assertTrue($registry->metadata('fx__echo')['parallel'], 'a read-only tool runs at once through the registry');
        $this->assertSame(['web' => ['url' => 'https://a.test/mcp', 'headers' => ['X' => '1']]], $fromFile);
        $this->assertNull(Servers::client('nowhere'));
        $this->configure([]);
        $this->assertSame([], Servers::tools());
    }

    /**
     * A result's other kinds of content read as text a model can use — a resource's text or its
     * address, a link's address, a note for an image — and a tool with no name is left out.
     */
    public function testOtherContentAndNamelessTools(): void
    {
        // Arrange — a server that answers every request with the same result
        $client = new Client(new FakeTransport(['result' => [
            'tools'   => [['name' => ''], ['name' => 'look', 'inputSchema' => []]],
            'content' => [
                ['type' => 'resource', 'resource' => ['uri' => 'file:///a.txt', 'text' => 'the file']],
                ['type' => 'resource', 'resource' => ['uri' => 'file:///b.bin']],
                ['type' => 'resource_link', 'uri' => 'https://x.test/doc'],
                ['type' => 'image', 'data' => '…'],
            ],
        ]]));

        // Act
        $tools = $client->tools('s');
        $text  = $client->callTool('look', []);
        $client->close();

        // Assert
        $this->assertSame(['s__look'], array_keys($tools));
        $this->assertSame("the file\nfile:///b.bin\nhttps://x.test/doc\n[image]", $text);
    }

    /** A server configured by `url` is spoken to over HTTP, and closing it forgets its session. */
    public function testAConfiguredHttpServer(): void
    {
        // Arrange
        $this->configure(['mcp_servers' => ['web' => ['url' => 'https://a.test/mcp']]]);
        Http::fake(['*' => static fn (Http $request): ClientResponse => ClientResponse::make(
            ['jsonrpc' => '2.0', 'id' => json_decode((string) $request->requestBody(), true)['id'] ?? null, 'result' => ['tools' => []]],
            200,
            ['mcp-session-id' => 's-9']
        )]);

        // Act
        $tools = Servers::tools();
        Servers::closeAll();

        // Assert
        $this->assertSame([], $tools);
        $this->assertNotNull(Servers::client('web'), 'made again after closing');
    }

    /** Run, expecting an MCP error saying this. */
    private function assertThrows(callable $run, string $says): void
    {
        try {
            $run();
            $this->fail('Expected an MCP error saying: ' . $says);
        } catch (McpException $e) {
            $this->assertStringContainsString($says, $e->getMessage());
        }
    }
}

/** A transport that answers every request the same, and records nothing. */
final class FakeTransport implements \Pramnos\Llm\Mcp\Transport
{
    /** @param array<string, mixed> $answer */
    public function __construct(private readonly array $answer)
    {
    }

    public function request(array $message, int $timeout): array
    {
        return ['id' => $message['id']] + $this->answer;
    }

    public function notify(array $message): void
    {
    }

    public function close(): void
    {
    }
}
