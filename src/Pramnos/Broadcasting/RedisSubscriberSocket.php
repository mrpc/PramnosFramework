<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

/**
 * Non-blocking Redis pub/sub subscriber over a raw RESP socket.
 *
 * The built-in WebSocket server runs a single-threaded stream_select() loop, so
 * it cannot use phpredis's blocking subscribe(). Instead this opens a plain TCP
 * socket to Redis, speaks just enough of the RESP protocol to SUBSCRIBE, and
 * exposes its stream so the server can add it to its own select set. On each
 * readable tick {@see drain()} parses any complete `message` replies and returns
 * them — no blocking, no extra process, no fork.
 *
 * This is deliberately minimal (SUBSCRIBE + message parsing); it is an ingest
 * path for fan-out, not a general Redis client.
 */
class RedisSubscriberSocket
{
    /** @var resource|null */
    private $stream = null;
    private string $buffer = '';

    private string $host;
    private int $port;
    private ?string $password;
    private int $database;
    /** @var string[] */
    private array $channels;

    /** @var callable(): mixed Factory returning a connected, non-blocking stream. */
    private $streamFactory;

    /**
     * @param array<string,mixed> $config   Keys: host, port, password, database.
     * @param string[]            $channels  Fully-qualified channel names to SUBSCRIBE.
     * @param callable|null       $streamFactory Test seam returning a stream resource.
     */
    public function __construct(array $config, array $channels, ?callable $streamFactory = null)
    {
        $this->host     = (string) ($config['host'] ?? '127.0.0.1');
        $this->port     = (int) ($config['port'] ?? 6379);
        $this->password = isset($config['password']) && $config['password'] !== '' ? (string) $config['password'] : null;
        $this->database = (int) ($config['database'] ?? 0);
        $this->channels = array_values($channels);
        $this->streamFactory = $streamFactory ?? fn () => $this->openSocket();
    }

    /**
     * Connect and issue AUTH / SELECT / SUBSCRIBE. Idempotent-ish: call once.
     */
    public function connect(): void
    {
        $this->stream = ($this->streamFactory)();
        if (!is_resource($this->stream)) {
            throw new \RuntimeException('RedisSubscriberSocket: could not open a stream to Redis.');
        }
        stream_set_blocking($this->stream, false);

        if ($this->password !== null) {
            $this->sendCommand(['AUTH', $this->password]);
        }
        if ($this->database > 0) {
            $this->sendCommand(['SELECT', (string) $this->database]);
        }
        if ($this->channels !== []) {
            $this->sendCommand(array_merge(['SUBSCRIBE'], $this->channels));
        }
    }

    /**
     * @return resource|null The underlying stream, for inclusion in stream_select().
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Read whatever is available (non-blocking) and return any complete pub/sub
     * messages parsed from the buffer.
     *
     * @return list<array{channel:string,message:string}>
     */
    public function drain(): array
    {
        if (!is_resource($this->stream)) {
            return [];
        }

        $data = @fread($this->stream, 65536);
        if (is_string($data) && $data !== '') {
            $this->buffer .= $data;
        }

        $messages = [];
        while (($parsed = $this->parseValue($this->buffer, 0)) !== null) {
            [$value, $consumed] = $parsed;
            $this->buffer = substr($this->buffer, $consumed);

            // A pub/sub delivery is: ["message", <channel>, <payload>]
            // (pattern deliveries are ["pmessage", <pattern>, <channel>, <payload>]).
            if (is_array($value) && isset($value[0]) && is_string($value[0])) {
                $kind = strtolower($value[0]);
                if ($kind === 'message' && count($value) >= 3) {
                    $messages[] = ['channel' => (string) $value[1], 'message' => (string) $value[2]];
                } elseif ($kind === 'pmessage' && count($value) >= 4) {
                    $messages[] = ['channel' => (string) $value[2], 'message' => (string) $value[3]];
                }
            }
        }

        return $messages;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream = null;
        $this->buffer = '';
    }

    /**
     * @return resource
     */
    private function openSocket()
    {
        $stream = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            5,
            STREAM_CLIENT_CONNECT
        );
        if ($stream === false) {
            throw new \RuntimeException("RedisSubscriberSocket: cannot connect to {$this->host}:{$this->port} — {$errstr} ({$errno})");
        }
        return $stream;
    }

    /**
     * Encode and send a RESP array command.
     *
     * @param string[] $args
     */
    private function sendCommand(array $args): void
    {
        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $cmd .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        @fwrite($this->stream, $cmd);
    }

    /**
     * Parse one complete RESP value from $buf starting at $offset.
     *
     * @return array{0:mixed,1:int}|null [value, absoluteBytesConsumed] or null if
     *                                   the buffer does not yet hold a full value.
     */
    private function parseValue(string $buf, int $offset): ?array
    {
        if ($offset >= strlen($buf)) {
            return null;
        }

        $type = $buf[$offset];
        $lineEnd = strpos($buf, "\r\n", $offset);
        if ($lineEnd === false) {
            return null; // header line not complete yet
        }

        $line = substr($buf, $offset + 1, $lineEnd - $offset - 1);
        $after = $lineEnd + 2;

        switch ($type) {
            case '+': // simple string
            case '-': // error
                return [$line, $after];

            case ':': // integer
                return [(int) $line, $after];

            case '$': // bulk string
                $len = (int) $line;
                if ($len < 0) {
                    return [null, $after]; // null bulk
                }
                if (strlen($buf) < $after + $len + 2) {
                    return null; // payload not fully arrived
                }
                return [substr($buf, $after, $len), $after + $len + 2];

            case '*': // array
                $count = (int) $line;
                if ($count < 0) {
                    return [null, $after];
                }
                $elements = [];
                $cursor = $after;
                for ($i = 0; $i < $count; $i++) {
                    $child = $this->parseValue($buf, $cursor);
                    if ($child === null) {
                        return null; // incomplete array
                    }
                    [$value, $cursor] = $child;
                    $elements[] = $value;
                }
                return [$elements, $cursor];

            default:
                // Unknown/garbled byte — skip the line to resync.
                return [null, $after];
        }
    }
}
