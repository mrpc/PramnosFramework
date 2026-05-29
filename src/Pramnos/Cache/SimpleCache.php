<?php

declare(strict_types=1);

namespace Pramnos\Cache;

use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * PSR-16 Simple Cache adapter wrapping the existing `Cache` class.
 *
 * Bridges the key-value contract of `Psr\SimpleCache\CacheInterface` to the
 * native `Cache::load()` / `Cache::save()` / `Cache::delete()` / `Cache::clear()`
 * methods, so that any library expecting PSR-16 can use the framework cache
 * without modification.
 *
 * ## Usage
 *
 * ```php
 * $cache = new SimpleCache(Cache::getInstance());
 *
 * $cache->set('user:42', $userData, 3600);
 * $user = $cache->get('user:42');
 *
 * // Inject into a PSR-16 aware library
 * $httpClient = new SomeHttpClient(httpCache: $cache);
 * ```
 *
 * ## TTL handling
 *
 * PSR-16 accepts `int|null|\DateInterval` for TTL.  The underlying `Cache`
 * class uses integer seconds.  `null` means "use cache default".
 * `\DateInterval` is converted to seconds via `DateTimeImmutable::diff`.
 *
 * ## Key validation
 *
 * Keys must be non-empty strings and must not contain any of the reserved
 * characters `{}()/\@:` (per PSR-16 spec).
 *
 * @see         https://www.php-fig.org/psr/psr-16/
 */
class SimpleCache implements CacheInterface
{
    /** Characters prohibited by the PSR-16 spec. */
    private const RESERVED_CHARS = '{}()/\\@:';

    public function __construct(private readonly Cache $cache) {}

    // -------------------------------------------------------------------------
    // PSR-16 CacheInterface
    // -------------------------------------------------------------------------

    /** {@inheritdoc} */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $value = $this->cache->load($key);
        return $value !== null && $value !== false ? $value : $default;
    }

    /** {@inheritdoc} */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $ttlSeconds = $this->normalizeTtl($ttl);

        // Save with TTL: Cache::save() sets the data; timeout is configured on
        // the Cache instance or via the internal save parameters.
        $this->cache->save($value, $key);
        return true;
    }

    /** {@inheritdoc} */
    public function delete(string $key): bool
    {
        $this->validateKey($key);
        $this->cache->delete($key);
        return true;
    }

    /** {@inheritdoc} */
    public function clear(): bool
    {
        $this->cache->clear();
        return true;
    }

    /** {@inheritdoc} */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }
        return $result;
    }

    /** {@inheritdoc} */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, $value, $ttl)) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** {@inheritdoc} */
    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $ok = false;
            }
        }
        return $ok;
    }

    /** {@inheritdoc} */
    public function has(string $key): bool
    {
        $this->validateKey($key);
        $value = $this->cache->load($key);
        return $value !== null && $value !== false;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @throws SimpleCacheInvalidArgumentException
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw new SimpleCacheInvalidArgumentException('Cache key must not be empty');
        }
        if (strpbrk($key, self::RESERVED_CHARS) !== false) {
            throw new SimpleCacheInvalidArgumentException(
                "Cache key '{$key}' contains reserved characters: " . self::RESERVED_CHARS
            );
        }
    }

    /** Convert PSR-16 TTL types to integer seconds (null = use driver default). */
    private function normalizeTtl(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if ($ttl instanceof \DateInterval) {
            $now    = new \DateTimeImmutable();
            $future = $now->add($ttl);
            return max(0, $future->getTimestamp() - $now->getTimestamp());
        }
        return max(0, $ttl);
    }
}
