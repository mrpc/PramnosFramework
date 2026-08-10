<?php

namespace Pramnos\Cache\Adapter;

/**
 * Redis cache adapter
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license    MIT
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
            try {
                // Route connection creation through the central manager (one place
                // for connect/auth/select); it throws on failure, which we map to
                // the connected flag exactly as before.
                $this->redis = (new \Pramnos\Redis\ConnectionManager([
                    'host'     => $this->host,
                    'port'     => $this->port,
                    'database' => $this->database,
                    'password' => $this->password,
                ]))->newConnection();
                $this->connected = true;
            }
            catch (\Throwable $exc) {
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
     * Atomically increment a raw integer counter and return the new value.
     *
     * Uses Redis INCRBY, so the key is stored as a bare integer (NOT the
     * serialized {data,time} envelope that {@see save()} writes) — this is a
     * distinct atomic-counter operation, not a cache value. When $ttl is a
     * positive number of seconds the key's expiry is (re)set on every call,
     * giving a sliding window (matching typical rate-limit/attempt counters).
     * Read the current value with {@see counter()}; never with {@see load()}.
     *
     * @param string   $key The raw (already-prefixed) counter key.
     * @param int      $by  Amount to add (default 1).
     * @param int|null $ttl Sliding TTL in seconds; null/<=0 leaves expiry untouched.
     * @return int|false New value, or false when caching/connection is unavailable.
     */
    public function increment($key, $by = 1, $ttl = null)
    {
        if (!$this->caching || !$this->connected) {
            return false;
        }

        try {
            $new = $this->redis->incrBy($key, (int) $by);
            if ($ttl !== null && (int) $ttl > 0) {
                $this->redis->expire($key, (int) $ttl);
            }
            return (int) $new;
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        }
    }

    /**
     * Atomically decrement a raw integer counter and return the new value.
     * Counterpart to {@see increment()}; uses Redis DECRBY.
     *
     * @param string   $key The raw (already-prefixed) counter key.
     * @param int      $by  Amount to subtract (default 1).
     * @param int|null $ttl Sliding TTL in seconds; null/<=0 leaves expiry untouched.
     * @return int|false New value, or false when caching/connection is unavailable.
     */
    public function decrement($key, $by = 1, $ttl = null)
    {
        if (!$this->caching || !$this->connected) {
            return false;
        }

        try {
            $new = $this->redis->decrBy($key, (int) $by);
            if ($ttl !== null && (int) $ttl > 0) {
                $this->redis->expire($key, (int) $ttl);
            }
            return (int) $new;
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        }
    }

    /**
     * Read a raw integer counter written by {@see increment()}/{@see decrement()}.
     * Returns 0 for a missing key WITHOUT creating it.
     *
     * @param string $key The raw (already-prefixed) counter key.
     * @return int
     */
    public function counter($key)
    {
        if (!$this->caching || !$this->connected) {
            return 0;
        }

        try {
            return (int) $this->redis->get($key);
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return 0;
        }
    }

    /**
     * Atomically set a key to a new value and return the previous one.
     *
     * Implemented with the native Redis GETSET, so concurrent callers cannot both
     * observe the same previous value — the atomic-swap analogue of {@see increment()}.
     * Like the other raw-key atomic operations, the value is stored verbatim (not
     * through the serialized cache envelope), so it round-trips as a plain string.
     *
     * @param  string $key
     * @param  string $value
     * @return string|null The previous value, or null when the key was unset.
     */
    public function swap($key, $value)
    {
        if (!$this->caching || !$this->connected) {
            return null;
        }

        try {
            $prev = $this->redis->getSet($key, (string) $value);
            return $prev === false ? null : (string) $prev;
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return null;
        }
    }

    // ── Structured operations — native Redis (HASH / LIST / SCAN) ───────────────
    //
    // Values are serialize()d per field/element so any PHP value round-trips.

    public function hashSet($key, $field, $value, $ttl = null)
    {
        if (!$this->caching || !$this->connected) {
            return;
        }
        try {
            $this->redis->hSet($key, (string) $field, serialize($value));
            if ($ttl !== null && (int) $ttl > 0) {
                $this->redis->expire($key, (int) $ttl);
            }
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
    }

    public function hashGet($key, $field, $default = null)
    {
        if (!$this->caching || !$this->connected) {
            return $default;
        }
        try {
            $value = $this->redis->hGet($key, (string) $field);
            return $value === false ? $default : unserialize($value);
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return $default;
        }
    }

    public function hashDelete($key, $field)
    {
        if (!$this->caching || !$this->connected) {
            return;
        }
        try {
            $this->redis->hDel($key, (string) $field);
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
    }

    public function hashGetAll($key)
    {
        if (!$this->caching || !$this->connected) {
            return [];
        }
        try {
            $all = $this->redis->hGetAll($key);
            if (!is_array($all)) {
                return [];
            }
            return array_map(static fn ($v) => unserialize($v), $all);
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return [];
        }
    }

    public function listPush($key, $value)
    {
        if (!$this->caching || !$this->connected) {
            return 0;
        }
        try {
            return (int) $this->redis->lPush($key, serialize($value));
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return 0;
        }
    }

    public function listTrim($key, $start, $stop)
    {
        if (!$this->caching || !$this->connected) {
            return;
        }
        try {
            $this->redis->lTrim($key, (int) $start, (int) $stop);
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
    }

    public function listRange($key, $start, $stop)
    {
        if (!$this->caching || !$this->connected) {
            return [];
        }
        try {
            $range = $this->redis->lRange($key, (int) $start, (int) $stop);
            if (!is_array($range)) {
                return [];
            }
            return array_map(static fn ($v) => unserialize($v), $range);
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return [];
        }
    }

    public function expire($key, $ttl)
    {
        if (!$this->caching || !$this->connected) {
            return;
        }
        try {
            $this->redis->expire($key, (int) $ttl);
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
    }

    public function keys($pattern)
    {
        if (!$this->caching || !$this->connected) {
            return [];
        }
        try {
            $found  = [];
            $cursor = null;
            do {
                $batch = $this->redis->scan($cursor, $pattern, 200);
                if ($batch === false) {
                    break;
                }
                foreach ($batch as $key) {
                    $found[] = (string) $key;
                }
            } while ($cursor !== 0 && $cursor !== null);
            return $found;
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return [];
        }
    }

    /**
     * Delete every key matching a pattern, without blocking the server.
     *
     * SCAN walks the keyspace in bounded steps, so a large database stays
     * responsive while this runs; KEYS would hold the server for the whole
     * sweep. Deletion happens in batches for the same reason — one DEL with a
     * hundred thousand arguments is a single very long command.
     *
     * Slower than FLUSHDB, and that is the trade: clearing is not a hot path,
     * and destroying another installation's data is not an acceptable
     * optimisation.
     *
     * @param  string $pattern Redis glob pattern, already prefixed
     * @return bool   True when the sweep completed
     */
    protected function deleteByPattern(string $pattern): bool
    {
        try {
            $batch  = [];
            $cursor = null;

            do {
                $found = $this->redis->scan($cursor, $pattern, self::SCAN_COUNT);
                if ($found === false) {
                    break;
                }
                foreach ($found as $key) {
                    $batch[] = (string) $key;
                    if (count($batch) >= self::DELETE_BATCH) {
                        $this->redis->del($batch);
                        $batch = [];
                    }
                }
            } while ($cursor !== 0 && $cursor !== null);

            if ($batch !== []) {
                $this->redis->del($batch);
            }

            return true;
        } catch (\Exception $ex) {
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        }
    }

    /** Keys asked for per SCAN step — a hint, not a guarantee. */
    private const SCAN_COUNT = 500;

    /** Keys deleted per DEL command. */
    private const DELETE_BATCH = 500;

    /**
     * Flush the whole Redis database, prefix and co-tenants included.
     *
     * This is what `clear()` used to do by accident. It stays available because
     * a single-tenant installation may genuinely want it — but as something
     * asked for by name, never as the default meaning of "clear the cache".
     *
     * @return bool
     */
    public function flushEverything()
    {
        if (!$this->caching || !$this->connected) {
            return false;
        }

        try {
            \Pramnos\Logs\Logger::log(
                'Flushing the entire Redis database, ignoring the key prefix. '
                . 'Any other installation sharing this database loses its cache too.',
                'cache'
            );
            $this->redis->flushDb();
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
            // Every read and write in this adapter is prefixed; clearing must
            // obey the same rule. flushDb() does not — it wipes the whole
            // database, including every co-tenant installation sharing it and
            // any keys written directly by something else. Since `cache:clear`
            // is typically run on each deploy, that turned a routine step into
            // "delete everyone's sessions, rate limiters and settings cache".
            //
            // With no prefix there is nothing to scope to, so flushing IS the
            // correct meaning of "clear everything" — logged, because at that
            // point it really is global.
            if ($this->prefix === '') {
                try {
                    \Pramnos\Logs\Logger::log(
                        'Cache clear with no key prefix: flushing the entire Redis database. '
                        . 'Set a prefix to scope it to this installation.',
                        'cache'
                    );
                    $this->redis->flushDb();
                    return true;
                } catch (\Exception $ex) {
                    \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                    return false;
                }
            }

            return $this->deleteByPattern($this->prefix . '*');
        } else {
            // Clear cache entries for the specific category
            try {
                // Sanitize the category name to match how keys are stored
                $sanitizedCategory = preg_replace(
                    array('/\s+/', '/[^\w\-]/'),
                    array('_', ''),
                    $category
                );
                
                // Find all keys that match this category. keys() below scans;
                // the raw KEYS command walks the whole keyspace in one blocking
                // pass, which stalls every other client on a large database.
                return $this->deleteByPattern(
                    $this->prefix . $sanitizedCategory . '_*'
                );
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
