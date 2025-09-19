<?php

namespace Pramnos\Cache\Adapter;

/**
 * Memcached cache adapter
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2024 Yannis - Pastis Glaros
 */
class MemcachedAdapter extends AbstractAdapter
{
    /**
     * Memcached connection
     * @var \Memcached
     */
    protected $memcached = null;
    
    /**
     * Whether the connection is established
     * @var boolean
     */
    protected $connected = false;
    
    /**
     * Memcached server hostname
     * @var string
     */
    protected $host = 'localhost';
    
    /**
     * Memcached server port
     * @var integer
     */
    protected $port = 11211;
    
    /**
     * Persistent ID for Memcached
     * @var string
     */
    protected $persistentId = '';
    
    /**
     * @param string $host Memcached server hostname
     * @param integer $port Memcached server port
     * @param string $persistentId Persistent ID for Memcached
     * @param string $prefix Prefix for all cache keys
     */
    public function __construct($host = 'localhost', $port = 11211, $persistentId = '', $prefix = '')
    {
        parent::__construct($prefix);
        $this->host = $host;
        $this->port = $port;
        $this->persistentId = $persistentId;
    }
    
    /**
     * Connect to Memcached
     * @return boolean Success of the connection
     */
    public function connect()
    {
        if (!class_exists('\Memcached')) {
            return false;
        }
        
        if ($this->memcached === null) {
            $this->memcached = new \Memcached($this->persistentId);
            
            $servers = $this->memcached->getServerList();
            if (is_array($servers) && count($servers) > 0) {
                $this->connected = true;
                return true;
            }
            
            try {
                $this->connected = $this->memcached->addServer(
                    $this->host, $this->port
                );
            }
            catch (\Exception $exc) {
                \pramnos\Logs\Logger::logError($exc->getMessage(), $exc);
                $this->connected = false;
            }
        }
        
        return $this->connected;
    }
    
    /**
     * Get the Memcached connection
     * @return \Memcached|null
     */
    public function getConnection()
    {
        return $this->memcached;
    }
    
    /**
     * @inheritDoc
     */
    public function load($key, $timeout = 3600)
    {
        if (!$this->caching || !$this->connected) {
            return false;
        }
        
        try {
            $entry = $this->memcached->get($key);
            
            if ($entry === false && $this->memcached->getResultCode() != \Memcached::RES_SUCCESS) {
                return false;
            }
            
            if (!is_array($entry)) {
                return false;
            }
            
            // Check for timeout
            if (isset($entry['time']) && $entry['time'] > 0) {
                if (($entry['time'] + $timeout) < time()) {
                    $this->memcached->delete($key);
                    return false;
                }
            }
            
            return $entry['data'];
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        }
    }
    
    /**
     * @inheritDoc
     */
    public function save($key, $data, $timeout = 3600)
    {
        if (!$this->caching || !$this->connected) {
            return false;
        }

        try {
            $entry = [
                'data' => $data,
                'time' => time()
            ];

            $result = $this->memcached->set($key, $entry, $timeout);
            
            // Check if the operation was successful
            if ($result === false || $this->memcached->getResultCode() != \Memcached::RES_SUCCESS) {
                return false;
            }
            
            // Track this key for category-based clearing
            $this->_trackKeyForCategory($key);
            
            return true;
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        }
    }   
    
    /**
     * @inheritDoc
     */
    public function delete($key)
    {
        if (!$this->caching || !$this->connected) {
            return false;
        }
        
        try {
            $result = $this->memcached->delete($key);
            
            // Check if the operation was successful
            // Note: delete() can return false if key doesn't exist, which is OK
            $resultCode = $this->memcached->getResultCode();
            if ($result === false && $resultCode != \Memcached::RES_NOTFOUND) {
                return false;
            }
            
            // Remove key from category tracking
            $this->_removeKeyFromCategory($key);
            
            return true;
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        }
    }
    
    /**
     * @inheritDoc
     */
    public function clear($category = '')
    {
        if (!$this->caching || !$this->connected) {
            return false;
        }
        
        if ($category == '') {
            try {
                $this->memcached->flush();
                return true;
            } catch (\Exception $ex) {
                \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                return false;
            }
        } else {
            // For category-specific clearing, use key tracking approach
            try {
                // Sanitize the category name to match how keys are stored
                $sanitizedCategory = preg_replace(
                    array('/\s+/', '/[^\w\-]/'),
                    array('_', ''),
                    $category
                );
                
                // Get list of keys for this category
                $categoryKeysKey = $this->prefix . 'category_keys_' . $sanitizedCategory;
                $keys = $this->memcached->get($categoryKeysKey);
                
                if (is_array($keys) && !empty($keys)) {
                    // Delete all keys in this category
                    foreach ($keys as $key) {
                        $this->memcached->delete($key);
                    }
                    
                    // Clear the category index
                    $this->memcached->delete($categoryKeysKey);
                }
                
                return true;
            } catch (\Exception $ex) {
                \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                return false;
            }
        }
    }
    
    /**
     * Track a key for category-based clearing
     * @param string $key The cache key to track
     */
    private function _trackKeyForCategory($key)
    {
        if (!$this->connected) {
            return;
        }
        
        try {
            // Extract category from key (assumes format: prefix + category + _ + specific_identifier)
            $keyWithoutPrefix = str_replace($this->prefix, '', $key);
            
            // Find the category part (everything before the last underscore or dash)
            if (preg_match('/^(.+?)[-_][^-_]+$/', $keyWithoutPrefix, $matches)) {
                $category = $matches[1];
                
                // Get existing keys for this category
                $categoryKeysKey = $this->prefix . 'category_keys_' . $category;
                $existingKeys = $this->memcached->get($categoryKeysKey);
                
                if (!is_array($existingKeys)) {
                    $existingKeys = array();
                }
                
                // Add this key to the category index
                if (!in_array($key, $existingKeys)) {
                    $existingKeys[] = $key;
                    $this->memcached->set($categoryKeysKey, $existingKeys, 0); // No expiration for index
                }
            }
        } catch (\Exception $ex) {
            // Silently fail - key tracking is not critical
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
    }
    
    /**
     * Remove a key from category tracking
     * @param string $key The cache key to remove from tracking
     */
    private function _removeKeyFromCategory($key)
    {
        if (!$this->connected) {
            return;
        }
        
        try {
            // Extract category from key (assumes format: prefix + category + _ + specific_identifier)
            $keyWithoutPrefix = str_replace($this->prefix, '', $key);
            
            // Find the category part (everything before the last underscore or dash)
            if (preg_match('/^(.+?)[-_][^-_]+$/', $keyWithoutPrefix, $matches)) {
                $category = $matches[1];
                
                // Get existing keys for this category
                $categoryKeysKey = $this->prefix . 'category_keys_' . $category;
                $existingKeys = $this->memcached->get($categoryKeysKey);
                
                if (is_array($existingKeys)) {
                    // Remove this key from the category index
                    $existingKeys = array_filter($existingKeys, function($existingKey) use ($key) {
                        return $existingKey !== $key;
                    });
                    
                    if (empty($existingKeys)) {
                        // If no keys left, delete the category index
                        $this->memcached->delete($categoryKeysKey);
                    } else {
                        // Update the category index
                        $this->memcached->set($categoryKeysKey, array_values($existingKeys), 0);
                    }
                }
            }
        } catch (\Exception $ex) {
            // Silently fail - key tracking is not critical
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function getCategories($prefix = '')
    {
        if (!$this->caching || !$this->connected) {
            return [];
        }
        
        try {
            $tagsArray = $this->memcached->get(($prefix ? $prefix : $this->prefix) . $this->tagsKey);
            return is_array($tagsArray) ? array_keys($tagsArray) : [];
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
        
        return [];
    }
    
    /**
     * @inheritDoc
     */
    public function getStats()
    {
        $stats = [
            'method' => 'memcached',
            'categories' => 0,
            'items' => 0
        ];
        
        if (!$this->caching || !$this->connected) {
            return $stats;
        }
        
        try {
            // Get categories
            $tagsArray = $this->memcached->get($this->prefix . $this->tagsKey);
            $stats['categories'] = is_array($tagsArray) ? count($tagsArray) : 0;
            
            // Get all stats
            $serverStats = $this->memcached->getStats();
            if (is_array($serverStats)) {
                foreach ($serverStats as $server => $values) {
                    if (isset($values['curr_items'])) {
                        $stats['items'] = $values['curr_items'];
                        break;
                    }
                }
            }
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
        
        return $stats;
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
        $items = [];
        
        if (!$this->caching || !$this->connected) {
            return $items;
        }
        
        try {
            // Get all keys from Memcached using getAllKeys if available
            $keys = $this->memcached->getAllKeys();
            
            if (!$keys) {
                // Fallback: Memcached doesn't have reliable key listing
                // Return empty array with a note
                return [
                    [
                        'key' => 'memcached_limitation',
                        'size' => 0,
                        'created_time' => 'N/A',
                        'ttl' => 0,
                        'type' => 'info',
                        'note' => 'Memcached does not support reliable key enumeration'
                    ]
                ];
            }
            
            // Filter keys by prefix
            $prefixedKeys = [];
            foreach ($keys as $key) {
                if (strpos($key, $this->prefix) === 0) {
                    $prefixedKeys[] = $key;
                }
            }
            
            // Filter out the tags key
            $tagsKey = $this->prefix . $this->tagsKey;
            $prefixedKeys = array_filter($prefixedKeys, function($key) use ($tagsKey) {
                return $key !== $tagsKey;
            });
            
            // Limit the results
            $prefixedKeys = array_slice($prefixedKeys, 0, $limit);
            
            foreach ($prefixedKeys as $key) {
                try {
                    $entry = $this->memcached->get($key);
                    if ($entry !== false) {
                        if (is_array($entry) && isset($entry['data'])) {
                            $size = strlen(serialize($entry));
                            $items[] = [
                                'key' => str_replace($this->prefix, '', $key),
                                'size' => $size,
                                'created_time' => isset($entry['time']) ? date('Y-m-d H:i:s', $entry['time']) : 'Unknown',
                                'ttl' => -1, // Memcached doesn't provide TTL info easily
                                'type' => gettype($entry['data'])
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    // Skip problematic keys
                    continue;
                }
            }
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            // Return limitation notice
            return [
                [
                    'key' => 'memcached_error',
                    'size' => 0,
                    'created_time' => 'N/A',
                    'ttl' => 0,
                    'type' => 'error',
                    'note' => 'Error retrieving Memcached keys: ' . $ex->getMessage()
                ]
            ];
        }
        
        return $items;
    }
}
