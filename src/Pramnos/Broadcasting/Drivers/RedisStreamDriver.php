<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Drivers;

use Pramnos\Broadcasting\SubscriptionOptions;

/**
 * Redis backplane with replay, built on Streams instead of pub/sub.
 *
 * {@see RedisDriver} publishes with `PUBLISH`, which delivers to whoever is
 * subscribed at that instant and keeps nothing. For a WebSocket daemon that
 * stays connected, that is exactly right. For SSE it is not, and the reason is
 * structural rather than occasional: `maxRuntime` ends every stream on purpose,
 * so each client reconnects on a schedule, and everything published between the
 * close and the new subscription is delivered to nobody. Two applications lost
 * events that way before anyone noticed, because nothing errors — the events
 * simply never arrive.
 *
 * A Redis **stream** is a log with ids. `XADD` appends, `XREAD` blocks for what
 * comes next, and `XRANGE` reads back what has already been written — which is
 * the entire difference. A consumer that says where it got to is given the rest.
 *
 * The two drivers are deliberately separate rather than one with a flag: they
 * have different storage, different memory behaviour and different operational
 * questions ("how much history do I keep?" has no meaning for pub/sub). An
 * existing deployment keeps `RedisDriver` and is unaffected.
 *
 * **Retention is a decision.** {@see $maxLength} caps each channel's stream with
 * `XADD MAXLEN ~`, so history covers a reconnect without growing forever. A
 * client away for longer than the cap gets what remains, and an application that
 * needs more than that needs a snapshot on connect, not a bigger stream.
 */
class RedisStreamDriver implements SubscribableDriverInterface
{
    /** Redis host. */
    private string $host;

    /** Redis port. */
    private int $port;

    /** Redis database index. */
    private int $database;

    /** Redis password, or null when the server needs none. */
    private ?string $password;

    /** Key prefix, so several applications can share one Redis. */
    private string $prefix;

    /**
     * Approximate cap on entries kept per channel.
     *
     * Enforced with `MAXLEN ~`, which lets Redis trim on node boundaries — the
     * exact count drifts a little above the cap and costs far less than trimming
     * precisely on every append.
     */
    private int $maxLength;

    /** @var callable(): object Factory returning a connected \Redis (or compatible). */
    private $factory;

    /** Lazily-opened shared connection used for publishing. */
    private ?object $publisher = null;

    /**
     * @param array<string,mixed> $config  Keys: host, port, database, password, prefix, maxLength.
     * @param callable|null       $factory Optional connection factory returning a connected
     *                                     \Redis. Injected in tests; defaults to a real \Redis.
     */
    public function __construct(array $config = [], ?callable $factory = null)
    {
        $this->host      = (string) ($config['host'] ?? '127.0.0.1');
        $this->port      = (int) ($config['port'] ?? 6379);
        $this->database  = (int) ($config['database'] ?? 0);
        $this->password  = isset($config['password']) && $config['password'] !== ''
            ? (string) $config['password']
            : null;
        $this->prefix    = (string) ($config['prefix'] ?? '');
        $this->maxLength = max(1, (int) ($config['maxLength'] ?? 1000));
        $this->factory   = $factory ?? fn (): object => $this->defaultConnection();
    }

    public function name(): string
    {
        return 'redis-stream';
    }

    /**
     * Append an event to the channel's stream.
     *
     * Unlike a publish, this is durable for as long as the cap allows: a
     * consumer that is not connected right now can still be given it.
     */
    public function broadcast(string $channel, string $event, array $payload): void
    {
        if ($this->publisher === null) {
            $this->publisher = ($this->factory)();
        }

        $this->publisher->xAdd(
            $this->key($channel),
            '*',
            ['envelope' => $this->encodeEnvelope($event, $payload)],
            $this->maxLength,
            true,   // approximate trimming — MAXLEN ~
        );
    }

    /**
     * Consume the given channels, optionally resuming from a previous id.
     *
     * With {@see SubscriptionOptions::$sinceId} set, everything already in the
     * stream after that id is delivered first, and only then does the loop block
     * for new entries. That ordering is the point: a client reconnecting is
     * caught up before it starts seeing live events, so it never has to
     * reassemble the sequence itself.
     *
     * @param string[] $channels
     */
    public function subscribe(array $channels, callable $onEvent, ?SubscriptionOptions $options = null): void
    {
        if ($channels === []) {
            throw new \InvalidArgumentException('subscribe() requires at least one channel.');
        }
        $options ??= new SubscriptionOptions();

        $channels = array_values($channels);
        $deadline = $options->maxRuntime !== null ? time() + $options->maxRuntime : null;

        $connection = ($this->factory)();

        // Where each channel's cursor starts. `$` means "only what arrives from
        // now on" — the right default for a first connection, and exactly the
        // wrong thing for a reconnect, which is why sinceId exists.
        $cursors = [];
        foreach ($channels as $channel) {
            $cursors[$this->key($channel)] = $options->sinceId ?? '$';
        }

        try {
            while (true) {
                if ($deadline !== null && time() >= $deadline) {
                    break;
                }

                $entries = null;
                try {
                    $entries = $connection->xRead(
                        $cursors,
                        0,                                 // no count limit
                        $options->readTimeout * 1000,      // block, in milliseconds
                    );
                } catch (\Throwable $e) {
                    // A blocked read that timed out surfaces here on some
                    // clients, and a transient error looks the same from up
                    // here: tick, honour the deadline, carry on.
                    $options->reportError($e);
                }

                if (!is_array($entries) || $entries === []) {
                    if (!$options->fireIdle()) {
                        break;
                    }
                    continue;
                }

                if (!$this->deliver($entries, $cursors, $onEvent, $deadline)) {
                    return;
                }
            }
        } finally {
            $this->closeQuietly($connection);
        }
    }

    /**
     * Hand one XREAD result to the consumer, advancing the cursors as it goes.
     *
     * @param  array<string, array<string, array<string, string>>> $entries
     * @param  array<string, string>                               $cursors  By reference: advanced per entry
     * @param  int|null                                            $deadline
     * @return bool False when the consumer asked to stop
     */
    private function deliver(array $entries, array &$cursors, callable $onEvent, ?int $deadline): bool
    {
        foreach ($entries as $key => $messages) {
            foreach ($messages as $id => $fields) {
                // Advanced before delivery, so an event that makes the consumer
                // stop is not replayed on the next connection as though it had
                // never arrived.
                $cursors[$key] = (string) $id;

                [$event, $payload] = $this->decodeEnvelope((string) ($fields['envelope'] ?? ''));

                $result = $onEvent($this->channelFromKey((string) $key), $event, $payload, (string) $id);
                if ($result === false) {
                    return false;
                }

                if ($deadline !== null && time() >= $deadline) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The Redis key holding a channel's stream.
     */
    private function key(string $channel): string
    {
        return $this->prefix . 'stream:' . $channel;
    }

    /**
     * The channel a stream key belongs to — the inverse of {@see key()}.
     */
    private function channelFromKey(string $key): string
    {
        $withoutPrefix = $this->prefix !== '' && str_starts_with($key, $this->prefix)
            ? substr($key, strlen($this->prefix))
            : $key;

        return str_starts_with($withoutPrefix, 'stream:')
            ? substr($withoutPrefix, 7)
            : $withoutPrefix;
    }

    /**
     * A connected \Redis using this driver's configuration.
     *
     * @codeCoverageIgnore Requires a live server; the loop is tested through an
     *                     injected factory.
     */
    private function defaultConnection(): object
    {
        $redis = new \Redis();
        $redis->connect($this->host, $this->port);
        if ($this->password !== null) {
            $redis->auth($this->password);
        }
        if ($this->database !== 0) {
            $redis->select($this->database);
        }

        return $redis;
    }

    /**
     * Close a connection, ignoring anything it says on the way out.
     */
    private function closeQuietly(object $connection): void
    {
        try {
            if (method_exists($connection, 'close')) {
                $connection->close();
            }
        } catch (\Throwable) {
            // The connection is being discarded anyway.
        }
    }

    /**
     * The same envelope {@see RedisDriver} publishes, so the two are readable by
     * the same consumers and a project can move between them.
     */
    private function encodeEnvelope(string $event, array $payload): string
    {
        return (string) json_encode(
            ['event' => $event, 'payload' => $payload, 'timestamp' => time()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * @return array{0:string,1:array<string,mixed>} [event, payload]
     */
    private function decodeEnvelope(string $message): array
    {
        $decoded = json_decode($message, true);
        if (is_array($decoded) && array_key_exists('event', $decoded)) {
            $payload = $decoded['payload'] ?? [];
            return [(string) $decoded['event'], is_array($payload) ? $payload : ['value' => $payload]];
        }

        // Non-enveloped entry (written by something else): deliver raw rather
        // than dropping it, so a migration can happen incrementally.
        return ['', is_array($decoded) ? $decoded : ['data' => $message]];
    }
}
