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
     * @param string $type The cache type
     * @return string
     */
    public function generateKey($id, $category = '', $type = 'cache')
    {
        $prefix = '';
        if ($this->prefix != '') {
            $prefix = $this->sanitizeName($this->prefix . '_');
        }
        
        $categoryHash = '';
        if ($category != '') {
            $categoryHash = $this->categoryHash($category) . '_';
        }
        
        $suffix = '.' . $this->sanitizeName($type);
        if (defined('CACHE_PREFIX')) {
            $suffix = '.' . CACHE_PREFIX . $suffix;
        }
        
        return $prefix . $categoryHash . $id . $suffix;
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
        
        $testKey = $this->generateKey('pramnos_test_connection');
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
}
