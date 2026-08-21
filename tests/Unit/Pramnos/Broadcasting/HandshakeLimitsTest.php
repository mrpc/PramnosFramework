<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Apps\AppRegistryInterface;
use Pramnos\Broadcasting\Apps\BroadcastApp;
use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\Http\ServerApi;
use Pramnos\Broadcasting\LocalBroadcastServer;

/**
 * Ceilings and a deadline on the pre-authentication read.
 *
 * The handshake buffer was the one unbounded read in the class, and it is the one an
 * **unauthenticated** peer controls: `authorizeConnection()` runs after the headers
 * are parsed, which cannot happen until the request is complete. Everything after the
 * handshake had two ceilings already — per frame and per reassembled message — and
 * closed the connection loudly on a violation. The handshake had none.
 *
 * In a single-process daemon that is not a slow client. Reaching `memory_limit` is a
 * fatal that takes every other connected client with it, and the supervisor then
 * restarts a worker whose clients all re-handshake at once.
 *
 * Reported by a project whose realtime daemon is internet-facing by design — it
 * advertises its host and port to every browser — and filed as a read of the loop
 * rather than a measurement, which is the right way round for this one.
 */
#[CoversClass(LocalBroadcastServer::class)]
class HandshakeLimitsTest extends TestCase
{
    private const KEY    = 'limits-key';
    private const SECRET = 'limits-secret';

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

    /**
     * A server with one handshaking connection.
     *
     * @return array{0:LocalBroadcastServer, 1:resource, 2:resource}
     */
    private function server(bool $withApi = false, int $connectedAt = 0): array
    {
        $server = new LocalBroadcastServer('key', null, new AllowAllAuthorizer());

        if ($withApi) {
            $registry = new class implements AppRegistryInterface {
                public function findByKey(string $key): ?BroadcastApp
                {
                    return $key === HandshakeLimitsTest::KEY
                        ? new BroadcastApp(HandshakeLimitsTest::KEY, HandshakeLimitsTest::SECRET)
                        : null;
                }

                public function defaultApp(): ?BroadcastApp
                {
                    return null;
                }
            };
            $server->useHttpApi(new ServerApi($server, $registry));
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];
        stream_set_blocking($pair[0], false);

        (new \ReflectionProperty($server, 'clients'))->setValue($server, [
            1 => [
                'socket'      => $pair[1],
                'state'       => 'handshaking',
                'buffer'      => '',
                'channels'    => [],
                'socketId'    => '1.1',
                'pingAt'      => time() + 30,
                'connectedAt' => $connectedAt !== 0 ? $connectedAt : time(),
                'assembler'   => null,
            ],
        ]);

        return [$server, $pair[0], $pair[1]];
    }

    private function feed(LocalBroadcastServer $server, mixed $clientEnd, mixed $serverEnd, string $bytes): string
    {
        fwrite($clientEnd, $bytes);
        (new \ReflectionMethod($server, 'readClient'))->invoke($server, $serverEnd);

        return (string) fread($clientEnd, 65536);
    }

    /**
     * Write $bytes and drive the read loop until it is all consumed, or the server
     * drops the connection.
     *
     * A single `readClient()` reads at most 8 KiB, and a socket pair's kernel buffer
     * is smaller than the limits under test — so one write plus one read can never
     * reach a ceiling. Anything asserting on a limit has to pump.
     *
     * @return string Whatever the server wrote back.
     */
    private function pump(
        LocalBroadcastServer $server,
        mixed $clientEnd,
        mixed $serverEnd,
        string $bytes
    ): string {
        $read     = new \ReflectionMethod($server, 'readClient');
        $response = '';
        $offset   = 0;
        $length   = strlen($bytes);

        // Bounded, so a server that never consumes fails the test rather than
        // hanging it.
        for ($guard = 0; $guard < 5000 && $offset < $length; $guard++) {
            $written = @fwrite($clientEnd, substr($bytes, $offset, 8192));

            if ($written !== false && $written > 0) {
                $offset += $written;
            }

            $read->invoke($server, $serverEnd);
            $response .= (string) fread($clientEnd, 65536);

            if ($this->clients($server) === []) {
                break;      // refused; nothing left to write to
            }
        }

        return $response;
    }

    private function clients(LocalBroadcastServer $server): array
    {
        return (new \ReflectionProperty($server, 'clients'))->getValue($server);
    }

    // -------------------------------------------------------------------------
    // Header ceiling
    // -------------------------------------------------------------------------

    /**
     * Headers past the ceiling, with no terminator in sight, get 431 and the
     * connection goes.
     *
     * This is the reproduction from the filing: connect, write, never send a blank
     * line. The buffer used to follow the client up.
     */
    public function testOversizedHeadersAreRefusedWith431(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server();
        $head = "GET /app/key HTTP/1.1\r\nHost: x\r\nX-Pad: ";

        // Act — headers, then padding past the ceiling, never a blank line
        $response = $this->pump(
            $server,
            $clientEnd,
            $serverEnd,
            $head . str_repeat('A', LocalBroadcastServer::HANDSHAKE_HEADER_MAX + 1)
        );

        // Assert
        $this->assertStringContainsString('431', $response);
        $this->assertSame([], $this->clients($server), 'the connection is dropped');
    }

    /**
     * The buffer does not keep growing once the ceiling is hit.
     *
     * The assertion the filing is really about: not that a status code is returned,
     * but that nothing holds the bytes.
     */
    public function testTheBufferDoesNotGrowPastTheCeiling(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server();

        // Act — write past the ceiling in chunks, as a real client would
        $this->pump(
            $server,
            $clientEnd,
            $serverEnd,
            "GET /app/key HTTP/1.1\r\nX-Pad: " . str_repeat('A', 40960)
        );

        // Assert
        $this->assertSame([], $this->clients($server), 'refused rather than accumulated');
    }

    /**
     * A normal upgrade well under the ceiling is unaffected.
     *
     * The compatibility assertion: the limit must be invisible to every real client.
     */
    public function testANormalHandshakeIsUnaffected(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server();

        // Act
        $response = $this->feed(
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
     * A large *body* after complete headers is not treated as oversized headers.
     *
     * The header ceiling applies only before the terminator. Applying it afterwards
     * would break the HTTP API, whose whole reason for accumulating is that a body
     * may arrive across several reads.
     */
    public function testTheHeaderCeilingDoesNotApplyOnceHeadersAreComplete(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server(withApi: true);
        $body = str_repeat('x', LocalBroadcastServer::HANDSHAKE_HEADER_MAX + 1000);

        // Act — headers complete, then a body larger than the header ceiling but
        // under the body ceiling
        $response = $this->pump(
            $server,
            $clientEnd,
            $serverEnd,
            "POST /apps/1/events HTTP/1.1\r\nHost: x\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body
        );

        // Assert — it reached the API and was refused there as unsigned, not as 431
        $this->assertStringNotContainsString('431', $response);
        $this->assertStringContainsString('401', $response);
    }

    // -------------------------------------------------------------------------
    // Body ceiling
    // -------------------------------------------------------------------------

    /**
     * A declared body over the ceiling is refused before it is read.
     *
     * Refused rather than truncated, and that is not a preference: a truncated body
     * fails `body_md5` and reads as tampering, which is the worst available answer.
     */
    public function testAnOversizedDeclaredBodyIsRefusedWith413(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server(withApi: true);
        $declared = LocalBroadcastServer::API_BODY_MAX + 1;

        // Act — only the headers are sent; the body never needs to arrive
        $response = $this->feed(
            $server,
            $clientEnd,
            $serverEnd,
            "POST /apps/1/events HTTP/1.1\r\nHost: x\r\n"
            . 'Content-Length: ' . $declared . "\r\n\r\n"
        );

        // Assert
        $this->assertStringContainsString('413', $response);
        $this->assertSame([], $this->clients($server));
    }

    /**
     * A body with no Content-Length cannot accumulate, because nothing waits for it.
     *
     * This is the answer to the second half of the filing, and it is a different
     * answer than expected: an undeclared body needs no ceiling of its own. Nothing
     * declares where it ends, so the request is dispatched on the read that completed
     * the headers and never grows across reads — and it cannot be signed either,
     * since `body_md5` needs a complete body, so it is refused a moment later by the
     * signature check.
     *
     * Asserted rather than reasoned about, because "unbounded growth is impossible
     * here" is exactly the kind of claim that stops being true when somebody makes
     * the API wait for an undeclared body.
     */
    public function testAnUndeclaredBodyIsDispatchedRatherThanAccumulated(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server(withApi: true);

        // Act — far more than the body ceiling, with no Content-Length
        $response = $this->pump(
            $server,
            $clientEnd,
            $serverEnd,
            "POST /apps/1/events HTTP/1.1\r\nHost: x\r\n\r\n"
            . str_repeat('x', LocalBroadcastServer::API_BODY_MAX + 1)
        );

        // Assert — answered and closed on the first read, so nothing accumulated
        $this->assertStringContainsString('401', $response, 'refused as unsigned');
        $this->assertSame([], $this->clients($server), 'and the connection is gone');
    }

    /**
     * A body inside the ceiling still waits for the rest of itself.
     *
     * The behaviour the API depends on, unchanged: a partially-arrived body is waited
     * for rather than acted on, because acting on it would report tampering for
     * ordinary TCP segmentation.
     */
    public function testABodyInsideTheCeilingIsStillWaitedFor(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->server(withApi: true);

        // Act — declare 100 bytes, send 10
        $response = $this->feed(
            $server,
            $clientEnd,
            $serverEnd,
            "POST /apps/1/events HTTP/1.1\r\nHost: x\r\nContent-Length: 100\r\n\r\n" . str_repeat('x', 10)
        );

        // Assert
        $this->assertSame('', $response, 'nothing answered yet');
        $this->assertArrayHasKey(1, $this->clients($server), 'and the connection stays open');
    }

    // -------------------------------------------------------------------------
    // Deadline
    // -------------------------------------------------------------------------

    /**
     * A connection that opens and stalls is retired.
     *
     * Size limits do not cover this: one byte and silence is under every ceiling and
     * would hold a slot in `$clients` for ever, because nothing ages out an
     * unfinished handshake.
     */
    public function testAStalledHandshakeIsRetired(): void
    {
        // Arrange — connected longer ago than the timeout allows
        [$server, $clientEnd, $serverEnd] = $this->server(
            connectedAt: time() - LocalBroadcastServer::HANDSHAKE_TIMEOUT - 1
        );
        $this->feed($server, $clientEnd, $serverEnd, 'G');

        // Act — the keepalive sweep is where the check lives
        (new \ReflectionMethod($server, 'sendKeepalives'))->invoke($server);

        // Assert
        $this->assertSame([], $this->clients($server));
        $this->assertStringContainsString('408', (string) fread($clientEnd, 8192));
    }

    /**
     * A connection inside the deadline is left alone.
     */
    public function testAFreshHandshakeIsNotRetired(): void
    {
        // Arrange
        [$server, , ] = $this->server();

        // Act
        (new \ReflectionMethod($server, 'sendKeepalives'))->invoke($server);

        // Assert
        $this->assertArrayHasKey(1, $this->clients($server));
    }

    /**
     * A connected client is never retired by the handshake deadline, however long it
     * has been connected.
     *
     * The deadline is about *unfinished* handshakes. Applying it to an established
     * connection would disconnect every long-lived subscriber — which is every
     * subscriber, since that is the point of a WebSocket.
     */
    public function testAnEstablishedConnectionIsNeverRetiredByTheDeadline(): void
    {
        // Arrange
        [$server, , ] = $this->server(connectedAt: time() - 86_400);
        $clients = $this->clients($server);
        $clients[1]['state'] = 'connected';
        (new \ReflectionProperty($server, 'clients'))->setValue($server, $clients);

        // Act
        (new \ReflectionMethod($server, 'sendKeepalives'))->invoke($server);

        // Assert
        $this->assertArrayHasKey(1, $this->clients($server), 'a day-old subscriber stays');
    }

    /**
     * The asymmetry the filing named is gone: the handshake side now has ceilings
     * too.
     *
     * Kept as an explicit assertion because the filing says to close it when this
     * stops being true, and a constant that exists is the cheapest possible proof.
     */
    public function testBothSidesOfTheReadAreNowBounded(): void
    {
        // Assert — frames had ceilings from the start; the handshake now has its own
        $this->assertGreaterThan(0, \Pramnos\Http\WebSocket\FrameCodec::DEFAULT_MAX_PAYLOAD);
        $this->assertGreaterThan(0, LocalBroadcastServer::HANDSHAKE_HEADER_MAX);
        $this->assertGreaterThan(0, LocalBroadcastServer::API_BODY_MAX);
        $this->assertGreaterThan(0, LocalBroadcastServer::HANDSHAKE_TIMEOUT);
    }
}
