<?php

namespace Pramnos\Cache\Adapter;

/**
 * File-based cache adapter
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license    MIT
 */
class FileAdapter extends AbstractAdapter
{
    /**
     * Cache directory path
     * @var string
     */
    /**
     * How often `clear()` also sweeps the whole tree for expired entries: one
     * call in this many.
     *
     * The sweep is O(everything cached) and `clear()` runs on every model save,
     * so doing it every time made a write cost a full cache inspection. Expired
     * files are never served — `load()` checks the timestamp — so this only
     * governs how promptly disk is reclaimed.
     */
    protected const GC_DIVISOR = 100;

    protected $cacheDir = '';

    

    /**
     * @param string $cacheDir Cache directory path
     * @param string $prefix Prefix for all cache keys
     */
    public function __construct($cacheDir = '', $prefix = '')
    {
        parent::__construct($prefix);

        if ($cacheDir == '' && defined('CACHE_PATH')) {
            $this->cacheDir = CACHE_PATH;
        } else {
            $this->cacheDir = $cacheDir;
        }
    }

    /**
     * Connect to the filesystem
     * @return boolean Success of the connection
     */
    public function connect()
    {
        if ($this->cacheDir == '') {
            return false;
        }

        if (!file_exists($this->cacheDir)) {
            try {
                mkdir($this->cacheDir, 0755, true);
            } catch (\Exception $ex) { // @codeCoverageIgnoreStart
                \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                return false;
            } // @codeCoverageIgnoreEnd
        }

        return is_dir($this->cacheDir) && is_writable($this->cacheDir);
    }

    /**
     * @inheritDoc
     */
    public function getCategories($prefix = '')
    {
        if (!$this->caching) {
            return [];
        }
        
        $path = $this->cacheDir;
        
        if ($prefix != '') {
            $path .= DIRECTORY_SEPARATOR . $this->sanitizeName($prefix);
        } else if ($this->prefix != '') {
            $path .= DIRECTORY_SEPARATOR . $this->sanitizeName($this->prefix);
        }
        
        if (!is_dir($path)) {
            return [];
        }
        
        try {
            $directories = glob($path . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
            $categories = [];

            foreach ($directories as $dir) {
                $categories[] = basename($dir);
            }

            return $categories;
        } catch (\Exception $ex) { // @codeCoverageIgnoreStart
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return [];
        } // @codeCoverageIgnoreEnd
    }

    /**
     * Create cache dir if it doesn't exist
     */
    private function _createCacheDir()
    {
        try {
            mkdir($this->cacheDir);
        } catch (\Exception $ex) { // @codeCoverageIgnoreStart
            $this->caching = false;
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        } // @codeCoverageIgnoreEnd
    }

    /**
     * Get the full path to a cache file
     * @param string $key Cache key
     * @param boolean $createDir Whether to create the directory if it doesn't exist
     * @return string|boolean Full path or false on failure
     */
    protected function getFilePath($key, $createDir = true)
    {
        $prefix = '';

        if ($this->prefix != '') {
            $prefix = $this->sanitizeName($this->prefix);
        }

        $category = $this->categoryDirectory($key);

        $path = $this->cacheDir;

        if ($prefix != '') {
            $path .= DIRECTORY_SEPARATOR . $prefix;

            if (!file_exists($path) && $createDir) {
                try {
                    mkdir($path, 0755, true);
                } catch (\Exception $ex) { // @codeCoverageIgnoreStart
                    \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                    return false;
                } // @codeCoverageIgnoreEnd
            }
        }

        if ($category != '') {
            $path .= DIRECTORY_SEPARATOR . $category;

            if (!file_exists($path) && $createDir) {
                try {
                    mkdir($path, 0755, true);
                } catch (\Exception $ex) { // @codeCoverageIgnoreStart
                    \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                    return false;
                } // @codeCoverageIgnoreEnd
            }
        }

        /*
         * Sanitised here, because this is the one place a cache key becomes a path.
         *
         * `generateKey()` cleans the prefix, the category and the extension and leaves the id
         * alone, so a key of `../../config` or `a/b` was concatenated onto the cache directory
         * unchanged: the first writes outside the cache, the second silently outside its category
         * directory, where `clear($category)` will never find it again. On a server-backed
         * adapter a slash in a key is just a character; here it is a directory separator, so the
         * check belongs to this adapter rather than to the shared key builder.
         *
         * `sanitizeName()` collapses runs of dots to one and drops anything outside
         * `[\w_.-]`, which removes both the traversal and the separator. Every operation —
         * `save()`, `load()`, `delete()`, `getRemainingTtl()` — resolves its path through this
         * method, so they all agree on the resulting filename. A key that changes shape under
         * sanitisation misses once against entries written before this and is then rewritten.
         */
        return $path . DIRECTORY_SEPARATOR . $this->sanitizeName($key);
    }

    /**
     * The directory an entry belongs in.
     *
     * **The category as it was given, not as the key can be parsed.** This used
     * to be `explode('_', $key)[0]`, which is right only for a category with no
     * underscore in it. For `schema_columns_things` the entry went into a
     * directory called `schema`, and {@see clear()} — which builds its path from
     * the category it was handed — then looked for `schema_columns_things`,
     * found nothing, and reported success. Every such category was permanently
     * unclearable, silently.
     *
     * Somebody had already met the same parsing: `Cache::_generateCacheName()`
     * strips underscores out of the *prefix* before building the key, for
     * exactly this reason, and did not do the same for the category.
     *
     * The key-splitting fallback remains for a caller that reaches an adapter
     * directly without going through Cache — that is how the adapters are
     * unit-tested, and losing it would change what those keys resolve to.
     *
     * @param  string $key
     * @return string Sanitised directory name, or '' for the prefix root.
     */
    protected function categoryDirectory($key)
    {
        if ($this->category !== '') {
            return $this->sanitizeName($this->category);
        }

        $parts = explode('_', $key);

        return count($parts) > 1 ? $parts[0] : '';
    }

    /**
     * @inheritDoc
     */
    public function load($key, $timeout = 3600)
    {
        if (!$this->caching) {
            return false;
        }

        $filePath = $this->getFilePath($key, false);
        if (!$filePath || !file_exists($filePath)) {
            return false;
        }

        try {
            $filedata = file_get_contents($filePath);
            if (!$filedata) {
                return false;
            }

            $entry = unserialize($filedata);
            if (!isset($entry['data'])) {
                return false;
            }

            if ($this->hasExpired($filePath, $entry, (int) $timeout)) {
                $this->delete($key);
                return false;
            }

            return $entry['data'];
        } catch (\Exception $ex) { // @codeCoverageIgnoreStart
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        } // @codeCoverageIgnoreEnd
    }

    /**
     * @inheritDoc
     */
    public function save($key, $data, $timeout = 3600)
    {
        if (!$this->caching) {
            return false;
        }

        $filePath = $this->getFilePath($key);
        if (!$filePath) {
            return false;
        }

        try {
            $entry = [
                'data' => $data,
                'time' => time(),
                'timeout' => $timeout
            ];

            $serialized = serialize($entry);
            file_put_contents($filePath, $serialized);

            return true;
        } catch (\Exception $ex) { // @codeCoverageIgnoreStart
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        } // @codeCoverageIgnoreEnd
    }

    /**
     * @inheritDoc
     */
    public function delete($key)
    {
        if (!$this->caching) {
            return false;
        }

        $filePath = $this->getFilePath($key, false);
        if (!$filePath || !file_exists($filePath)) {
            return true; // Already deleted
        }

        try {
            unlink($filePath);
            $this->cleanEmptyDirectories(dirname($filePath));
            return true;
        } catch (\Exception $ex) { // @codeCoverageIgnoreStart
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return false;
        } // @codeCoverageIgnoreEnd
    }

    /**
     * Remove empty directories
     * @param string $dir Directory path
     */
    protected function cleanEmptyDirectories($dir)
    {
        if ($dir == $this->cacheDir) {
            return;
        }

        if (is_dir($dir)) {
            $files = $this->scanDirectory($dir);

            /*
             * A read that failed is not an empty directory.
             *
             * `scandir()` answers `false` when the directory has gone between the `is_dir()`
             * above and the read — which happens, because this sweep runs from
             * `Database::cacheflush()` and that runs on every model save, so two of them can be
             * walking the same tree. `count(false)` is a **TypeError**, and a TypeError is an
             * `Error` rather than an `Exception`, so it went straight past the catch below and
             * out of the save that triggered the flush.
             *
             * Seen once in a full suite run and never in the twenty before it, which is the
             * signature of the race. Same shape as the vanishing directory `cleanup()` was
             * taught about earlier: reclaiming disk is best-effort, and the request that
             * happened to trigger it must not fail for it.
             */
            if (!is_array($files)) {
                return;
            }

            if (count($files) <= 2) { // Only . and ..
                try {
                    rmdir($dir);
                    $this->cleanEmptyDirectories(dirname($dir));
                } catch (\Throwable $ex) { // @codeCoverageIgnoreStart
                    \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
                } // @codeCoverageIgnoreEnd
            }
        }
    }

    /**
     * The entries of a directory, or `false` when it cannot be read.
     *
     * A seam, for the same reason {@see directoryIterator()} is one: the interesting case is the
     * read *failing*, and a test cannot make `scandir()` fail on a directory it owns — the suite
     * runs as root, so permissions are no lever. `@` because a vanished directory is a warning
     * this method is about to handle, not one to print.
     *
     * @param  string $dir
     * @return array<int, string>|false
     */
    protected function scanDirectory(string $dir)
    {
        return @scandir($dir);
    }

    /**
     * @inheritDoc
     */
    public function clear($category = '')
    {
        if ($category === '') {
            $category = $this->category;
        }

        $root = $this->cacheDir;
        if ($this->prefix != '') {
            $root .= DIRECTORY_SEPARATOR . $this->sanitizeName($this->prefix);
        }

        $path = $category === ''
            ? $root
            : $root . DIRECTORY_SEPARATOR . $this->sanitizeName($category);

        if (is_dir($path)) {
            foreach ($this->listDirectoryFiles($path) as $file) {
                $this->deleteIfStillThere($file);
            }

            /*
             * Which pruner, and why it matters which.
             *
             * `cleanEmptyDirectories()` walks *upward* from the directory it is given and its
             * first line refuses to touch the cache root — correctly, the root is ours to keep.
             * But a flush of everything passes the root as its path, so the guard fired
             * immediately and **a full flush never removed a single directory**.
             *
             * Nothing looked wrong. The entries were gone, every read missed, every count was
             * right. What was left was one empty directory per category the installation had
             * ever cached under — and {@see \Pramnos\Database\Database::columnCacheCategory()}
             * makes one per table, so a database that creates and drops tables accumulates them
             * for ever. Found at **8,589 empty directories holding zero files** in a checkout,
             * where every `getStats()`, `getAllItems()` and `clear()` walks the lot: 1.2 seconds
             * a call over a bind mount, and about a thousand new ones per day of running a test
             * suite.
             *
             * So: the downward sweep when the whole cache was flushed, and the cheap upward walk
             * when one category was.
             */
            if ($category === '') {
                $this->pruneEmptyDirectories();
            } else {
                $this->cleanEmptyDirectories($path);
            }
        }

        // Entries written by the old layout, which put a category with an
        // underscore in it into a directory named after its first segment —
        // `schema_columns_things_<id>.sql` inside `schema/`. Without this sweep
        // a flush would leave every one of them in place, and they would go on
        // being served until they expired. Matched on the file name, which is
        // where the whole category has always been, so it is exact rather than
        // a prefix guess.
        if ($category !== '') {
            $this->clearLegacyLayout($root, $category);
        }

        /**
         * Garbage collection is occasional, not per-clear.
         *
         * `cleanup()` walks the **whole** cache tree and reads every file in it to
         * decide whether it has expired. `clear()` is called from
         * `Database::cacheflush()`, which every model save calls — so the cost of
         * writing one row was the cost of inspecting the entire cache.
         *
         * Measured on this project's container: **1358 ms per call**, with the
         * cache holding no files at all. The walk was of 3064 empty directories,
         * which is the second half of the same bug (see cleanup()). A suite that
         * saves models spent seconds per test inside it, and a production write
         * paid the same on every request.
         *
         * Expired entries are not a correctness problem — `load()` checks the
         * timestamp before returning anything, so a stale file is never served.
         * The sweep only reclaims disk. That makes it exactly the kind of work to
         * sample rather than to do on every write, the way PHP's own session
         * garbage collection does.
         */
        if ($this->shouldCollectGarbage()) {
            $this->cleanup();
        }
    }

    /**
     * Whether this call should also sweep expired entries.
     *
     * One call in {@see GC_DIVISOR} — and never under test.
     *
     * The sampling is what keeps the amortised cost of a write bounded. It is also
     * a random draw, and a suite in which some calls sweep and others do not is a
     * suite whose outcome depends on the draw: this framework has a test that
     * asserts on cache contents left by earlier tests, and it began passing or
     * failing by luck the moment the sweep became occasional.
     *
     * So under `PRAMNOS_TESTING` it never fires on its own. The sweep itself is
     * covered by overriding this method, which is the only way to test it without
     * a coin.
     */
    protected function shouldCollectGarbage(): bool
    {
        if (defined('PRAMNOS_TESTING')) {
            return false;
        }

        return random_int(1, self::GC_DIVISOR) === 1;
    }

    /**
     * Delete entries an older version of {@see getFilePath()} misfiled.
     *
     * Looks in the directory that version would have chosen — the category's
     * first underscore-separated segment — for files whose name begins with the
     * full category. A file called `schema_columns_things_<id>.sql` belongs to
     * category `schema_columns_things` and to no other, because the separator
     * after the category is the same `_` the name is built with.
     *
     * @param  string $root     The prefix root inside the cache directory.
     * @param  string $category The category being cleared.
     * @return void
     */
    protected function clearLegacyLayout($root, $category)
    {
        $sanitised = $this->sanitizeName($category);
        $segment   = explode('_', $sanitised)[0];

        if ($segment === $sanitised) {
            return;   // no underscore: the directory above was the right one
        }

        $legacy = $root . DIRECTORY_SEPARATOR . $segment;
        if (!is_dir($legacy)) {
            return;
        }

        foreach ($this->listDirectoryFiles($legacy) as $file) {
            if (str_starts_with(basename($file), $sanitised . '_')) {
                $this->deleteIfStillThere($file);
            }
        }
        $this->cleanEmptyDirectories($legacy);
    }

    /**
     * @inheritDoc
     */
    protected function cleanup()
    {
        $files = $this->listDirectoryFiles($this->cacheDir);
        foreach ($files as $file) {
            if ($this->checkIfFileIsExpired($file)) {
                $this->deleteIfStillThere($file);
            }
        }

        /**
         * Prune the empty directories, which nothing used to.
         *
         * This called `cleanEmptyDirectories($this->cacheDir)`, and that method's
         * first line is `if ($dir == $this->cacheDir) return;` — it walks *upward*
         * from a directory it is given, so handing it the root is a guaranteed
         * no-op. Every directory a cache write created stayed for good: 3064 of
         * them on one container, all empty, each one walked again by the next
         * sweep.
         *
         * Bottom-up, so a directory whose children were just removed is itself
         * seen as empty in the same pass.
         */
        $this->pruneEmptyDirectories();
    }

    /**
     * Remove every empty directory under the cache root, deepest first.
     *
     * The root itself is left in place: it is configuration, not a cache entry,
     * and recreating it on the next write would race with a concurrent reader.
     */
    protected function pruneEmptyDirectories()
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if (!$entry->isDir()) {
                continue;
            }
            $path = $entry->getPathname();
            $contents = @scandir($path);
            if (is_array($contents) && count($contents) <= 2) {
                @rmdir($path);
            }
        }
    }

    private function checkIfFileIsExpired($file)
    {
        $remaining = $this->remainingTtl((string) $file);

        // Unreadable (null) is not expired: a file this adapter cannot parse is
        // not one it may delete. Never-expiring (-1) is not expired either — see
        // remainingTtl().
        return $remaining !== null && $remaining !== -1 && $remaining <= 0;
    }

    /**
     * Whether an entry on disk has expired.
     *
     * Two limits, and the entry's own was the one being ignored. `save()` records the TTL it was
     * given; expiry was decided entirely from the `$timeout` the *reader* passed, which every
     * other adapter treats as advisory because the server holds the real TTL. Two consequences,
     * in opposite directions, and both were live:
     *
     *   - a value saved to live **forever** (`$timeout <= 0`) was deleted an hour after it was
     *     written, because `load()` defaults to 3600 and the structured helpers in
     *     {@see AbstractAdapter} — `hashGet()`, `listRange()`, `expire()` — took that default
     *     without meaning anything by it. A permanent hash was readable for one hour;
     *   - a counter written with a **one-second** TTL stayed readable indefinitely, because
     *     `counter()` reads with `$timeout = 0` and `0 > 0` is false, so nothing was checked at
     *     all. A rate-limit window never closed.
     *
     * So the stored TTL decides whether the entry is still valid, and the caller's `$timeout`
     * keeps the meaning `Cache::load($id, $category, $timeout)` has always given it: an
     * *additional* maximum age this particular reader will accept. Either limit exceeded expires
     * the entry.
     *
     * An entry with no recorded TTL was written before `save()` stored one. Those fall back to
     * the caller's argument — the previous behaviour, for the cache files already on disk.
     *
     * @param  string              $filePath Where the entry lives, for its mtime
     * @param  array<string,mixed> $entry    The unserialised entry
     * @param  int                 $maxAge   The reader's own limit; 0 or less means no limit
     * @return bool
     */
    protected function hasExpired(string $filePath, array $entry, int $maxAge): bool
    {
        /*
         * The file's mtime, not the `time` recorded inside the entry.
         *
         * The two are the same value at every write, and mtime is what
         * {@see getRemainingTtl()} and therefore `cleanup()` already measure from — so a sweep
         * and a read agree about which entries are stale. It is also the one a test or an
         * operator can move.
         */
        $writtenAt = (int) (filemtime($filePath) ?: ($entry['time'] ?? time()));
        $age       = time() - $writtenAt;

        if (array_key_exists('timeout', $entry)) {
            $stored = (int) $entry['timeout'];

            if ($stored > 0 && $age > $stored) {
                return true;
            }
        }

        return $maxAge > 0 && $age > $maxAge;
    }

    /**
     * Seconds left on a cached file: -1 when it never expires, null when the
     * file cannot be read as a cache entry.
     *
     * Two things came out of collapsing this into a boolean.
     *
     * **An entry saved to never expire was deleted by the next sweep.** `save()`
     * treats `timeout <= 0` as "no expiry" — `load()` checks `$timeout > 0`
     * before comparing — but the expiry test did not, and
     * `filemtime < time() - 0` is true of every file written more than a moment
     * ago. So `cleanup()`, the sampled garbage collection, deleted exactly the
     * entries a caller asked to keep. It presents as a cache that "does not
     * work" for the values that were meant to be permanent, intermittently,
     * because the sweep is sampled.
     *
     * **And the cache browser said nothing expires.** `getAllItems()` reported
     * `-1` for every live entry, so the TTL column read "Never" for all of them
     * — the one thing that column exists to say, said wrongly, on the screen an
     * operator opens to find out when something will be dropped. The expiry was
     * always computable: it is `filemtime + timeout`.
     */
    private function remainingTtl(string $file): ?int
    {
        if (!is_file($file)) {
            return null;
        }

        $contents = file_get_contents($file);
        if ($contents === false
            || !\Pramnos\General\Helpers::checkUnserialize($contents)) {
            return null;
        }

        try {
            $details = unserialize($contents);
        } catch (\Exception $ex) { // @codeCoverageIgnoreStart
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return null;
        } // @codeCoverageIgnoreEnd

        if (!is_array($details) || !isset($details['timeout'])) {
            return null;
        }

        $timeout = (int) $details['timeout'];
        if ($timeout <= 0) {
            return -1;
        }

        return filemtime($file) + $timeout - time();
    }

    /**
     * Delete a file the sweep listed, tolerating its having gone already.
     *
     * A sweep is inherently a listing followed by deletes, and between the two
     * another process — a second worker, a concurrent request, the sampled
     * garbage collection — may have removed the same entry. `unlink()` then emits
     * a warning about a file nobody needed any more, which is noise in a log and,
     * in a test run, an intermittent failure with no cause to find.
     *
     * The `is_file()` check does not close the window; nothing can. It narrows it,
     * and the `@` states that losing this particular race is the expected outcome
     * rather than something to report.
     */
    private function deleteIfStillThere(string $file): void
    {
        if (is_file($file)) {
            @unlink($file);
        }
    }

    protected function listDirectoryFiles($path)
    {
        $files = [];
        // A cache group directory only exists once something has been written
        // to it. Flushing or cleaning a group that was never written — or was
        // already removed by a previous cleanEmptyDirectories() — is a no-op,
        // not an error, so bail out instead of letting RecursiveDirectoryIterator
        // throw on the missing path.
        if (!is_dir($path)) {
            return $files;
        }

        /*
         * And the guard above cannot be enough, because it is a check followed by a use.
         *
         * The directory can go between the two — another request flushing the same group, or
         * this adapter's own `cleanEmptyDirectories()` from a concurrent call — and then the
         * iterator throws `UnexpectedValueException: Failed to open directory`. Observed, not
         * imagined:
         *
         *     UnexpectedValueException: RecursiveDirectoryIterator::__construct(
         *         …/var/cache/userlist): Failed to open directory: No such file or directory
         *       FileAdapter.php:610 → Cache.php:728 → Database.php:2673 → User.php:755
         *       ← User::activate()
         *
         * Which is the part that matters: the throw did not break a cache flush, it broke a
         * **user activation**. `save()` flushes the user list, the flush raised, and the
         * operation the visitor asked for failed because of housekeeping that had already
         * succeeded — the directory was gone, which is the state the flush wanted.
         *
         * Caught, and caught around the loop rather than the constructor alone: a subdirectory
         * can vanish mid-walk with the same result. Whatever was collected before it went is
         * returned, because those files are the ones still there to delete.
         */
        try {
            foreach ($this->directoryIterator($path) as $file) {
                $files[] = $file->getPathname();
            }
        } catch (\UnexpectedValueException $vanished) {
            // A directory that disappeared while being listed has nothing left to remove.
        }

        return $files;
    }

    /**
     * The recursive iterator over one cache directory.
     *
     * A thin, overridable seam — the same idiom as `Auth\NewDeviceAuthLink::notifier()`. The
     * failure it exists for is a race, so it cannot be reproduced by arranging files: a test
     * makes this throw and asserts that the flush survives, which is the only honest way to
     * cover a `catch` for something that happens between two statements.
     *
     * @param  string $path
     * @return iterable<\SplFileInfo>
     */
    protected function directoryIterator($path)
    {
        return new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );
    }

    /**
     * @inheritDoc
     */
    public function getStats()
    {
        $stats = [
            'method' => 'file',
            'categories' => 0,
            'items' => 0
        ];

        if (!$this->caching) {
            return $stats;
        }

        try {
            $path = $this->cacheDir;

            if ($this->prefix != '') {
                $path .= DIRECTORY_SEPARATOR . $this->sanitizeName($this->prefix);
            }

            if (!is_dir($path)) {
                return $stats;
            }

            // Categories are directories, and there is already a method that
            // lists them. This called `listDirectoryFiles($path, true)` — and
            // that method takes **one** parameter, so PHP dropped the `true` and
            // returned the same recursive file list as the line below it. The
            // cache dashboard's "Categories" tile therefore showed the number of
            // cached *items*, identical to the tile beside it, in all three
            // bundled themes and in the DevPanel.
            $stats['categories'] = count($this->getCategories());
            $stats['items'] = count($this->listDirectoryFiles($path));
        } catch (\Exception $ex) { // @codeCoverageIgnoreStart
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        } // @codeCoverageIgnoreEnd

        return $stats;
    }

    /**
     * @inheritDoc
     */
    public function categoryHash($category, $prefix = '', $reset = false)
    {
        // For file system, we just return the category name
        return $category;
    }
    
    /**
     * @inheritDoc
     */
    public function getAllItems($category = '', $limit = 100)
    {
        $items = [];
        
        if (!$this->caching) {
            return $items;
        }
        
        try {
            $searchPath = $this->cacheDir;
            
            // If category specified, search in category subdirectory
            if ($category !== '') {
                $searchPath = $this->cacheDir . DIRECTORY_SEPARATOR . $category;
                if (!is_dir($searchPath)) {
                    return $items;
                }
            }
            
            $files = $this->listDirectoryFiles($searchPath);
            
            // Filter cache files only (skip directories and non-cache files)
            $cacheFiles = array_filter($files, function($file) {
                return is_file($file) && !is_dir($file);
            });
            
            // Limit the results
            $cacheFiles = array_slice($cacheFiles, 0, $limit);
            
            foreach ($cacheFiles as $file) {
                try {
                    if (file_exists($file)) {
                        $relativePath = str_replace($this->cacheDir . DIRECTORY_SEPARATOR, '', $file);
                        $key = basename($file);
                        
                        // The real remaining seconds, not a boolean widened
                        // back out: -1 means never, null means the file is not
                        // a readable cache entry, and anything <= 0 is expired.
                        $remaining = $this->remainingTtl($file);
                        $isExpired = $remaining !== null
                            && $remaining !== -1 && $remaining <= 0;

                        $items[] = [
                            'key' => $key,
                            'size' => filesize($file),
                            'created_time' => date('Y-m-d H:i:s', filemtime($file)),
                            'ttl' => $remaining,
                            'type' => 'file',
                            'path' => $relativePath,
                            'expired' => $isExpired
                        ];
                    }
                } catch (\Exception $e) { // @codeCoverageIgnoreStart
                    // Skip problematic files
                    continue;
                } // @codeCoverageIgnoreEnd
            }
        } catch (\Exception $ex) { // @codeCoverageIgnoreStart
            \pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        } // @codeCoverageIgnoreEnd
        
        return $items;
    }
}
