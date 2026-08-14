<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

/**
 * Non-blocking Redis **stream** ingest over a raw RESP socket.
 *
 * The same shape as {@see RedisSubscriberSocket} and for the same reason — the
 * WebSocket server's `stream_select()` loop cannot call a blocking client — but
 * it issues `XREAD BLOCK 0` instead of `SUBSCRIBE`. That is the whole difference,
 * and it is the difference between working and silently receiving nothing when
 * the application publishes with {@see Drivers\RedisStreamDriver}.
 *
 * **Why this class had to exist.** An application with both transports has two
 * consumers of one backplane: SSE reads through `SubscribableDriverInterface`
 * (which the stream driver implements, blocking `XREAD` and all) while the
 * WebSocket daemon reads through a raw socket. With only a pub/sub socket
 * available, publishing to a stream left the daemon subscribed to a key nothing
 * would ever `PUBLISH` to — no error, no warning, no events. The only way out was
 * to publish twice, which puts two representations of one event on the backplane:
 * exactly what the driver abstraction exists to prevent.
 *
 * **`XREAD BLOCK 0` fits a select loop better than `SUBSCRIBE` does.** It is one
 * command whose reply arrives when an entry does, which is precisely the property
 * `stream_select()` needs — and because the read is positional, the cursor
 * survives a restart of the *daemon* as well. A worker restarted mid-deploy with
 * a pub/sub socket misses everything published while it was down; one reading
 * from its last id does not. {@see cursors()} is what a supervisor persists to
 * get that, and {@see __construct}'s `$sinceIds` is how it resumes.
 *
 * Deliberately minimal: AUTH, SELECT, XREAD and enough RESP to parse the reply.
 * It is an ingest path for fan-out, not a Redis client.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license     MIT
 */
class RedisStreamSocket implements RedisIngestInterface
{
    /** @var resource|null The socket, once connected. */
    private $stream = null;

    /** Bytes read but not yet parsed into a complete reply. */
    private string $buffer = '';

    /** Redis host. */
    private string $host;

    /** Redis port. */
    private int $port;

    /** Password, or null when the server needs none. */
    private ?string $password;

    /** Database index; only SELECTed when it is not 0. */
    private int $database;

    /**
     * Where each stream is being read from.
     *
     * `$` means "only entries added after this read was issued" and is what a
     * daemon with no history to resume starts from. After the first reply every
     * key holds a real id, which is what makes the position survive a reconnect.
     *
     * @var array<string, string> Stream key → last id read
     */
    private array $cursors = [];

    /** How many entries one XREAD may return per stream. */
    private int $count;

    /**
     * Whether an XREAD is outstanding.
     *
     * `XREAD BLOCK 0` answers once, when there is something to answer with — so
     * exactly one is in flight at a time and the next is issued after each reply.
     * Without this the socket would be readable with nothing behind it.
     */
    private bool $pending = false;

    /** @var callable(): mixed Factory returning a connected stream; a test seam. */
    private $streamFactory;

    /**
     * @param array<string, mixed>  $config        Keys: host, port, password, database, count.
     * @param string[]              $streams       Fully-qualified stream keys to read.
     * @param array<string, string> $sinceIds      Stream key → last id already handled, for a
     *                                             daemon resuming after a restart. Anything
     *                                             absent starts from `$` (new entries only).
     * @param callable|null         $streamFactory Test seam returning a stream resource.
     */
    public function __construct(
        array $config,
        array $streams,
        array $sinceIds = [],
        ?callable $streamFactory = null
    ) {
        $this->host     = (string) ($config['host'] ?? '127.0.0.1');
        $this->port     = (int) ($config['port'] ?? 6379);
        $this->password = isset($config['password']) && $config['password'] !== ''
            ? (string) $config['password']
            : null;
        $this->database = (int) ($config['database'] ?? 0);
        $this->count    = max(1, (int) ($config['count'] ?? 100));

        foreach (array_values($streams) as $key) {
            $this->cursors[(string) $key] = isset($sinceIds[$key]) && (string) $sinceIds[$key] !== ''
                ? (string) $sinceIds[$key]
                : '$';
        }

        $this->streamFactory = $streamFactory ?? fn () => $this->openSocket();
    }

    /**
     * Connect, authenticate, and issue the first read.
     *
     * @return void
     * @throws \RuntimeException When no stream could be opened.
     */
    public function connect(): void
    {
        $this->stream = ($this->streamFactory)();
        if (!is_resource($this->stream)) {
            throw new \RuntimeException('RedisStreamSocket: could not open a stream to Redis.');
        }
        stream_set_blocking($this->stream, false);

        if ($this->password !== null) {
            $this->sendCommand(['AUTH', $this->password]);
        }
        if ($this->database > 0) {
            $this->sendCommand(['SELECT', (string) $this->database]);
        }

        $this->pending = false;
        $this->read();
    }

    /**
     * @return resource|null The socket, for inclusion in `stream_select()`.
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Where each stream is currently being read from.
     *
     * Worth persisting: handed back to the constructor as `$sinceIds`, a restarted
     * daemon continues instead of skipping whatever arrived while it was down.
     *
     * @return array<string, string> Stream key → last id read
     */
    public function cursors(): array
    {
        return $this->cursors;
    }

    /**
     * Read whatever has arrived and return the entries in it.
     *
     * Every complete reply advances the cursor for its stream and causes the next
     * `XREAD` to be issued, so the flow continues without the caller knowing that
     * this transport is request/response rather than a subscription.
     *
     * @return list<array{channel: string, message: string}>
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
        $answered = false;

        while (($parsed = $this->parseValue($this->buffer, 0)) !== null) {
            [$value, $consumed] = $parsed;
            $this->buffer = substr($this->buffer, $consumed);

            // `+OK` from AUTH or SELECT, and the nil that a BLOCK timeout
            // produces. Neither carries entries; the nil still means this read is
            // over and the next one is due.
            if (!is_array($value)) {
                if ($value === null) {
                    $answered = true;
                }
                continue;
            }

            $answered = true;
            foreach ($this->entriesOf($value) as $message) {
                $messages[] = $message;
            }
        }

        if ($answered) {
            // The read that just answered is finished. Issue the next one, from
            // the cursors it advanced.
            $this->pending = false;
            $this->read();
        }

        return $messages;
    }

    /**
     * Close the socket and forget any partial reply.
     *
     * The cursors are kept: a caller that closes in order to reconnect wants to
     * carry on from where it was, and that is the whole advantage of reading a
     * stream rather than subscribing to one.
     *
     * @return void
     */
    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
        $this->stream  = null;
        $this->buffer  = '';
        $this->pending = false;
    }

    /**
     * Turn one `XREAD` reply into messages, advancing each stream's cursor.
     *
     * The reply is `[[key, [[id, [field, value, …]], …]], …]`. Only the driver's
     * own `envelope` field is passed on when it is present, so a stream written by
     * {@see Drivers\RedisStreamDriver} produces exactly what a pub/sub delivery
     * would have — the server's fan-out then needs to know nothing about which
     * transport brought the event. An entry written by something else is handed
     * over as a JSON object of its fields rather than dropped.
     *
     * @param  array<int, mixed> $reply
     * @return list<array{channel: string, message: string, id: string}>
     */
    private function entriesOf(array $reply): array
    {
        $messages = [];

        foreach ($reply as $stream) {
            if (!is_array($stream) || count($stream) < 2 || !is_array($stream[1])) {
                continue;
            }

            $key = (string) $stream[0];

            foreach ($stream[1] as $entry) {
                if (!is_array($entry) || count($entry) < 2) {
                    continue;
                }

                $id = (string) $entry[0];
                // Advance even for an entry that carries nothing usable: a cursor
                // that stops moving re-delivers the same unusable entry for ever.
                $this->cursors[$key] = $id;

                $fields = $this->fieldsOf(is_array($entry[1]) ? $entry[1] : []);
                if ($fields === []) {
                    continue;
                }

                $messages[] = [
                    'channel' => $key,
                    'message' => isset($fields['envelope'])
                        ? (string) $fields['envelope']
                        : (string) json_encode($fields),
                    // The entry id, which until 2026-08-14 was consumed for the cursor and
                    // dropped. A WebSocket worker therefore could not tell *when* an event was
                    // published, while an SSE stream could — `SseWriter::stream()` has always
                    // passed the id to `onEvent`.
                    //
                    // That asymmetry is not academic: an ephemeral event carries no timestamp of
                    // its own, so a consumer that sets state from receipt time shows a replayed
                    // "someone is typing…" for somebody who stopped minutes ago. It stayed
                    // invisible only because a worker starts at `$` and never replays —
                    // persisting cursors, which is the point of reading a stream, is exactly the
                    // change that would have made it visible, for every WebSocket client at once.
                    'id' => $id,
                ];
            }
        }

        return $messages;
    }

    /**
     * A stream entry's flat `[field, value, field, value]` list as a map.
     *
     * @param  array<int, mixed>     $flat
     * @return array<string, string>
     */
    private function fieldsOf(array $flat): array
    {
        $fields = [];
        $values = array_values($flat);

        for ($i = 0; $i + 1 < count($values); $i += 2) {
            $fields[(string) $values[$i]] = (string) $values[$i + 1];
        }

        return $fields;
    }

    /**
     * Issue one `XREAD`, unless one is already outstanding.
     *
     * `BLOCK 0` waits indefinitely, which is what makes the socket quiet until
     * there is something to say — and quiet is what a select loop wants.
     *
     * @return void
     */
    private function read(): void
    {
        if ($this->pending || $this->cursors === [] || !is_resource($this->stream)) {
            return;
        }

        $args = ['XREAD', 'COUNT', (string) $this->count, 'BLOCK', '0', 'STREAMS'];
        foreach (array_keys($this->cursors) as $key) {
            $args[] = $key;
        }
        foreach ($this->cursors as $id) {
            $args[] = $id;
        }

        $this->sendCommand($args);
        $this->pending = true;
    }

    /**
     * @return resource
     * @throws \RuntimeException When the connection is refused.
     *
     * @codeCoverageIgnore Opens a real TCP connection to a real Redis. Every test
     *                     supplies the stream through the factory instead, which
     *                     is why the factory exists; exercising this would mean
     *                     asserting that `stream_socket_client()` works.
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
            throw new \RuntimeException(
                "RedisStreamSocket: cannot connect to {$this->host}:{$this->port} — {$errstr} ({$errno})"
            );
        }
        return $stream;
    }

    /**
     * Encode and send a RESP array command.
     *
     * @param  string[] $args
     * @return void
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
     * Parse one complete RESP value out of `$buf` at `$offset`.
     *
     * @param  string $buf
     * @param  int    $offset
     * @return array{0: mixed, 1: int}|null [value, bytes consumed], or null while
     *                                      the buffer holds no complete value.
     */
    private function parseValue(string $buf, int $offset): ?array
    {
        if ($offset >= strlen($buf)) {
            return null;
        }

        $type    = $buf[$offset];
        $lineEnd = strpos($buf, "\r\n", $offset);
        if ($lineEnd === false) {
            return null;
        }

        $line  = substr($buf, $offset + 1, $lineEnd - $offset - 1);
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
                    return [null, $after];
                }
                if (strlen($buf) < $after + $len + 2) {
                    return null;
                }
                return [substr($buf, $after, $len), $after + $len + 2];

            case '*': // array
                $count = (int) $line;
                if ($count < 0) {
                    // A nil array is what `BLOCK` returns on timeout.
                    return [null, $after];
                }
                $elements = [];
                $cursor   = $after;
                for ($i = 0; $i < $count; $i++) {
                    $child = $this->parseValue($buf, $cursor);
                    if ($child === null) {
                        return null;
                    }
                    [$value, $cursor] = $child;
                    $elements[] = $value;
                }
                return [$elements, $cursor];

            default:
                // A byte that starts no RESP value: skip the line to resync
                // rather than stall on it for ever.
                return [null, $after];
        }
    }
}
