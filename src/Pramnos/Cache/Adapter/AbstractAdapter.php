<?php

namespace Pramnos\Cache\Adapter;

use Pramnos\Cache\AdapterInterface;

/**
 * Abstract base class for all cache adapters
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license    MIT
 */
abstract class AbstractAdapter implements AdapterInterface
{
    /**
     * Prefix for all cache keys
     * @var string
     */
    protected $prefix = '';
    
    /**
     * Key for category hashes
     * @var string
     */
    protected $tagsKey = 'memcachedtags';
    
    /**
     * Whether caching is enabled
     * @var boolean
     */
    protected $caching = true;
    
    /**
     * @param string $prefix Prefix for all cache keys
     */
    public function __construct($prefix = '')
    {
        $this->prefix = $prefix;
    }
    
    /**
     * Set the cache prefix
     * @param string $prefix
     * @return self
     */
    public function setPrefix($prefix)
    {
        $this->prefix = $prefix;
        return $this;
    }
    
    /**
     * Get the cache prefix
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }
    
    /**
     * Set caching enabled/disabled
     * @param boolean $enabled
     * @return self
     */
    public function setCaching($enabled)
    {
        $this->caching = (bool)$enabled;
        return $this;
    }
    
    /**
     * Check if caching is enabled
     * @return boolean
     */
    public function isCachingEnabled()
    {
        return $this->caching;
    }
    
    /**
     * Sanitize a name for use in cache keys
     * @param string $name
     * @return string
     */
    protected function sanitizeName($name)
    {
        return preg_replace(
            array('/\s/', '/\.[\.]+/', '/[^\w_\.\-]/'),
            array('_', '.', ''), $name
        );
    }
    
    /**
     * Generate a cache key
     * @param string $id The cache ID
     * @param string $category The category
     * @param string $extension The cache extension (suffix)
     * @return string
     */
    public function generateKey($id, $category = '', $extension = 'cache')
    {
        $prefix = '';
        if ($this->prefix != '') {
            $prefix = $this->sanitizeName($this->prefix . '_');
        }
        
        $categoryHash = '';
        if ($category != '') {
            $categoryHash = $this->categoryHash($category) . '_';
        }
        
        $suffix = '.' . $this->sanitizeName($extension);
        
        return $prefix . $categoryHash . $id . $suffix;
    }
    
    /**
     * Load data from the cache by key
     * @param string $key The cache key
     * @param int|null $timeout The cache timeout in seconds (optional)
     * @return mixed|null The cached data or null if not found
     */
    public function load($key, $timeout = null)
    {
        if (!$this->caching) {
            return null;
        }

        // This method should be implemented by concrete adapters
        throw new \BadMethodCallException("The 'load' method is not implemented in the adapter.");
    }
    
    /**
     * Test the cache with a standard operation
     * @return boolean Success of the test
     */
    public function test()
    {
        if (!$this->caching) {
            return false;
        }
        
        $testKey = 'pramnos_test_connection_' . time();
        $testValue = 'Cache test value - ' . time();
        // Save test data
        $saveResult = $this->save($testKey, $testValue);
        if (!$saveResult) {
            return false;
        }
        
        // Load the test data
        $loadedValue = $this->load($testKey);
        if ($loadedValue !== $testValue) {
            return false;
        }
        
        // Clean up
        $this->delete($testKey);
        
        return true;
    }
    
    /**
     * @inheritDoc
     */
    public function connect()
    {
        // Default implementation - should be overridden by concrete adapters
        return true;
    }
    
    /**
     * @inheritDoc
     */
    public function save($key, $data, $timeout = 3600)
    {
        // Default implementation - should be overridden by concrete adapters
        throw new \BadMethodCallException("The 'save' method is not implemented in the adapter.");
    }
    
    /**
     * @inheritDoc
     */
    public function delete($key)
    {
        // Default implementation - should be overridden by concrete adapters
        throw new \BadMethodCallException("The 'delete' method is not implemented in the adapter.");
    }

    /**
     * Increment an integer counter and return the new value.
     *
     * Non-atomic default (load + save via this adapter). Backends with a native
     * atomic primitive (e.g. {@see RedisAdapter} via INCRBY) override this to be
     * concurrency-safe; the semantics — "add $by, return the new total, and
     * (re)set a sliding TTL when $ttl is given" — are identical either way.
     *
     * @param string   $key The counter key.
     * @param int      $by  Amount to add (default 1).
     * @param int|null $ttl Sliding TTL in seconds; null/<=0 leaves expiry untouched.
     * @return int New value.
     */
    public function increment($key, $by = 1, $ttl = null)
    {
        $new = (int) $this->counter($key) + (int) $by;
        $this->save($key, $new, ($ttl !== null && (int) $ttl > 0) ? (int) $ttl : 0);
        return $new;
    }

    /**
     * Decrement an integer counter and return the new value.
     * Non-atomic default; see {@see increment()}.
     *
     * @param string   $key The counter key.
     * @param int      $by  Amount to subtract (default 1).
     * @param int|null $ttl Sliding TTL in seconds; null/<=0 leaves expiry untouched.
     * @return int New value.
     */
    public function decrement($key, $by = 1, $ttl = null)
    {
        return $this->increment($key, -(int) $by, $ttl);
    }

    /**
     * Read the current integer value of a counter (0 when absent).
     * Non-atomic default (via {@see load()}); backends may override.
     *
     * @param string $key The counter key.
     * @return int
     */
    public function counter($key)
    {
        $value = $this->load($key, 0);
        return $value === false || $value === null ? 0 : (int) $value;
    }

    /**
     * Atomically set a key to a new value and return the previous one.
     *
     * Part of the raw-key atomic family alongside {@see increment()}: like those,
     * it operates on the raw key so a backend (e.g. Redis GETSET) can implement it
     * as a single operation. This default fallback (read-then-write) is NOT atomic
     * — concrete adapters that can do a real swap should override it.
     *
     * @param  string $key
     * @param  string $value
     * @return string|null The previous value, or null when the key was unset.
     */
    public function swap($key, $value)
    {
        $prev = $this->load($key, null);
        $this->save($key, $value, 0);
        return ($prev === false || $prev === null) ? null : (string) $prev;
    }

    // ── Structured operations (hash / list / enumeration) ──────────────────────
    //
    // Non-atomic defaults that keep the whole structure under one key via
    // load()/save(). Backends with native structures (e.g. RedisAdapter via
    // HSET/LPUSH/SCAN) override these; a bare AdapterInterface without them is
    // handled by FlatCache's own fallback.

    /**
     * Set a field on a hash. Optionally (re)sets the key TTL.
     */
    public function hashSet($key, $field, $value, $ttl = null)
    {
        $hash = $this->load($key);
        if (!is_array($hash)) {
            $hash = [];
        }
        $hash[$field] = $value;
        $this->save($key, $hash, ($ttl !== null && (int) $ttl > 0) ? (int) $ttl : 0);
    }

    /**
     * Get a field from a hash, or $default when absent.
     */
    public function hashGet($key, $field, $default = null)
    {
        $hash = $this->load($key);
        return (is_array($hash) && array_key_exists($field, $hash)) ? $hash[$field] : $default;
    }

    /**
     * Delete a field from a hash.
     */
    public function hashDelete($key, $field)
    {
        $hash = $this->load($key);
        if (is_array($hash) && array_key_exists($field, $hash)) {
            unset($hash[$field]);
            $this->save($key, $hash, 0);
        }
    }

    /**
     * Return the whole hash as an associative array (empty when absent).
     */
    public function hashGetAll($key)
    {
        $hash = $this->load($key);
        return is_array($hash) ? $hash : [];
    }

    /**
     * Prepend a value to a list (like Redis LPUSH). Returns the new length.
     */
    public function listPush($key, $value)
    {
        $list = $this->load($key);
        if (!is_array($list)) {
            $list = [];
        }
        array_unshift($list, $value);
        $this->save($key, $list, 0);
        return count($list);
    }

    /**
     * Trim a list to the inclusive [$start, $stop] range (Redis LTRIM semantics,
     * negative indices allowed).
     */
    public function listTrim($key, $start, $stop)
    {
        $list = $this->load($key);
        if (is_array($list)) {
            $this->save($key, $this->rangeSlice($list, (int) $start, (int) $stop), 0);
        }
    }

    /**
     * Return the inclusive [$start, $stop] slice of a list (Redis LRANGE).
     */
    public function listRange($key, $start, $stop)
    {
        $list = $this->load($key);
        return is_array($list) ? $this->rangeSlice($list, (int) $start, (int) $stop) : [];
    }

    /**
     * (Re)set a key's TTL. Non-atomic default: re-save the current value.
     */
    public function expire($key, $ttl)
    {
        $value = $this->load($key);
        if ($value !== null && $value !== false) {
            $this->save($key, $value, (int) $ttl);
        }
    }

    /**
     * Can this adapter list the keys it holds?
     *
     * File, Array and Memcached cannot — the first two have no index to scan
     * without walking the filesystem, and Memcached exposes no reliable key
     * enumeration at all. Redis can, through SCAN.
     *
     * The question exists because {@see keys()} answers `[]` for two different
     * situations: "nothing matched" and "I cannot look". Nothing depends on
     * telling them apart today, and the first thing that does would break
     * silently on three adapters out of four. Ask this first.
     *
     * @return bool
     */
    public function supportsKeyEnumeration(): bool
    {
        return false;
    }

    /**
     * Return keys matching a glob-style pattern.
     *
     * An empty array from an adapter that cannot enumerate means "I cannot
     * look", not "nothing matched" — see {@see supportsKeyEnumeration()}.
     *
     * @return string[]
     */
    public function keys($pattern)
    {
        return [];
    }

    /**
     * Redis LRANGE/LTRIM-style inclusive slice with negative-index support.
     *
     * @param array<int,mixed> $list
     * @return array<int,mixed>
     */
    protected function rangeSlice(array $list, int $start, int $stop): array
    {
        $len = count($list);
        if ($len === 0) {
            return [];
        }
        if ($start < 0) {
            $start = max($len + $start, 0);
        }
        if ($stop < 0) {
            $stop = $len + $stop;
        }
        if ($start > $stop || $start >= $len) {
            return [];
        }
        $stop = min($stop, $len - 1);
        return array_values(array_slice($list, $start, $stop - $start + 1));
    }

    /**
     * @inheritDoc
     */
    public function clear($category = '')
    {
        // Default implementation - should be overridden by concrete adapters
        return false;
    }

    /**
     * Wipe the entire backend, ignoring this installation's prefix.
     *
     * `clear()` is scoped to the prefix, because that is the isolation rule the
     * adapter enforces on every read and write — an installation must not be
     * able to destroy a co-tenant's data by clearing its own cache. This is the
     * explicit way to ask for the other thing.
     *
     * Adapters that can flush their whole backend override it. The default is
     * to do nothing and say so, which is the right answer for a backend that
     * has no such operation, and a much better default than guessing.
     *
     * @return bool True when the backend was flushed
     */
    public function flushEverything()
    {
        return false;
    }
    
    /**
     * @inheritDoc
     */
    public function getCategories($prefix = '')
    {
        // Default implementation - return empty array
        return [];
    }
    
    /**
     * @inheritDoc
     */
    public function getStats()
    {
        // Default implementation
        return [
            'method' => 'unknown',
            'categories' => 0,
            'items' => 0
        ];
    }
    
    /**
     * @inheritDoc
     */
    public function categoryHash($category, $prefix = '', $reset = false)
    {
        if ($category == '') {
            return '';
        }
        
        // Sanitize the category name to make it safe for cache keys
        // Remove spaces, special characters, keep only alphanumeric, underscores, and hyphens
        return preg_replace(
            array('/\s+/', '/[^\w\-]/'),
            array('_', ''),
            $category
        );
    }
    
    /**
     * @inheritDoc
     */
    public function getAllItems($category = '', $limit = 100)
    {
        // Default implementation - return empty array
        return [];
    }
}
