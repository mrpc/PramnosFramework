<?php

declare(strict_types=1);

namespace Pramnos\Database;

/**
 * Writes that should not be paid for while the visitor is waiting.
 *
 * Some rows are worth keeping and worth nothing individually: an access log, an
 * audit trail, a hit counter, a metric. They are written on every request, they
 * are read in bulk long afterwards, and nobody notices if one arrives a minute
 * late — but the request pays for them synchronously, and on a compressed
 * hypertable a single such insert can cost more than the query the page
 * actually ran.
 *
 * This buffers them. `append()` puts the row somewhere cheap; a periodic
 * `spool:drain` writes what has accumulated, batched, out of the request path.
 *
 * ```php
 * WriteSpool::append('#PREFIX#tokenactions', [
 *     'tokenid'    => 7,
 *     'urlid'      => 3,
 *     'method'     => 'POST',
 *     'params'     => $body,
 *     'servertime' => time(),
 * ]);
 * ```
 *
 * ## Where it puts them
 *
 * Three backends. The default order is measured rather than assumed — per row,
 * on one machine, against a real PostgreSQL and a real Redis in another
 * container:
 *
 * | | ms/row | vs. the insert today |
 * |---|---|---|
 * | INSERT into the real table (hypertable + indexes) | 2.807 | — |
 * | INSERT into a plain, unindexed spool table | 2.362 | 0.84× |
 * | Redis `RPUSH` | 0.041 | 0.015× |
 * | file append under `LOCK_EX` | 0.003 | 0.001× |
 *
 * Two things follow from that, and both are the opposite of the obvious guess:
 *
 * **A spool table in the database is not worth building.** The cost is not the
 * indexes and not the hypertable — it is the round trip. An unindexed table
 * pays the same round trip and saves 16%, for the price of a table, a migration
 * and a drain.
 *
 * **The file is the fastest, not Redis.** Redis is also a round trip, over TCP,
 * to another host; an append to a local file is a syscall. So:
 *
 *  - **file** (default) — one line of JSON appended per row, under `LOCK_EX`.
 *    No dependencies, and the cheapest thing measured here by an order of
 *    magnitude.
 *  - **redis** — one `RPUSH` per row. Slower than the file, and worth it for
 *    one reason: the buffer is shared, so several web servers can be drained by
 *    one process. With files, each server drains its own — which is fine when
 *    every server runs the schedule, and not when only one does.
 *  - **sync** — write it to the database now, exactly as the caller would have.
 *    The floor: spooling is an optimisation, and when it cannot be done the row
 *    is still written.
 *
 * The choice is made once per process. Set `spool_driver` (`file`, `redis`,
 * `sync`) to override it — `redis` is the one to reach for on a multi-server
 * installation with a single drain.
 *
 * ## What it guarantees, and what it does not
 *
 * A spooled row is written **later**, not immediately: anything that reads it
 * back in the same request will not find it. That is the trade, and it is why
 * this is opt-in per call site rather than something the query builder does on
 * its own.
 *
 * With the file driver, a row is on disk before `append()` returns, so a crash
 * loses nothing. With Redis, it is in Redis — durable to whatever degree that
 * Redis is configured for. Neither is a queue with delivery guarantees, and
 * neither should be used for anything a user would notice the absence of.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class WriteSpool
{
    /** @var string One RPUSH per row into a Redis list */
    public const DRIVER_REDIS = 'redis';

    /** @var string One appended line of JSON per row */
    public const DRIVER_FILE = 'file';

    /** @var string Straight to the database, here and now */
    public const DRIVER_SYNC = 'sync';

    /** @var int How many rows one INSERT pass handles */
    protected const BATCH_SIZE = 500;

    /**
     * The driver in use, decided once per process.
     *
     * @var string|null
     */
    protected static ?string $driver = null;

    /**
     * The directory the file driver writes to, resolved once.
     *
     * @var string|null
     */
    protected static ?string $directory = null;

    /**
     * Per-table row transformers, applied when a row is written.
     *
     * A buffered row can be cheaper to produce than the row the database wants.
     * The audit log is the case that motivated this: it needs the id of a URL
     * from a lookup table, and resolving that in the request costs a SELECT —
     * the very kind of cost buffering exists to avoid. So the row carries the
     * URL, and this turns it into an id at drain time, in a process that is
     * long-running and can remember what it has already resolved.
     *
     * A transformer runs in the drain, not in the request, so it may query
     * freely. It must return the row to write, and it must be idempotent: a
     * batch that fails is replayed.
     *
     * @var array<string, callable>
     */
    protected static array $transformers = [];

    /**
     * Register a transformer for one table.
     *
     * ```php
     * WriteSpool::transform('#PREFIX#tokenactions', function (array $row): array {
     *     $row['urlid'] = UrlRegistry::idFor($row['url']);
     *     unset($row['url']);
     *     return $row;
     * });
     * ```
     *
     * @param  string        $table
     * @param  callable|null $transformer function(array $row): array, or null to remove
     * @return void
     */
    public static function transform(string $table, ?callable $transformer): void
    {
        if ($transformer === null) {
            unset(static::$transformers[$table]);

            return;
        }

        static::$transformers[$table] = $transformer;
    }

    /**
     * Apply the registered transformer, if there is one.
     *
     * A transformer that raises leaves the row untouched rather than losing it:
     * the write may still succeed, and if it does not, the row is requeued and
     * tried again once whatever the transformer needed is working.
     *
     * @param  string               $table
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected static function applyTransformer(string $table, array $row): array
    {
        static::registerFrameworkTransformers();

        if (!isset(static::$transformers[$table])) {
            return $row;
        }

        try {
            $transformed = (static::$transformers[$table])($row);

            return is_array($transformed) ? $transformed : $row;
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);

            return $row;
        }
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Buffer one row for later.
     *
     * Never raises. A failure to buffer falls back to writing the row directly,
     * and a failure to do *that* is logged and swallowed — the caller is
     * recording something about a request, and instrumentation is not allowed to
     * be the reason the request fails.
     *
     * @param  string               $table The table the row belongs in
     * @param  array<string, mixed> $row   Column => value
     * @return string Which driver took it — one of the DRIVER_* constants
     */
    public static function append(string $table, array $row): string
    {
        $driver = static::driver();

        try {
            if ($driver === static::DRIVER_FILE && static::appendToFile($table, $row)) {
                return static::DRIVER_FILE;
            }

            if ($driver === static::DRIVER_REDIS) {
                if (static::appendToRedis($table, $row)) {
                    return static::DRIVER_REDIS;
                }

                // Redis was chosen and did not take it. The file is still
                // better than making the request wait for the database.
                if (static::appendToFile($table, $row)) {
                    return static::DRIVER_FILE;
                }
            }
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
        }

        static::persist($table, $row);

        return static::DRIVER_SYNC;
    }

    /**
     * Which backend this process is using.
     *
     * Resolved once: the Redis probe is a connection attempt, and doing it per
     * row would cost more than the write it is trying to save.
     *
     * @return string
     */
    public static function driver(): string
    {
        if (static::$driver !== null) {
            return static::$driver;
        }

        $configured = \Pramnos\Application\Settings::getSetting('spool_driver');

        if (is_string($configured) && $configured !== '') {
            $configured = strtolower(trim($configured));
            if (in_array($configured, [
                static::DRIVER_REDIS, static::DRIVER_FILE, static::DRIVER_SYNC,
            ], true)) {
                return static::$driver = $configured;
            }
        }

        // The file first, because it measured an order of magnitude cheaper
        // than Redis for this workload — a syscall against a TCP round trip.
        // Redis is the better answer only when the buffer has to be shared
        // between servers, and that is a decision an installation makes, not
        // something to infer from Redis merely being reachable.
        if (static::directory() !== null) {
            return static::$driver = static::DRIVER_FILE;
        }

        if (static::redisAvailable()) {
            return static::$driver = static::DRIVER_REDIS;
        }

        return static::$driver = static::DRIVER_SYNC;
    }

    /**
     * Force a driver, or forget the resolved one.
     *
     * For tests, and for a console command that wants to drain what a web
     * process wrote regardless of what it would have chosen itself.
     *
     * @param  string|null $driver One of the DRIVER_* constants, or null to re-resolve
     * @return void
     */
    public static function setDriver(?string $driver): void
    {
        static::$driver = $driver;
    }

    /**
     * Forget everything resolved so far.
     *
     * @return void
     */
    public static function reset(): void
    {
        static::$driver    = null;
        static::$directory = null;
    }

    /**
     * Forget every registered transformer.
     *
     * @return void
     */
    public static function resetTransformers(): void
    {
        static::$transformers      = [];
        static::$frameworkRegistered = false;
    }

    /**
     * Whether the framework's own transformers have been registered.
     *
     * @var bool
     */
    protected static bool $frameworkRegistered = false;

    /**
     * Register the transformers the framework itself needs.
     *
     * Done here, at the point a row is written, rather than in a service
     * provider: the drain runs in a console process that may not have booted
     * the same providers a web request does, and a transformer that is missing
     * there would write a row with the wrong columns — silently, and only in
     * the process nobody is watching.
     *
     * @return void
     */
    protected static function registerFrameworkTransformers(): void
    {
        if (static::$frameworkRegistered) {
            return;
        }
        static::$frameworkRegistered = true;

        if (isset(static::$transformers['#PREFIX#tokenactions'])) {
            return;   // an application replaced it
        }

        static::transform(
            '#PREFIX#tokenactions',
            static fn(array $row): array => \Pramnos\User\Token::resolveActionRow($row)
        );
    }

    // -------------------------------------------------------------------------
    // Draining
    // -------------------------------------------------------------------------

    /**
     * Write everything that has been buffered.
     *
     * Both backends are drained regardless of which one is currently active, so
     * that switching driver — or draining from a CLI process that resolves a
     * different one than the web server did — never strands rows.
     *
     * @param  callable|null $reporter function(string $message): void
     * @return array{written: int, failed: int, tables: array<string, int>}
     */
    public static function drain(?callable $reporter = null): array
    {
        $stats = ['written' => 0, 'failed' => 0, 'tables' => []];

        static::drainRedis($stats, $reporter);
        static::drainFiles($stats, $reporter);

        return $stats;
    }

    /**
     * How many rows are waiting.
     *
     * @return int
     */
    public static function pending(): int
    {
        $count = 0;

        foreach (static::redisKeys() as $key) {
            try {
                $count += (int) static::redis()->lLen($key);
            } catch (\Throwable) {
                // A Redis that has gone away has nothing pending to report.
            }
        }

        foreach (static::spoolFiles() as $file) {
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }
            while (fgets($handle) !== false) {
                $count++;
            }
            fclose($handle);
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Redis backend
    // -------------------------------------------------------------------------

    /**
     * Push one row onto its Redis list.
     *
     * @param  string               $table
     * @param  array<string, mixed> $row
     * @return bool Whether Redis took it
     */
    protected static function appendToRedis(string $table, array $row): bool
    {
        $payload = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($payload === false) {
            return false;
        }

        return (bool) static::redis()->rPush(static::redisKey($table), $payload);
    }

    /**
     * Drain every Redis list.
     *
     * The list is renamed before it is read, so rows arriving during the drain
     * go to a fresh list rather than being read and then deleted unwritten.
     * `RENAME` is atomic; reading and then deleting is not, and the difference
     * is rows lost under load, which is the only load that matters here.
     *
     * @param  array{written: int, failed: int, tables: array<string, int>} $stats
     * @return void
     */
    protected static function drainRedis(array &$stats, ?callable $reporter): void
    {
        foreach (static::redisKeys() as $key) {
            $table = static::tableFromRedisKey($key);

            try {
                $redis   = static::redis();
                $working = $key . ':draining:' . getmypid() . ':' . static::uniqueSuffix();

                if (!$redis->rename($key, $working)) {
                    continue;
                }
            } catch (\Throwable $ex) {
                static::report($reporter, '  redis ' . $key . ': ' . $ex->getMessage());
                continue;
            }

            // A batch at a time, for the same reason the file is streamed: one
            // `LRANGE 0 -1` over a backlog that built up while nothing drained
            // would pull all of it into memory at once.
            $written = 0;
            $failed  = [];

            try {
                while (true) {
                    $batch = $redis->lRange($working, 0, static::BATCH_SIZE - 1);

                    if (!is_array($batch) || $batch === []) {
                        break;
                    }

                    $redis->lTrim($working, count($batch), -1);
                    $written += static::writeBatch($table, $batch, $stats, $failed, $reporter);
                }

                $redis->del($working);
            } catch (\Throwable $ex) {
                static::report($reporter, '  redis ' . $table . ': ' . $ex->getMessage());
                continue;
            }

            if ($written > 0 || $failed !== []) {
                static::report(
                    $reporter,
                    '  redis ' . $table . ': ' . $written . ' row(s)'
                    . ($failed !== [] ? ', ' . count($failed) . ' failed' : '')
                );
            }

            // Rows that could not be written go back on the live list, so the
            // next drain sees them without replaying the ones that landed.
            foreach ($failed as $payload) {
                try {
                    $redis->rPush($key, $payload);
                } catch (\Throwable) {
                    // A Redis that has gone away mid-drain has already been
                    // reported; the rows are lost either way and saying so
                    // twice helps nobody.
                }
            }
        }
    }

    /**
     * Every spool list currently in Redis.
     *
     * @return list<string>
     */
    protected static function redisKeys(): array
    {
        try {
            $keys = static::redis()->keys(static::redisKey('*'));
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($keys)) {
            return [];
        }

        // A list mid-drain belongs to whoever is draining it.
        return array_values(array_filter(
            $keys,
            static fn($key): bool => !str_contains((string) $key, ':draining:')
        ));
    }

    /**
     * The Redis key a table's rows live under.
     */
    protected static function redisKey(string $table): string
    {
        return \Pramnos\Redis\ConnectionManager::getInstance()->prefix()
            . 'spool:' . $table;
    }

    /**
     * The table a Redis key belongs to.
     */
    protected static function tableFromRedisKey(string $key): string
    {
        $position = strpos($key, 'spool:');

        return $position === false ? $key : substr($key, $position + 6);
    }

    /**
     * The shared Redis connection.
     *
     * @return object
     */
    protected static function redis(): object
    {
        return \Pramnos\Redis\ConnectionManager::getInstance()->connection();
    }

    /**
     * Can this process talk to Redis at all?
     *
     * @return bool
     */
    protected static function redisAvailable(): bool
    {
        if (!class_exists('\Redis')) {
            return false;
        }

        try {
            return (bool) static::redis()->ping();
        } catch (\Throwable) {
            // No Redis, wrong password, wrong port: all the same answer here.
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // File backend
    // -------------------------------------------------------------------------

    /**
     * Append one row to its spool file.
     *
     * One JSON object per line, `LOCK_EX` held for the append. Concurrent
     * writers interleave whole lines rather than corrupting each other, which is
     * the only property this format needs.
     *
     * @param  string               $table
     * @param  array<string, mixed> $row
     * @return bool
     */
    protected static function appendToFile(string $table, array $row): bool
    {
        $directory = static::directory();

        if ($directory === null) {
            return false;
        }

        $payload = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($payload === false) {
            return false;
        }

        return @file_put_contents(
            $directory . DIRECTORY_SEPARATOR . static::fileName($table),
            $payload . "\n",
            FILE_APPEND | LOCK_EX
        ) !== false;
    }

    /**
     * Drain every spool file.
     *
     * Each file is renamed before it is read, for the same reason the Redis list
     * is: rows appended during the drain go to a fresh file instead of being
     * read and then truncated unwritten. A rename within a directory is atomic,
     * and a writer that had the old path open keeps writing to the renamed
     * inode — which is why the rename happens before the read, not after.
     *
     * A file that fails to write is left in place under its working name, so the
     * next run picks it up rather than dropping it.
     *
     * @param  array{written: int, failed: int, tables: array<string, int>} $stats
     * @return void
     */
    protected static function drainFiles(array &$stats, ?callable $reporter): void
    {
        $directory = static::directory();

        if ($directory === null) {
            return;
        }

        // Files still being written, plus anything a previous run left behind.
        foreach (static::spoolFiles() as $file) {
            $working = $file;

            if (!str_ends_with($file, '.draining')) {
                $working = $file . '.' . getmypid() . '.' . static::uniqueSuffix() . '.draining';

                if (!@rename($file, $working)) {
                    continue;   // somebody else got there first
                }
            }

            $table = static::tableFromFileName(basename($working));

            $handle = @fopen($working, 'rb');
            if ($handle === false) {
                continue;
            }

            // Streamed, a batch at a time. Reading the whole file into an array
            // first costs memory proportional to the backlog: a 100 MB spool
            // measured at 130 MB peak, which on a default `memory_limit` is a
            // fatal error — and a fatal error here is a spiral, because the
            // spool that could not be drained is the one that keeps growing.
            // Streaming holds one batch, whatever the file's size, and measured
            // twice as fast into the bargain.
            $batch   = [];
            $written = 0;
            $failed  = [];

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $batch[] = $line;

                if (count($batch) >= static::BATCH_SIZE) {
                    $written += static::writeBatch($table, $batch, $stats, $failed, $reporter);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $written += static::writeBatch($table, $batch, $stats, $failed, $reporter);
            }

            fclose($handle);

            if ($written > 0 || $failed !== []) {
                static::report(
                    $reporter,
                    '  file ' . $table . ': ' . $written . ' row(s)'
                    . ($failed !== [] ? ', ' . count($failed) . ' failed' : '')
                );
            }

            // What could not be written goes back to a fresh spool file, and
            // the working copy is dropped. Keeping the whole file instead would
            // replay every row that already landed, on every run, for as long as
            // one bad row stayed in it.
            static::requeue($table, $failed);
            @unlink($working);
        }
    }

    /**
     * Write one batch, collecting what could not be written.
     *
     * @param  list<string>  $batch  JSON payloads
     * @param  array{written: int, failed: int, tables: array<string, int>} $stats
     * @param  list<string>  $failed Collected, in place
     * @return int How many rows of this batch were written
     */
    protected static function writeBatch(
        string $table,
        array $batch,
        array &$stats,
        array &$failed,
        ?callable $reporter
    ): int {
        $before = $stats['written'];

        // The payloads that did not land, collected as they fail rather than
        // inferred from how many did. Inferring works only when the failures
        // are at the end of the batch: with a bad row in the middle, "the last
        // N did not land" requeues rows that did — and an audit log then grows
        // a duplicate on every run.
        $rejected = [];

        static::writeRows($table, $batch, $stats, $reporter, $rejected);

        $failed = array_merge($failed, $rejected);

        return $stats['written'] - $before;
    }

    /**
     * Put rows that could not be written back on the spool.
     *
     * @param  list<string> $rows JSON payloads
     * @return void
     */
    protected static function requeue(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $directory = static::directory();

        if ($directory === null) {
            return;
        }

        @file_put_contents(
            $directory . DIRECTORY_SEPARATOR . static::fileName($table),
            implode("\n", $rows) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Every spool file on disk, including ones a previous run was working on.
     *
     * @return list<string>
     */
    protected static function spoolFiles(): array
    {
        $directory = static::directory();

        if ($directory === null) {
            return [];
        }

        $files = glob($directory . DIRECTORY_SEPARATOR . '*.spool*');

        return is_array($files) ? array_values($files) : [];
    }

    /**
     * The file name a table's rows are appended to.
     */
    protected static function fileName(string $table): string
    {
        return static::slug($table) . '.spool';
    }

    /**
     * The table a spool file belongs to.
     */
    protected static function tableFromFileName(string $name): string
    {
        $name = preg_replace('/\.spool.*$/', '', $name) ?? $name;

        return str_replace('#PREFIX#', '#PREFIX#', $name);
    }

    /**
     * A table name reduced to something safe to put in a path.
     *
     * `#PREFIX#` is kept as a readable marker rather than stripped, because the
     * drain has to hand the table name back to the query builder exactly as the
     * caller gave it.
     */
    protected static function slug(string $table): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.#-]/', '_', $table);
    }

    /**
     * Where spool files live, or null when nowhere is writable.
     *
     * @return string|null
     */
    protected static function directory(): ?string
    {
        if (static::$directory !== null) {
            return static::$directory === '' ? null : static::$directory;
        }

        $base = defined('VAR_PATH')
            ? VAR_PATH
            : (defined('ROOT') ? ROOT . DIRECTORY_SEPARATOR . 'var' : sys_get_temp_dir());

        $directory = $base . DIRECTORY_SEPARATOR . 'spool';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            static::$directory = '';

            return null;
        }

        if (!is_writable($directory)) {
            static::$directory = '';

            return null;
        }

        return static::$directory = $directory;
    }

    // -------------------------------------------------------------------------
    // Writing to the database
    // -------------------------------------------------------------------------

    /**
     * Write a batch of buffered rows.
     *
     * Each batch is one transaction; when it raises, the batch is replayed row
     * by row so that one unwritable row does not take a few hundred good ones
     * with it.
     *
     * @param  list<string> $rows     JSON payloads
     * @param  array{written: int, failed: int, tables: array<string, int>} $stats
     * @param  list<string> $rejected Payloads that could not be written, collected
     * @return void
     */
    protected static function writeRows(
        string $table,
        array $rows,
        array &$stats,
        ?callable $reporter = null,
        array &$rejected = []
    ): void {
        foreach (array_chunk($rows, static::BATCH_SIZE) as $batch) {
            $decoded = [];

            foreach ($batch as $payload) {
                $row = json_decode((string) $payload, true);

                if (is_array($row) && $row !== []) {
                    $decoded[] = $row;
                    continue;
                }

                // A line that is not a row cannot be written and will never
                // become writable. It is counted as failed — but deliberately
                // NOT put back: requeuing it would make every later drain read
                // and reject it again, for ever.
                $stats['failed']++;
                static::report($reporter, '    unreadable row skipped');
            }

            if ($decoded === []) {
                continue;
            }

            try {
                static::beginBatch();
                foreach ($decoded as $row) {
                    static::persist($table, $row);
                }
                static::commitBatch();

                $stats['written'] += count($decoded);
                $stats['tables'][$table] = ($stats['tables'][$table] ?? 0) + count($decoded);
            } catch (\Throwable $ex) {
                static::rollbackBatch();
                static::report($reporter, '    batch failed: ' . $ex->getMessage());
                static::writeRowsIndividually($table, $decoded, $stats, $reporter, $rejected);
            }
        }
    }

    /**
     * Replay a failed batch one row at a time.
     *
     * @param  list<array<string, mixed>> $rows
     * @param  array{written: int, failed: int, tables: array<string, int>} $stats
     * @param  list<string> $rejected Payloads that could not be written, collected
     * @return void
     */
    protected static function writeRowsIndividually(
        string $table,
        array $rows,
        array &$stats,
        ?callable $reporter = null,
        array &$rejected = []
    ): void {
        foreach ($rows as $row) {
            try {
                static::persist($table, $row);
                $stats['written']++;
                $stats['tables'][$table] = ($stats['tables'][$table] ?? 0) + 1;
            } catch (\Throwable $ex) {
                $stats['failed']++;
                // Re-encoded rather than kept from the original line, because a
                // row that came back from Redis and one that came off disk have
                // to be requeued in the same form.
                $payload = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                if ($payload !== false) {
                    $rejected[] = $payload;
                }
                static::report($reporter, '    row failed: ' . $ex->getMessage());
            }
        }
    }

    /**
     * Write one row to the database now.
     *
     * @param  string               $table
     * @param  array<string, mixed> $row
     * @return void
     */
    protected static function writeNow(string $table, array $row): void
    {
        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()->table($table)->insert($row);
    }

    /**
     * Finish a row and write it.
     *
     * The transformer belongs here rather than inside {@see writeNow()}: the
     * write is the thing an application might replace, and replacing it should
     * not silently drop the step that turns a buffered row into the row the
     * table actually wants.
     *
     * @param  string               $table
     * @param  array<string, mixed> $row
     * @return void
     */
    protected static function persist(string $table, array $row): void
    {
        static::writeNow($table, static::applyTransformer($table, $row));
    }

    /**
     * Open the transaction that wraps one batch.
     *
     * @return void
     */
    protected static function beginBatch(): void
    {
        \Pramnos\Framework\Factory::getDatabase()->startTransaction();
    }

    /**
     * Commit the batch.
     *
     * @return void
     */
    protected static function commitBatch(): void
    {
        \Pramnos\Framework\Factory::getDatabase()->commitTransaction();
    }

    /**
     * Abandon the batch, so its rows can be replayed one at a time.
     *
     * @return void
     */
    protected static function rollbackBatch(): void
    {
        \Pramnos\Framework\Factory::getDatabase()->rollbackTransaction();
    }

    // -------------------------------------------------------------------------
    // Odds and ends
    // -------------------------------------------------------------------------

    /**
     * Something unlikely to collide with a concurrent drainer.
     */
    protected static function uniqueSuffix(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * Hand a progress line to the caller, when it asked for them.
     *
     * @param  callable|null $reporter
     * @param  string        $message
     * @return void
     */
    protected static function report(?callable $reporter, string $message): void
    {
        if ($reporter !== null) {
            $reporter($message);
        }
    }
}
