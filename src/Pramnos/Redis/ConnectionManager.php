<?php

declare(strict_types=1);

namespace Pramnos\Redis;

/**
 * Central Redis connection manager.
 *
 * The single place that opens \Redis connections for the framework, so the
 * cache, broadcasting and queue Redis drivers — and applications — share one
 * connection source instead of each re-rolling connect/auth/select. It provides:
 *
 * - {@see connection()} — a shared, lazily-opened connection for ordinary work
 *   (get/set, publish, sorted sets, hashes);
 * - {@see newConnection()} — a fresh, dedicated connection for a blocking
 *   `SUBSCRIBE` (a subscribed connection cannot be used for anything else);
 * - {@see prefix()} — the per-install key prefix, exposed (not applied as
 *   OPT_PREFIX) so callers prefix explicitly and keys stay byte-predictable.
 *
 * Configuration is a plain array (`host`, `port`, `database`, `password`,
 * `prefix`); the default instance ({@see getInstance()}) resolves it from the
 * framework `redis` settings section. A connection factory can be injected for
 * tests (no live server needed) or to reuse an existing connection.
 */
class ConnectionManager
{
    private string $host;
    private int $port;
    private int $database;
    private ?string $password;
    private string $prefix;
    private float $timeout;
    private ?float $readTimeout;

    /** @var callable(): object Factory returning a connected \Redis (or compatible). */
    private $factory;

    /** Lazily-opened shared connection. */
    private ?object $shared = null;

    private static ?self $instance = null;

    /** @var array<string, self> One manager per endpoint. See forConfig(). */
    private static array $pool = [];

    /**
     * @param array<string,mixed> $config  host, port, database, password, prefix,
     *                                      timeout (connect seconds), read_timeout
     *                                      (OPT_READ_TIMEOUT seconds)
     * @param callable|null       $factory Optional factory returning a connected \Redis;
     *                                      defaults to a real connection from $config.
     */
    public function __construct(array $config = [], ?callable $factory = null)
    {
        $this->host        = (string) ($config['host'] ?? '127.0.0.1');
        $this->port        = (int) ($config['port'] ?? 6379);
        $this->database    = (int) ($config['database'] ?? 0);
        $this->password    = isset($config['password']) && $config['password'] !== ''
            ? (string) $config['password']
            : null;
        $this->prefix      = (string) ($config['prefix'] ?? '');
        $this->timeout     = (float) ($config['timeout'] ?? 0.0);
        $this->readTimeout = isset($config['read_timeout']) ? (float) $config['read_timeout'] : null;
        $this->factory     = $factory ?? fn (): object => $this->makeConnection();
    }

    /**
     * The shared connection (opened on first use). Reuse this for everything
     * except a blocking subscribe.
     */
    public function connection(): object
    {
        return $this->shared ??= ($this->factory)();
    }

    /**
     * The manager for one endpoint, shared by everything that asks for it.
     *
     * `new ConnectionManager($config)` gives an object with a pool of its own, so two
     * callers naming the same server get two managers and two sockets. That is invisible
     * in a request — the process exits — and it is a leak in a daemon.
     *
     * It cost a production incident. `Cache::getInstance()` keeps one instance per
     * category and each one built its own manager, so a `queue:process --daemon` worker
     * reached **1,016 open redis connections in five minutes**, against a default
     * `LimitNOFILE` of 1024. What happens at that ceiling is the part worth knowing: with
     * no descriptors left the autoloader cannot open a class file either, and PHP reports
     * `Class "…\MemcachedAdapter" not found` — a class that is present, and that
     * `class_exists()` confirms from a fresh process on the same machine while the error
     * is being logged.
     *
     * Keyed on what actually determines the socket. The password is hashed into the key
     * rather than placed in it: array keys end up in `var_dump`, in a stack trace, and in
     * whatever prints one.
     *
     * @param array<string, mixed> $config
     */
    public static function forConfig(array $config): self
    {
        $key = implode("\0", array(
            (string) ($config['host'] ?? ''),
            (string) ($config['port'] ?? ''),
            (string) ($config['database'] ?? ''),
            ($config['password'] ?? '') === '' || ($config['password'] ?? null) === null
                ? ''
                : hash('sha256', (string) $config['password']),
        ));

        return self::$pool[$key] ??= new self($config);
    }

    /**
     * Forget the pooled managers. For tests, and for a process rebuilding its world.
     */
    public static function resetPool(): void
    {
        self::$pool = array();
    }

    /**
     * A fresh, dedicated connection — for a blocking `SUBSCRIBE` loop, which
     * monopolises its connection and so must not share the pooled one.
     *
     * **Not for ordinary use.** Every caller that only needs to get and set should use
     * {@see connection()}; this one opens a socket that nothing will close until the
     * process ends.
     */
    public function newConnection(): object
    {
        return ($this->factory)();
    }

    /**
     * The configured per-install key prefix (exposed, not auto-applied).
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function database(): int
    {
        return $this->database;
    }

    public function password(): ?string
    {
        return $this->password;
    }

    private function makeConnection(): \Redis
    {
        if (!class_exists('\Redis')) {
            throw new \RuntimeException('The phpredis extension (\\Redis) is required for Redis connections.');
        }
        $redis = new \Redis();
        // Fail fast on connect/auth so callers get a clear error instead of a
        // dead connection that only throws on the first command.
        //
        // The connect is silenced because phpredis raises a PHP warning *and*
        // returns false, and the throw below says the same thing with the host
        // and port in it. Callers of this — the cache, above all — treat a
        // failure as "use the next backend" and log it themselves, so the raw
        // warning added a second entry per request for a condition already
        // reported, in the exact situation where the log matters most.
        $connected = $this->timeout > 0
            ? @$redis->connect($this->host, $this->port, $this->timeout)
            : @$redis->connect($this->host, $this->port);
        if (!$connected) {
            throw new \RuntimeException("Could not connect to Redis at {$this->host}:{$this->port}");
        }
        if ($this->readTimeout !== null && defined('\Redis::OPT_READ_TIMEOUT')) {
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, $this->readTimeout);
        }
        if ($this->password !== null && !$redis->auth($this->password)) {
            throw new \RuntimeException('Redis AUTH failed');
        }
        if ($this->database > 0) {
            $redis->select($this->database);
        }
        return $redis;
    }

    /**
     * The default manager, configured from the framework `redis` settings
     * section on first use. Applications that manage their own configuration can
     * override it with {@see setInstance()} during bootstrap.
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self(self::resolveConfig());
    }

    /**
     * Override (or, with null, clear) the default manager — the bootstrap seam
     * for applications and the reset seam for tests.
     */
    public static function setInstance(?self $manager): void
    {
        self::$instance = $manager;
    }

    /**
     * @return array<string,mixed>
     */
    private static function resolveConfig(): array
    {
        // Prefer native env vars (envvar(): getenv/$_ENV/$_SERVER) — the framework's
        // recommended configuration source.
        if (function_exists('envvar') && envvar('REDIS_HOST') !== null) {
            return [
                'host'     => (string) envvar('REDIS_HOST'),
                'port'     => (int) envvar('REDIS_PORT', 6379),
                'database' => (int) envvar('REDIS_DATABASE', 0),
                'password' => envvar('REDIS_PASSWORD'),
                'prefix'   => (string) envvar('REDIS_PREFIX', ''),
            ];
        }
        // Fall back to a `redis` settings section if one is configured.
        if (class_exists(\Pramnos\Application\Settings::class)) {
            $redis = \Pramnos\Application\Settings::getSetting('redis');
            if (is_array($redis)) {
                return $redis;
            }
            if (is_object($redis)) {
                return (array) $redis;
            }
        }
        return [];
    }
}
