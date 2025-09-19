<?php

namespace Pramnos\Cache;

/**
 * Interface for all cache adapters
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2024 Yannis - Pastis Glaros
 */
interface AdapterInterface
{
    /**
     * Connect to the cache backend
     * @return boolean Success of the connection
     */
    public function connect();
    
    /**
     * Load data from cache
     * @param string $key The cache key
     * @param int $timeout Cache lifetime in seconds
     * @return mixed|boolean The cached data or false if not found
     */
    public function load($key, $timeout = 3600);
    
    /**
     * Save data to cache
     * @param string $key The cache key
     * @param mixed $data The data to cache
     * @param int $timeout Cache lifetime in seconds
     * @return boolean Success of the operation
     */
    public function save($key, $data, $timeout = 3600);
    
    /**
     * Delete a cached item
     * @param string $key The cache key
     * @return boolean Success of the operation
     */
    public function delete($key);
    
    /**
     * Clear all cached data or a specific category
     * @param string $category Optional category to clear
     * @return boolean Success of the operation
     */
    public function clear($category = '');
    
    /**
     * Get all categories
     * @param string $prefix Optional prefix to filter categories
     * @return array List of categories
     */
    public function getCategories($prefix = '');
    
    /**
     * Get stats about the cache
     * @return array Cache statistics
     */
    public function getStats();
    
    /**
     * Test the cache connection
     * @return boolean Success of the test
     */
    public function test();
    
    /**
     * Get or set a unique hash for a category
     * @param string $category The category name
     * @param string $prefix Optional prefix
     * @param boolean $reset Whether to reset the category hash
     * @return string The category hash
     */
    public function categoryHash($category, $prefix = '', $reset = false);
    
    /**
     * Get all cache items with metadata
     * @param string $category Optional category to filter by
     * @param int $limit Optional limit for number of items returned
     * @return array Array of cache items with metadata (key, size, created_time, etc.)
     */
    public function getAllItems($category = '', $limit = 100);
}
