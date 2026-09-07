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
                /*
                 * The **shared** connection for this endpoint, from the **pooled**
                 * manager. Both halves matter and both were wrong.
                 *
                 * `newConnection()` is documented as being for a blocking `SUBSCRIBE`,
                 * which monopolises its socket; a cache only gets and sets. And
                 * `new ConnectionManager(...)` builds a manager with a pool of its own,
                 * so even `connection()` on it would have opened a second socket.
                 *
                 * `Cache::getInstance()` keeps one instance per category — rightly, so
                 * that `cache:clear --category=views` matches something — and each one
                 * came here. In a request that is invisible, because the process exits.
                 * In a daemon it is one socket per category, for the life of the worker:
                 * 1,016 open connections in five minutes on the installation that
                 * reported it, against a `LimitNOFILE` of 1024.
                 *
                 * No `__destruct` closing this, deliberately: the connection is shared
                 * now, so an adapter going out of scope must not take it from the
                 * adapters still using it.
                 */
                $this->redis = \Pramnos\Redis\ConnectionManager::forConfig([
                    'host'     => $this->host,
                    'port'     => $this->port,
                    'database' => $this->database,
                    'password' => $this->password,
                ])->connection();
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
            // A Redis instance is shared with everything else the application
            // keeps there. A key that is not one of ours is a miss, not an
            // error: unserialize() raises a *warning* rather than throwing, so
            // the catch below never saw it and `$entry['data']` then ran on
            // false.
            if (!\Pramnos\General\Helpers::checkUnserialize($entry)) {
                return false;
            }
            $entry = unserialize($entry);
            if (!is_array($entry) || !array_key_exists('data', $entry)) {
                return false;
            }
            
            // Check for timeout
            if (isset($entry['time']) && $entry['time'] > 0 && $timeout > 0) {
                if (($entry['time'] + $timeout) < time()) {
                    $this->redis->del($key);
                    return false;
                }
            }
            
            return $entry['data'];
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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

            $this->indexInCategory($key, (int) $timeout);

            return true;
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        }
    }

    /**
     * Every key matching a pattern, without blocking the server.
     *
     * **`KEYS` is O(the whole database) and single-threaded**: redis serves nothing else
     * while it runs, so a diagnostic screen on a large instance stalls production for
     * everything sharing it. `SCAN` walks the same keyspace in bounded pieces and lets other
     * clients in between them.
     *
     * It is not free and it is not meant to be: this is for the screens that genuinely mean
     * *everything* — a listing with no category, a total — and it is still proportional to
     * the database. `$limit` stops early where the caller only wants a page.
     *
     * The trade `SCAN` makes for that: a key added or removed mid-walk may or may not appear,
     * and a key may appear twice. For a dashboard that is the right trade; for anything that
     * must be exact, ask the category index instead.
     *
     * @param  string $pattern
     * @param  int    $limit 0 for everything
     * @return array<int, string>
     */
    /**
     * Slowest tolerable keyspace sweep before it is worth a log line, in seconds.
     */
    protected const SWEEP_REPORT_SECONDS = 0.25;

    /**
     * Say when a keyspace sweep was expensive, because nothing else will.
     *
     * **`SCAN … MATCH` filters what is returned, not what is traversed**: every call walks
     * the whole keyspace, so a sweep costs what the database is large rather than what the
     * pattern matches. That is fine for the one thing this is still used for — an explicit
     * `cache:clear` with no category — and it was ruinous when it ran twice per
     * `Model::save()`.
     *
     * The installation that found that spent four hours on four other explanations, all of
     * which fitted the evidence, because the only visible symptom was wall-clock time in a
     * handler that was using 1.6% of a core. Their own note is the reason this method
     * exists: *"a slow-operation log line above some threshold, or a counter, would have
     * turned four hours into ten minutes."*
     *
     * Above the threshold, not on every sweep: a line nobody reads is the same as no line,
     * and a routine deploy-time clear on a large cache is meant to be slow.
     */
    protected function reportSweep(string $pattern, int $trips, int $deleted, float $seconds): void
    {
        if ($seconds < static::SWEEP_REPORT_SECONDS) {
            return;
        }

        \Pramnos\Logs\Logger::log(
            sprintf(
                'Cache sweep of "%s" walked the keyspace in %d round trips and removed %d '
                . 'key(s) in %.3fs. SCAN filters what it returns, not what it traverses, so '
                . 'this costs the size of the whole database. If it is happening on a write '
                . 'path rather than from cache:clear, that is the thing to fix.',
                $pattern,
                $trips,
                $deleted,
                $seconds
            ),
            'cache'
        );
    }

    /**
     * Record this key as a member of its category, so the category can later be
     * cleared without asking Redis to look for it.
     *
     * **Why this exists.** `clear($category)` used to delete by pattern, and
     * `SCAN … MATCH` is not the narrow operation it reads as: **`MATCH` filters
     * what is returned, not what is traversed.** Every call walks the whole
     * keyspace — every key of every application sharing the database — so
     * clearing one category costs exactly what clearing all of them costs.
     * Measured at **268 ms** against a database that was not even large, with
     * the file cache empty so it was not a directory walk.
     *
     * `Model` clears on every write — once per save, twice per delete — so that
     * was the price of a save in production, and it grew with the size of the
     * database rather than with the size of the category.
     *
     * With a set per category the flush is `SMEMBERS` plus `DEL`: the size of
     * the category. See {@see clear()} for how an installation that predates
     * this crosses over safely.
     *
     * **The set's expiry is pushed forward on every save**, to one hour past the
     * newest entry's own TTL. That is what stops it growing without bound in a
     * category that is written constantly and never cleared — dead members would
     * otherwise accumulate for ever. Because each save extends it, the set
     * always outlives every member; when the writing stops, the last member
     * expires before the set does. An entry saved with no expiry makes the set
     * permanent too, since there is then a member that will never go away on its
     * own.
     *
     * A key removed by {@see delete()} or expired by its own TTL stays in the
     * set as a dead member until the next clear — `DEL` on a key that is not
     * there costs nothing, so the only price is a little memory and a slightly
     * larger `SMEMBERS`, and the expiry above bounds it. Removing each one at
     * delete time would add a round trip to every delete to save that.
     *
     * @param string $key     The full, prefixed key that was just written.
     * @param int    $timeout The entry's TTL in seconds; 0 or less means never.
     */
    protected function indexInCategory($key, $timeout)
    {
        if ($this->category === '') {
            return;
        }

        $index = $this->categoryIndexKey($this->category);

        $this->redis->sAdd($index, $key);

        // And the category's *name*, in the one set that lists them. See
        // categoryNamesKey() for why a set per category was not enough.
        $this->redis->sAdd($this->categoryNamesKey(), $this->sanitizeCategory($this->category));

        if ($timeout > 0) {
            $this->redis->expire($index, $timeout + 3600);
        } else {
            $this->redis->persist($index);
        }

        /*
         * **No marker.** There used to be one per category here — `catindexed:<name>` — set
         * on every save so that `clear()` could tell "indexed" from "never indexed" and skip
         * a pattern sweep. It was written for a category with no members too, because
         * `Model::save()` invalidates a *key* as though it were a category, and it had no
         * TTL. That is how one installation reached 288,706 redis keys of which 95% never
         * expired, each one making the sweeps it was meant to avoid slower.
         *
         * `clear()` reads this set and nothing else now, so there is nothing left for a
         * marker to answer.
         */
    }

    /**
     * The set holding one category's keys.
     *
     * `:` as the separator is deliberate: category names are sanitised down to
     * `\w` and `-`, so a colon can never appear in one. An entry key is
     * `prefix + category + '_' + hash`, which therefore cannot collide with
     * this no matter what a category is called — including a category named
     * `catindex_something`.
     *
     * @param string $category
     * @return string
     */
    protected function categoryIndexKey($category)
    {
        return $this->prefix . 'catindex:' . $this->sanitizeCategory($category);
    }

    /**
     * The one set that names every category this installation has written to.
     *
     * **Why this exists at all**, since a set per category already does: enumerating those
     * sets meant asking redis which keys match `catindex:*`, and that is a keyspace walk —
     * `KEYS`, which *blocks the server* for its duration, on every cache dashboard load and
     * every `getStats()`. The per-category index answers *what is in this category*; nothing
     * answered *which categories are there* without scanning past everything else.
     *
     * Bounded by the schema rather than by the data, which is the difference from the
     * `catindexed:` markers this replaces the last use of: one member per category name — a
     * table, a screen, `settings` — not one per cached entity. And pruned, so a category
     * whose entries have all gone stops being named.
     *
     * @return string
     */
    protected function categoryNamesKey()
    {
        return $this->prefix . 'catnames';
    }

    /**
     * The marker that says a category's set is authoritative.
     *
     * @param string $category
     * @return string
     */
    protected function categoryMarkerKey($category)
    {
        return $this->prefix . 'catindexed:' . $this->sanitizeCategory($category);
    }

    /**
     * Remove the `catindexed:` keys an older build left behind.
     *
     * They were written one per category-clear, with no TTL, and one installation reached
     * 275,000 of them — 95% of its keyspace — because `Model::save()` clears a *key* as
     * though it were a category and each of those wrote a marker for a category that never
     * had a member. Nothing reads them now.
     *
     * `cache:clear` with no category removes them along with everything else, which is the
     * ordinary upgrade step. This exists for an installation that wants them gone **without**
     * throwing away a warm cache — the sweep is one deliberate keyspace walk instead of two
     * per save.
     *
     * @return bool
     */
    public function purgeCategoryMarkers(): bool
    {
        if (!$this->caching || !$this->connected) {
            return false;
        }

        return $this->deleteByPattern($this->prefix . 'catindexed:*');
    }

    /**
     * Category names as they appear inside a key.
     *
     * Kept in one place because {@see clear()} and the index must agree
     * exactly; they were two copies of this expression, and a category whose
     * name they sanitised differently would be indexed under one name and
     * cleared under another — which looks like a cache that ignores
     * invalidation.
     *
     * @param string $category
     * @return string
     */
    protected function sanitizeCategory($category)
    {
        return preg_replace(
            array('/\s+/', '/[^\w\-]/'),
            array('_', ''),
            (string) $category
        );
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        }
    }

    /**
     * Atomically increment a raw integer counter and return the new value.
     *
     * Uses Redis INCRBY, so the key is stored as a bare integer (NOT the
     * serialized {data,time} envelope that {@see save()} writes) — this is a
     * distinct atomic-counter operation, not a cache value. When $ttl is a
     * positive number of seconds the expiry is applied by the call that creates
     * the key; pass $slidingExpiry to refresh it on every call instead.
     * Read the current value with {@see counter()}; never with {@see load()}.
     *
     * @param string   $key The raw (already-prefixed) counter key.
     * @param int      $by  Amount to add (default 1).
     * @param int|null $ttl TTL in seconds; null/<=0 leaves expiry untouched.
     * @param bool     $slidingExpiry When true the TTL is refreshed on every
     *                 call. Defaults to false — the expiry is applied only by
     *                 the call that creates the key — because a sliding expiry
     *                 never lets a busy key die: sustained traffic refreshes it
     *                 on every hit, so a rate-limit counter climbs for ever and
     *                 locks the client out permanently. FlatCache asks for the
     *                 sliding behaviour explicitly, since that is what its own
     *                 documented contract promises.
     * @return int|false New value, or false when caching/connection is unavailable.
     */
    /**
     * Redis counts atomically.
     */
    public function supportsAtomicCounter(): bool
    {
        // INCRBY reads and writes in one server-side operation.
        return true;
    }

    public function increment($key, $by = 1, $ttl = null, $slidingExpiry = false)
    {
        if (!$this->caching || !$this->connected) {
            return false;
        }

        try {
            $new = $this->redis->incrBy($key, (int) $by);
            if ($ttl !== null && (int) $ttl > 0) {
                // A sliding expiry never lets a busy key die: sustained traffic
                // refreshes it on every hit, so a rate-limit counter would climb
                // for ever and lock the client out permanently. A fixed window
                // sets the expiry once, on the call that created the key.
                if ($slidingExpiry || (int) $new === (int) $by) {
                    $this->redis->expire($key, (int) $ttl);
                }
            }
            return (int) $new;
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }
    }

    /**
     * Redis can enumerate, through SCAN.
     */
    public function supportsKeyEnumeration(): bool
    {
        return true;
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
    protected function scanKeys(string $pattern, int $limit = 0): array
    {
        $keys   = [];
        $cursor = null;

        try {
            do {
                $found = $this->redis->scan($cursor, $pattern, self::SCAN_COUNT);

                if ($found === false) {
                    break;
                }

                foreach ($found as $key) {
                    $keys[] = (string) $key;

                    if ($limit > 0 && count($keys) >= $limit) {
                        return $keys;
                    }
                }
            } while ($cursor !== 0 && $cursor !== null);
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }

        return $keys;
    }

    protected function deleteByPattern(string $pattern): bool
    {
        try {
            $batch   = [];
            $cursor  = null;
            $started = microtime(true);
            $trips   = 0;
            $deleted = 0;

            do {
                $trips++;
                $found = $this->redis->scan($cursor, $pattern, self::SCAN_COUNT);
                if ($found === false) {
                    break;
                }
                foreach ($found as $key) {
                    $batch[] = (string) $key;
                    $deleted++;
                    if (count($batch) >= self::DELETE_BATCH) {
                        $this->redis->del($batch);
                        $batch = [];
                    }
                }
            } while ($cursor !== 0 && $cursor !== null);

            if ($batch !== []) {
                $this->redis->del($batch);
            }

            $this->reportSweep($pattern, $trips, $deleted, microtime(true) - $started);

            return true;
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
                    \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                    return false;
                }
            }

            return $this->deleteByPattern($this->prefix . '*');
        } else {
            try {
                return $this->clearCategory($category);
            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                return false;
            }
        }
    }

    /**
     * Clear one category, by reading its own index rather than searching for it.
     *
     * Costs the size of the category. The pattern scan it replaces cost the size
     * of the **database**, because `SCAN … MATCH` filters what it returns and
     * not what it walks — see {@see indexInCategory()} for the measurement and
     * why every model write was paying it.
     *
     * ## Crossing over from an installation that has no indexes
     *
     * The index only knows about keys written since it existed, so on an
     * existing installation the first clear must still find the keys written
     * before the upgrade. Deciding that by "is the set empty?" would be wrong
     * twice over: an empty set is also what a category with nothing cached
     * looks like, so every clear of an idle category would pay the 268 ms again
     * — and that is precisely the case a test suite hits over and over.
     *
     * So the decision is a **marker**, not the set. No marker means nothing has
     * ever indexed this category here: scan once, the old way, catching every
     * pre-upgrade key including the ones saved with no expiry that would
     * otherwise sit there for ever. Then write the marker, and no category is
     * ever scanned twice.
     *
     * **There is no pattern sweep here any more, and no marker.** Both existed for a
     * crossover — the keys an older build wrote before the index existed can only be found
     * by looking — and the arrangement cost more than the thing it was helping.
     *
     * `Model::save()` calls `cacheflush($specificKey)`: a *key* where a *category* is
     * expected. No index or marker ever existed for that name, so every save took the
     * crossover branch, walked the whole keyspace with `SCAN`, and then wrote **a permanent
     * marker for a category with no members**. One key per entity ever saved, with no TTL,
     * for ever.
     *
     * Measured on the installation that reported it: **288,706 keys, only 13,792 with a
     * TTL** — so 95% of that keyspace was markers left by sweeps that found nothing. Each
     * sweep was about 1,443 round trips at `COUNT 200`, two per save, and the sweeps got
     * slower as the markers accumulated. The structure that existed to make category
     * invalidation possible was the structure that made it unaffordable.
     *
     * What it looked like: queue workers in `do_poll` in 148 of 150 samples at **1.6% CPU**,
     * twenty redis clients concurrently in `SCAN`, 0.932 s of wall clock per task — and
     * throughput *falling* as workers were added, three moving 11 tasks a second where
     * eighteen moved 8.6. Four other explanations fitted the evidence first, because the
     * queue's `execution_time` measures wall clock around the handler and every wrong
     * hypothesis explained a large number.
     *
     * So the crossover is gone rather than made cheaper: **an installation upgrading past
     * this runs `cache:clear` once**, which is a single deliberate sweep by an operator and
     * is on most deploy scripts already. Entries an older build wrote otherwise leave on
     * their own TTL, and the markers themselves go with that one clear.
     *
     * The index sets are bounded and always were: each save pushes the set's expiry to an
     * hour past the newest member's, so it outlives its members and dies when writing stops.
     * The markers were the unbounded half.
     *
     * @param string $category
     * @return bool
     */
    protected function clearCategory($category)
    {
        $index   = $this->categoryIndexKey($category);
        $members = $this->redis->sMembers($index);

        if (!is_array($members)) {
            $members = array();
        }

        foreach (array_chunk($members, self::DELETE_BATCH) as $batch) {
            $this->redis->del($batch);
        }

        $this->redis->del($index);

        /*
         * And the name, because an emptied category is not a category.
         *
         * Without this the names set only grows — which is how the `catindexed:` markers it
         * replaces became 95% of a keyspace. A name left behind is not *wrong*: reading it
         * finds a missing index and answers empty. It is just a listing that reports
         * categories nobody has, and a set that never shrinks.
         */
        $this->redis->sRem($this->categoryNamesKey(), $this->sanitizeCategory($category));

        return true;
    }
    
    /**
     * @inheritDoc
     */
    public function getCategories($prefix = '')
    {
        if (!$this->caching || !$this->connected) {
            return [];
        }

        /**
         * Read from the category index this adapter actually maintains.
         *
         * This used to read a `memcachedtags` JSON blob — and **nothing has ever
         * written that key**. Three adapters read it and no code anywhere sets
         * it, so `getCategories()` always answered `[]` and `getStats()` always
         * reported zero categories. On the cache dashboard that is a namespace
         * list with nothing in it and a "Categories" tile reading 0 next to an
         * item count of thirteen: not an empty cache, a listing that could not
         * see one.
         *
         * The real index is right here: {@see indexInCategory()} adds every key it writes to
         * a `catindex:<category>` set, and {@see clearCategory()} reads that set and nothing
         * else. Enumerating those sets is therefore the same source of truth invalidation
         * uses, rather than a second one that could disagree.
         *
         * This read the `catindexed:<category>` **markers** until those were removed —
         * they were written per category-clear, never expired, and one installation reached
         * 275,000 of them. A category with entries has an index set; that is the thing to
         * count, and unlike a marker it goes away when the entries do, so the listing stops
         * reporting categories that no longer hold anything.
         */
        try {
            $base = ($prefix !== '' ? $prefix : $this->prefix);

            /*
             * `SMEMBERS` of one set, not `KEYS catindex:*`.
             *
             * `KEYS` is O(the whole database) **and it blocks the server** for its duration —
             * so a cache dashboard and every `getStats()` stalled redis for everything else
             * on the instance, in proportion to how much was in it. That is the same mistake
             * as the invalidation sweep, one screen further out.
             *
             * A category with entries is in this set; one whose entries have gone is removed
             * by {@see clearCategory()}. A name that outlives its index — an entry that
             * expired rather than being cleared — is filtered out below rather than reported,
             * so the listing stops naming categories nobody has.
             */
            $names = $this->redis->sMembers($base . 'catnames');

            if (!is_array($names)) {
                return [];
            }

            $categories = [];

            foreach ($names as $name) {
                $name = (string) $name;

                if ($name === '') {
                    continue;
                }

                if ($this->redis->exists($base . 'catindex:' . $name)) {
                    $categories[] = $name;

                    continue;
                }

                // Its last entry expired on its own. Nothing cleared it, so nothing removed
                // the name; do it here rather than leave the set growing.
                $this->redis->sRem($base . 'catnames', $name);
            }

            sort($categories);

            return $categories;
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            // Counted from the same index the listing reads — see
            // getCategories(). It used to count a key nothing writes, so this
            // tile read 0 beside an item count in the dozens.
            $stats['categories'] = count($this->getCategories());

            /**
             * The entries this cache wrote — not every key in the database.
             *
             * This was `dbSize()`, which answers a different question: it counts
             * the whole Redis database, so the number included sessions, queue
             * payloads, whatever another application keeps in the same instance,
             * and this adapter's own bookkeeping (a `catindex:<category>` set and
             * a `catindexed:<category>` marker each). On a shared instance the
             * "Total items" tile was a number about Redis rather than about the
             * cache, and on a dedicated one it was still inflated by twice the
             * category count. It also subtracted one for the `memcachedtags` key,
             * which nothing writes.
             *
             * It counted them with `keys($prefix . '*')`, which is **O(the whole
             * database) and blocks the server** — so opening a cache dashboard
             * stalled redis for everything sharing the instance, in proportion to
             * how much was in it. Adding a key to exclude from that count, which
             * is what a bookkeeping key needs, made it slightly wronger each time.
             *
             * Summed from the category indexes instead: `sCard` per category, no
             * keyspace walk, and the bookkeeping keys are not in the sets so there
             * is nothing to exclude.
             *
             * **An entry written with no category is not counted.** It is in no
             * index, so nothing can find it without a walk — and the honest choice
             * is a number that is slightly low over a screen that can stall
             * production. Every framework caller supplies a category.
             */
            $stats['items'] = 0;

            foreach ($this->getCategories() as $category) {
                $stats['items'] += (int) $this->redis->sCard($this->categoryIndexKey($category));
            }
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
            /*
             * One category comes from its index; everything comes from a `KEYS` sweep.
             *
             * The pattern this used for a category — `<prefix><category>_*` — never matched
             * anything. `Cache::_generateCacheName()` builds `<prefix>_<category>_<id>…`, so
             * the underscore after the prefix was missing and the dashboard reported every
             * category as empty. Reading the index is both correct and cheap, and it is the
             * same set `clear()` uses, so a listing and a flush can no longer disagree about
             * what is in a category.
             *
             * `KEYS` for the no-category case is left alone: it is a deliberate "show me
             * everything" from a screen somebody is looking at, not something on a write
             * path.
             */
            if ($category !== '') {
                $keys = $this->redis->sMembers($this->categoryIndexKey($category));
                $keys = is_array($keys) ? $keys : array();
            } else {
                // `SCAN`, not `KEYS`: this is a diagnostic screen and it must not be able
                // to stall redis for everything else on the instance. {@see scanKeys()}
                $keys = $this->scanKeys($this->prefix . '*');
            }
            
            // Filter out the tags key
            $tagsKey = $this->prefix . $this->tagsKey;
            $keys = array_filter($keys, function($key) use ($tagsKey) {
                return $key !== $tagsKey;
            });
            
            // Limit the results
            $keys = array_slice($keys, 0, $limit);
            
            foreach ($keys as $key) {
                try {
                    $raw = $this->redis->get($key);
                    if ($raw === false || $raw === null || $raw === '') {
                        continue;
                    }

                    $item = [
                        'key'          => str_replace($this->prefix, '', $key),
                        // Measured from the value already fetched: this used to
                        // call get() a second time purely to take its length.
                        'size'         => strlen((string) $raw),
                        'created_time' => 'Unknown',
                        'ttl'          => $this->redis->ttl($key),
                        'type'         => 'raw',
                    ];

                    // Anything else in the instance — a session, a queue payload,
                    // a key another library owns — is listed as a raw value rather
                    // than skipped or, as before, run through unserialize() until
                    // it printed "Error at offset 0" into the page that was
                    // supposed to be showing the cache.
                    if (\Pramnos\General\Helpers::checkUnserialize($raw)) {
                        $entry = unserialize($raw);
                        if (is_array($entry)) {
                            $item['created_time'] = isset($entry['time'])
                                ? date('Y-m-d H:i:s', (int) $entry['time'])
                                : 'Unknown';
                            $item['type'] = gettype($entry['data'] ?? null);
                        }
                    }

                    $items[] = $item;
                } catch (\Throwable $e) {
                    // Skip problematic keys
                    continue;
                }
            }
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
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
