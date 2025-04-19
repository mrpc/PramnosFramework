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
                \pramnos\Logs\Logger::logError($exc->getMessage(), $exc);
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
            return $this->categoryHash($category, $this->prefix, true) !== false;
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
            if ($stats['items'] > 0) {
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
    public function categoryHash($category, $prefix = '', $reset = false)
    {
        if ($category == '') {
            return '';
        }
        
        if (!$this->caching || !$this->connected) {
            return $category;
        }
        
        try {
            $tagsKey = ($prefix ? $prefix : $this->prefix) . $this->tagsKey;
            $entry = $this->redis->get($tagsKey);
            
            if ($entry) {
                $entry = json_decode($entry, true);
            }
            
            if (!is_array($entry)) {
                $entry = [];
            }
            
            // If we're resetting or don't have the category yet
            if ($reset || !isset($entry[$category])) {
                $entry[$category] = uniqid(substr(md5($category), 0, 3));
                
                $this->redis->set(
                    $tagsKey, 
                    json_encode($entry)
                );
            }
            
            return $entry[$category];
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return $category;
        }
    }
}
