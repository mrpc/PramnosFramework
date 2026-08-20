<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\WebSocket\FrameCodec;
use Pramnos\Http\WebSocket\WebSocketProtocolException;
use Pramnos\Http\WebSocketClient;

/**
 * Covers the outbound WebSocket client's handshake, framing and loop contract.
 *
 * Every test drives a real socket pair rather than a mock, because the properties
 * that matter here are socket properties: that connect() verifies what it reads
 * back, that read() never blocks, and that a ping is answered without the caller
 * being told. The peer end of the pair stands in for the remote server, so the
 * bytes asserted on are the bytes that would go on the wire.
 */
#[CoversClass(WebSocketClient::class)]
class WebSocketClientTest extends TestCase
{
    /** The fixed handshake nonce used by the anonymous subclass in client(); public because an anonymous class cannot reach a private constant of its enclosing scope. */
    public const TEST_KEY = 'dGhlIHNhbXBsZSBub25jZQ==';

    /** @var list<resource> Sockets to close in tearDown. */
    private array $openSockets = [];

    protected function tearDown(): void
    {
        foreach ($this->openSockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->openSockets = [];
    }

    /**
     * A client whose handshake nonce is fixed, so a test can precompute the
     * matching Sec-WebSocket-Accept — the value connect() verifies.
     *
     * @param resource $ours The end of the pair the client should use.
     */
    private function client(mixed $ours, array $headers = []): WebSocketClient
    {
        return new class('ws://example.test/app/key?protocol=7', $headers, 2.0, FrameCodec::DEFAULT_MAX_PAYLOAD, fn () => $ours)
            extends WebSocketClient {
            protected function generateHandshakeKey(): string
            {
                return WebSocketClientTest::TEST_KEY;
            }
        };
    }

    /**
     * @return array{0:resource,1:resource} [client end, server end]
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->openSockets[] = $pair[0];
        $this->openSockets[] = $pair[1];

        // The peer end must not block: a test asserting that nothing was sent
        // reads it expecting '', and on a blocking socket that read waits out the
        // 60-second default timeout instead of answering immediately.
        stream_set_blocking($pair[1], false);

        return [$pair[0], $pair[1]];
    }

    /** The 101 response a conforming server returns for self::TEST_KEY. */
    private function goodHandshakeResponse(string $extraHeaders = ''): string
    {
        $accept = base64_encode(
            sha1(self::TEST_KEY . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)
        );

        return "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n"
            . $extraHeaders
            . "\r\n";
    }

    // -------------------------------------------------------------------------
    // URL validation
    // -------------------------------------------------------------------------

    /**
     * A URL whose scheme is not ws:// or wss:// is rejected at construction.
     *
     * Failing here rather than at connect() means a typo surfaces where the URL
     * is written, not inside a daemon's first loop iteration.
     */
    public function testRejectsNonWebSocketScheme(): void
    {
        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ws:\/\/ and wss:\/\/ only/');
        new WebSocketClient('https://example.test/socket');
    }

    /**
     * A URL with no host at all is rejected, covering the parse_url guard.
     */
    public function testRejectsUrlWithoutHost(): void
    {
        // Act & Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/absolute ws:\/\/ or wss:\/\/ URL/');
        new WebSocketClient('not-a-url');
    }

    // -------------------------------------------------------------------------
    // Handshake
    // -------------------------------------------------------------------------

    /**
     * A conforming 101 response completes the handshake, and the request sent
     * carries the mandatory RFC 6455 headers.
     *
     * The absence of Sec-WebSocket-Extensions is asserted deliberately:
     * permessage-deflate is declined by never offering it, so a server has no
     * opening to enable compression this client cannot inflate.
     */
    public function testCompletesHandshakeAndSendsRequiredHeaders(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);

        // Act
        $client->connect();

        // Assert
        $this->assertTrue($client->isConnected());
        $this->assertNotNull($client->stream(), 'stream() exposes the resource for stream_select()');

        $request = fread($theirs, 8192);
        $this->assertStringContainsString('GET /app/key?protocol=7 HTTP/1.1', $request);
        $this->assertStringContainsString('Host: example.test', $request);
        $this->assertStringContainsString('Upgrade: websocket', $request);
        $this->assertStringContainsString('Sec-WebSocket-Version: 13', $request);
        $this->assertStringContainsString('Sec-WebSocket-Key: ' . self::TEST_KEY, $request);
        $this->assertStringNotContainsString(
            'Sec-WebSocket-Extensions',
            $request,
            'compression must be declined by not offering it'
        );
    }

    /**
     * Extra headers supplied by the caller are included in the handshake.
     *
     * Providers routinely require an Origin or Authorization on the upgrade
     * request, and there is no second chance to send one.
     */
    public function testSendsCallerSuppliedHeaders(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours, ['Origin' => 'https://app.test']);

        // Act
        $client->connect();

        // Assert
        $this->assertStringContainsString('Origin: https://app.test', fread($theirs, 8192));
    }

    /**
     * A response that is not 101 aborts the connection.
     *
     * A proxy or an error page answering 200 must not be mistaken for a
     * WebSocket peer.
     */
    public function testRefusesNon101Response(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n");
        $client = $this->client($ours);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Expected "101 Switching Protocols"/');
        $client->connect();
    }

    /**
     * A 101 whose Sec-WebSocket-Accept does not match the key sent is refused.
     *
     * This is the check that separates a WebSocket server from anything that can
     * be talked into returning 101 — without it, the client would go on to frame
     * bytes at something that never agreed to speak the protocol.
     */
    public function testRefusesMismatchedAcceptValue(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Sec-WebSocket-Accept: obviously-wrong\r\n\r\n");
        $client = $this->client($ours);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not match the key sent/');
        $client->connect();
    }

    /**
     * A server that negotiates an extension we never offered is refused.
     *
     * Accepting it would mean receiving compressed frames with no inflate path —
     * silent corruption rather than a visible failure.
     */
    public function testRefusesUnrequestedExtension(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse("Sec-WebSocket-Extensions: permessage-deflate\r\n"));
        $client = $this->client($ours);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unrequested WebSocket extension/');
        $client->connect();
    }

    /**
     * connect() on an already-connected client is a no-op rather than a second
     * handshake — a daemon calling it defensively each loop must not reconnect.
     */
    public function testConnectIsIdempotent(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);
        $client->connect();
        fread($theirs, 8192);                      // drain the first request

        // Act
        $client->connect();

        // Assert
        $this->assertSame('', (string) fread($theirs, 8192), 'no second handshake was sent');
        $this->assertTrue($client->isConnected());
    }

    // -------------------------------------------------------------------------
    // Sending
    // -------------------------------------------------------------------------

    /**
     * A sent message is a masked frame, as RFC 6455 §5.3 requires of a client.
     *
     * An unmasked client frame is a protocol error the peer closes on, so this is
     * the difference between a client that works and one that is disconnected.
     */
    public function testSendsMaskedFrames(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);
        $client->connect();
        fread($theirs, 8192);

        // Act
        $client->send('{"event":"ping"}');

        // Assert
        $sent    = fread($theirs, 8192);
        $decoded = FrameCodec::decode($sent);
        $this->assertSame(0x80, ord($sent[1]) & 0x80, 'the mask bit must be set on a client frame');
        $this->assertSame('{"event":"ping"}', $decoded['payload']);
    }

    /**
     * Sending before connect() fails loudly instead of writing to a null socket.
     */
    public function testSendBeforeConnectThrows(): void
    {
        // Arrange
        [$ours] = $this->socketPair();
        $client = $this->client($ours);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not connected/');
        $client->send('too early');
    }

    /**
     * ping() emits a masked ping frame for callers that want to probe liveness.
     */
    public function testPingSendsMaskedPingFrame(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);
        $client->connect();
        fread($theirs, 8192);

        // Act
        $client->ping('probe');

        // Assert
        $decoded = FrameCodec::decode(fread($theirs, 8192));
        $this->assertSame(FrameCodec::OP_PING, $decoded['opcode']);
        $this->assertSame('probe', $decoded['payload']);
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * read() returns complete messages and nothing else.
     */
    public function testReadReturnsCompleteMessages(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);
        $client->connect();
        fwrite($theirs, FrameCodec::encode('first') . FrameCodec::encode('second'));

        // Act
        $messages = $client->read();

        // Assert
        $this->assertSame(['first', 'second'], $messages);
    }

    /**
     * read() on an idle socket returns an empty array immediately.
     *
     * This is the contract that lets the client live inside somebody else's
     * stream_select() loop: nothing but connect() may block.
     */
    public function testReadOnIdleSocketReturnsEmptyWithoutBlocking(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);
        $client->connect();

        // Act
        $started  = microtime(true);
        $messages = $client->read();
        $elapsed  = microtime(true) - $started;

        // Assert
        $this->assertSame([], $messages);
        $this->assertLessThan(0.5, $elapsed, 'read() must not block on an idle socket');
    }

    /**
     * A ping from the peer is answered with a pong and never surfaced.
     *
     * A caller should not have to know keepalives exist to stay connected, and a
     * ping reaching the application as a message would be parsed as one.
     */
    public function testAnswersPingWithoutSurfacingIt(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);
        $client->connect();
        fread($theirs, 8192);
        fwrite($theirs, FrameCodec::encode('keepalive', FrameCodec::OP_PING));

        // Act
        $messages = $client->read();

        // Assert
        $this->assertSame([], $messages, 'a ping is not a message');
        $reply = FrameCodec::decode(fread($theirs, 8192));
        $this->assertSame(FrameCodec::OP_PONG, $reply['opcode']);
        $this->assertSame('keepalive', $reply['payload'], 'a pong echoes the ping payload');
    }

    /**
     * A pong from the peer is absorbed silently.
     */
    public function testAbsorbsPong(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);
        $client->connect();
        fwrite($theirs, FrameCodec::encode('', FrameCodec::OP_PONG));

        // Act & Assert
        $this->assertSame([], $client->read());
        $this->assertTrue($client->isConnected());
    }

    /**
     * A close frame ends the connection and is reported as a closed socket, not
     * as a message — and any messages that arrived before it are still returned.
     *
     * Losing the last message on a graceful close is a data-loss bug that only
     * appears when a peer closes tidily.
     */
    public function testCloseFrameEndsConnectionAndKeepsEarlierMessages(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);
        $client->connect();
        fwrite($theirs, FrameCodec::encode('last words') . FrameCodec::encode('', FrameCodec::OP_CLOSE));

        // Act
        $messages = $client->read();

        // Assert
        $this->assertSame(['last words'], $messages);
        $this->assertFalse($client->isConnected(), 'a close frame closes the connection');
        $this->assertNull($client->stream());
        $this->assertSame([], $client->read(), 'reading a closed client is safe');
    }

    /**
     * EOF without a close frame — the peer simply vanished — also ends the
     * connection rather than looping on a dead socket.
     */
    public function testEofEndsConnection(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);
        $client->connect();
        fclose($theirs);

        // Act
        $messages = $client->read();

        // Assert
        $this->assertSame([], $messages);
        $this->assertFalse($client->isConnected());
    }

    /**
     * A framing violation closes the connection and rethrows, rather than leaving
     * a desynchronised socket open.
     *
     * Once frame offsets are lost every later frame is misread too, so continuing
     * would produce plausible-looking garbage instead of an error.
     */
    public function testProtocolViolationClosesConnectionAndThrows(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);
        $client->connect();
        // A continuation frame with no message in progress.
        fwrite($theirs, chr(0x80 | FrameCodec::OP_CONTINUATION) . chr(4) . 'oops');

        // Act & Assert
        try {
            $client->read();
            $this->fail('a protocol violation must not pass silently');
        } catch (WebSocketProtocolException) {
            $this->assertFalse($client->isConnected(), 'the connection is closed before the throw escapes');
        }
    }

    /**
     * A message split across frames is delivered whole, once.
     *
     * The client inherits this from MessageAssembler; the test is here because a
     * caller of this class must be able to rely on it without knowing that.
     */
    public function testReassemblesFragmentedMessage(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);
        $client->connect();
        fwrite($theirs, chr(FrameCodec::OP_TEXT) . chr(5) . 'frag-'
            . chr(0x80 | FrameCodec::OP_CONTINUATION) . chr(6) . 'mented');

        // Act
        $messages = $client->read();

        // Assert
        $this->assertSame(['frag-mented'], $messages);
    }

    /**
     * close() sends a close frame and releases the socket.
     */
    public function testCloseSendsCloseFrameAndReleasesSocket(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);
        $client->connect();
        fread($theirs, 8192);

        // Act
        $client->close();

        // Assert
        $frame = FrameCodec::decode((string) fread($theirs, 8192));
        $this->assertNotNull($frame);
        $this->assertSame(FrameCodec::OP_CLOSE, $frame['opcode']);
        $this->assertFalse($client->isConnected());
        // Closing twice must not fault on an already-released resource.
        $client->close();
        $this->assertFalse($client->isConnected());
    }

    /**
     * A binary frame is surfaced rather than dropped.
     *
     * Both text and binary are byte strings in PHP, and silently discarding a
     * message because a peer labelled it differently is worse than handing it
     * over.
     */
    public function testSurfacesBinaryMessages(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = $this->client($ours);
        $client->connect();
        fwrite($theirs, FrameCodec::encode("\x01\x02", FrameCodec::OP_BINARY));

        // Act & Assert
        $this->assertSame(["\x01\x02"], $client->read());
    }

    // -------------------------------------------------------------------------
    // Real socket paths (no injected factory)
    // -------------------------------------------------------------------------

    /**
     * With no stream factory, the client opens a real socket — and a refused
     * connection is reported with the remote address in the message.
     *
     * Covers the production openSocket() path, which the injected-factory tests
     * above deliberately bypass. Port 1 is reserved and never listening.
     */
    public function testRealConnectFailureReportsRemote(): void
    {
        // Arrange
        $client = new WebSocketClient('ws://127.0.0.1:1/socket', [], 0.5);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot connect to tcp:\/\/127\.0\.0\.1:1/');
        $client->connect();
    }

    /**
     * stream() is null before connect(), so a caller building a select set has a
     * value to skip rather than a dangling resource.
     */
    public function testStreamIsNullBeforeConnect(): void
    {
        // Arrange
        $client = new WebSocketClient('wss://example.test/socket');

        // Assert
        $this->assertNull($client->stream());
        $this->assertFalse($client->isConnected());
    }

    /**
     * A non-default port appears in the Host header; a default one does not.
     *
     * RFC 7230 §5.4 makes the port part of Host whenever it is not the scheme's
     * default, and some providers route on it.
     */
    public function testHostHeaderCarriesNonDefaultPort(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, $this->goodHandshakeResponse());
        $client = new class('ws://example.test:8080/socket', [], 2.0, FrameCodec::DEFAULT_MAX_PAYLOAD, fn () => $ours)
            extends WebSocketClient {
            protected function generateHandshakeKey(): string
            {
                return WebSocketClientTest::TEST_KEY;
            }
        };

        // Act
        $client->connect();

        // Assert
        $this->assertStringContainsString('Host: example.test:8080', fread($theirs, 8192));
    }

    /**
     * A peer that accepts the connection and then says nothing fails with a
     * timeout rather than hanging for the life of the process.
     *
     * The peer stays open on purpose: a closed one is a different failure, proved
     * by testWriteToClosedPeerFailsImmediately below.
     */
    public function testNoHandshakeResponseTimesOut(): void
    {
        // Arrange: peer open, nothing written to it.
        [$ours] = $this->socketPair();
        $client = new class('ws://example.test/socket', [], 0.3, FrameCodec::DEFAULT_MAX_PAYLOAD, fn () => $ours)
            extends WebSocketClient {
            protected function generateHandshakeKey(): string
            {
                return WebSocketClientTest::TEST_KEY;
            }
        };

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No handshake response/');
        $client->connect();
    }

    /**
     * Writing to a socket whose peer has gone fails immediately, naming the
     * connection as closed.
     *
     * A zero-length write is ordinary backpressure on a non-blocking socket, so
     * the write loop retries — and on a dead socket it retried until the timeout
     * and then reported one. "Closed" and "slow" lead to different fixes, and the
     * message has to say which one happened.
     */
    public function testWriteToClosedPeerFailsImmediately(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fclose($theirs);
        $client = new class('ws://example.test/socket', [], 5.0, FrameCodec::DEFAULT_MAX_PAYLOAD, fn () => $ours)
            extends WebSocketClient {
            protected function generateHandshakeKey(): string
            {
                return WebSocketClientTest::TEST_KEY;
            }
        };

        // Act
        $started = microtime(true);
        try {
            $client->connect();
            $this->fail('writing the handshake to a closed peer must fail');
        } catch (\RuntimeException $e) {
            $elapsed = microtime(true) - $started;

            // Assert
            $this->assertStringContainsString('closed after writing', $e->getMessage());
            $this->assertLessThan(
                2.0,
                $elapsed,
                'the failure must not wait out the 5s timeout'
            );
        }
    }

    /**
     * Handshake headers beyond 64 KiB are refused instead of buffered.
     *
     * Without the ceiling, a peer that streams header bytes forever grows one
     * string until the process dies — before the protocol has even started.
     *
     * The peer here is a temp file rather than a socket pair: a pair's kernel
     * buffer caps how much can be made readable in one go, which is below the
     * limit under test.
     */
    public function testRefusesOversizedHandshakeHeaders(): void
    {
        // Arrange
        $peer = tmpfile();
        $this->openSockets[] = $peer;
        fwrite($peer, "HTTP/1.1 101 Switching Protocols\r\n");
        // ~90 KiB of header lines, never terminated by a blank line.
        for ($i = 0; $i < 900; $i++) {
            fwrite($peer, 'X-Filler-' . $i . ': ' . str_repeat('a', 90) . "\r\n");
        }
        rewind($peer);

        $client = new class('ws://example.test/socket', [], 2.0, FrameCodec::DEFAULT_MAX_PAYLOAD, fn () => $peer)
            extends WebSocketClient {
            protected function generateHandshakeKey(): string
            {
                return WebSocketClientTest::TEST_KEY;
            }
        };

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeded 64 KiB/');
        $client->connect();
    }

    /**
     * A wss:// URL is parsed as TLS and defaults to port 443.
     *
     * Asserted through the failure message because completing a real TLS
     * handshake needs a certificate; the address is what the scheme decides.
     */
    public function testWssDefaultsToTlsOnPort443(): void
    {
        // Arrange: 127.0.0.1:443 is not listening in the test container.
        $client = new WebSocketClient('wss://127.0.0.1/socket', [], 0.5);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ssl:\/\/127\.0\.0\.1:443/');
        $client->connect();
    }

    /**
     * The production nonce is 16 random bytes, base64-encoded, and differs each
     * time — RFC 6455 §4.1 requires it to be unpredictable, because it is what
     * makes the Sec-WebSocket-Accept check meaningful.
     *
     * Reached by reflection because every other test in this class overrides it to
     * get a fixed value, which would otherwise leave the real generator untested.
     */
    public function testGeneratesUnpredictableHandshakeKey(): void
    {
        // Arrange
        $client = new WebSocketClient('ws://example.test/socket');
        $method = new \ReflectionMethod($client, 'generateHandshakeKey');

        // Act
        $first  = $method->invoke($client);
        $second = $method->invoke($client);

        // Assert
        $this->assertSame(16, strlen((string) base64_decode($first, true)), '16 raw bytes');
        $this->assertNotSame($first, $second, 'a fresh nonce per handshake');
    }

    /**
     * The real socket path opens a connection to a listening peer.
     *
     * The injected-factory tests never exercise stream_socket_client(), so this is
     * the only cover for it succeeding. The listener deliberately says nothing, so
     * the handshake then times out — proving the socket was opened, which is the
     * point under test.
     */
    public function testOpensRealSocketToListeningPeer(): void
    {
        // Arrange
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener, 'test needs a local listening socket');
        $this->openSockets[] = $listener;
        $port = (int) explode(':', (string) stream_socket_get_name($listener, false))[1];

        $client = new WebSocketClient('ws://127.0.0.1:' . $port . '/socket', [], 0.3);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No handshake response/');
        $client->connect();
    }

    /**
     * A handshake response that ends at EOF without a blank line is still
     * evaluated, and rejected.
     *
     * The read loop stops at EOF rather than spinning to the timeout, so a peer
     * that half-answers and hangs up fails on the header check instead of on a
     * clock. The peer's write side is shut down rather than the socket closed, so
     * our own request still writes: closing outright is a different failure, and
     * testWriteToClosedPeerFailsImmediately covers that one.
     */
    public function testHandshakeTruncatedAtEofIsRejected(): void
    {
        // Arrange
        [$ours, $theirs] = $this->socketPair();
        fwrite($theirs, "HTTP/1.1 101 Switching Protocols\r\n");
        stream_socket_shutdown($theirs, STREAM_SHUT_WR);

        $client = new class('ws://example.test/socket', [], 2.0, FrameCodec::DEFAULT_MAX_PAYLOAD, fn () => $ours)
            extends WebSocketClient {
            protected function generateHandshakeKey(): string
            {
                return WebSocketClientTest::TEST_KEY;
            }
        };

        // Act
        $started = microtime(true);
        try {
            $client->connect();
            $this->fail('a truncated handshake must be rejected');
        } catch (\RuntimeException $e) {
            // Assert
            $this->assertStringContainsString('does not match the key sent', $e->getMessage());
            $this->assertLessThan(1.0, microtime(true) - $started, 'EOF ends the read, no timeout wait');
        }
    }
}
