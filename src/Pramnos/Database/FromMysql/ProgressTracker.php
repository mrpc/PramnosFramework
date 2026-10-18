<?php

declare(strict_types=1);

namespace Pramnos\Database\FromMysql;

use Pramnos\Database\Database;

/**
 * Where a move from MySQL stands, kept in the target: `pramnos_migration_progress`.
 *
 * One row per table, phase and window: the operational copy's moment (T0) and highest id per
 * table, the cutover's completion, each backfill window's rows and state. A run that stops picks
 * up from here; a window marked done is not read again.
 *
 * Moments are Unix times (`chunk_start`, `chunk_end`), so a window means the same whatever the
 * column it was cut on — a `datetime`, a `timestamptz`, a unix time in an `int`.
 */
final class ProgressTracker
{
    public const TABLE = 'pramnos_migration_progress';

    public const DONE = 'COMPLETED';

    public const RUNNING = 'IN_PROGRESS';

    public function __construct(private Database $target)
    {
    }

    /** The table, created on first use: it belongs to a move, not to the application. */
    public function ensure(): void
    {
        $this->target->query('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'table_name VARCHAR(190) NOT NULL, phase VARCHAR(32) NOT NULL, chunk_start BIGINT NOT NULL, chunk_end BIGINT NOT NULL,'
            . ' rows_migrated BIGINT NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL, detail TEXT, updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),'
            . ' PRIMARY KEY (table_name, phase, chunk_start))');
    }

    /**
     * Record a table's state in a phase, for a window starting at `$start`.
     *
     * @param array<string, mixed> $detail
     */
    public function put(string $table, string $phase, int $start, int $end, int $rows, string $status, array $detail = []): void
    {
        $this->ensure();
        $result = @pg_query_params(
            $this->target->getConnectionLink(),
            'INSERT INTO ' . self::TABLE . ' (table_name, phase, chunk_start, chunk_end, rows_migrated, status, detail, updated_at) VALUES ($1, $2, $3, $4, $5, $6, $7, NOW())'
            . ' ON CONFLICT (table_name, phase, chunk_start) DO UPDATE SET chunk_end = EXCLUDED.chunk_end, rows_migrated = EXCLUDED.rows_migrated,'
            . ' status = EXCLUDED.status, detail = EXCLUDED.detail, updated_at = NOW()',
            [$table, $phase, $start, $end, $rows, $status, json_encode($detail)]
        );
        if ($result === false) {
            throw new \RuntimeException('Could not record progress: ' . pg_last_error($this->target->getConnectionLink()));
        }
    }

    /**
     * A table's rows in a phase, by window start.
     *
     * @return array<int, array{start: int, end: int, rows: int, status: string, detail: array<string, mixed>}>
     */
    public function of(string $table, string $phase): array
    {
        $this->ensure();
        $result = @pg_query_params(
            $this->target->getConnectionLink(),
            'SELECT chunk_start, chunk_end, rows_migrated, status, detail FROM ' . self::TABLE . ' WHERE table_name = $1 AND phase = $2 ORDER BY chunk_start',
            [$table, $phase]
        );
        $rows = [];
        while ($result !== false && ($row = pg_fetch_assoc($result)) !== false) {
            $rows[(int) $row['chunk_start']] = [
                'start'  => (int) $row['chunk_start'],
                'end'    => (int) $row['chunk_end'],
                'rows'   => (int) $row['rows_migrated'],
                'status' => (string) $row['status'],
                'detail' => (array) json_decode((string) $row['detail'], true),
            ];
        }

        return $rows;
    }

    /** Forget a table's rows in a phase: a restart. */
    public function forget(string $table, string $phase): void
    {
        $this->ensure();
        @pg_query_params($this->target->getConnectionLink(), 'DELETE FROM ' . self::TABLE . ' WHERE table_name = $1 AND phase = $2', [$table, $phase]);
    }

    /** The latest moment any table reached in a phase, or null when none has. */
    public function latest(string $phase): ?int
    {
        $this->ensure();
        $result = @pg_query_params($this->target->getConnectionLink(), 'SELECT MAX(chunk_end) AS m FROM ' . self::TABLE . ' WHERE phase = $1 AND status = $2', [$phase, self::DONE]);
        $row    = $result !== false ? pg_fetch_assoc($result) : false;

        return $row !== false && $row['m'] !== null ? (int) $row['m'] : null;
    }
}
