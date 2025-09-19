<?php

namespace Pramnos\Cache\Adapter;

/**
 * Redis cache adapter
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2024 Yannis - Pastis Glaros
 */
class RedisAdapter extends AbstractAdapter
{
    /**
     * Redis connection
     * @var \Redis
     */
    protected $redis = null;
    
    /**
     * Whether the connection is established
     * @var boolean
     */
    protected $connected = false;
    
    /**
     * Redis server hostname
     * @var string
     */
    protected $host = 'localhost';
    
    /**
     * Redis server port
     * @var integer
     */
    protected $port = 6379;
    
    /**
     * Redis database index
     * @var integer
     */
    protected $database = 0;
    
    /**
     * Redis auth password
     * @var string|null
     */
    protected $password = null;
    
    /**
     * @param string $host Redis server hostname
     * @param integer $port Redis server port
     * @param integer $database Redis database index
     * @param string|null $password Redis auth password
     * @param string $prefix Prefix for all cache keys
     */
    public function __construct($host = 'localhost', $port = 6379, $database = 0, $password = null, $prefix = '')
    {
        parent::__construct($prefix);
        $this->host = $host;
        $this->port = $port;
        $this->database = $database;
        $this->password = $password;
    }
    
    /**
     * Connect to Redis
     * @return boolean Success of the connection
     */
    public function connect()
    {
        if (!class_exists('\Redis')) {
            return false;
        }
        
        if ($this->redis === null) {
            $this->redis = new \Redis();
            try {
                $this->connected = $this->redis->connect(
                    $this->host, 
                    $this->port
                );
                
                if ($this->password) {
                    if (!$this->redis->auth($this->password)) {
                        $this->connected = false;
                    }
                }
                
                if ($this->connected && $this->database > 0) {
                    $this->redis->select($this->database);
                }
            }
            catch (\Exception $exc) {
                // Log error if logger is available, otherwise continue silently
                if (class_exists('\Pramnos\Logs\Logger')) {
                    \Pramnos\Logs\Logger::logError($exc->getMessage(), $exc);
                }
                $this->connected = false;
            }
        }
        
        return $this->connected;
    }
    
    /**
     * Get the Redis connection
     * @return \Redis|null
     */
    public function getConnection()
    {
        return $this->redis;
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
            $entry = $this->redis->get($key);
            if (!$entry) {
                return false;
            }
            $entry = unserialize($entry);
            
            // Check for timeout
            if (isset($entry['time']) && $entry['time'] > 0 && $timeout > 0) {
                if (($entry['time'] + $timeout) < time()) {
                    $this->redis->del($key);
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
            
            if ($timeout > 0) {
                $this->redis->setex(
                    $key, 
                    $timeout, 
                    serialize($entry)
                );
            } else {
                $this->redis->set(
                    $key, 
                    serialize($entry)
                );
            }
            
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
            $this->redis->del($key);
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
                $this->redis->flushDb();
                return true;
            } catch (\Exception $ex) {
                \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                return false;
            }
        } else {
            // Clear cache entries for the specific category
            try {
                // Sanitize the category name to match how keys are stored
                $sanitizedCategory = preg_replace(
                    array('/\s+/', '/[^\w\-]/'),
                    array('_', ''),
                    $category
                );
                
                // Find all keys that match this category
                $pattern = $this->prefix . $sanitizedCategory . '_*';
                $keys = $this->redis->keys($pattern);
                
                // Delete all matching keys
                if (!empty($keys)) {
                    $this->redis->del($keys);
                }
                
                return true;
            } catch (\Exception $ex) {
                \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                return false;
            }
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
            $tagsData = $this->redis->get($this->prefix . $this->tagsKey);
            if ($tagsData) {
                $tagsArray = json_decode($tagsData, true);
                return is_array($tagsArray) ? array_keys($tagsArray) : [];
            }
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
            'method' => 'redis',
            'categories' => 0,
            'items' => 0
        ];
        
        if (!$this->caching || !$this->connected) {
            return $stats;
        }
        
        try {
            $tagsData = $this->redis->get($this->prefix . $this->tagsKey);
            if ($tagsData) {
                $tagsArray = json_decode($tagsData, true);
                $stats['categories'] = is_array($tagsArray) ? count($tagsArray) : 0;
            }
            
            // Get number of items
            $stats['items'] = $this->redis->dbSize();
            // Remove one for the tags key
            if ($stats['items'] > 0 && $tagsData) {
                $stats['items']--;
            }
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
        
        return $stats;
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
            // Get all keys from Redis
            $pattern = $this->prefix . '*';
            if ($category !== '') {
                // Sanitize the category name to match how keys are stored
                $sanitizedCategory = preg_replace(
                    array('/\s+/', '/[^\w\-]/'),
                    array('_', ''),
                    $category
                );
                $pattern = $this->prefix . $sanitizedCategory . '_*';
            }
            
            $keys = $this->redis->keys($pattern);
            
            // Filter out the tags key
            $tagsKey = $this->prefix . $this->tagsKey;
            $keys = array_filter($keys, function($key) use ($tagsKey) {
                return $key !== $tagsKey;
            });
            
            // Limit the results
            $keys = array_slice($keys, 0, $limit);
            
            foreach ($keys as $key) {
                try {
                    $entry = $this->redis->get($key);
                    if ($entry) {
                        $entry = unserialize($entry);
                        $size = strlen($this->redis->get($key));
                        
                        $items[] = [
                            'key' => str_replace($this->prefix, '', $key),
                            'size' => $size,
                            'created_time' => isset($entry['time']) ? date('Y-m-d H:i:s', $entry['time']) : 'Unknown',
                            'ttl' => $this->redis->ttl($key),
                            'type' => gettype($entry['data'] ?? null)
                        ];
                    }
                } catch (\Exception $e) {
                    // Skip problematic keys
                    continue;
                }
            }
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
        
        return $items;
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
}
