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
            
            $this->memcached->set($key, $entry, $timeout);
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
            $this->memcached->delete($key);
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
        
        if (!$this->caching || !$this->connected) {
            return $category;
        }
        
        try {
            $tagsKey = ($prefix ? $prefix : $this->prefix) . $this->tagsKey;
            $entry = $this->memcached->get($tagsKey);
            
            if (!is_array($entry)) {
                $entry = [];
            }
            
            // If we're resetting or don't have the category yet
            if ($reset || !isset($entry[$category])) {
                $entry[$category] = uniqid(substr(md5($category), 0, 3));
                
                $this->memcached->set($tagsKey, $entry);
            }
            
            return $entry[$category];
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return $category;
        }
    }
}
