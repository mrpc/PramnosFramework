<?php

declare(strict_types=1);

namespace Pramnos\Email;

use Pramnos\Database\Database;

/**
 * `mails` as a TimescaleDB hypertable — partitioned by its `date`, its old chunks compressed —
 * reached without stopping the site.
 *
 * ```php
 * $mails = new MailHypertable($db);
 * $mails->state();     // unsupported | plain | draining | hypertable
 * $mails->convert();   // milliseconds: the table is renamed and its successor made
 * $mails->drain();     // the rows moved into it, newest first, in batches
 * ```
 *
 * `php bin/pramnos mail:hypertable` does both. A mail log is the table that grows without limit,
 * and on TimescaleDB its old months compress to a few percent and statistics over a period read
 * only that period's chunks. Plain PostgreSQL and MySQL keep the table they have.
 *
 * ## How it gets there
 *
 * 1. **Convert**, in one transaction that only touches the catalogue: `mails` becomes
 *    `mails_legacy`, and a new `mails` is made like it — the same columns and defaults, the id
 *    sequence kept (a serial id) or numbered on from the old table's highest (an identity id),
 *    `date` widened to `bigint` (so it outlives 2038), the primary key `(id, date)` as a
 *    hypertable needs, monthly chunks, compression after ninety days. From this moment new mail is
 *    written to the new table.
 * 2. **Drain**: the rows move a batch at a time, each batch deleted from the old table and
 *    inserted into the new one in one statement, so a row is never in both and a run cut short is
 *    finished by the next. **Newest first**, so the history screens fill from today backwards
 *    rather than showing years ago first. When the old table is empty it is dropped.
 *
 * `date` stays a Unix timestamp: every reader and writer — the framework's and an application's —
 * goes on comparing it with `time()`. While the drain runs, history not moved yet is not in
 * `mails`; {@see MailPersonalData} and {@see Retention} reach the old table too, so an erasure or a
 * policy run then misses nothing.
 */
final class MailHypertable
{
    /** Rows moved per batch, unless told otherwise. */
    public const BATCH = 10000;

    /** A chunk's length, in seconds: a month. */
    public const CHUNK = 2592000;

    /** How old a chunk is before it is compressed, in seconds: ninety days. */
    public const COMPRESS_AFTER = 7776000;

    /** `state()`: the database has no TimescaleDB, or is not PostgreSQL. */
    public const UNSUPPORTED = 'unsupported';

    /** `state()`: an ordinary table. */
    public const PLAIN = 'plain';

    /** `state()`: converted, rows still in the old table. */
    public const DRAINING = 'draining';

    /** `state()`: a hypertable with nothing left to move. */
    public const HYPERTABLE = 'hypertable';

    /**
     * @param Database $db    The connection
     * @param string   $table The table, unprefixed
     */
    public function __construct(private Database $db, private string $table = 'mails')
    {
    }

    /** Where the table is on its way. */
    public function state(): string
    {
        if ($this->db->type !== 'postgresql' || !$this->db->capabilities()->hasTimescaleDB()) {
            return self::UNSUPPORTED;
        }
        if ($this->db->schema()->hasTable($this->legacy())) {
            return self::DRAINING;
        }
        // Raw: the TimescaleDB catalogue.
        $result = $this->db->query(
            "SELECT 1 AS yes FROM timescaledb_information.hypertables WHERE hypertable_name = '" . $this->name() . "'"
        );

        return $result && $result->numRows > 0 ? self::HYPERTABLE : self::PLAIN;
    }

    /** How many rows the old table still holds. */
    public function pending(): int
    {
        return $this->db->schema()->hasTable($this->legacy())
            ? $this->db->queryBuilder()->table($this->legacy())->count()
            : 0;
    }

    /**
     * Rename the table and make its successor, in one transaction that touches only the catalogue.
     *
     * @throws \RuntimeException when the table is not an ordinary one on TimescaleDB
     */
    public function convert(): void
    {
        if (($state = $this->state()) !== self::PLAIN) {
            throw new \RuntimeException('`' . $this->name() . '` cannot be converted: it is ' . $state . '.');
        }
        $table  = $this->quoted($this->name());
        $legacy = $this->quoted($this->legacy());
        // The indexes the old table has, to give the new one, and to rename out of its way.
        $indexes = $this->db->query(
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = '" . $this->name() . "'"
        )->fetchAll();
        $sequence = (string) ($this->db->query("SELECT pg_get_serial_sequence('" . $this->name() . "', 'id') AS seq")->fields['seq'] ?? '');
        // An identity column — what `db:migrate-from-mysql` makes — rather than a serial one.
        $identity = (string) ($this->db->query(
            "SELECT is_identity FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '" . $this->name() . "' AND column_name = 'id'"
        )->fields['is_identity'] ?? '') === 'YES';

        // Raw throughout: DDL, and TimescaleDB's own functions.
        $this->db->startTransaction();
        try {
            $this->db->query('ALTER TABLE ' . $table . ' RENAME TO ' . $legacy);
            foreach ($indexes as $index) {
                $this->db->query('ALTER INDEX ' . $this->quoted($index['indexname']) . ' RENAME TO ' . $this->quoted(substr($index['indexname'], 0, 50) . '_legacy'));
            }
            $this->db->query('CREATE TABLE ' . $table . ' (LIKE ' . $legacy . ' INCLUDING DEFAULTS INCLUDING COMMENTS INCLUDING STORAGE'
                . ($identity ? ' INCLUDING IDENTITY' : '') . ')');
            $this->db->query('ALTER TABLE ' . $table . ' ALTER COLUMN date TYPE bigint');
            if ($identity) {
                /*
                 * An identity's sequence is the column's own and cannot be handed to another
                 * table, so `OWNED BY` below refused it ("cannot change ownership of identity
                 * sequence") and the conversion rolled back. The new column has an identity of its
                 * own instead, numbered on from the old table's highest id — read here, under the
                 * rename's lock, so no new mail can take an id a row still waiting to move has.
                 */
                $next = (int) ($this->db->query('SELECT COALESCE(MAX(id), 0) + 1 AS next FROM ' . $legacy)->fields['next'] ?? 1);
                $this->db->query('ALTER TABLE ' . $table . ' ALTER COLUMN id RESTART WITH ' . max(1, $next));
                /*
                 * …and it takes the old sequence's name. A connection remembers which sequence
                 * answers `getInsertId()` for this table, by name: a mail worker running through the
                 * conversion would otherwise go on reading the old table's sequence — "not yet
                 * defined in this session", then "does not exist" once the drain drops it.
                 */
                $fresh = (string) ($this->db->query("SELECT pg_get_serial_sequence('" . $this->name() . "', 'id') AS seq")->fields['seq'] ?? '');
                if ($sequence !== '' && $fresh !== '' && $fresh !== $sequence) {
                    $bare = static fn (string $qualified): string => str_replace('""', '"', trim((string) preg_replace('/^.*\.(?=(?:[^"]|"[^"]*")*$)/', '', $qualified), '"'));
                    $this->db->query('ALTER SEQUENCE ' . $sequence . ' RENAME TO ' . $this->quoted(substr($this->legacy(), 0, 50) . '_id_seq'));
                    $this->db->query('ALTER SEQUENCE ' . $fresh . ' RENAME TO ' . $this->quoted($bare($sequence)));
                }
            } elseif ($sequence !== '') {
                // The id sequence belongs to the new table: dropping the old one must not take it.
                $this->db->query('ALTER SEQUENCE ' . $sequence . ' OWNED BY ' . $table . '.id');
            }
            $this->db->query('ALTER TABLE ' . $table . ' ADD PRIMARY KEY (id, date)');
            $this->db->query("SELECT create_hypertable('" . $this->name() . "', 'date', chunk_time_interval => " . self::CHUNK . ', create_default_indexes => false)');
            // Read before the renames, so each definition names the new table and a free name.
            foreach ($indexes as $index) {
                if (str_ends_with($index['indexname'], '_pkey')) {
                    continue;
                }
                // The outbox reads by status and date: one index for both instead of status alone.
                $this->db->query(preg_match('/\(status\)$/', $index['indexdef']) === 1
                    ? 'CREATE INDEX ' . $this->quoted($index['indexname']) . ' ON ' . $table . ' (status, date)'
                    : $index['indexdef']);
            }
            // A Unix-timestamp partition needs to be told what "now" is, to compress by age.
            $this->db->query('CREATE OR REPLACE FUNCTION pf_unix_now() RETURNS bigint LANGUAGE SQL STABLE AS $$ SELECT extract(epoch FROM now())::bigint $$');
            $this->db->query("SELECT set_integer_now_func('" . $this->name() . "', 'pf_unix_now')");
            $this->db->query('ALTER TABLE ' . $table . " SET (timescaledb.compress, timescaledb.compress_orderby = 'date DESC, id DESC')");
            $this->db->query("SELECT add_compression_policy('" . $this->name() . "', compress_after => " . self::COMPRESS_AFTER . '::bigint)');
            $this->db->commitTransaction();
        } catch (\Throwable $e) {
            $this->db->rollbackTransaction();
            throw $e;
        }
    }

    /**
     * Move the old table's rows into the new one, newest first, and drop it once it is empty.
     *
     * @param int                     $batch    Rows a statement moves
     * @param int                     $limit    Stop after this many (0: all)
     * @param callable(int, int)|null $progress Told the rows moved so far and those left, each batch
     * @return int How many rows were moved
     */
    public function drain(int $batch = self::BATCH, int $limit = 0, ?callable $progress = null): int
    {
        if (!$this->db->schema()->hasTable($this->legacy())) {
            return 0;
        }
        $table   = $this->quoted($this->name());
        $legacy  = $this->quoted($this->legacy());
        $columns = implode(', ', array_map(fn (string $column): string => $this->quoted($column), $this->columns()));
        $moved   = 0;

        // With the new table's compression paused: the chunk the move is filling must not be
        // compressed under it, and every chunk is compressed once, when the move is done.
        $this->db->schema()->withCompressionPaused('#PREFIX#' . $this->table, function () use ($batch, $limit, $progress, $table, $legacy, $columns, &$moved): void {
            while ($limit === 0 || $moved < $limit) {
                $size = $limit === 0 ? $batch : min($batch, $limit - $moved);
                // Raw: a data-modifying CTE, which the query builder cannot express. One statement,
                // so a row is moved or it is not — never copied twice, never lost.
                $result = $this->db->query(
                    'WITH moved AS (DELETE FROM ' . $legacy . ' WHERE id IN (SELECT id FROM ' . $legacy . ' ORDER BY id DESC LIMIT ' . max(1, $size) . ') RETURNING ' . $columns . ') '
                    // OVERRIDING SYSTEM VALUE: a row keeps its id, also under `GENERATED ALWAYS`.
                    . 'INSERT INTO ' . $table . ' (' . $columns . ') OVERRIDING SYSTEM VALUE SELECT ' . $columns . ' FROM moved'
                );
                $count = $result ? (int) $result->getAffectedRows() : 0;
                $moved += $count;
                if ($progress !== null) {
                    $progress($moved, $this->pending());
                }
                if ($count < $size) {
                    break;
                }
            }
        });
        if ($this->pending() === 0) {
            $this->db->query('DROP TABLE ' . $legacy);
        }

        return $moved;
    }

    /**
     * The tables the mail log is in now, for a query builder: `mails`, and while a drain runs the
     * old table too — what reaches every message, an erasure or a retention policy, reads both.
     *
     * @return list<string>
     */
    public static function tables(?Database $db = null): array
    {
        $db ??= \Pramnos\Framework\Factory::getDatabase();

        return $db->type === 'postgresql' && $db->schema()->hasTable('mails_legacy')
            ? ['#PREFIX#mails', '#PREFIX#mails_legacy']
            : ['#PREFIX#mails'];
    }

    /** The old table while the drain runs; its name otherwise. */
    public function legacy(): string
    {
        return $this->name() . '_legacy';
    }

    /** The table's name, with the installation's prefix. */
    private function name(): string
    {
        return $this->db->prefix . $this->table;
    }

    /** The columns both tables have, in the new table's order. @return list<string> */
    private function columns(): array
    {
        $result = $this->db->query(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '" . $this->name()
            . "' AND column_name IN (SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = '"
            . $this->legacy() . "') ORDER BY ordinal_position"
        );

        return array_map(static fn (array $row): string => (string) $row['column_name'], $result ? $result->fetchAll() : []);
    }

    /** An identifier, quoted. */
    private function quoted(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }
}
