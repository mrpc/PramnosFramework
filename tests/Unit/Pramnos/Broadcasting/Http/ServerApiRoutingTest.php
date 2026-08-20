<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Apps\AppRegistryInterface;
use Pramnos\Broadcasting\Apps\BroadcastApp;
use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\Http\ServerApi;
use Pramnos\Broadcasting\LocalBroadcastServer;

/**
 * How an API request reaches the API: the server answers it on the WebSocket port,
 * on the same code path that used to reject anything that was not an upgrade.
 *
 * The port is shared on purpose. A second listener would need its own address, its
 * own firewall rule and its own supervisor entry to carry requests the process is
 * already able to answer — and it would have to reach into the same in-memory
 * occupancy state anyway.
 */
#[CoversClass(LocalBroadcastServer::class)]
class ServerApiRoutingTest extends TestCase
{
    // Public because the anonymous registry below cannot reach a private constant
    // of its enclosing scope.
    public const KEY    = 'routing-key';
    public const SECRET = 'routing-secret';

    /** @var list<resource> */
    private array $sockets = [];

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->sockets = [];
    }

    private function registry(): AppRegistryInterface
    {
        return new class implements AppRegistryInterface {
            public function findByKey(string $key): ?BroadcastApp
            {
                return $key === ServerApiRoutingTest::KEY
                    ? new BroadcastApp(ServerApiRoutingTest::KEY, ServerApiRoutingTest::SECRET, '', 'Api')
                    : null;
            }

            public function defaultApp(): ?BroadcastApp
            {
                return null;
            }
        };
    }

    /**
     * A server with one handshaking connection, optionally with the API installed.
     *
     * @return array{0:LocalBroadcastServer, 1:resource, 2:resource}
     */
    private function server(bool $withApi, array $subscriptions = []): array
    {
        $server = new LocalBroadcastServer('key', null, new AllowAllAuthorizer());

        if ($withApi) {
            $server->useHttpApi(new ServerApi($server, $this->registry()));
        }
        if ($subscriptions !== []) {
            (new \ReflectionProperty($server, 'subscriptions'))->setValue($server, $subscriptions);
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];
        stream_set_blocking($pair[0], false);

        (new \ReflectionProperty($server, 'clients'))->setValue($server, [
            1 => [
                'socket'    => $pair[1],
                'state'     => 'handshaking',
                'buffer'    => '',
                'channels'  => [],
                'socketId'  => '1.1',
                'pingAt'    => time() + 30,
                'assembler' => null,
            ],
        ]);

        return [$server, $pair[0], $pair[1]];
    }

    /** @return array<string,string> a signed query for $method $path */
    private function signedQuery(string $method, string $path, string $body = ''): array
    {
        $query = [
            'auth_key'       => self::KEY,
            'auth_timestamp' => (string) time(),
            'auth_version'   => '1.0',
        ];

        if ($body !== '') {
            $query['body_md5'] = md5($body);
        }

        ksort($query);
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        $query['auth_signature'] = hash_hmac(
            'sha256',
            strtoupper($method) . "\n" . $path . "\n" . implode('&', $pairs),
            self::SECRET
        );

        return $query;
    }

    /** Feed a raw HTTP request through readClient(). */
    private function request(LocalBroadcastServer $server, mixed $clientEnd, mixed $serverEnd, string $raw): string
    {
        fwrite($clientEnd, $raw);
        (new \ReflectionMethod($server, 'readClient'))->invoke($server, $serverEnd);

        return (string) fread($clientEnd, 65536);
    }

    /** @return array{0:int,1:array<string,mixed>} [status, decoded body] */
    private function parse(string $response): array
    {
        $status = 0;
        if (preg_match('#^HTTP/1\.1 (\d+)#', $response, $m) === 1) {
            $status = (int) $m[1];
        }

        $separator = strpos($response, "\r\n\r\n");
        $body      = $separator === false ? '' : substr($response, $separator + 4);

        return [$status, (array) json_decode($body, true)];
    }

    /**
     * A signed GET is answered with JSON on the WebSocket port.
     */
    public function testAnsweredOnTheWebSocketPort(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server(true, ['ops' => [5 => 5]]);
        $path  = '/apps/1/channels';
        $query = http_build_query($this->signedQuery('GET', $path));

        // Act
        $response = $this->request(
            $server,
            $clientEnd,
            $serverEnd,
            "GET {$path}?{$query} HTTP/1.1\r\nHost: localhost\r\n\r\n"
        );

        // Assert
        [$status, $body] = $this->parse($response);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('application/json', $response);
        $this->assertArrayHasKey('ops', $body['channels']);
    }

    /**
     * A POST with a body publishes, and the response carries a Content-Length so a
     * plain HTTP client knows the body ended.
     */
    public function testPostWithBodyIsHandled(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server(true);
        $path  = '/apps/1/events';
        $body  = (string) json_encode(['name' => 'e', 'channel' => 'ops']);
        $query = http_build_query($this->signedQuery('POST', $path, $body));

        // Act
        $response = $this->request(
            $server,
            $clientEnd,
            $serverEnd,
            "POST {$path}?{$query} HTTP/1.1\r\nHost: localhost\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );

        // Assert
        [$status] = $this->parse($response);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Content-Length:', $response);
    }

    /**
     * A body that has not fully arrived is waited for rather than rejected.
     *
     * Signing binds the body through body_md5, so acting on a truncated one would
     * report tampering for an ordinary TCP segmentation. The buffer keeps
     * accumulating and the handler runs again on the next read.
     */
    public function testWaitsForAnIncompleteBody(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server(true);
        $path  = '/apps/1/events';
        $body  = (string) json_encode(['name' => 'e', 'channel' => 'ops']);
        $query = http_build_query($this->signedQuery('POST', $path, $body));

        $head = "POST {$path}?{$query} HTTP/1.1\r\nHost: localhost\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n";

        // Act — headers plus half the body
        $first = $this->request($server, $clientEnd, $serverEnd, $head . substr($body, 0, 5));

        // Assert — nothing answered yet, and the connection is still open
        $this->assertSame('', $first);
        $clients = (new \ReflectionProperty($server, 'clients'))->getValue($server);
        $this->assertArrayHasKey(1, $clients, 'the connection must stay open');

        // Act — the rest arrives
        $second = $this->request($server, $clientEnd, $serverEnd, substr($body, 5));

        // Assert
        [$status] = $this->parse($second);
        $this->assertSame(200, $status);
    }

    /**
     * An unsigned API request is answered 401 rather than 400.
     *
     * The distinction matters to whoever is holding a client: 400 says "this is not
     * a WebSocket upgrade", 401 says "your signature is the problem".
     */
    public function testUnsignedApiRequestIsUnauthorized(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server(true);

        // Act
        $response = $this->request(
            $server,
            $clientEnd,
            $serverEnd,
            "GET /apps/1/channels HTTP/1.1\r\nHost: localhost\r\n\r\n"
        );

        // Assert
        [$status] = $this->parse($response);
        $this->assertSame(401, $status);
    }

    /**
     * With no API installed, an /apps/ request gets the old 400.
     *
     * This is the compatibility assertion: the API is opt-in, and a deployment that
     * has not enabled it behaves exactly as before — a signed publish endpoint does
     * not appear on a port because the framework was updated.
     */
    public function testApiPathIsRejectedWhenTheApiIsNotInstalled(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server(false);
        $path  = '/apps/1/channels';
        $query = http_build_query($this->signedQuery('GET', $path));

        // Act
        $response = $this->request(
            $server,
            $clientEnd,
            $serverEnd,
            "GET {$path}?{$query} HTTP/1.1\r\nHost: localhost\r\n\r\n"
        );

        // Assert
        $this->assertStringContainsString('400 Bad Request', $response);
    }

    /**
     * A non-API path that is not an upgrade still gets 400, with the API installed.
     *
     * The API must not swallow every malformed request — a client that failed to
     * send an Upgrade header needs to hear about that, not a 404 from an API it was
     * not calling.
     */
    public function testNonApiPathStillGetsBadRequest(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server(true);

        // Act
        $response = $this->request(
            $server,
            $clientEnd,
            $serverEnd,
            "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n"
        );

        // Assert
        $this->assertStringContainsString('400 Bad Request', $response);
    }

    /**
     * A WebSocket upgrade is unaffected by the API being installed.
     */
    public function testWebSocketUpgradeStillWorksWithTheApiInstalled(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server(true);

        // Act
        $response = $this->request(
            $server,
            $clientEnd,
            $serverEnd,
            "GET /app/key?protocol=7 HTTP/1.1\r\nHost: localhost\r\n"
            . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n"
        );

        // Assert
        $this->assertStringContainsString('101 Switching Protocols', $response);
    }

    /**
     * The connection is closed after an API response.
     *
     * An API caller is not a WebSocket client, and leaving its socket in the select
     * set would leak a descriptor per request for the life of the daemon.
     */
    public function testConnectionIsClosedAfterAnApiResponse(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server(true);
        $path  = '/apps/1/channels';
        $query = http_build_query($this->signedQuery('GET', $path));

        // Act
        $this->request(
            $server,
            $clientEnd,
            $serverEnd,
            "GET {$path}?{$query} HTTP/1.1\r\nHost: localhost\r\n\r\n"
        );

        // Assert
        $clients = (new \ReflectionProperty($server, 'clients'))->getValue($server);
        $this->assertSame([], $clients);
    }
}
