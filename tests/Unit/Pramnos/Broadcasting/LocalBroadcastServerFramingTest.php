<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\LocalBroadcastServer;
use Pramnos\Http\WebSocket\FrameCodec;

/**
 * Framing behaviour of the broadcast server after its framing moved to the shared
 * {@see FrameCodec} / {@see \Pramnos\Http\WebSocket\MessageAssembler} pair.
 *
 * Each test here pins something the server got wrong before that move, and each
 * failure mode was silent: no exception, no log line, just a message that did not
 * arrive or arrived in pieces.
 */
#[CoversClass(LocalBroadcastServer::class)]
class LocalBroadcastServerFramingTest extends TestCase
{
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
     * Build a client→server frame: masked, as RFC 6455 §5.3 requires, with FIN
     * and opcode under the caller's control so fragmentation can be expressed.
     */
    private function clientFrame(string $payload, int $opcode, bool $fin): string
    {
        $frame = chr(($fin ? 0x80 : 0x00) | $opcode);
        $len   = strlen($payload);
        $mask  = "\x01\x02\x03\x04";

        $frame .= chr(0x80 | $len) . $mask;

        for ($i = 0; $i < $len; $i++) {
            $frame .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }

        return $frame;
    }

    /**
     * @return array{0:LocalBroadcastServer,1:resource,2:resource} [server, client end, server end]
     */
    private function connectedServer(string $state = 'connected'): array
    {
        $server  = new LocalBroadcastServer('test-key');
        $pair    = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];
        stream_set_blocking($pair[0], false);

        (new \ReflectionProperty($server, 'clients'))->setValue($server, [
            1 => [
                'socket'    => $pair[1],
                'state'     => $state,
                'buffer'    => '',
                'channels'  => [],
                'socketId'  => '1.2',
                'pingAt'    => time() + 30,
                'assembler' => null,
            ],
        ]);

        return [$server, $pair[0], $pair[1]];
    }

    private function readClient(LocalBroadcastServer $server, mixed $serverEnd): void
    {
        (new \ReflectionMethod($server, 'readClient'))->invoke($server, $serverEnd);
    }

    /**
     * A subscribe message split across a text frame and a continuation frame is
     * acted on once, as one message.
     *
     * Before the framing was shared, the server read the opcode but never the FIN
     * bit, so each fragment reached the JSON decoder on its own. Both halves are
     * invalid JSON, so the subscribe was dropped — from a client that had done
     * nothing wrong. The assertion that matters is that the channel is subscribed
     * at all.
     */
    public function testActsOnFragmentedSubscribeAsOneMessage(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->connectedServer();
        $payload = json_encode([
            'event' => 'pusher:subscribe',
            'data'  => ['channel' => 'fragmented-channel'],
        ]);
        $half = (int) floor(strlen($payload) / 2);

        fwrite($clientEnd, $this->clientFrame(substr($payload, 0, $half), FrameCodec::OP_TEXT, false));
        fwrite($clientEnd, $this->clientFrame(substr($payload, $half), FrameCodec::OP_CONTINUATION, true));

        // Act
        $this->readClient($server, $serverEnd);

        // Assert
        $subscriptions = (new \ReflectionProperty($server, 'subscriptions'))->getValue($server);
        $this->assertArrayHasKey(
            'fragmented-channel',
            $subscriptions,
            'the two fragments must be reassembled into one subscribe message'
        );
    }

    /**
     * A frame arriving in the same TCP segment as the handshake is not lost.
     *
     * The handshake used to clear the whole read buffer once it completed, which
     * discarded anything the client had pipelined behind its request. It is a
     * timing-dependent loss: the same client works whenever the kernel happens to
     * deliver the two writes separately.
     */
    public function testFramePipelinedWithHandshakeIsNotLost(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->connectedServer('handshaking');
        $subscribe = $this->clientFrame(
            (string) json_encode([
                'event' => 'pusher:subscribe',
                'data'  => ['channel' => 'pipelined-channel'],
            ]),
            FrameCodec::OP_TEXT,
            true
        );

        $handshake = "GET /app/test-key?protocol=7 HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";

        // One write, so both land in the same segment.
        fwrite($clientEnd, $handshake . $subscribe);

        // Act
        $this->readClient($server, $serverEnd);

        // Assert
        $response = (string) fread($clientEnd, 8192);
        $this->assertStringContainsString('101 Switching Protocols', $response);
        // The connection_established frame must still precede anything the
        // pipelined frame caused, or a client sees events before it has a socket id.
        $this->assertStringContainsString('pusher:connection_established', $response);

        $subscriptions = (new \ReflectionProperty($server, 'subscriptions'))->getValue($server);
        $this->assertArrayHasKey(
            'pipelined-channel',
            $subscriptions,
            'a frame pipelined behind the handshake must still be processed'
        );
    }

    /**
     * A framing violation disconnects the client instead of being ignored.
     *
     * Once frame boundaries are lost every later frame is misread too, so the only
     * safe action is to stop. Continuing would feed the JSON decoder arbitrary
     * slices of the stream — which is how a desynchronised connection turns into
     * plausible-looking garbage rather than an error.
     */
    public function testFramingViolationDisconnectsClient(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->connectedServer();
        // A continuation frame with no message in progress.
        fwrite($clientEnd, $this->clientFrame('orphan', FrameCodec::OP_CONTINUATION, true));

        // Act
        $this->readClient($server, $serverEnd);

        // Assert
        $clients = (new \ReflectionProperty($server, 'clients'))->getValue($server);
        $this->assertArrayNotHasKey(1, $clients, 'the offending client must be disconnected');
    }

    /**
     * A ping is answered with a pong carrying the same payload.
     *
     * Covers the control-frame path through the assembler: a ping must not be
     * held behind a data message, and must not reach the Pusher message handler.
     */
    public function testPingIsAnsweredWithMatchingPong(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->connectedServer();
        fwrite($clientEnd, $this->clientFrame('liveness', FrameCodec::OP_PING, true));

        // Act
        $this->readClient($server, $serverEnd);

        // Assert
        $reply = FrameCodec::decode((string) fread($clientEnd, 8192));
        $this->assertNotNull($reply);
        $this->assertSame(FrameCodec::OP_PONG, $reply['opcode']);
        $this->assertSame('liveness', $reply['payload']);
    }

    /**
     * A close frame from the client closes the connection from our side too.
     */
    public function testCloseFrameDisconnectsClient(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->connectedServer();
        fwrite($clientEnd, $this->clientFrame('', FrameCodec::OP_CLOSE, true));

        // Act
        $this->readClient($server, $serverEnd);

        // Assert
        $clients = (new \ReflectionProperty($server, 'clients'))->getValue($server);
        $this->assertArrayNotHasKey(1, $clients);
    }

    /**
     * A server frame must not be masked (RFC 6455 §5.3).
     *
     * A masked server frame is a protocol error that conforming clients — pusher-js
     * among them — close the connection on, so this is the difference between a
     * working broadcast and a reconnect loop.
     */
    public function testServerFramesAreNotMasked(): void
    {
        // Arrange
        [$server, $clientEnd, $serverEnd] = $this->connectedServer();
        (new \ReflectionProperty($server, 'subscriptions'))->setValue($server, ['room' => [1 => 1]]);

        // Act
        $server->broadcast('room', 'event.name', ['k' => 'v']);

        // Assert
        $raw = (string) fread($clientEnd, 8192);
        $this->assertSame(0x00, ord($raw[1]) & 0x80, 'the mask bit must be clear on a server frame');
        $decoded = FrameCodec::decode($raw);
        $this->assertStringContainsString('event.name', $decoded['payload']);
    }

    /**
     * acceptClient() gives every new connection an assembler slot.
     *
     * The slot has to exist from the start: the read path creates one lazily if it
     * is missing, but a connection that reached the frame loop without one used to
     * read every frame into a no-op — silence rather than an error.
     */
    public function testAcceptedClientGetsAnAssemblerSlot(): void
    {
        // Arrange
        $server   = new LocalBroadcastServer('test-key');
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener, 'test needs a local listening socket');
        $this->sockets[] = $listener;
        $port = (int) explode(':', (string) stream_socket_get_name($listener, false))[1];

        (new \ReflectionProperty($server, 'serverSocket'))->setValue($server, $listener);

        $incoming = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1.0);
        $this->assertNotFalse($incoming);
        $this->sockets[] = $incoming;

        // Act
        (new \ReflectionMethod($server, 'acceptClient'))->invoke($server);

        // Assert
        $clients = (new \ReflectionProperty($server, 'clients'))->getValue($server);
        $this->assertCount(1, $clients);
        $client = reset($clients);
        $this->assertSame('handshaking', $client['state']);
        $this->assertArrayHasKey('assembler', $client, 'the slot must exist from acceptance');
        $this->assertNull($client['assembler'], 'and start empty, since no frame can have arrived yet');
    }

    /**
     * Reading from a socket the server does not know is a no-op rather than an
     * error, which is what keeps a stale entry in a select set from faulting the
     * loop.
     */
    public function testReadFromUnknownSocketIsIgnored(): void
    {
        // Arrange
        [$server] = $this->connectedServer();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        // Act
        $this->readClient($server, $pair[0]);

        // Assert — the known client is untouched and nothing threw.
        $clients = (new \ReflectionProperty($server, 'clients'))->getValue($server);
        $this->assertArrayHasKey(1, $clients);
    }
}
