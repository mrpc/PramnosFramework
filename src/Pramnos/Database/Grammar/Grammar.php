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
 * @package     PramnosFramework
 * @subpackage  Database\Grammar
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
    // SELECT
    // -------------------------------------------------------------------------

    public function compileSelect(QueryBuilder $qb): string
    {
        $sql = 'SELECT ';
        if ($qb->isDistinct()) {
            $sql .= 'DISTINCT ';
        }
        $sql .= implode(', ', $qb->getColumns());
        $sql .= ' FROM ' . $qb->getFrom();

        foreach ($qb->getJoins() as $join) {
            if (isset($join['type']) && $join['type'] === 'Raw') {
                $sql .= ' ' . $join['sql'];
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

        foreach ($qb->getUnions() as $union) {
            $sql .= ' ' . ($union['all'] ? 'UNION ALL' : 'UNION') . ' '
                . $this->compileSelect($union['query']);
        }

        return $sql;
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
}
