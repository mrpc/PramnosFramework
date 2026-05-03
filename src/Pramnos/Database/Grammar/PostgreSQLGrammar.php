<?php

namespace Pramnos\Database\Grammar;

use Pramnos\Database\QueryBuilder;

/**
 * PostgreSQL 12+ SQL grammar.
 *
 * - Identifier quoting: double-quote
 * - Conflict handling: ON CONFLICT DO NOTHING / DO UPDATE SET
 * - RETURNING clause on INSERT / UPDATE / DELETE
 * - LIKE / ILIKE on non-text columns requires a ::text cast
 *
 * @package     PramnosFramework
 * @subpackage  Database\Grammar
 */
class PostgreSQLGrammar extends Grammar
{
    private const LIKE_OPS = ['LIKE', 'ILIKE', 'NOT LIKE', 'NOT ILIKE'];

    public function quoteColumn(string $column): string
    {
        return '"' . $column . '"';
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    protected function compileReturning(QueryBuilder $qb): string
    {
        $returning = $qb->getReturning();
        return empty($returning) ? '' : ' RETURNING ' . implode(', ', $returning);
    }

    protected function wrapColumnForOperator(string $column, string $operator): string
    {
        if (in_array(strtoupper($operator), self::LIKE_OPS, true)) {
            return $column . '::text';
        }
        return $column;
    }

    // -------------------------------------------------------------------------
    // Conflict handling
    // -------------------------------------------------------------------------

    public function compileInsertOrIgnore(QueryBuilder $qb, array $values): string
    {
        $quotedCols   = array_map(fn($c) => $this->quoteColumn($c), array_keys($values));
        $placeholders = array_map(fn($v) => $this->getPlaceholder($v), array_values($values));

        $sql = 'INSERT INTO ' . $qb->getFrom()
            . ' (' . implode(', ', $quotedCols) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')'
            . ' ON CONFLICT DO NOTHING';

        return $sql . $this->compileReturning($qb);
    }

    public function compileUpsert(QueryBuilder $qb, array $values, array $conflictColumns, array $updateValues): string
    {
        $quotedCols   = array_map(fn($c) => $this->quoteColumn($c), array_keys($values));
        $placeholders = array_map(fn($v) => $this->getPlaceholder($v), array_values($values));

        $quotedConflict = array_map(fn($c) => $this->quoteColumn($c), $conflictColumns);

        $sql = 'INSERT INTO ' . $qb->getFrom()
            . ' (' . implode(', ', $quotedCols) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')'
            . ' ON CONFLICT (' . implode(', ', $quotedConflict) . ')';

        if (empty($updateValues)) {
            $sql .= ' DO NOTHING';
        } else {
            $sets = array_map(
                fn($col) => $this->quoteColumn($col) . ' = EXCLUDED.' . $this->quoteColumn($col),
                $updateValues
            );
            $sql .= ' DO UPDATE SET ' . implode(', ', $sets);
        }

        return $sql . $this->compileReturning($qb);
    }
}
