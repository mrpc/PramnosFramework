<?php

declare(strict_types=1);

namespace Pramnos\Database;

/**
 * Writes that arrive too late for a compressed chunk, held until they can land.
 *
 * A hypertable with a compression policy stops accepting writes into the time
 * ranges it has already compressed. Every application that writes late data
 * meets this: a delayed reading, a backfill, a correction, a webhook that turns
 * up months after the event it describes. The write simply fails, and the row
 * is lost unless something catches it.
 *
 * Catching it is the easy half. The half worth putting in the framework is what
 * happens next, because the obvious implementation is the expensive one:
 * decompressing a chunk, writing one row, and compressing it back costs the same
 * as decompressing it, writing ten thousand rows, and compressing it back. So
 * the rows are queued, and the drain groups them **by chunk** — one
 * decompress/compress pair per chunk, no matter how many rows are waiting inside
 * it. That grouping is the whole point; it is not obvious, and an application
 * that rediscovers it will probably rediscover it the slow way first.
 *
 * ## Writing
 *
 * Replace a direct insert with {@see write()}. It decides, per row, whether the
 * target time is still writable:
 *
 * ```php
 * $queue = new DeferredWriteQueue($db);
 * $queue->write('readings', [
 *     'device_id'   => 42,
 *     'measured_at' => $timestamp,
 *     'value'       => 19.4,
 * ]);
 * ```
 *
 * Recent rows go straight into the hypertable. Rows older than the compression
 * cutoff go into `deferredwrites` instead. On a database with no compression
 * policy — and on MySQL, and on any development or CI box without TimescaleDB —
 * the cutoff does not exist, nothing is ever deferred, and this is a plain
 * insert with one cached lookup in front of it.
 *
 * ## Draining
 *
 * `php pramnos timescale:drain`, from cron. See
 * {@see \Pramnos\Console\Commands\TimescaleDrain}.
 *
 * ## Declaring a table
 *
 * The table must be declared in {@see HypertableRegistry} with
 * `deferred_writes => true`, and — if the row should overwrite rather than
 * duplicate an existing one — with the columns that define a conflict:
 *
 * ```php
 * HypertableRegistry::register('readings', [
 *     'time_column'     => 'measured_at',
 *     'chunk_interval'  => '1 day',
 *     'compress_after'  => '7 days',
 *     'deferred_writes' => true,
 *     'conflict'        => ['device_id', 'measured_at'],
 *     'conflict_update' => ['value'],
 * ]);
 * ```
 *
 * The queue stores the row as JSON, so it carries no per-table knowledge: any
 * declared hypertable works, and adding one is a registry entry rather than a
 * code change.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class DeferredWriteQueue
{
    /** @var string The queue table */
    public const TABLE = 'deferredwrites';

    /** @var int Waiting to be written */
    public const STATUS_PENDING = 0;

    /** @var int Tried and failed; kept for inspection, never retried on its own */
    public const STATUS_FAILED = 2;

    /** @var int How many rows one INSERT pass handles */
    protected const BATCH_SIZE = 500;

    /** @var Database The connection everything runs on */
    protected Database $db;

    /**
     * Compression cutoffs already looked up, keyed by table.
     *
     * A long-running import calls {@see write()} once per row; without this it
     * would ask the database for the same policy every time. The value may be
     * null, which is itself an answer — "this table has no cutoff" — so the key
     * being present is what counts, not the value being truthy.
     *
     * @var array<string, int|null>
     */
    protected array $cutoffs = [];

    /**
     * @param Database|null $db Connection to use; the default one when omitted
     */
    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? \Pramnos\Framework\Factory::getDatabase();
    }

    // -------------------------------------------------------------------------
    // Write side
    // -------------------------------------------------------------------------

    /**
     * Write a row, or queue it when its chunk is already compressed.
     *
     * @param  string          $table Logical table name, as declared in the registry
     * @param  array<string, mixed> $row Column => value, exactly as it would be inserted
     * @param  int|string|null $time The row's time, when it is not simply
     *                               `$row[<time column>]`. Unix timestamp or
     *                               anything `strtotime()` understands.
     * @return bool True when the row went into the table, false when it was queued
     * @throws \InvalidArgumentException When the row carries no usable time
     */
    public function write(string $table, array $row, int|string|null $time = null): bool
    {
        $timestamp = $this->timestampOf($table, $row, $time);

        if ($this->shouldDefer($table, $timestamp)) {
            $this->defer($table, $row, $timestamp);

            return false;
        }

        try {
            $this->insert($table, $row);
        } catch (\Throwable $ex) {
            // The cutoff is read once and the policy runs on its own schedule,
            // so a row can be judged writable and then meet a chunk that was
            // compressed a moment later. Losing it to that race would defeat
            // the point of the class, so it goes on the queue like any other
            // late row — and the drain, which opens the chunk properly, writes
            // it.
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            $this->defer($table, $row, $timestamp);

            return false;
        }

        return true;
    }

    /**
     * Whether a row for this time would hit an already-compressed chunk.
     *
     * @param  string $table
     * @param  int    $timestamp Unix timestamp of the row
     * @return bool
     */
    public function shouldDefer(string $table, int $timestamp): bool
    {
        $cutoff = $this->writeCutoff($table);

        return $cutoff !== null && $timestamp < $cutoff;
    }

    /**
     * The oldest time that can still be written directly, as a unix timestamp.
     *
     * Read from the live compression policy, because that is the thing that
     * actually decides — a constant in application code drifts the first time
     * somebody changes the policy, and drifts silently. When the policy cannot
     * be read but the registry declares a `compress_after`, that declaration is
     * used instead; it is the same value the policy was created from.
     *
     * Null means nothing is ever deferred: no TimescaleDB, no policy, no
     * declaration. That is the correct answer on MySQL and on every development
     * box without the extension.
     *
     * @param  string $table
     * @return int|null Unix timestamp, or null when this table has no cutoff
     */
    public function writeCutoff(string $table): ?int
    {
        if (array_key_exists($table, $this->cutoffs)) {
            return $this->cutoffs[$table];
        }

        $this->cutoffs[$table] = $this->lookupCutoff($table);

        return $this->cutoffs[$table];
    }

    /**
     * Forget cached cutoffs, so the next write re-reads the policies.
     *
     * For a process that outlives a policy change, and for tests.
     */
    public function forgetCutoffs(): void
    {
        $this->cutoffs = [];
    }

    /**
     * Put a row on the queue without asking whether it could have been written.
     *
     * {@see write()} is the method to call; this one is public for the case
     * where a direct insert has already been attempted and failed.
     *
     * @param  string               $table
     * @param  array<string, mixed> $row
     * @param  int                  $timestamp Unix timestamp of the row
     * @return void
     */
    public function defer(string $table, array $row, int $timestamp): void
    {
        $this->db->queryBuilder()->table(static::TABLE)->insert([
            'tablename'  => $table,
            'targetdate' => date('Y-m-d H:i:s', $timestamp),
            'data'       => json_encode($row),
            'status'     => static::STATUS_PENDING,
            'createdat'  => date('Y-m-d H:i:s'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Reporting
    // -------------------------------------------------------------------------

    /**
     * How many rows are waiting.
     *
     * @param  string|null $table Limit to one table, or count them all
     * @return int
     */
    public function pending(?string $table = null): int
    {
        return $this->countByStatus(static::STATUS_PENDING, $table);
    }

    /**
     * How many rows were tried and could not be written.
     *
     * @param  string|null $table Limit to one table, or count them all
     * @return int
     */
    public function failed(?string $table = null): int
    {
        return $this->countByStatus(static::STATUS_FAILED, $table);
    }

    /**
     * Put failed rows back in the queue, to be tried again on the next drain.
     *
     * Failures are kept rather than retried automatically, because a row that
     * fails once — a missing foreign key, a column that no longer exists —
     * usually fails the same way for ever, and a queue that retries it every
     * hour hides the problem instead of showing it. Retrying is therefore a
     * decision somebody makes after fixing the cause.
     *
     * @param  string|null $table Limit to one table
     * @return int How many rows were reset
     */
    public function retryFailed(?string $table = null): int
    {
        $count = $this->failed($table);

        if ($count === 0) {
            return 0;
        }

        $builder = $this->db->queryBuilder()->table(static::TABLE)
            ->where('status', static::STATUS_FAILED);

        if ($table !== null) {
            $builder->where('tablename', $table);
        }

        $builder->update([
            'status'       => static::STATUS_PENDING,
            'errormessage' => null,
            'processedat'  => null,
        ]);

        return $count;
    }

    /**
     * The tables that currently have rows waiting.
     *
     * @return list<string>
     */
    public function tablesWithPendingRows(): array
    {
        $rows = $this->db->queryBuilder()->table(static::TABLE)
            ->select('tablename')
            ->where('status', static::STATUS_PENDING)
            ->groupBy('tablename')
            ->orderBy('tablename')
            ->getAll();

        $tables = [];
        foreach ($rows as $row) {
            $tables[] = (string) $row['tablename'];
        }

        return $tables;
    }

    // -------------------------------------------------------------------------
    // Drain
    // -------------------------------------------------------------------------

    /**
     * Write everything that is waiting, one decompress/compress pair per chunk.
     *
     * @param  string|null   $only     Drain a single table instead of all of them
     * @param  callable|null $reporter function(string $message): void — progress,
     *                                 so a console command can say what is
     *                                 happening during a long run
     * @return array<string, array{chunks: int, inserted: int, failed: int}>
     *         What happened, per table
     */
    public function process(?string $only = null, ?callable $reporter = null): array
    {
        $tables = $only !== null ? [$only] : $this->tablesWithPendingRows();
        $report = [];

        foreach ($tables as $table) {
            $report[$table] = $this->processTable($table, $reporter);
        }

        return $report;
    }

    /**
     * Drain one table.
     *
     * Compressed chunks first, grouped; then whatever is left, which is
     * everything on a database where nothing is compressed — the normal path in
     * development and CI, and the reason this method does not require
     * TimescaleDB to do its job.
     *
     * @param  string        $table
     * @param  callable|null $reporter
     * @return array{chunks: int, inserted: int, failed: int}
     */
    protected function processTable(string $table, ?callable $reporter = null): array
    {
        $stats = ['chunks' => 0, 'inserted' => 0, 'failed' => 0];

        foreach ($this->chunksWithPendingRows($table) as $chunk) {
            $stats['chunks']++;
            $compressed = $this->isCompressed($chunk);

            $this->report(
                $reporter,
                '  chunk ' . $chunk->chunk_schema . '.' . $chunk->chunk_name
                . ($compressed ? ' (compressed)' : '')
            );

            if ($compressed) {
                $this->decompress($chunk);
            }

            try {
                $this->drain(
                    $table,
                    $stats,
                    $reporter,
                    (int) $chunk->range_start_ts,
                    (int) $chunk->range_end_ts
                );
            } finally {
                // A chunk left decompressed because a batch raised is a silent
                // storage regression — it never compresses again on its own,
                // since the policy only ever looks at chunks it has not seen.
                if ($compressed) {
                    $this->recompress($chunk);
                }
            }
        }

        // Rows whose time falls outside every known chunk: nothing to
        // decompress, so they go straight in. On a backend without compression
        // this is where every queued row is handled.
        $this->drain($table, $stats, $reporter);

        return $stats;
    }

    /**
     * Write the pending rows for one table, optionally limited to a time range.
     *
     * Each batch is one transaction. When it raises, the batch is replayed row
     * by row, so that one bad row is marked failed and its five hundred
     * blameless neighbours are still written — the difference between a queue
     * that drains and one that jams behind a single row.
     *
     * @param  array{chunks: int, inserted: int, failed: int} $stats Updated in place
     * @param  int|null $from Unix timestamp, inclusive
     * @param  int|null $to   Unix timestamp, exclusive
     * @return void
     */
    protected function drain(
        string $table,
        array &$stats,
        ?callable $reporter = null,
        ?int $from = null,
        ?int $to = null
    ): void {
        while (true) {
            $rows = $this->pendingRows($table, $from, $to);

            if ($rows === []) {
                return;
            }

            $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);

            try {
                $this->beginBatch();
                foreach ($rows as $row) {
                    $this->insert($table, $this->payloadOf($row));
                }
                $this->commitBatch();

                $this->deleteRows($ids);
                $stats['inserted'] += count($rows);
            } catch (\Throwable $ex) {
                $this->rollbackBatch();
                $this->report($reporter, '    batch failed: ' . $ex->getMessage());
                $this->drainOneByOne($table, $rows, $stats);
            }
        }
    }

    /**
     * Replay a failed batch one row at a time, isolating the row that broke it.
     *
     * @param  list<array<string, mixed>> $rows
     * @param  array{chunks: int, inserted: int, failed: int} $stats Updated in place
     * @return void
     */
    protected function drainOneByOne(string $table, array $rows, array &$stats): void
    {
        foreach ($rows as $row) {
            try {
                $this->insert($table, $this->payloadOf($row));
                $this->deleteRows([(int) $row['id']]);
                $stats['inserted']++;
            } catch (\Throwable $ex) {
                $this->markFailed((int) $row['id'], $ex->getMessage());
                $stats['failed']++;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Take a chunk out of compression so that it accepts writes.
     *
     * @param  object $chunk As reported by {@see chunksWithPendingRows()}
     * @return void
     */
    protected function decompress(object $chunk): void
    {
        $this->db->schema()->decompressChunk(
            (string) $chunk->chunk_schema,
            (string) $chunk->chunk_name
        );
    }

    /**
     * Put a chunk back the way it was found.
     *
     * @param  object $chunk As reported by {@see chunksWithPendingRows()}
     * @return void
     */
    protected function recompress(object $chunk): void
    {
        $this->db->schema()->compressChunk(
            (string) $chunk->chunk_schema,
            (string) $chunk->chunk_name
        );
    }

    /**
     * Open the transaction that wraps one batch.
     *
     * @return void
     */
    protected function beginBatch(): void
    {
        $this->db->startTransaction();
    }

    /**
     * Commit the batch.
     *
     * @return void
     */
    protected function commitBatch(): void
    {
        $this->db->commitTransaction();
    }

    /**
     * Abandon the batch, leaving the rows queued so they can be replayed
     * individually.
     *
     * @return void
     */
    protected function rollbackBatch(): void
    {
        $this->db->rollbackTransaction();
    }

    /**
     * Insert one row into its real table, overwriting a conflict when the
     * registry says the row identifies an existing one.
     *
     * @param  string               $table
     * @param  array<string, mixed> $row
     * @return void
     */
    protected function insert(string $table, array $row): void
    {
        $spec     = HypertableRegistry::spec($table) ?? [];
        $conflict = $spec['conflict'] ?? null;

        $builder = $this->db->queryBuilder()->table($table);

        if (is_array($conflict) && $conflict !== []) {
            $update = $spec['conflict_update'] ?? null;
            if (!is_array($update) || $update === []) {
                // Nothing named: rewrite every column that is not part of the key.
                $update = array_values(
                    array_diff(array_keys($row), $conflict)
                );
            }
            $builder->upsert($row, $conflict, $update);

            return;
        }

        $builder->insert($row);
    }

    /**
     * The chunks of one hypertable that have rows waiting for them.
     *
     * Asking for the chunks that matter, rather than all of them, is what keeps
     * a drain proportional to the backlog instead of to the table's age: a
     * three-year hypertable has hundreds of chunks and a typical backlog touches
     * two.
     *
     * @param  string $table
     * @return array<int, object> Each with chunk_schema, chunk_name,
     *                            is_compressed, range_start_ts, range_end_ts
     */
    protected function chunksWithPendingRows(string $table): array
    {
        if (!$this->hasTimescaleDB()) {
            return [];
        }

        // Raw by necessity: this reads TimescaleDB's own catalog and correlates
        // it with the queue through a range join the builder cannot express.
        $sql = $this->db->prepareQuery(
            'SELECT c.chunk_schema, c.chunk_name, c.is_compressed,'
            . ' EXTRACT(EPOCH FROM c.range_start)::bigint AS range_start_ts,'
            . ' EXTRACT(EPOCH FROM c.range_end)::bigint   AS range_end_ts'
            . ' FROM timescaledb_information.chunks c'
            . ' WHERE c.hypertable_name = %s'
            . ' AND EXISTS ('
            . '   SELECT 1 FROM ' . static::TABLE . ' q'
            . '   WHERE q.tablename = %s AND q.status = %d'
            . '   AND q.targetdate >= c.range_start AND q.targetdate < c.range_end'
            . ' )'
            . ' ORDER BY c.range_start',
            $this->unqualified($table),
            $table,
            static::STATUS_PENDING
        );

        $result = $this->db->query($sql);

        if (!$result || !$result->numRows) {
            return [];
        }

        return array_map(
            static fn(array $row): object => (object) $row,
            $result->fetchAll()
        );
    }

    /**
     * One batch of pending rows, optionally inside a time range.
     *
     * @param  int|null $from Unix timestamp, inclusive
     * @param  int|null $to   Unix timestamp, exclusive
     * @return list<array<string, mixed>>
     */
    protected function pendingRows(string $table, ?int $from, ?int $to): array
    {
        $builder = $this->db->queryBuilder()->table(static::TABLE)
            ->select(['id', 'data'])
            ->where('tablename', $table)
            ->where('status', static::STATUS_PENDING);

        if ($from !== null) {
            $builder->where('targetdate', '>=', date('Y-m-d H:i:s', $from));
        }
        if ($to !== null) {
            $builder->where('targetdate', '<', date('Y-m-d H:i:s', $to));
        }

        return $builder->orderBy('id')->limit(static::BATCH_SIZE)->getAll();
    }

    /**
     * Remove rows that have been written.
     *
     * @param  list<int> $ids
     * @return void
     */
    protected function deleteRows(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->db->queryBuilder()->table(static::TABLE)
            ->whereIn('id', $ids)
            ->delete();
    }

    /**
     * Keep a row that could not be written, with the reason.
     *
     * @param  int    $id
     * @param  string $message
     * @return void
     */
    protected function markFailed(int $id, string $message): void
    {
        $this->db->queryBuilder()->table(static::TABLE)
            ->where('id', $id)
            ->update([
                'status'       => static::STATUS_FAILED,
                'processedat'  => date('Y-m-d H:i:s'),
                'errormessage' => mb_substr($message, 0, 500),
            ]);
    }

    /**
     * Count queue rows in one state.
     *
     * @param  int         $status
     * @param  string|null $table
     * @return int
     */
    protected function countByStatus(int $status, ?string $table): int
    {
        $builder = $this->db->queryBuilder()->table(static::TABLE)
            ->where('status', $status);

        if ($table !== null) {
            $builder->where('tablename', $table);
        }

        return $builder->count();
    }

    /**
     * Read the compression cutoff for one table.
     *
     * @param  string $table
     * @return int|null
     */
    protected function lookupCutoff(string $table): ?int
    {
        if ($this->hasTimescaleDB()) {
            try {
                $live = $this->livePolicyCutoff($table);

                if ($live !== null) {
                    return $live;
                }
            } catch (\Throwable $ex) {
                // An unreadable catalog is not a reason to stop writing; fall
                // through to what the framework declared.
                \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            }
        }

        // No policy on this database, but the framework says there should be
        // one: honour the declaration rather than writing into a chunk that the
        // next policy run would refuse anyway.
        $declared = HypertableRegistry::spec($table)['compress_after'] ?? null;

        if (is_string($declared) && $declared !== '' && $this->hasTimescaleDB()) {
            $cutoff = strtotime('-' . $declared);

            return $cutoff === false ? null : $cutoff;
        }

        return null;
    }

    /**
     * The compression cutoff as the running policy defines it.
     *
     * @param  string $table
     * @return int|null Null when this table has no compression policy
     */
    protected function livePolicyCutoff(string $table): ?int
    {
        // Raw by necessity: TimescaleDB's job catalog keeps the interval inside
        // a JSON config column, and the arithmetic has to happen in the database
        // so that "now" is the database's now.
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP"
                . " - ((config->>'compress_after')::interval)))::bigint AS cutoff"
                . ' FROM timescaledb_information.jobs'
                . ' WHERE hypertable_name = %s'
                . " AND proc_name = 'policy_compression'"
                . " AND config ? 'compress_after'"
                . ' ORDER BY job_id DESC LIMIT 1',
                $this->unqualified($table)
            )
        );

        if ($result && $result->numRows && isset($result->fields['cutoff'])) {
            return (int) $result->fields['cutoff'];
        }

        return null;
    }

    /**
     * Whether this connection can compress anything in the first place.
     *
     * @return bool
     */
    protected function hasTimescaleDB(): bool
    {
        return $this->db->capabilities()->hasTimescaleDB();
    }

    /**
     * The row's time as a unix timestamp.
     *
     * @param  string               $table
     * @param  array<string, mixed> $row
     * @param  int|string|null      $time
     * @return int
     * @throws \InvalidArgumentException When there is nothing to read it from
     */
    protected function timestampOf(string $table, array $row, int|string|null $time): int
    {
        if ($time === null) {
            $column = HypertableRegistry::spec($table)['time_column'] ?? null;
            $time   = is_string($column) ? ($row[$column] ?? null) : null;
        }

        if (is_int($time)) {
            return $time;
        }

        if (is_numeric($time)) {
            return (int) $time;
        }

        if (is_string($time)) {
            $parsed = strtotime($time);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw new \InvalidArgumentException(
            'DeferredWriteQueue: no usable time for a row of "' . $table
            . '". Pass one explicitly, or declare the table\'s time_column in '
            . 'HypertableRegistry and put a value in it.'
        );
    }

    /**
     * The stored row, decoded.
     *
     * @param  array<string, mixed> $queueRow
     * @return array<string, mixed>
     */
    protected function payloadOf(array $queueRow): array
    {
        $data = json_decode((string) $queueRow['data'], true);

        if (!is_array($data) || $data === []) {
            throw new \RuntimeException(
                'DeferredWriteQueue: queue row ' . $queueRow['id']
                . ' carries no usable payload.'
            );
        }

        return $data;
    }

    /**
     * Whether a chunk row reports itself as compressed.
     *
     * PostgreSQL booleans arrive as `true`, `'t'` or `'1'` depending on the
     * driver and the fetch mode, and treating `'t'` as false would skip the
     * decompress and fail every insert into that chunk.
     *
     * @param  object $chunk
     * @return bool
     */
    protected function isCompressed(object $chunk): bool
    {
        $value = $chunk->is_compressed ?? false;

        return $value === true || $value === 't' || $value === '1' || $value === 1;
    }

    /**
     * A logical table name without its schema, which is how TimescaleDB's
     * catalog stores `hypertable_name`.
     *
     * @param  string $table
     * @return string
     */
    protected function unqualified(string $table): string
    {
        $position = strrpos($table, '.');

        return $position === false ? $table : substr($table, $position + 1);
    }

    /**
     * Hand a progress line to the caller, when it asked for them.
     *
     * @param  callable|null $reporter
     * @param  string        $message
     * @return void
     */
    protected function report(?callable $reporter, string $message): void
    {
        if ($reporter !== null) {
            $reporter($message);
        }
    }
}
