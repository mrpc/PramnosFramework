<?php

declare(strict_types=1);

namespace Pramnos\Queue\Drivers;

use Pramnos\Queue\Contracts\QueueDriverInterface;
use Pramnos\Queue\ReservedJob;

/**
 * Redis-backed delayed-queue driver.
 *
 * The queue is a Redis sorted set scored by each job's run-at timestamp, plus a
 * companion hash holding the JSON payloads:
 *
 *   <prefix><namespace>:delayed   ZSET   jobId => runAt (unix seconds)
 *   <prefix><namespace>:data      HASH   jobId => json payload
 *
 * Claiming is atomic per job: a worker only owns a job if *its* ZREM removed the
 * id from the sorted set, so multiple workers never process the same job twice.
 * This mirrors the low-latency delayed dispatcher pattern (bot replies, deferred
 * deliveries) that a database-polling queue would add latency to — hence Redis
 * is the natural default driver for {@see \Pramnos\Queue\DelayedQueue}, with
 * {@see DatabaseQueueDriver} available where a database backend is preferred.
 *
 * ## Connections
 *
 * The driver either self-connects from the supplied config (host/port/auth/db)
 * or reuses an injected connection factory — the same pattern as the broadcasting
 * {@see \Pramnos\Broadcasting\Drivers\RedisDriver}, so an application can share
 * its existing \Redis connection (and prefix) rather than opening a second one.
 *
 * ## Prefixing
 *
 * Keys are prefixed by this driver explicitly (it does not rely on
 * \Redis::OPT_PREFIX). The `prefix` config is applied verbatim in front of the
 * namespace, so a migrating application that passes its historical Redis prefix
 * keeps addressing byte-identical keys — no jobs are stranded across the cutover.
 */
class RedisQueueDriver implements QueueDriverInterface
{
    private const DEFAULT_NAMESPACE = 'jobs';

    private string $host;
    private int $port;
    private int $database;
    private ?string $password;
    private string $prefix;
    private string $namespace;

    /** @var callable(): object Factory returning a connected \Redis (or compatible) instance. */
    private $factory;

    /** Lazily-opened shared connection. */
    private ?object $connection = null;

    /**
     * @param array<string,mixed> $config  Keys: host, port, database, password, prefix, namespace.
     * @param callable|null       $factory Optional connection factory returning a connected
     *                                      \Redis. Injected in tests or to share the app's
     *                                      connection; defaults to a real \Redis from config.
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
        $namespace       = (string) ($config['namespace'] ?? self::DEFAULT_NAMESPACE);
        $this->namespace = $namespace !== '' ? $namespace : self::DEFAULT_NAMESPACE;
        $this->factory   = $factory ?? fn (): object => $this->defaultConnection();
    }

    public function name(): string
    {
        return 'redis';
    }

    /**
     * The key namespace this driver operates under.
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    public function push(string $type, array $payload, int $delaySeconds = 0, int $attempts = 0): string
    {
        $jobId = bin2hex(random_bytes(12));
        $now   = time();
        $runAt = $now + max(0, $delaySeconds);

        $job = [
            'id'         => $jobId,
            'type'       => $type,
            'payload'    => $payload,
            'attempts'   => max(0, $attempts),
            'created_at' => $now,
            'run_at'     => $runAt,
        ];

        $redis = $this->connection();
        $redis->hSet($this->key('data'), $jobId, (string) json_encode($job, JSON_UNESCAPED_UNICODE));
        $redis->zAdd($this->key('delayed'), $runAt, $jobId);

        return $jobId;
    }

    public function claimDue(int $limit = 20): array
    {
        $redis = $this->connection();

        $ids = $redis->zRangeByScore(
            $this->key('delayed'),
            '0',
            (string) time(),
            ['limit' => [0, max(1, $limit)]]
        );

        if (!is_array($ids) || empty($ids)) {
            return [];
        }

        $claimed = [];

        foreach ($ids as $jobId) {
            // Whoever's ZREM returns 1 owns the job.
            if ((int) $redis->zRem($this->key('delayed'), $jobId) !== 1) {
                continue;
            }

            $raw = $redis->hGet($this->key('data'), $jobId);
            $redis->hDel($this->key('data'), $jobId);

            if (!is_string($raw)) {
                continue;
            }

            $job = json_decode($raw, true);
            if (!is_array($job) || !isset($job['type'])) {
                continue;
            }

            $claimed[] = new ReservedJob(
                (string) ($job['id'] ?? $jobId),
                (string) $job['type'],
                is_array($job['payload'] ?? null) ? $job['payload'] : [],
                (int) ($job['attempts'] ?? 0),
                (int) ($job['run_at'] ?? time())
            );
        }

        return $claimed;
    }

    public function size(): int
    {
        return (int) $this->connection()->zCard($this->key('delayed'));
    }

    public function secondsUntilNext(): ?int
    {
        $next = $this->connection()->zRange($this->key('delayed'), 0, 0, true);

        if (!is_array($next) || empty($next)) {
            return null;
        }

        $runAt = (int) reset($next);

        return max(0, $runAt - time());
    }

    public function flush(): int
    {
        $redis = $this->connection();
        $count = $this->size();
        $redis->del($this->key('delayed'));
        $redis->del($this->key('data'));

        return $count;
    }

    /**
     * Fully-qualified Redis key for a queue structure ("delayed" or "data").
     */
    private function key(string $structure): string
    {
        return $this->prefix . $this->namespace . ':' . $structure;
    }

    private function connection(): object
    {
        if ($this->connection === null) {
            $this->connection = ($this->factory)();
        }
        return $this->connection;
    }

    private function defaultConnection(): \Redis
    {
        if (!class_exists('\Redis')) {
            throw new \RuntimeException(
                'The phpredis extension (\\Redis) is required for the "redis" queue driver.'
            );
        }
        $redis = new \Redis();
        $redis->connect($this->host, $this->port);
        if ($this->password !== null) {
            $redis->auth($this->password);
        }
        if ($this->database > 0) {
            $redis->select($this->database);
        }
        return $redis;
    }
}
