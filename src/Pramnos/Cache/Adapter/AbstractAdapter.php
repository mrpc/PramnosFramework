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
     * @inheritDoc
     */
    public function clear($category = '')
    {
        // Default implementation - should be overridden by concrete adapters
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
