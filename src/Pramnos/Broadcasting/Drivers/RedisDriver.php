<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Drivers;

use Pramnos\Broadcasting\SubscriptionOptions;

/**
 * Redis pub/sub backplane driver.
 *
 * Publishes events with {@see broadcast()} via `\Redis::publish` and consumes
 * them with {@see subscribe()} via `\Redis::subscribe`. This is the primary
 * backplane for both the SSE transport and the built-in WebSocket server: any
 * number of web-tier processes publish, and any number of long-lived consumers
 * (SSE streams, the WS daemon) subscribe, all decoupled through Redis.
 *
 * ## Envelope
 *
 * Every event is published as a JSON envelope `{event, payload, timestamp}` on
 * the (prefixed) channel, and decoded symmetrically on subscribe. Messages that
 * are not envelopes (e.g. published by legacy code) are still delivered, with an
 * empty event name and the decoded body as the payload, so a migration can move
 * publishers and consumers over incrementally.
 *
 * ## Connections
 *
 * Publishing reuses one lazily-opened connection. Subscribing always uses its
 * own dedicated connection (a subscribed connection cannot be used for anything
 * else), created through an injectable factory so the loop can be unit-tested
 * without a live server.
 */
class RedisDriver implements SubscribableDriverInterface
{
    private string $host;
    private int $port;
    private int $database;
    private ?string $password;
    private string $prefix;

    /** @var callable(): object Factory returning a connected \Redis (or compatible) instance. */
    private $factory;

    /** Lazily-opened shared connection used for publishing. */
    private ?object $publisher = null;

    /**
     * @param array<string,mixed> $config Keys: host, port, database, password, prefix.
     * @param callable|null       $factory Optional connection factory returning a connected
     *                                     \Redis. Injected in tests; defaults to a real \Redis.
     */
    public function __construct(array $config = [], ?callable $factory = null)
    {
        $this->host     = (string) ($config['host'] ?? '127.0.0.1');
        $this->port     = (int) ($config['port'] ?? 6379);
        $this->database = (int) ($config['database'] ?? 0);
        $this->password = isset($config['password']) && $config['password'] !== ''
            ? (string) $config['password']
            : null;
        $this->prefix   = (string) ($config['prefix'] ?? '');
        $this->factory  = $factory ?? fn (): object => $this->defaultConnection();
    }

    public function name(): string
    {
        return 'redis';
    }

    public function broadcast(string $channel, string $event, array $payload): void
    {
        if ($this->publisher === null) {
            $this->publisher = ($this->factory)();
        }
        $this->publisher->publish($this->prefix . $channel, $this->encodeEnvelope($event, $payload));
    }

    public function subscribe(array $channels, callable $onEvent, ?SubscriptionOptions $options = null): void
    {
        if ($channels === []) {
            throw new \InvalidArgumentException('subscribe() requires at least one channel.');
        }
        $options ??= new SubscriptionOptions();

        $prefixed = array_map(fn (string $c): string => $this->prefix . $c, array_values($channels));
        $deadline = $options->maxRuntime !== null ? time() + $options->maxRuntime : null;

        // The read timeout is a connection option here rather than a per-read argument, so the
        // clamp has to be applied when the connection is opened and again on every reconnect.
        // Without it the last blocking read runs past the deadline and the stream ends
        // somewhere in [maxRuntime, maxRuntime + readTimeout] — see
        // SubscriptionOptions::blockingWindow().
        $connection = $this->openSubscriber($options->blockingWindow($deadline));

        try {
            while (true) {
                if ($deadline !== null && time() >= $deadline) {
                    break;
                }

                try {
                    $connection->subscribe(
                        $prefixed,
                        function ($redis, string $rawChannel, string $message) use ($onEvent, $deadline): bool {
                            if ($deadline !== null && time() >= $deadline) {
                                return false; // unsubscribe
                            }
                            [$event, $payload] = $this->decodeEnvelope($message);
                            $result = $onEvent($this->stripPrefix($rawChannel), $event, $payload);
                            return $result !== false;
                        }
                    );

                    // Callback returned false → consumer asked to stop.
                    break;
                } catch (\RedisException $e) {
                    // A read-timeout (no message within readTimeout) surfaces here and is
                    // our idle signal; other transient errors are handled the same way —
                    // tick, honour the deadline, then reconnect for another pass.
                    $options->reportError($e);

                    if (!$options->fireIdle()) {
                        break;
                    }
                    if ($deadline !== null && time() >= $deadline) {
                        break;
                    }

                    $this->closeQuietly($connection);
                    $connection = $this->openSubscriber($options->blockingWindow($deadline));
                }
            }
        } finally {
            $this->closeQuietly($connection);
        }
    }

    /**
     * Open a fresh, dedicated connection configured for a blocking subscribe with
     * the given read timeout (the timeout is what drives the idle/ping cadence).
     */
    private function openSubscriber(int $readTimeout): object
    {
        $connection = ($this->factory)();
        if (defined('\Redis::OPT_READ_TIMEOUT') && method_exists($connection, 'setOption')) {
            $connection->setOption(\Redis::OPT_READ_TIMEOUT, (float) $readTimeout);
        }
        return $connection;
    }

    private function defaultConnection(): object
    {
        // Route connection creation through the central manager so connect/auth/
        // select lives in one place; honours this driver's own config.
        return (new \Pramnos\Redis\ConnectionManager([
            'host'     => $this->host,
            'port'     => $this->port,
            'database' => $this->database,
            'password' => $this->password,
        ]))->newConnection();
    }

    private function closeQuietly(object $connection): void
    {
        try {
            if (method_exists($connection, 'close')) {
                $connection->close();
            }
        } catch (\Throwable) {
            // Ignore close errors — the connection is being discarded anyway.
        }
    }

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
        // Non-enveloped message (legacy publisher): deliver raw so migration is incremental.
        return ['', is_array($decoded) ? $decoded : ['data' => $message]];
    }

    private function stripPrefix(string $channel): string
    {
        if ($this->prefix !== '' && str_starts_with($channel, $this->prefix)) {
            return substr($channel, strlen($this->prefix));
        }
        return $channel;
    }
}
