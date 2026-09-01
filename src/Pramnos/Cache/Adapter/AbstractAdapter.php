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
     * The category the current operation belongs to.
     *
     * Set by {@see \Pramnos\Cache\Cache} before each adapter call, because the
     * category is chosen per call and an adapter instance is shared. It is here
     * rather than being re-derived from the key: {@see FileAdapter} used to
     * recover it by splitting the key on its first underscore, which is right
     * for `userlist_<id>` and wrong for `schema_columns_things_<id>` — the
     * entry went into a directory called `schema` and `clear()` then looked for
     * one called `schema_columns_things`, found nothing, and reported success.
     *
     * @var string
     */
    protected $category = '';

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
     * Set the category the next operation belongs to.
     *
     * Write-only on purpose. Nothing needs to ask an adapter which category it
     * is on — the caller always knows, because it just set it — and a
     * `getCategory()` here would collide in meaning with
     * {@see \Pramnos\Cache\Cache::getCategory()}, which is a sanitiser that
     * takes a category and returns a cleaned copy of it.
     *
     * @param string $category
     * @return self
     */
    public function setCategory($category)
    {
        $this->category = (string) $category;
        return $this;
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
     * Whether {@see increment()} is atomic on this backend.
     *
     * False here, and that is the important part: every adapter inherits a
     * working `increment()` from this class, so the mere presence of the method
     * says nothing about whether it is safe under concurrency. The default
     * above is a load followed by a save, which loses increments when two
     * processes overlap.
     *
     * A caller doing security work — a rate limiter, a single-use token — needs
     * to know the difference, and asking `method_exists()` would tell it the
     * File adapter counts atomically. Backends with a native counter override
     * this to true.
     */
    public function supportsAtomicCounter(): bool
    {
        return false;
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
    //
    // Every read below passes `0` as the timeout, and that argument is the reason: it is a
    // *maximum age the reader will accept*, not the entry's TTL, and these helpers have no
    // opinion about age — they want whatever is stored, if it has not expired. Taking `load()`'s
    // 3600 default instead meant a hash saved with no expiry became unreadable one hour after it
    // was written, on any adapter that honours the argument.

    /**
     * Set a field on a hash. Optionally (re)sets the key TTL.
     */
    public function hashSet($key, $field, $value, $ttl = null)
    {
        $hash = $this->load($key, 0);
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
        $hash = $this->load($key, 0);
        return (is_array($hash) && array_key_exists($field, $hash)) ? $hash[$field] : $default;
    }

    /**
     * Delete a field from a hash.
     */
    public function hashDelete($key, $field)
    {
        $hash = $this->load($key, 0);
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
        $hash = $this->load($key, 0);
        return is_array($hash) ? $hash : [];
    }

    /**
     * Prepend a value to a list (like Redis LPUSH). Returns the new length.
     */
    public function listPush($key, $value)
    {
        $list = $this->load($key, 0);
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
        $list = $this->load($key, 0);
        if (is_array($list)) {
            $this->save($key, $this->rangeSlice($list, (int) $start, (int) $stop), 0);
        }
    }

    /**
     * Return the inclusive [$start, $stop] slice of a list (Redis LRANGE).
     */
    public function listRange($key, $start, $stop)
    {
        $list = $this->load($key, 0);
        return is_array($list) ? $this->rangeSlice($list, (int) $start, (int) $stop) : [];
    }

    /**
     * (Re)set a key's TTL. Non-atomic default: re-save the current value.
     */
    public function expire($key, $ttl)
    {
        $value = $this->load($key, 0);
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
     * Sweep the entries that have expired, and say how many went.
     *
     * Nothing to do here, and that is the honest default rather than a stub: Redis, Memcached and
     * the in-process array all drop an entry when its TTL passes, so there is no accumulation to
     * reclaim. Only a backend that stores entries where nothing else looks at them — the file
     * adapter — has a sweep worth asking for, and it overrides this.
     *
     * **Public, and separate from the sampled sweep.** {@see FileAdapter} already collects garbage
     * on roughly one call in a hundred, which is right for amortising the cost across ordinary
     * traffic and useless as a guarantee: an installation with a scheduled housekeeping task has a
     * deterministic moment to do this and had no way to ask for it. `flushEverything()` is not that
     * — it removes everything, including what is still valid.
     *
     * Not added to {@see \Pramnos\Cache\AdapterInterface}, deliberately. An application with its
     * own adapter implements that interface, and a new method on it would break every one of them
     * on upgrade; {@see \Pramnos\Cache\Cache::cleanup()} asks whether the adapter has this before
     * calling it.
     *
     * @return int Entries removed
     */
    public function cleanup()
    {
        return 0;
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
