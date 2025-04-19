<?php

namespace Pramnos\Cache\Adapter;

/**
 * Memcache cache adapter (for older Memcache extension)
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2024 Yannis - Pastis Glaros
 */
class MemcacheAdapter extends AbstractAdapter
{
    /**
     * Memcache connection
     * @var \Memcache
     */
    protected $memcache = null;
    
    /**
     * Whether the connection is established
     * @var boolean
     */
    protected $connected = false;
    
    /**
     * Memcache server hostname
     * @var string
     */
    protected $host = 'localhost';
    
    /**
     * Memcache server port
     * @var integer
     */
    protected $port = 11211;
    
    /**
     * @param string $host Memcache server hostname
     * @param integer $port Memcache server port
     * @param string $prefix Prefix for all cache keys
     */
    public function __construct($host = 'localhost', $port = 11211, $prefix = '')
    {
        parent::__construct($prefix);
        $this->host = $host;
        $this->port = $port;
    }
    
    /**
     * Connect to Memcache
     * @return boolean Success of the connection
     */
    public function connect()
    {
        if (!class_exists('\Memcache')) {
            return false;
        }
        
        if ($this->memcache === null) {
            $this->memcache = new \Memcache();
            
            try {
                $this->connected = $this->memcache->connect(
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
     * Get the Memcache connection
     * @return \Memcache|null
     */
    public function getConnection()
    {
        return $this->memcache;
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
            $entry = $this->memcache->get($key);
            
            if ($entry === false) {
                return false;
            }
            
            if (!is_array($entry)) {
                return false;
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
            
            $this->memcache->set($key, $entry, false, $timeout);
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
            $this->memcache->delete($key);
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
                $this->memcache->flush();
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
            $tagsArray = $this->memcache->get(($prefix ? $prefix : $this->prefix) . $this->tagsKey);
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
            'method' => 'memcache',
            'categories' => 0,
            'items' => 0
        ];
        
        if (!$this->caching || !$this->connected) {
            return $stats;
        }
        
        try {
            // Get categories
            $tagsArray = $this->memcache->get($this->prefix . $this->tagsKey);
            $stats['categories'] = is_array($tagsArray) ? count($tagsArray) : 0;
            
            // Get all stats
            $serverStats = $this->memcache->getExtendedStats();
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
        
        if (!$this->caching || !$this->connected) {
            return $category;
        }
        
        try {
            $tagsKey = ($prefix ? $prefix : $this->prefix) . $this->tagsKey;
            $entry = $this->memcache->get($tagsKey);
            
            if (!is_array($entry)) {
                $entry = [];
            }
            
            // If we're resetting or don't have the category yet
            if ($reset || !isset($entry[$category])) {
                $entry[$category] = uniqid(substr(md5($category), 0, 3));
                
                $this->memcache->set($tagsKey, $entry, false, 0);
            }
            
            return $entry[$category];
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return $category;
        }
    }
}
