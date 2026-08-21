<?php

declare(strict_types=1);

namespace Pramnos\Http;

use Pramnos\Http\WebSocket\FrameCodec;
use Pramnos\Http\WebSocket\MessageAssembler;
use Pramnos\Http\WebSocket\WebSocketProtocolException;

/**
 * Outbound WebSocket client — RFC 6455 transport, and nothing above it.
 *
 * The framework could already *serve* a WebSocket ({@see
 * \Pramnos\Broadcasting\LocalBroadcastServer}) and *publish* to one ({@see
 * \Pramnos\Broadcasting\Drivers\PusherDriver}), but it could not listen to
 * somebody else's. An application consuming a third party's live feed had to
 * hand-roll the handshake, client-side masking and the three payload-length
 * forms, or give up and poll.
 *
 * **The caller keeps its own loop.** That is the property that decides the shape
 * of this class, and it is why it looks like {@see
 * \Pramnos\Broadcasting\RedisSubscriberSocket} rather than like a typical client
 * library. A worker multiplexing sixty SSE reads, one WebSocket and a `.stop`
 * sentinel in one process cannot use a client that owns the loop or blocks in
 * `read()`:
 *
 * ```php
 * $socket = new WebSocketClient('wss://example.test/app/key?protocol=7');
 * $socket->connect();
 *
 * $read = [$socket->stream(), ...$otherStreams];
 * stream_select($read, $w, $e, 1);
 *
 * foreach ($socket->read() as $message) {   // whole messages, [] when none
 *     handle($message);
 * }
 * ```
 *
 * Only {@see connect()} blocks, and only for the handshake. Everything else is
 * non-blocking.
 *
 * **The protocol above the transport stays out.** Pusher's
 * `pusher:connection_established` / `pusher:subscribe` exchange, its
 * `activity_timeout`, its channel auth — those belong to whoever is speaking
 * Pusher. This is the same split as {@see Client} and the APIs it calls: a
 * `PusherClient` here would be a guess about one provider, while a WebSocket
 * client is what every provider needs.
 *
 * The Pusher handshake is a short exchange on top of this — send
 * `pusher:subscribe` once `pusher:connection_established` arrives, and answer
 * `pusher:ping`, which is the *application-layer* ping and distinct from the
 * protocol ping {@see read()} already handles. The Realtime guide's *Consuming
 * somebody else's WebSocket* section spells it out.
 */
class WebSocketClient
{
    /** RFC 6455 §1.3 — the fixed GUID the Accept hash is salted with. */
    private const HANDSHAKE_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var resource|null */
    private mixed $socket = null;

    private MessageAssembler $assembler;

    private bool $connected = false;

    /** Base64 key sent in the handshake, kept to verify the response against. */
    private string $handshakeKey = '';

    /** @var callable|null Injection seam for tests: (string $remote, float $timeout) */
    private $streamFactory;

    private string $scheme;
    private string $host;
    private int $port;
    private string $path;

    /**
     * @param string $url          ws:// or wss:// URL, query string included.
     * @param array<string,string> $headers Extra request headers for the handshake
     *                                      (an Authorization or Origin a provider
     *                                      requires, for instance).
     * @param float  $timeout      Connect and handshake timeout, in seconds.
     * @param int    $maxMessage   Ceiling for one reassembled message. A peer that
     *                             never stops sending must not take the process
     *                             with it.
     * @param callable|null $streamFactory Test seam; production leaves it null.
     * @throws \InvalidArgumentException When $url is not a ws:// or wss:// URL.
     */
    public function __construct(
        private readonly string $url,
        private readonly array $headers = [],
        private readonly float $timeout = 10.0,
        int $maxMessage = FrameCodec::DEFAULT_MAX_PAYLOAD,
        ?callable $streamFactory = null,
    ) {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException(
                'WebSocketClient needs an absolute ws:// or wss:// URL; got "' . $url . '".'
            );
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'ws' && $scheme !== 'wss') {
            throw new \InvalidArgumentException(
                'WebSocketClient supports ws:// and wss:// only; got "' . $scheme . '://".'
            );
        }

        $this->scheme = $scheme;
        $this->host   = $parts['host'];
        $this->port   = (int) ($parts['port'] ?? ($scheme === 'wss' ? 443 : 80));
        $this->path   = ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $this->assembler     = new MessageAssembler(FrameCodec::DEFAULT_MAX_PAYLOAD, $maxMessage);
        $this->streamFactory = $streamFactory;
    }

    /**
     * Open the connection and complete the handshake.
     *
     * @throws \RuntimeException When the socket cannot be opened, the server does
     *         not answer 101, or the Sec-WebSocket-Accept value does not match the
     *         key we sent — the last one being the check that proves we are
     *         talking to a WebSocket server and not to something that merely
     *         returned 101.
     */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $this->socket = $this->openSocket();
        stream_set_blocking($this->socket, true);
        stream_set_timeout($this->socket, (int) ceil($this->timeout));

        $this->handshakeKey = $this->generateHandshakeKey();
        $this->writeAll($this->buildHandshakeRequest());

        $response = $this->readHandshakeResponse();
        $this->verifyHandshake($response);

        stream_set_blocking($this->socket, false);
        $this->assembler->reset();
        $this->connected = true;
    }

    /**
     * The stream resource to put in a `stream_select()` read set.
     *
     * @return resource|null Null before connect() and after close().
     */
    public function stream(): mixed
    {
        return $this->socket;
    }

    /**
     * True while the connection is usable. Goes false when the peer sends a close
     * frame or the socket reaches EOF — a closed connection is a closed socket
     * here, never a message handed to the application.
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Send one data message as a single masked frame.
     *
     * @param string $payload Message body.
     * @param int    $opcode  OP_TEXT (default) or OP_BINARY.
     * @throws \RuntimeException When the connection is not open or the write fails.
     */
    public function send(string $payload, int $opcode = FrameCodec::OP_TEXT): void
    {
        $this->assertConnected('send');

        // RFC 6455 §5.3: a client frame MUST be masked. Not an option, and an
        // unmasked one is a protocol error the peer closes the connection on.
        $this->writeAll(FrameCodec::encode($payload, $opcode, true));
    }

    /**
     * Read whatever has arrived and return the messages it completed.
     *
     * Never blocks: with nothing readable it returns an empty array. Ping frames
     * are answered here and not surfaced — a caller should not have to know that
     * keepalives exist to stay connected. A close frame is answered, the socket is
     * shut and {@see isConnected()} goes false.
     *
     * @return list<string> Complete message payloads, in arrival order.
     * @throws WebSocketProtocolException On a malformed frame or a bad
     *         fragmentation sequence — a connection in that state cannot recover,
     *         so it is closed before the exception leaves.
     */
    public function read(): array
    {
        if (!$this->connected || $this->socket === null) {
            return [];
        }

        $chunk = @fread($this->socket, 65536);

        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            // EOF without a close frame: the peer is gone.
            $this->closeQuietly();
            return [];
        }

        try {
            $frames = $this->assembler->feed($chunk === false ? '' : $chunk);
        } catch (WebSocketProtocolException $e) {
            $this->closeQuietly();
            throw $e;
        }

        $messages = [];

        foreach ($frames as $frame) {
            switch ($frame['opcode']) {
                case FrameCodec::OP_TEXT:
                case FrameCodec::OP_BINARY:
                    // Both are bytes in PHP; surfacing binary too avoids silently
                    // dropping a message a peer chose to label differently.
                    $messages[] = $frame['payload'];
                    break;

                case FrameCodec::OP_PING:
                    // Answer with the same application data, per RFC 6455 §5.5.3.
                    $this->writeAll(FrameCodec::encode($frame['payload'], FrameCodec::OP_PONG, true));
                    break;

                case FrameCodec::OP_PONG:
                    break;      // nothing to do; we do not track outstanding pings

                case FrameCodec::OP_CLOSE:
                    $this->writeAll(FrameCodec::encode('', FrameCodec::OP_CLOSE, true));
                    $this->closeQuietly();
                    return $messages;
            }
        }

        return $messages;
    }

    /**
     * Send a ping. The peer's pong is absorbed by {@see read()}.
     */
    public function ping(string $payload = ''): void
    {
        $this->assertConnected('ping');
        $this->writeAll(FrameCodec::encode($payload, FrameCodec::OP_PING, true));
    }

    /**
     * Send a close frame (best effort) and release the socket.
     */
    public function close(): void
    {
        if ($this->connected && $this->socket !== null) {
            @fwrite($this->socket, FrameCodec::encode('', FrameCodec::OP_CLOSE, true));
        }

        $this->closeQuietly();
    }

    // -------------------------------------------------------------------------
    // Handshake
    // -------------------------------------------------------------------------

    /**
     * The nonce sent as Sec-WebSocket-Key (RFC 6455 §4.1: 16 random bytes,
     * base64).
     *
     * Overridable so a test can fix it and therefore precompute the matching
     * Sec-WebSocket-Accept: verifying that check needs both halves, and the key
     * is otherwise generated inside connect() where no test can see it.
     */
    protected function generateHandshakeKey(): string
    {
        return base64_encode(random_bytes(16));
    }

    /**
     * @return resource
     */
    private function openSocket(): mixed
    {
        // wss:// needs SNI: a host sharing an IP is resolved by the name in the
        // TLS handshake, so peer_name must carry it or verification fails against
        // whichever certificate the address answers with by default.
        $transport = $this->scheme === 'wss' ? 'ssl' : 'tcp';
        $remote    = $transport . '://' . $this->host . ':' . $this->port;

        if ($this->streamFactory !== null) {
            return ($this->streamFactory)($remote, $this->timeout);
        }

        $context = stream_context_create([
            'ssl' => [
                'peer_name'         => $this->host,
                'SNI_enabled'       => true,
                'verify_peer'       => true,
                'verify_peer_name'  => true,
            ],
        ]);

        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException(
                'Cannot connect to ' . $remote . ' — ' . $errstr . ' (' . $errno . ')'
            );
        }

        return $socket;
    }

    private function buildHandshakeRequest(): string
    {
        $hostHeader = $this->host;
        if (
            ($this->scheme === 'ws' && $this->port !== 80)
            || ($this->scheme === 'wss' && $this->port !== 443)
        ) {
            $hostHeader .= ':' . $this->port;
        }

        $lines = [
            'GET ' . $this->path . ' HTTP/1.1',
            'Host: ' . $hostHeader,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $this->handshakeKey,
            'Sec-WebSocket-Version: 13',
        ];

        // permessage-deflate is declined by simply never offering it: a server
        // enables compression only for a client that asked. Declining costs a
        // header we do not send; getting inflate wrong is silent corruption.
        foreach ($this->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines) . "\r\n\r\n";
    }

    /**
     * Read up to the end of the response headers, then stop — anything after the
     * blank line is already WebSocket frames and belongs to the assembler.
     */
    private function readHandshakeResponse(): string
    {
        $response = '';
        $deadline = microtime(true) + $this->timeout;

        while (microtime(true) < $deadline) {
            $line = @fgets($this->socket, 8192);

            if ($line === false) {
                if (feof($this->socket)) {
                    break;
                }
                continue;
            }

            $response .= $line;

            if (str_ends_with($response, "\r\n\r\n") || str_ends_with($response, "\n\n")) {
                return $response;
            }

            if (strlen($response) > 65536) {
                throw new \RuntimeException(
                    'Handshake response headers exceeded 64 KiB; refusing to continue.'
                );
            }
        }

        if ($response === '') {
            throw new \RuntimeException(
                'No handshake response from ' . $this->url . ' within '
                . $this->timeout . 's.'
            );
        }

        return $response;
    }

    private function verifyHandshake(string $response): void
    {
        $lines  = preg_split('/\r?\n/', trim($response)) ?: [];
        $status = (string) ($lines[0] ?? '');

        if (!preg_match('#^HTTP/1\.[01]\s+101\b#i', $status)) {
            $this->closeQuietly();
            throw new \RuntimeException(
                'Expected "101 Switching Protocols" from ' . $this->url
                . ', got "' . $status . '".'
            );
        }

        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }

        // The Accept value is what distinguishes a WebSocket server from anything
        // else that can be talked into returning 101.
        $expected = base64_encode(sha1($this->handshakeKey . self::HANDSHAKE_GUID, true));
        $actual   = $headers['sec-websocket-accept'] ?? '';

        if (!hash_equals($expected, $actual)) {
            $this->closeQuietly();
            throw new \RuntimeException(
                'Sec-WebSocket-Accept from ' . $this->url . ' does not match the key sent; '
                . 'the peer is not a conforming WebSocket server.'
            );
        }

        // We never offer an extension, so a server naming one is negotiating
        // something we would then have to implement. Refusing beats corrupting.
        if (($headers['sec-websocket-extensions'] ?? '') !== '') {
            $this->closeQuietly();
            throw new \RuntimeException(
                'Server negotiated the unrequested WebSocket extension "'
                . $headers['sec-websocket-extensions'] . '"; refusing the connection.'
            );
        }
    }

    // -------------------------------------------------------------------------
    // Plumbing
    // -------------------------------------------------------------------------

    private function assertConnected(string $operation): void
    {
        if (!$this->connected || $this->socket === null) {
            throw new \RuntimeException(
                'Cannot ' . $operation . ' on a WebSocketClient that is not connected.'
            );
        }
    }

    /**
     * Write every byte, tolerating short writes on a non-blocking socket.
     */
    private function writeAll(string $data): void
    {
        if ($this->socket === null) {
            return;
        }

        $total    = strlen($data);
        $written  = 0;
        $deadline = microtime(true) + $this->timeout;

        while ($written < $total) {
            $n = @fwrite($this->socket, substr($data, $written));

            if ($n === false || $n === 0) {
                // A zero-length write is normal backpressure on a non-blocking
                // socket, but on a socket whose peer has gone it repeats until the
                // deadline and then reports a timeout — which reads as a slow
                // network rather than a closed connection.
                if (feof($this->socket)) {
                    throw new \RuntimeException(
                        'Connection to ' . $this->url . ' closed after writing '
                        . $written . ' of ' . $total . ' bytes.'
                    );
                }
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException(
                        'Timed out writing to ' . $this->url . ' after ' . $written
                        . ' of ' . $total . ' bytes.'
                    );
                }
                continue;
            }

            $written += $n;
        }
    }

    private function closeQuietly(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket    = null;
        $this->connected = false;
        $this->assembler->reset();
    }
}
