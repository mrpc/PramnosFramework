<?php

declare(strict_types=1);

namespace Pramnos\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Flat-key PSR-16 cache backed directly by Redis.
 *
 * Unlike {@see Cache} / {@see SimpleCache} — which are category-based and
 * sanitise keys through `_generateCacheName()` — this store writes and reads the
 * key **verbatim** (under a fixed prefix), so an application keeps full control
 * of its Redis key namespace, including colon-namespaced keys such as
 * `chat:messages:hash`. It is the natural fit for apps that already address Redis
 * with explicit flat keys.
 *
 * Values are `serialize()`d so any serialisable type round-trips; TTL uses native
 * `SETEX`. The `\Redis` connection is created lazily through an injectable
 * factory, so the store can be unit-tested without a live server.
 *
 * PSR-16 note: the spec reserves `{}()/\@:` for future use but permits stores to
 * accept a wider key set; this store deliberately allows `:` (Redis-native).
 */
class RedisStore implements CacheInterface
{
    private string $host;
    private int $port;
    private int $database;
    private ?string $password;
    private string $prefix;

    /** @var callable(): object */
    private $factory;
    private ?object $redis = null;

    /**
     * @param array<string,mixed> $config Keys: host, port, database, password, prefix.
     * @param callable|null       $factory Optional connection factory (test seam).
     */
    public function __construct(array $config = [], ?callable $factory = null)
    {
        $this->host     = (string) ($config['host'] ?? '127.0.0.1');
        $this->port     = (int) ($config['port'] ?? 6379);
        $this->database = (int) ($config['database'] ?? 0);
        $this->password = isset($config['password']) && $config['password'] !== '' ? (string) $config['password'] : null;
        $this->prefix   = (string) ($config['prefix'] ?? '');
        $this->factory  = $factory ?? fn (): object => $this->connect();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertKey($key);
        $raw = $this->redis()->get($this->prefix . $key);
        if ($raw === false || $raw === null) {
            return $default;
        }
        $value = @unserialize((string) $raw);
        return $value === false && $raw !== serialize(false) ? $default : $value;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->assertKey($key);
        $seconds = $this->ttlToSeconds($ttl);
        $payload = serialize($value);
        $redisKey = $this->prefix . $key;

        if ($seconds === null) {
            return (bool) $this->redis()->set($redisKey, $payload);
        }
        if ($seconds <= 0) {
            // Already expired per PSR-16 → ensure it is not stored.
            $this->redis()->del($redisKey);
            return true;
        }
        return (bool) $this->redis()->setex($redisKey, $seconds, $payload);
    }

    public function delete(string $key): bool
    {
        $this->assertKey($key);
        $this->redis()->del($this->prefix . $key);
        return true;
    }

    public function clear(): bool
    {
        // Prefix-scoped wipe (never a blind FLUSHDB). No prefix = nothing to scope.
        if ($this->prefix === '') {
            return false;
        }
        $keys = $this->redis()->keys($this->prefix . '*');
        if (is_array($keys) && $keys !== []) {
            $this->redis()->del($keys);
        }
        return true;
    }

    public function has(string $key): bool
    {
        $this->assertKey($key);
        return (bool) $this->redis()->exists($this->prefix . $key);
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }
        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete($key) && $ok;
        }
        return $ok;
    }

    private function redis(): object
    {
        if ($this->redis === null) {
            $this->redis = ($this->factory)();
        }
        return $this->redis;
    }

    private function connect(): \Redis
    {
        if (!class_exists('\Redis')) {
            throw new \RuntimeException('The phpredis extension (\\Redis) is required for RedisStore.');
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

    private function ttlToSeconds(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }
        return $ttl;
    }

    private function assertKey(string $key): void
    {
        if ($key === '') {
            throw new SimpleCacheInvalidArgumentException('Cache key must not be empty');
        }
    }
}
