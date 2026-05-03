<?php

namespace Pramnos\Database\Grammar;

use Pramnos\Database\QueryBuilder;

/**
 * MySQL 5.7+ / 8.0 SQL grammar.
 *
 * - Identifier quoting: backtick
 * - Conflict handling: INSERT IGNORE, ON DUPLICATE KEY UPDATE
 *
 * @package     PramnosFramework
 * @subpackage  Database\Grammar
 */
class MySQLGrammar extends Grammar
{
    public function quoteColumn(string $column): string
    {
        return '`' . $column . '`';
    }

    public function compileInsertOrIgnore(QueryBuilder $qb, array $values): string
    {
        $quotedCols   = array_map(fn($c) => $this->quoteColumn($c), array_keys($values));
        $placeholders = array_map(fn($v) => $this->getPlaceholder($v), array_values($values));

        return 'INSERT IGNORE INTO ' . $qb->getFrom()
            . ' (' . implode(', ', $quotedCols) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';
    }

    public function compileUpsert(QueryBuilder $qb, array $values, array $conflictColumns, array $updateValues): string
    {
        $quotedCols   = array_map(fn($c) => $this->quoteColumn($c), array_keys($values));
        $placeholders = array_map(fn($v) => $this->getPlaceholder($v), array_values($values));

        $sql = 'INSERT INTO ' . $qb->getFrom()
            . ' (' . implode(', ', $quotedCols) . ')'
            . ' VALUES (' . implode(', ', $placeholders) . ')';

        if (empty($updateValues)) {
            // No columns to update → INSERT IGNORE semantics
            return 'INSERT IGNORE INTO ' . $qb->getFrom()
                . ' (' . implode(', ', $quotedCols) . ')'
                . ' VALUES (' . implode(', ', $placeholders) . ')';
        }

        $sets = array_map(
            fn($col) => $this->quoteColumn($col) . ' = VALUES(' . $this->quoteColumn($col) . ')',
            $updateValues
        );

        return $sql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
    }
}
