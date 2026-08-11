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
    /** The default Redis-backed instance, built lazily from the ConnectionManager. */
    private static ?self $default = null;

    public function __construct(
        private readonly AdapterInterface $adapter,
        private readonly string $prefix = '',
    ) {
        $this->adapter->connect();
    }

    /**
     * The default flat cache: a Redis adapter bound to the shared
     * {@see \Pramnos\Redis\ConnectionManager} (host/port/database/password and
     * per-install prefix), built lazily on first use so an application that
     * configures the manager during bootstrap (ConnectionManager::setInstance())
     * is already in effect. This is what lets an app depend on the cache
     * capability without re-wiring the adapter itself.
     *
     * The prefix is applied both to the adapter and to this FlatCache, so
     * colon-namespaced keys are stored verbatim under the install prefix.
     */
    public static function default(): self
    {
        if (self::$default === null) {
            $cm     = \Pramnos\Redis\ConnectionManager::getInstance();
            $prefix = $cm->prefix();
            self::$default = new self(
                new \Pramnos\Cache\Adapter\RedisAdapter(
                    $cm->host(),
                    $cm->port(),
                    $cm->database(),
                    $cm->password(),
                    $prefix
                ),
                $prefix
            );
        }
        return self::$default;
    }

    /**
     * Override the default instance (bootstrap wiring / test-reset seam). Pass
     * null to clear it so the next {@see default()} rebuilds from the current
     * ConnectionManager.
     */
    public static function setDefault(?self $cache): void
    {
        self::$default = $cache;
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

    /**
     * Atomically set a key to a new value and return the previous one.
     *
     * A raw-key atomic operation (like {@see increment()}): on a backend that
     * supports it (e.g. Redis GETSET) the read-and-set is a single operation, so
     * concurrent callers cannot both observe the same previous value — useful for
     * de-duplication (record only when the value changed). The value is stored
     * verbatim, so read it back with another swap() rather than get().
     *
     * @return string|null The previous value, or null when the key was unset.
     */
    public function swap(string $key, string $value): ?string
    {
        $full = $this->key($key);
        if (method_exists($this->adapter, 'swap')) {
            $prev = $this->adapter->swap($full, $value);
            return $prev === null ? null : (string) $prev;
        }
        // Fallback for a bare AdapterInterface without the swap capability.
        $prev = $this->adapter->load($full, null);
        $this->adapter->save($full, $value, 0);
        return ($prev === false || $prev === null) ? null : (string) $prev;
    }

    // ── Structured operations (hash / list / TTL / enumeration) ────────────────
    //
    // Field/element values are stored serialised, so any value round-trips.
    // Backed natively by a capable adapter (RedisAdapter: HASH/LIST/SCAN) or by
    // the AbstractAdapter load/save fallback.

    /** Set a field on a hash, optionally (re)setting the key TTL. */
    public function hashSet(string $key, string $field, mixed $value, null|int|\DateInterval $ttl = null): void
    {
        $this->adapter->hashSet($this->key($key), $field, $value, $this->ttlToSeconds($ttl));
    }

    /** Get a hash field, or $default when absent. */
    public function hashGet(string $key, string $field, mixed $default = null): mixed
    {
        return $this->adapter->hashGet($this->key($key), $field, $default);
    }

    /** Delete a hash field. */
    public function hashDelete(string $key, string $field): void
    {
        $this->adapter->hashDelete($this->key($key), $field);
    }

    /**
     * The whole hash as an associative array (empty when absent).
     *
     * @return array<string,mixed>
     */
    public function hashGetAll(string $key): array
    {
        return $this->adapter->hashGetAll($this->key($key));
    }

    /** Prepend a value to a list (Redis LPUSH). Returns the new length. */
    public function listPush(string $key, mixed $value): int
    {
        return (int) $this->adapter->listPush($this->key($key), $value);
    }

    /** Trim a list to the inclusive [$start, $stop] range (Redis LTRIM). */
    public function listTrim(string $key, int $start, int $stop): void
    {
        $this->adapter->listTrim($this->key($key), $start, $stop);
    }

    /**
     * The inclusive [$start, $stop] slice of a list (Redis LRANGE).
     *
     * @return array<int,mixed>
     */
    public function listRange(string $key, int $start, int $stop): array
    {
        return $this->adapter->listRange($this->key($key), $start, $stop);
    }

    /** (Re)set a key's TTL. */
    public function expire(string $key, int|\DateInterval $ttl): void
    {
        $this->adapter->expire($this->key($key), (int) $this->ttlToSeconds($ttl));
    }

    /**
     * Keys matching a glob-style pattern, returned in the caller's logical
     * key-space (the cache prefix is stripped). Requires an enumeration-capable
     * adapter (RedisAdapter via SCAN); others return an empty list.
     *
     * @return string[]
     */
    public function keys(string $pattern): array
    {
        // An adapter that cannot enumerate answers `[]`, which reads exactly
        // like "nothing matched". Saying so once, out loud, is the difference
        // between a caller learning why its invalidation never fires and a
        // caller believing the cache was empty.
        if (!$this->supportsKeyEnumeration()) {
            \Pramnos\Logs\Logger::log(
                'Cache keys() was asked of ' . get_class($this->adapter)
                . ', which cannot enumerate keys — the empty result means '
                . '"cannot look", not "nothing matched". Use the Redis adapter '
                . 'where key enumeration matters.',
                'cache'
            );

            return [];
        }

        $found  = $this->adapter->keys($this->key($pattern));
        $prefix = $this->prefix;
        if ($prefix === '') {
            return $found;
        }
        $out = [];
        foreach ($found as $k) {
            $out[] = str_starts_with($k, $prefix) ? substr($k, strlen($prefix)) : $k;
        }
        return $out;
    }

    /**
     * Can the adapter behind this cache list its keys?
     *
     * @return bool
     */
    public function supportsKeyEnumeration(): bool
    {
        return method_exists($this->adapter, 'supportsKeyEnumeration')
            && $this->adapter->supportsKeyEnumeration();
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
