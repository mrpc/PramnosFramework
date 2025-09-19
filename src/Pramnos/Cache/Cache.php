<?php

namespace Pramnos\Cache;

/**
 * Simple cache methods to be used in all applications created by PramnosFramework
 * @static
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @subpackage  Cache
 * @copyright   (C) 2024 Yannis - Pastis Glaros
 */
class Cache extends \Pramnos\Framework\Base
{

    /**
     * Prefix for all cache files. By default this will be the memcached cache
     * @var string
     */
    public $prefix='';
    /**
     * A second prefix for cache files. Used to control many cache files at once
     * @var strimg
     */
    public $category='';
    /**
     * Type of cache. This will be used as the cache file extension
     * @var string
     */
    public $extension = 'cache'; // Renamed from 'type' to 'extension'
    /**
     * cache lifetime (in seconds), if set to 0, the cache is valid forever.
     * @var integer
     */
    public $timeout=3600;
    /**
     * Cache Save Time
     * @var int
     */
    public $time = 0;
    /**
     * Cache file contents
     * @var string
     */
    public $data='';
    /**
     * enable / disable caching
     * @var boolean
     */
    public $caching=true;
    /**
     * Extra data to be saved inside the cache file
     * @var mixed
     */
    public $extradata=NULL;


    /**
     * Hostname for the cache server
     * @var string
     */
    public $hostname = 'localhost';

    /**
     * Port for the cache server
     * @var int
     */
    public $port = 11211;

    /**
     * Database index (for Redis or similar systems)
     * @var int
     */
    public $database = 0;

    /**
     * Password for the cache server (if required)
     * @var string|null
     */
    public $password = null;

    protected static $_connections = [];
    protected static $_connected = [];

    protected $_id='';
    protected $_cachename='';

    /**
     * A key that saves all memecached tags
     * @var string
     */
    protected $tagsKey = 'memcachedtags';


    /**
     * Cache method: file, memcache, memcached, redis
     * If something is wrong, it defaults to file
     * @var string
     */
    public $method='memcached';


    /**
     * The adapter instance
     * @var AdapterInterface
     */
    protected $adapter = null;

    /**
     * Unified connection status for the cache server
     * @var boolean
     */
    protected static $connected = false;

    /**
     * Class constructor. 
     * @param string $category
     * @param string $extension
     * @param string $method file, memcache, memcached, redis
     */
    public function __construct(
        $category = NULL, $extension = NULL, $method = '', $settings = array()
    ) {


        $initialSettings = (array) \Pramnos\Application\Settings::getSetting('cache');
        if (is_array($initialSettings)) {
            $settings = array_merge($initialSettings, $settings);
        }
        
        foreach ($settings as $setting => $value) {
            if (property_exists($this, $setting)) {
                $this->$setting = $value;
            }
        }
        
        if ($method != '') {
            $this->method = $method;
        }
        
        if ($this->method == '') {
            $this->method = 'memcached';
        }


        if ($category !== NULL) {
            $this->category = $category;
        }
        if ($extension !== NULL) {
            $this->extension = $extension; // Updated to use 'extension'
        }
        if ($this->prefix == '') {
            $prefix = \Pramnos\Application\Settings::getSetting('database')->prefix;
            if ($prefix != '') {
                $this->prefix = $prefix;
            }
        }

        // Create the appropriate adapter
        $this->initializeAdapter($this->method);

        parent::__construct();
    }

    /**
     * Initialize the cache adapter based on the selected method
     * @param string $method cache method
     */
    protected function initializeAdapter($method)
    {
        $methodKey = strtolower($method);

        switch ($methodKey) {
            case 'redis':
                if (class_exists('\Redis')) {
                    $this->adapter = new Adapter\RedisAdapter(
                        $this->hostname,
                        $this->port,
                        $this->database,
                        $this->password,
                        $this->prefix
                    );
                    
                    if (!$this->adapter->connect()) {
                        self::$_connected[$methodKey] = false;
                        $this->initializeAdapter('memcached');
                    } else {
                        self::$_connected[$methodKey] = true;
                    }
                } else {
                    $this->initializeAdapter('memcached');
                }
                break;

            case 'memcached':
                if (class_exists('\Memcached')) {
                    $this->adapter = new Adapter\MemcachedAdapter(
                        $this->hostname,
                        $this->port,
                        \Pramnos\Application\Settings::getSetting('database')->database,
                        $this->prefix
                    );
                    
                    if (!$this->adapter->connect()) {
                        self::$_connected[$methodKey] = false;
                        $this->initializeAdapter('memcache');
                    } else {
                        self::$_connected[$methodKey] = true;
                    }
                } else {
                    $this->initializeAdapter('memcache');
                }
                break;

            case 'memcache':
                if (class_exists('\Memcache')) {
                    $this->adapter = new Adapter\MemcacheAdapter(
                        $this->hostname,
                        $this->port,
                        $this->prefix
                    );
                    
                    if (!$this->adapter->connect()) {
                        self::$_connected[$methodKey] = false;
                        $this->initializeAdapter('file');
                    } else {
                        self::$_connected[$methodKey] = true;
                    }
                } else {
                    $this->initializeAdapter('file');
                }
                break;

            case 'file':
            default:
                $this->adapter = new Adapter\FileAdapter(
                    $this->prefix,
                    $this->extension // Pass 'extension' to FileAdapter
                );

                if (!$this->adapter->connect()) {
                    self::$_connected[$methodKey] = false;
                    $this->caching = false;
                } else {
                    self::$_connected[$methodKey] = true;
                }
                break;
        }
    }

    /**
     * Get the current adapter
     * @return AdapterInterface
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * Returns a unique hash name for the category
     * @param string $category
     * @return string
     */
    public function getCategory($category)
    {
        $methodKey = strtolower($this->method);

        if ($category == '') {
            return '';
        }
        if ($this->method == 'file') {
            return $category;
        }
        
        if (!isset(self::$_connected[$methodKey]) || !self::$_connected[$methodKey]) {
            return $category;
        }

        return self::$_connected[$methodKey]->categoryHash($category, $this->prefix, true);
    }

    

   

    /**
     * Connect to the cache server
     * @return boolean
     */
    protected function _connect()
    {
        $methodKey = strtolower($this->method);

        if (!class_exists($this->method)) {
            return false;
        }

        if (!isset(self::$_connections[$methodKey])) {
            $class = '\\' . ucfirst($this->method);
            self::$_connections[$methodKey] = new $class();
            try {
                self::$_connected[$methodKey] = self::$_connections[$methodKey]->connect(
                    $this->hostname, 
                    $this->port
                );
                
                if ($this->password && method_exists(self::$_connections[$methodKey], 'auth')) {
                    if (!self::$_connections[$methodKey]->auth($this->password)) {
                        self::$_connected[$methodKey] = false;
                    }
                }
                
                if (self::$_connected[$methodKey] && $this->database > 0 && method_exists(self::$_connections[$methodKey], 'select')) {
                    self::$_connections[$methodKey]->select($this->database);
                }
            } catch (\Exception $exc) {
                \pramnos\Logs\Logger::logError($exc->getMessage(), $exc);
                $this->method = 'file';
                self::$_connected[$methodKey] = false;
            }
        }
        
        return self::$_connected[$methodKey];
    }

    





    /**
     * Factory Method
     * @param string $category
     * @param string $extension
     * @param string $method file, memcache, memcached, redis
     * @return \Cache
     */
    public static function getInstance($category=NULL, $extension=NULL,
        $method='memcached', $settings = array())
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new Cache($category, $extension, $method, $settings);
        }
        return $instance;
    }

    /**
     * Load and return data from cache
     * @param string $id Cache name
     * @param string $category Cache category to override object property
     * @param integer $timeout Timeout in seconds. Overrides object property
     * @return boolean|string Returns data or False if cache doesn't exist
     */
    public function load($id, $category = NULL, $timeout = NULL)
    {
        if ($this->caching == false) {
            return false;
        }
        if ($timeout !== NULL) {
            $this->timeout = $timeout;
        }
        if ($category !== NULL) {
            $this->category = $category;
        }
        $this->_id = $id;
        $this->_cachename = $this->_generateCacheName($id);

        return $this->adapter ? $this->adapter->load($this->_cachename, $this->timeout) : false;
    }

    /**
     * Remove a cache object
     * @param string $id
     * @return boolean
     */
    public function delete($id)
    {
        if ($this->caching == false) {
            return false;
        }

        $this->_id = $id;
        $this->_cachename = $this->_generateCacheName($id);

        $result = $this->adapter ? $this->adapter->delete($this->_cachename) : false;

        return $result;
    }

    /**
     * Clear a cache category or the whole cache under prefix
     * @param string $category
     * @return boolean
     */
    public function clear($category = '')
    {
        return $this->adapter ? $this->adapter->clear($category) : false;
    }

    /**
     * Save data to the cache
     * @param string $data Data to be written
     * @param string $id Optional, override the object property
     * @return boolean Return true if data is written
     */
    public function save($data = '', $id = NULL)
    {
        if ($data != '') {
            $this->data = $data;
        }
        if ($this->caching == false) {
            return false;
        }
        $this->time = time();

        $this->_id = $id ?? $this->_id;
        $this->_cachename = $this->_generateCacheName($this->_id);

        return $this->adapter ? $this->adapter->save($this->_cachename, $this->data, $this->timeout) : false;
    }

    

    /**
     * Generate the file name to be saved
     * @param string $id
     * @return string
     */
    protected function _generateCacheName($id)
    {
        $prefix = '';
        $category = '';
        if ($this->prefix != ''){
            $this->prefix = str_replace("_", "", $this->prefix);
            $prefix = $this->_sanitizeName($this->prefix . '_');
        }
        if ($this->category != ''){
            $category = $this->_sanitizeName($this->category);
        }
        if ($this->prefix != '') {
            $this->_cachename = $prefix
                . $this->getCategory($category) . '_'
                . $id
                . '.'
                . $this->prefix
                . '.'
                . $this->_sanitizeName($this->extension); // Updated to use 'extension'
        } else {
            $this->_cachename = $prefix
                . $this->getCategory($category) . '_'
                . $id
                . '.'
                . $this->_sanitizeName($this->extension); // Updated to use 'extension'
        }

        return $this->_cachename;
    }

    /**
     * Cleans up a string to be used in cache filename
     * @param string $name
     * @return string
     */
    protected function _sanitizeName($name)
    {
        return preg_replace(
            array('/\s/', '/\.[\.]+/', '/[^\w_\.\-]/'),
            array('_', '.', ''), $name
        );
    }

    /**
     * Returns the Redis connection object if using Redis adapter
     * @return \Redis|null
     */
    public function getRedis()
    {
        if ($this->adapter instanceof \Pramnos\Cache\Adapter\RedisAdapter) {
            return $this->adapter->getConnection();
        }
        return null;
    }
    
    /**
     * Tests the current cache connection by writing and reading data
     * @param string $testKey Key to use for testing
     * @return boolean True on success, false on failure
     */
    public function testConnection()
    {
        if (!$this->caching || $this->adapter === null) {
            return false;
        }
        
        return $this->adapter->test();
    }
    
    /**
     * Returns statistics about the cache
     * @return array Array containing statistics about the cache
     */
    public function getStats()
    {
        if (!$this->caching || $this->adapter === null) {
            return [
                'method' => $this->method,
                'categories' => 0,
                'items' => 0
            ];
        }
        
        return $this->adapter->getStats();
    }
    
    /**
     * Get all cache items with metadata
     * @param string $category Optional category to filter by
     * @param int $limit Optional limit for number of items returned
     * @return array Array of cache items with metadata
     */
    public function getAllItems($category = '', $limit = 100)
    {
        if (!$this->caching || $this->adapter === null) {
            return [];
        }
        
        return $this->adapter->getAllItems($category, $limit);
    }
    
    /**
     * Get all cache categories
     * @param string $prefix Optional prefix to filter categories
     * @return array List of categories
     */
    public function getCategories($prefix = '')
    {
        if (!$this->caching || $this->adapter === null) {
            return [];
        }
        
        return $this->adapter->getCategories($prefix);
    }
}