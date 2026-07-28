<?php

declare(strict_types=1);

namespace Pramnos\Cache;

use Pramnos\Cache\AdapterInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Flat-key PSR-16 cache over any cache {@see AdapterInterface}.
 *
 * Backend-agnostic (Array for tests, Redis / File / Memcached in production) —
 * unlike {@see Cache}/{@see SimpleCache}, which are category-based and sanitise
 * keys through _generateCacheName(). FlatCache writes and reads the key
 * **verbatim** under a fixed prefix, so an application keeps full control of its
 * key namespace, including colon-namespaced keys such as "chat:messages:hash"
 * (which PSR-16's SimpleCache rejects).
 *
 * Serialisation and TTL are delegated to the adapter (each adapter already wraps
 * values and enforces expiry), so this class is a thin, portable key mapper.
 *
 * ```php
 * $cache = new FlatCache(new ArrayAdapter(), 'app:');          // tests
 * $cache = new FlatCache(new RedisAdapter($h, $p, 0, null, 'app:'), 'app:'); // prod
 * $cache->set('chat:messages:hash', $data, 300);
 * ```
 *
 * PSR-16 note: the spec reserves `{}()/\@:` but permits stores to accept a wider
 * key set; this store deliberately allows `:`. A stored `false` is reported as a
 * miss (adapters signal "not found" with false) — cache boolean-false behind a
 * wrapper array if you must distinguish it.
 */
class FlatCache implements CacheInterface
{
    public function __construct(
        private readonly AdapterInterface $adapter,
        private readonly string $prefix = '',
    ) {
        $this->adapter->connect();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // timeout 0 → do not let the adapter's load-time recheck override the
        // real backend expiry (the adapter already enforces the write-time TTL).
        $value = $this->adapter->load($this->key($key), 0);
        return $value === false ? $default : $value;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = $this->ttlToSeconds($ttl);
        if ($seconds !== null && $seconds <= 0) {
            $this->adapter->delete($this->key($key)); // already expired per PSR-16
            return true;
        }
        return (bool) $this->adapter->save($this->key($key), $value, $seconds ?? 0);
    }

    public function delete(string $key): bool
    {
        return (bool) $this->adapter->delete($this->key($key));
    }

    public function clear(): bool
    {
        return (bool) $this->adapter->clear();
    }

    public function has(string $key): bool
    {
        $sentinel = new \stdClass();
        return $this->get($key, $sentinel) !== $sentinel;
    }

    /**
     * Atomically increment an integer counter and return the new value.
     *
     * A counter is distinct from a cached value: it is stored as a bare integer
     * so the backend can use a native atomic primitive (Redis INCRBY). Read it
     * back with {@see counter()} — NOT {@see get()} — and never mix the two key
     * spaces. When $ttl is given the expiry is (re)set on every call (sliding
     * window), which is what rate-limit / attempt / epoch counters want.
     *
     * @param string                   $key The counter key (prefixed verbatim).
     * @param int                      $by  Amount to add (default 1).
     * @param null|int|\DateInterval   $ttl Sliding TTL; null leaves expiry untouched.
     */
    public function increment(string $key, int $by = 1, null|int|\DateInterval $ttl = null): int
    {
        $seconds = $this->ttlToSeconds($ttl);
        $full    = $this->key($key);
        if (method_exists($this->adapter, 'increment')) {
            return (int) $this->adapter->increment($full, $by, $seconds);
        }
        // Fallback for a bare AdapterInterface without the counter capability.
        $new = (int) $this->counter($key) + $by;
        $this->adapter->save($full, $new, $seconds ?? 0);
        return $new;
    }

    /**
     * Atomically decrement an integer counter and return the new value.
     * See {@see increment()}.
     */
    public function decrement(string $key, int $by = 1, null|int|\DateInterval $ttl = null): int
    {
        return $this->increment($key, -$by, $ttl);
    }

    /**
     * Read the current value of a counter written by {@see increment()}
     * (0 when absent, without creating the key).
     */
    public function counter(string $key): int
    {
        $full = $this->key($key);
        if (method_exists($this->adapter, 'counter')) {
            return (int) $this->adapter->counter($full);
        }
        $value = $this->adapter->load($full, 0);
        return $value === false || $value === null ? 0 : (int) $value;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }
        return $out;
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

    private function key(string $key): string
    {
        if ($key === '') {
            throw new SimpleCacheInvalidArgumentException('Cache key must not be empty');
        }
        return $this->prefix . $key;
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
}
