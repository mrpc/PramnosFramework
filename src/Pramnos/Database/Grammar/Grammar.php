<?php

namespace Pramnos\Database\Grammar;

use Pramnos\Database\Expression;
use Pramnos\Database\QueryBuilder;

/**
 * Base Grammar — dialect-neutral SQL compilation with template-method hooks.
 *
 * Concrete grammars override the abstract methods (quoteColumn,
 * compileInsertOrIgnore, compileUpsert) and the protected hooks
 * (compileReturning, wrapColumnForOperator) where dialects diverge.
 *
 */
abstract class Grammar implements GrammarInterface
{
    // -------------------------------------------------------------------------
    // Abstract — must be provided by every concrete grammar
    // -------------------------------------------------------------------------

    abstract public function quoteColumn(string $column): string;
    abstract public function compileInsertOrIgnore(QueryBuilder $qb, array $values): string;
    abstract public function compileUpsert(QueryBuilder $qb, array $values, array $conflictColumns, array $updateValues): string;

    // -------------------------------------------------------------------------
    // Template-method hooks (override in dialect subclasses as needed)
    // -------------------------------------------------------------------------

    /**
     * Return a RETURNING clause string (space-prefixed) when the QB has
     * returning columns set, or an empty string for dialects without RETURNING.
     */
    protected function compileReturning(QueryBuilder $qb): string
    {
        return '';
    }

    /**
     * Optionally modify a column reference for a specific operator.
     * Used by PostgreSQLGrammar to add ::text casts on LIKE/ILIKE.
     */
    protected function wrapColumnForOperator(string $column, string $operator): string
    {
        return $column;
    }

    /**
     * Compile a row-locking suffix for SELECT statements.
     * Returns empty string for dialects that don't support locking reads or
     * when no lock is requested.
     */
    protected function compileLock(QueryBuilder $qb): string
    {
        return '';
    }

    /**
     * Compile a date-part extraction expression for a WHERE DatePart clause.
     *
     * Default (MySQL) implementation.  Override in dialect subclasses.
     *
     * @param  string $part    One of: date, year, month, day, time
     * @param  string $column  Column name or expression
     * @return string          SQL expression fragment
     */
    protected function compileDatePartExtraction(string $part, string $column): string
    {
        return match (strtolower($part)) {
            'year'  => "YEAR({$column})",
            'month' => "MONTH({$column})",
            'day'   => "DAY({$column})",
            'time'  => "TIME({$column})",
            default => "DATE({$column})",
        };
    }

    // -------------------------------------------------------------------------
    // Placeholder
    // -------------------------------------------------------------------------

    public function getPlaceholder($value): string
    {
        if ($value instanceof Expression) return (string)$value;
        if (is_int($value))              return '%i';
        if (is_float($value))            return '%d';
        if (is_bool($value))             return '%b';
        return '%s';
    }

    // -------------------------------------------------------------------------
    // CTEs
    // -------------------------------------------------------------------------

    public function compileCtes(QueryBuilder $qb): string
    {
        $ctes = $qb->getCtes();
        if (empty($ctes)) {
            return '';
        }

        $hasRecursive = array_filter($ctes, fn($c) => $c['recursive']);
        $keyword      = !empty($hasRecursive) ? 'WITH RECURSIVE' : 'WITH';

        $parts = array_map(
            fn($c) => $c['name'] . ' AS (' . $c['sql'] . ')',
            $ctes
        );

        return $keyword . ' ' . implode(', ', $parts) . ' ';
    }

    // -------------------------------------------------------------------------
    // SELECT
    // -------------------------------------------------------------------------

    public function compileSelect(QueryBuilder $qb): string
    {
        $prefix = $this->compileCtes($qb);

        $sql = 'SELECT ';
        if ($qb->isDistinct()) {
            $sql .= 'DISTINCT ';
        }
        $sql .= implode(', ', $qb->getColumns());
        $sql .= ' FROM ' . $qb->getFrom();

        foreach ($qb->getJoins() as $join) {
            if (isset($join['type']) && $join['type'] === 'Raw') {
                $sql .= ' ' . $join['sql'];
            } elseif (strtolower($join['type']) === 'cross') {
                $sql .= ' CROSS JOIN ' . $join['table'];
            } else {
                $sql .= ' ' . strtoupper($join['type']) . ' JOIN '
                    . $join['table'] . ' ON '
                    . $join['first'] . ' ' . $join['operator'] . ' ' . $join['second'];
            }
        }

        $wheres = $qb->getWheres();
        if (!empty($wheres)) {
            $sql .= ' WHERE ' . $this->compileWheres($qb);
        }

        $groups = $qb->getGroups();
        if (!empty($groups)) {
            $sql .= ' GROUP BY ' . implode(', ', $groups);
        }

        $havings = $qb->getHavings();
        if (!empty($havings)) {
            $sql .= ' HAVING ' . $this->compileHavings($qb);
        }

        $orders = $qb->getOrders();
        if (!empty($orders)) {
            $orderParts = [];
            foreach ($orders as $order) {
                if (isset($order['type']) && $order['type'] === 'Raw') {
                    $orderParts[] = $order['sql'];
                } else {
                    $orderParts[] = $order['column'] . ' ' . strtoupper($order['direction']);
                }
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        $limit = $qb->getLimit();
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int)$limit;
        }

        $offset = $qb->getOffset();
        if ($offset !== null) {
            $sql .= ' OFFSET ' . (int)$offset;
        }

        $sql .= $this->compileLock($qb);

        foreach ($qb->getUnions() as $union) {
            $sql .= ' ' . ($union['all'] ? 'UNION ALL' : 'UNION') . ' '
                . $this->compileSelect($union['query']);
        }

        return $prefix . $sql;
    }

    public function compileWheres(QueryBuilder $qb): string
    {
        $parts = [];
        foreach ($qb->getWheres() as $where) {
            $part = '';
            if (!empty($parts)) {
                $part .= strtoupper($where['boolean']) . ' ';
            }

            switch ($where['type']) {
                case 'Basic':
                    $col = $this->wrapColumnForOperator($where['column'], $where['operator']);
                    $part .= $col . ' ' . $where['operator'] . ' ' . $this->getPlaceholder($where['value']);
                    break;

                case 'In':
                case 'NotIn':
                    $placeholders   = array_map(fn($v) => $this->getPlaceholder($v), $where['values']);
                    $operator       = $where['type'] === 'In' ? 'IN' : 'NOT IN';
                    $part .= $where['column'] . ' ' . $operator . ' (' . implode(', ', $placeholders) . ')';
                    break;

                case 'Null':
                    $part .= $where['column'] . ' IS NULL';
                    break;

                case 'NotNull':
                    $part .= $where['column'] . ' IS NOT NULL';
                    break;

                case 'Between':
                    $part .= $where['column'] . ' BETWEEN '
                        . $this->getPlaceholder($where['values'][0])
                        . ' AND '
                        . $this->getPlaceholder($where['values'][1]);
                    break;

                case 'NotBetween':
                    $part .= $where['column'] . ' NOT BETWEEN '
                        . $this->getPlaceholder($where['values'][0])
                        . ' AND '
                        . $this->getPlaceholder($where['values'][1]);
                    break;

                case 'Nested':
                    $part .= '(' . $this->compileWheres($where['query']) . ')';
                    break;

                case 'Raw':
                    $part .= $where['sql'];
                    break;

                case 'Exists':
                    $part .= 'EXISTS (' . $this->compileSelect($where['sub']) . ')';
                    break;

                case 'NotExists':
                    $part .= 'NOT EXISTS (' . $this->compileSelect($where['sub']) . ')';
                    break;

                case 'DatePart':
                    $expr = $this->compileDatePartExtraction($where['part'], $where['column']);
                    $part .= $expr . ' ' . $where['operator'] . ' ' . $this->getPlaceholder($where['value']);
                    break;
            }
            $parts[] = $part;
        }
        return implode(' ', $parts);
    }

    public function compileHavings(QueryBuilder $qb): string
    {
        $parts = [];
        foreach ($qb->getHavings() as $having) {
            $part = '';
            if (!empty($parts)) {
                $part .= strtoupper($having['boolean']) . ' ';
            }
            if (isset($having['type']) && $having['type'] === 'Raw') {
                $part .= $having['sql'];
            } else {
                $part .= $having['column'] . ' ' . $having['operator'] . ' ' . $this->getPlaceholder($having['value']);
            }
            $parts[] = $part;
        }
        return implode(' ', $parts);
    }

    // -------------------------------------------------------------------------
    // INSERT
    // -------------------------------------------------------------------------

    public function compileInsert(QueryBuilder $qb, array $values): string
    {
        $quotedCols   = array_map(fn($c) => $this->quoteColumn($c), array_keys($values));
        $placeholders = array_map(fn($v) => $this->getPlaceholder($v), array_values($values));

        $sql = 'INSERT INTO ' . $qb->getFrom()
            . ' (' . implode(', ', $quotedCols) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        return $sql . $this->compileReturning($qb);
    }

    // -------------------------------------------------------------------------
    // UPDATE
    // -------------------------------------------------------------------------

    public function compileUpdate(QueryBuilder $qb, array $values): string
    {
        $sets = [];
        foreach ($values as $column => $value) {
            $sets[] = $this->quoteColumn($column) . ' = ' . $this->getPlaceholder($value);
        }

        $sql = 'UPDATE ' . $qb->getFrom() . ' SET ' . implode(', ', $sets);

        $wheres = $qb->getWheres();
        if (!empty($wheres)) {
            $sql .= ' WHERE ' . $this->compileWheres($qb);
        }

        return $sql . $this->compileReturning($qb);
    }

    // -------------------------------------------------------------------------
    // DELETE
    // -------------------------------------------------------------------------

    public function compileDelete(QueryBuilder $qb): string
    {
        $sql = 'DELETE FROM ' . $qb->getFrom();

        $wheres = $qb->getWheres();
        if (!empty($wheres)) {
            $sql .= ' WHERE ' . $this->compileWheres($qb);
        }

        return $sql . $this->compileReturning($qb);
    }

    // -------------------------------------------------------------------------
    // TRUNCATE
    // -------------------------------------------------------------------------

    public function compileTruncate(QueryBuilder $qb): string
    {
        return 'TRUNCATE TABLE ' . $qb->getFrom();
    }

    // -------------------------------------------------------------------------
    // Time-bucket (MySQL / default implementation)
    // -------------------------------------------------------------------------

    /**
     * MySQL time-bucket via UNIX_TIMESTAMP arithmetic for sub-month intervals,
     * and DATE_FORMAT for month/year truncation.
     *
     * {@inheritdoc}
     */
    public function compileTimeBucket(string $interval, string $column): string
    {
        $parsed = self::parseInterval($interval);

        if ($parsed === null) {
            return "DATE({$column})";
        }

        ['count' => $count, 'unit' => $unit] = $parsed;

        // Month / year: use DATE_FORMAT (can't do UNIX arithmetic across months)
        if ($unit === 'year') {
            return "DATE_FORMAT({$column}, '%Y-01-01')";
        }
        if ($unit === 'month') {
            return "DATE_FORMAT({$column}, '%Y-%m-01')";
        }

        // All sub-month intervals: map to seconds and use UNIX_TIMESTAMP arithmetic
        $seconds = self::unitToSeconds($unit, $count);
        if ($seconds === null) {
            return "DATE({$column})";
        }

        return "FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP({$column}) / {$seconds}) * {$seconds})";
    }

    // -------------------------------------------------------------------------
    // Window functions
    // -------------------------------------------------------------------------

    /**
     * Compile a window function OVER clause.
     *
     * The partition and order columns are quoted using the dialect's quoteColumn()
     * so backtick / double-quote quoting is handled automatically.
     *
     * @param  string   $fn        Raw function call, e.g. 'RANK()', 'ROW_NUMBER()'
     * @param  string[] $partition Column names for PARTITION BY
     * @param  array    $order     Assoc ['col'=>'dir',...] or indexed ['col1','col2',...]
     * @param  string   $frame     Optional ROWS/RANGE frame clause
     * @return string
     */
    public function compileWindowOver(string $fn, array $partition, array $order, string $frame): string
    {
        $clauses = [];

        if (!empty($partition)) {
            $quoted    = array_map(fn(string $c) => $this->quoteColumn($c), $partition);
            $clauses[] = 'PARTITION BY ' . implode(', ', $quoted);
        }

        if (!empty($order)) {
            $parts = [];
            foreach ($order as $col => $dir) {
                if (is_int($col)) {
                    // Indexed: $dir is the column name, direction defaults to ASC
                    $parts[] = $this->quoteColumn($dir);
                } else {
                    $parts[] = $this->quoteColumn($col) . ' ' . strtoupper($dir);
                }
            }
            $clauses[] = 'ORDER BY ' . implode(', ', $parts);
        }

        if ($frame !== '') {
            $clauses[] = $frame;
        }

        return $fn . ' OVER (' . implode(' ', $clauses) . ')';
    }

    // =========================================================================
    // Interval helpers (shared across grammars)
    // =========================================================================

    /**
     * Parse an interval string such as "1 hour", "15 minutes", "2 days" into
     * an array ['count' => int, 'unit' => string] where unit is the canonical
     * singular lowercase form (second, minute, hour, day, week, month, year).
     *
     * Returns null for unrecognised formats.
     *
     * @param  string $interval
     * @return array{count:int,unit:string}|null
     */
    protected static function parseInterval(string $interval): ?array
    {
        $interval = trim(strtolower($interval));

        if (!preg_match('/^(\d+)\s+(second|minute|hour|day|week|month|year)s?$/', $interval, $m)) {
            return null;
        }

        return ['count' => (int)$m[1], 'unit' => $m[2]];
    }

    /**
     * Convert a count + unit to total seconds.  Returns null for calendar units
     * (month, year) that have variable length.
     *
     * @param  string $unit   canonical singular unit (second, minute, …, week)
     * @param  int    $count
     * @return int|null
     */
    protected static function unitToSeconds(string $unit, int $count): ?int
    {
        $multipliers = [
            'second' => 1,
            'minute' => 60,
            'hour'   => 3600,
            'day'    => 86400,
            'week'   => 604800,
        ];

        if (!isset($multipliers[$unit])) {
            return null;
        }

        return $multipliers[$unit] * $count;
    }

    /**
     * Map a count+unit to the precision string required by DATE_TRUNC (PostgreSQL).
     * Only returns a precision for intervals that correspond to an exact single-unit
     * truncation (count = 1 and unit is one of PG's supported precisions).
     *
     * @param  int    $count
     * @param  string $unit
     * @return string|null
     */
    protected static function unitToDateTruncPrecision(int $count, string $unit): ?string
    {
        if ($count !== 1) {
            return null;
        }

        $map = [
            'second' => 'second',
            'minute' => 'minute',
            'hour'   => 'hour',
            'day'    => 'day',
            'week'   => 'week',
            'month'  => 'month',
            'year'   => 'year',
        ];

        return $map[$unit] ?? null;
    }
}
