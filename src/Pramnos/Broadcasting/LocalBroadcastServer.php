<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

/**
 * Pure-PHP WebSocket server for local broadcasting (development only).
 *
 * Implements a minimal subset of RFC 6455 (WebSocket) and the Pusher Wire
 * Protocol v7, so existing pramnos-echo.js clients can connect without any
 * configuration change other than pointing to localhost.
 *
 * Features:
 *  - Pusher-compatible handshake + subscription flow
 *  - Channel fan-out: broadcast(channel, event, data)
 *  - JSONL file tail: reads new entries from a LogDriver output file and
 *    pushes them to subscribed clients automatically
 *  - Ping/pong keepalive
 *  - Graceful shutdown on SIGTERM / SIGINT
 *
 * Limitations (intentional — this is a dev tool):
 *  - Single-threaded (stream_select event loop)
 *  - No TLS (plain TCP only)
 *  - No authentication / presence channel metadata
 *  - Up to ~100 concurrent connections without tuning
 *
 * @package PramnosFramework
 */
class LocalBroadcastServer
{
    /** @var resource|null Server socket. */
    private $serverSocket = null;

    /** @var array<int, array{socket:resource, state:string, buffer:string, channels:string[], socketId:string, pingAt:int}> */
    private array $clients = [];

    /** @var array<string, int[]> channel → list of client IDs */
    private array $subscriptions = [];

    /** @var int Next auto-increment socket ID. */
    private int $nextSocketId = 1;

    /** @var string Pusher app-key (for URL path matching). */
    private string $appKey;

    /** @var string|null Path to JSONL log file produced by LogDriver. */
    private ?string $logFile;

    /** @var int File offset for incremental reading of $logFile. */
    protected int $logOffset = 0;

    /** @var bool  Main loop flag. */
    private bool $running = false;

    /** @var callable|null  Callback invoked each tick (for progress output). */
    private $tickCallback = null;

    public function __construct(string $appKey = 'pramnos-local', ?string $logFile = null)
    {
        $this->appKey  = $appKey;
        $this->logFile = $logFile;
    }

    /**
     * Register a tick callback; called after each event-loop iteration.
     *
     * @param callable $cb  fn(int $clientCount, int $subscriptionCount): void
     */
    public function onTick(callable $cb): void
    {
        $this->tickCallback = $cb;
    }

    /**
     * Start the server and block until stop() is called or a fatal error occurs.
     *
     * @param string $host  Bind address (default: 0.0.0.0)
     * @param int    $port  Listen port (default: 6001)
     * @throws \RuntimeException if the socket cannot be created.
     */
    public function run(string $host = '0.0.0.0', int $port = 6001): void
    {
        $this->serverSocket = @stream_socket_server(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );

        if ($this->serverSocket === false) {
            throw new \RuntimeException("Cannot bind on {$host}:{$port} — {$errstr} ({$errno})");
        }

        stream_set_blocking($this->serverSocket, false);

        if ($this->logFile !== null && file_exists($this->logFile)) {
            $this->logOffset = (int) filesize($this->logFile);
        }

        $this->running = true;

        while ($this->running) {
            $this->loopIteration();
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($this->tickCallback !== null) {
                ($this->tickCallback)(count($this->clients), count($this->subscriptions));
            }
        }

        $this->shutdown();
    }

    /**
     * Signal the main loop to stop cleanly.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Broadcast a message to all clients subscribed to $channel.
     *
     * @param string $channel  Channel name (e.g. "orders", "private-user.42")
     * @param string $event    Event name (e.g. "App\\Events\\OrderCreated")
     * @param mixed  $data     Payload (will be JSON-encoded)
     */
    public function broadcast(string $channel, string $event, $data): void
    {
        $payload = json_encode([
            'event'   => $event,
            'data'    => is_string($data) ? $data : json_encode($data),
            'channel' => $channel,
        ]);

        foreach ($this->subscriptions[$channel] ?? [] as $id) {
            if (isset($this->clients[$id])) {
                $this->wsSend($this->clients[$id]['socket'], $payload);
            }
        }
    }

    // =========================================================================
    // Event loop
    // =========================================================================

    private function loopIteration(): void
    {
        $read = [$this->serverSocket];
        foreach ($this->clients as $client) {
            $read[] = $client['socket'];
        }

        $write  = null;
        $except = null;
        // 100 ms select timeout so we can poll the log file frequently enough
        $changed = @stream_select($read, $write, $except, 0, 100_000);

        if ($changed === false || $changed === 0) {
            $this->pollLogFile();
            $this->sendKeepalives();
            return;
        }

        foreach ($read as $socket) {
            if ($socket === $this->serverSocket) {
                $this->acceptClient();
            } else {
                $this->readClient($socket);
            }
        }

        $this->pollLogFile();
        $this->sendKeepalives();
    }

    private function acceptClient(): void
    {
        $socket = @stream_socket_accept($this->serverSocket, 0);
        if ($socket === false) {
            return;
        }
        stream_set_blocking($socket, false);

        $id = $this->nextSocketId++;
        $this->clients[$id] = [
            'socket'   => $socket,
            'state'    => 'handshaking', // handshaking | connected | closing
            'buffer'   => '',
            'channels' => [],
            'socketId' => "{$id}.{$this->nextSocketId}",
            'pingAt'   => time() + 30,
        ];
    }

    private function readClient(mixed $socket): void
    {
        $id = $this->findClientId($socket);
        if ($id === null) {
            return;
        }

        $client = &$this->clients[$id];
        $data   = @fread($socket, 8192);

        if ($data === false || ($data === '' && feof($socket))) {
            $this->disconnectClient($id);
            return;
        }

        $client['buffer'] .= $data;

        if ($client['state'] === 'handshaking') {
            $this->processHandshake($id);
        } else {
            $this->processFrames($id);
        }
    }

    // =========================================================================
    // WebSocket handshake (RFC 6455 §4.2)
    // =========================================================================

    private function processHandshake(int $id): void
    {
        $client = &$this->clients[$id];
        $buf    = $client['buffer'];

        // Wait until we have the full HTTP request headers
        if (strpos($buf, "\r\n\r\n") === false) {
            return;
        }

        $headers   = $this->parseHttpHeaders($buf);
        $wsKey     = $headers['sec-websocket-key'] ?? '';
        $upgrade   = strtolower($headers['upgrade'] ?? '');
        $conn      = strtolower($headers['connection'] ?? '');

        if ($upgrade !== 'websocket' || strpos($conn, 'upgrade') === false || $wsKey === '') {
            $this->sendHttpError($client['socket'], 400, 'Bad Request');
            $this->disconnectClient($id);
            return;
        }

        // RFC 6455 §4.2.2 — compute accept key
        $acceptKey = base64_encode(sha1($wsKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$acceptKey}\r\n"
            . "\r\n";

        fwrite($client['socket'], $response);

        $client['state']  = 'connected';
        $client['buffer'] = '';

        // Pusher protocol: send pusher:connection_established event
        $this->wsSend($client['socket'], json_encode([
            'event' => 'pusher:connection_established',
            'data'  => json_encode([
                'socket_id'        => $client['socketId'],
                'activity_timeout' => 120,
            ]),
        ]));
    }

    private function parseHttpHeaders(string $request): array
    {
        $headers = [];
        $lines   = explode("\r\n", $request);
        foreach (array_slice($lines, 1) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }

    // =========================================================================
    // WebSocket framing (RFC 6455 §5)
    // =========================================================================

    private function processFrames(int $id): void
    {
        $client = &$this->clients[$id];

        while (strlen($client['buffer']) > 0) {
            $frame = $this->parseFrame($client['buffer']);
            if ($frame === null) {
                break; // incomplete frame — wait for more data
            }

            $consumed = $frame['headerLen'] + $frame['payloadLen'];
            $client['buffer'] = substr($client['buffer'], $consumed);

            switch ($frame['opcode']) {
                case 0x1: // text
                    $this->handleTextMessage($id, $frame['payload']);
                    break;
                case 0x8: // close
                    $this->wsSendClose($client['socket']);
                    $this->disconnectClient($id);
                    return;
                case 0x9: // ping
                    $this->wsSend($client['socket'], $frame['payload'], 0xA); // pong
                    break;
                case 0xA: // pong
                    break;
            }
        }
    }

    /**
     * Parse one WebSocket frame from $buffer.
     *
     * @return array{headerLen:int, payloadLen:int, opcode:int, payload:string}|null
     */
    private function parseFrame(string $buffer): ?array
    {
        $len = strlen($buffer);
        if ($len < 2) {
            return null;
        }

        $byte0   = ord($buffer[0]);
        $byte1   = ord($buffer[1]);
        $opcode  = $byte0 & 0x0F;
        $masked  = ($byte1 & 0x80) !== 0;
        $payLen  = $byte1 & 0x7F;
        $offset  = 2;

        if ($payLen === 126) {
            if ($len < 4) {
                return null;
            }
            $payLen = (ord($buffer[2]) << 8) | ord($buffer[3]);
            $offset = 4;
        } elseif ($payLen === 127) {
            if ($len < 10) {
                return null;
            }
            $payLen = 0;
            for ($i = 0; $i < 8; $i++) {
                $payLen = ($payLen << 8) | ord($buffer[2 + $i]);
            }
            $offset = 10;
        }

        $maskLen = $masked ? 4 : 0;
        if ($len < $offset + $maskLen + $payLen) {
            return null;
        }

        $mask    = $masked ? substr($buffer, $offset, 4) : '';
        $offset += $maskLen;
        $payload = substr($buffer, $offset, $payLen);

        if ($masked) {
            for ($i = 0; $i < $payLen; $i++) {
                $payload[$i] = chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
            }
        }

        return [
            'headerLen'  => $offset,
            'payloadLen' => $payLen,
            'opcode'     => $opcode,
            'payload'    => $payload,
        ];
    }

    /**
     * Send a WebSocket text frame (or specified opcode) to $socket.
     */
    private function wsSend(mixed $socket, string $payload, int $opcode = 0x1): void
    {
        $len = strlen($payload);

        if ($len < 126) {
            $frame = chr(0x80 | $opcode) . chr($len) . $payload;
        } elseif ($len < 65536) {
            $frame = chr(0x80 | $opcode) . chr(126) . chr($len >> 8) . chr($len & 0xFF) . $payload;
        } else {
            $frame = chr(0x80 | $opcode) . chr(127)
                . "\x00\x00\x00\x00" . pack('N', $len)
                . $payload;
        }

        @fwrite($socket, $frame);
    }

    private function wsSendClose(mixed $socket): void
    {
        @fwrite($socket, chr(0x88) . chr(0x00));
    }

    // =========================================================================
    // Pusher protocol messages
    // =========================================================================

    private function handleTextMessage(int $id, string $payload): void
    {
        $msg = @json_decode($payload, true);
        if (!is_array($msg) || !isset($msg['event'])) {
            return;
        }

        switch ($msg['event']) {
            case 'pusher:subscribe':
                $this->handleSubscribe($id, $msg['data'] ?? []);
                break;
            case 'pusher:unsubscribe':
                $this->handleUnsubscribe($id, ($msg['data'] ?? [])['channel'] ?? '');
                break;
            case 'pusher:ping':
                $client = &$this->clients[$id];
                $this->wsSend($client['socket'], json_encode(['event' => 'pusher:pong', 'data' => '{}']));
                break;
        }
    }

    private function handleSubscribe(int $id, mixed $data): void
    {
        $data    = is_string($data) ? (json_decode($data, true) ?? []) : (array) $data;
        $channel = $data['channel'] ?? '';

        if ($channel === '') {
            return;
        }

        $client = &$this->clients[$id];
        if (!in_array($channel, $client['channels'], true)) {
            $client['channels'][] = $channel;
        }

        $this->subscriptions[$channel][$id] = $id;

        $this->wsSend($client['socket'], json_encode([
            'event'   => 'pusher_internal:subscription_succeeded',
            'data'    => '{}',
            'channel' => $channel,
        ]));
    }

    private function handleUnsubscribe(int $id, string $channel): void
    {
        $client   = &$this->clients[$id];
        $client['channels'] = array_filter(
            $client['channels'],
            fn($c) => $c !== $channel
        );

        unset($this->subscriptions[$channel][$id]);
    }

    // =========================================================================
    // Log-file tail (integration with LogDriver)
    // =========================================================================

    /**
     * Read any new lines appended to the log file since the last poll.
     *
     * Each line must be a JSON object with keys channel, event, data
     * (the format written by LogDriver).
     */
    protected function pollLogFile(): void
    {
        if ($this->logFile === null || !file_exists($this->logFile)) {
            return;
        }

        clearstatcache(true, $this->logFile);
        $size = filesize($this->logFile);

        if ($size <= $this->logOffset) {
            // Handle log rotation: file shrank
            if ($size < $this->logOffset) {
                $this->logOffset = 0;
            }
            return;
        }

        $fp = @fopen($this->logFile, 'r');
        if ($fp === false) {
            return;
        }

        fseek($fp, $this->logOffset);
        while (($line = fgets($fp)) !== false) {
            $entry = @json_decode(trim($line), true);
            if (is_array($entry) && isset($entry['channel'], $entry['event'])) {
                // Support both LogDriver format ('payload') and generic format ('data')
                $data = $entry['payload'] ?? $entry['data'] ?? [];
                $this->broadcast($entry['channel'], $entry['event'], $data);
            }
        }
        $this->logOffset = (int) ftell($fp);
        fclose($fp);
    }

    // =========================================================================
    // Keepalives
    // =========================================================================

    private function sendKeepalives(): void
    {
        $now = time();
        foreach ($this->clients as $id => $client) {
            if ($client['state'] !== 'connected') {
                continue;
            }
            if ($now >= $client['pingAt']) {
                $this->wsSend(
                    $client['socket'],
                    json_encode(['event' => 'pusher:ping', 'data' => '{}']),
                    0x1
                );
                $this->clients[$id]['pingAt'] = $now + 30;
            }
        }
    }

    // =========================================================================
    // Connection management
    // =========================================================================

    private function disconnectClient(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $client = $this->clients[$id];

        foreach ($client['channels'] as $channel) {
            unset($this->subscriptions[$channel][$id]);
            if (empty($this->subscriptions[$channel])) {
                unset($this->subscriptions[$channel]);
            }
        }

        @fclose($client['socket']);
        unset($this->clients[$id]);
    }

    private function findClientId(mixed $socket): ?int
    {
        foreach ($this->clients as $id => $client) {
            if ($client['socket'] === $socket) {
                return $id;
            }
        }
        return null;
    }

    private function shutdown(): void
    {
        foreach ($this->clients as $id => $client) {
            $this->wsSendClose($client['socket']);
            @fclose($client['socket']);
        }
        $this->clients       = [];
        $this->subscriptions = [];

        if ($this->serverSocket !== null) {
            @fclose($this->serverSocket);
            $this->serverSocket = null;
        }
    }

    private function sendHttpError(mixed $socket, int $code, string $message): void
    {
        fwrite($socket, "HTTP/1.1 {$code} {$message}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }
}
