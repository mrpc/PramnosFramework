<?php

declare(strict_types=1);

namespace Pramnos\Database\FromMysql;

/**
 * One MySQL value as a move writes it into its PostgreSQL column — the one place the move, the
 * key comparison and the window digest all convert through, so what is verified is what was
 * written.
 *
 * On top of {@see TypeMapper::value()}: double-encoded text repaired in the columns named for it,
 * MySQL's zero date replaced in a column that takes no NULL when a replacement is given, and the
 * NUL bytes text loses on the way counted. Each of those is counted in the caller's stats.
 */
final class RowConverter
{
    /**
     * @param string                             $zone     The zone the source's `datetime` values were written in
     * @param array<string, array<string, true>> $repair   Columns whose double-encoded values are repaired, by table
     * @param string|null                        $zeroDate What a zero date becomes in a column that takes no NULL; null leaves it NULL
     */
    public function __construct(
        private string $zone = 'UTC',
        private array $repair = [],
        private ?string $zeroDate = null,
    ) {
    }

    /** Whether a table has columns to repair — which only PHP can do. */
    public function repairs(string $table): bool
    {
        return ($this->repair[$table] ?? []) !== [];
    }

    /**
     * The value as the target column takes it.
     *
     * @param array<string, int> $stats `nulled`, `repaired`, `zeroDates`, `nulBytes` — counted where present
     */
    public function value(string $table, string $column, mixed $raw, string $mysqlType, string $pgType, bool $notNull, array &$stats): mixed
    {
        if (is_string($raw) && isset($this->repair[$table][$column]) && ($repaired = TypeMapper::repairEncoding($raw)) !== null) {
            $raw = $repaired;
            self::count($stats, 'repaired');
        }
        $pg = strtolower($pgType);
        if ($this->zeroDate !== null && $notNull && is_string($raw) && preg_match('/^0000-00-00/', $raw) === 1
            && (str_starts_with($pg, 'timestamp') || $pg === 'date')) {
            $raw = $this->zeroDate;
            self::count($stats, 'zeroDates');
        }
        $value = TypeMapper::value($raw, $mysqlType, $pgType, $refused, $this->zone);
        if ($refused) {
            self::count($stats, 'nulled');
        }
        // PostgreSQL text takes no NUL byte, and the mapper drops it: said, not silent.
        if (is_string($raw) && is_string($value) && $pg !== 'bytea' && str_contains($raw, "\0") && !str_contains($value, "\0")) {
            self::count($stats, 'nulBytes');
        }

        return $value;
    }

    /** @param array<string, int> $stats */
    private static function count(array &$stats, string $what): void
    {
        $stats[$what] = ($stats[$what] ?? 0) + 1;
    }
}
