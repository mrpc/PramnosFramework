<?php

namespace Pramnos\Database\Grammar;

use Pramnos\Database\QueryBuilder;

/**
 * Contract for all SQL dialect grammars.
 *
 * Each grammar translates a QueryBuilder AST into dialect-specific SQL.
 * Grammars are stateless; every method receives the builder as its first argument.
 *
 * @package     PramnosFramework
 * @subpackage  Database\Grammar
 */
interface GrammarInterface
{
    // -------------------------------------------------------------------------
    // Quoting
    // -------------------------------------------------------------------------

    /**
     * Wrap a bare column name in the dialect's identifier quotes.
     */
    public function quoteColumn(string $column): string;

    // -------------------------------------------------------------------------
    // Placeholder
    // -------------------------------------------------------------------------

    /**
     * Return the type-aware placeholder token for a bound value.
     * Returns the value's string representation for Expression objects.
     *
     * @param  mixed $value
     */
    public function getPlaceholder($value): string;

    // -------------------------------------------------------------------------
    // SELECT compilation
    // -------------------------------------------------------------------------

    public function compileSelect(QueryBuilder $qb): string;
    public function compileWheres(QueryBuilder $qb): string;
    public function compileHavings(QueryBuilder $qb): string;

    // -------------------------------------------------------------------------
    // DML compilation
    // -------------------------------------------------------------------------

    public function compileInsert(QueryBuilder $qb, array $values): string;
    public function compileInsertOrIgnore(QueryBuilder $qb, array $values): string;
    public function compileUpsert(QueryBuilder $qb, array $values, array $conflictColumns, array $updateValues): string;
    public function compileUpdate(QueryBuilder $qb, array $values): string;
    public function compileDelete(QueryBuilder $qb): string;
    public function compileTruncate(QueryBuilder $qb): string;
}
