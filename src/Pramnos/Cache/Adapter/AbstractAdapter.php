<?php

namespace Pramnos\Cache\Adapter;

use Pramnos\Cache\AdapterInterface;

/**
 * Abstract base class for all cache adapters
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2024 Yannis - Pastis Glaros
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
        // Default implementation - just return the category name
        return $category;
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
