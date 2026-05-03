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

    /**
     * Compile the WITH [RECURSIVE] … preamble for a query that has CTEs.
     * Returns an empty string when there are no CTEs.
     */
    public function compileCtes(QueryBuilder $qb): string;

    // -------------------------------------------------------------------------
    // DML compilation
    // -------------------------------------------------------------------------

    public function compileInsert(QueryBuilder $qb, array $values): string;
    public function compileInsertOrIgnore(QueryBuilder $qb, array $values): string;
    public function compileUpsert(QueryBuilder $qb, array $values, array $conflictColumns, array $updateValues): string;
    public function compileUpdate(QueryBuilder $qb, array $values): string;
    public function compileDelete(QueryBuilder $qb): string;
    public function compileTruncate(QueryBuilder $qb): string;

    // -------------------------------------------------------------------------
    // Time-bucket / time-series helpers
    // -------------------------------------------------------------------------

    /**
     * Compile a time-bucket expression for the connected dialect.
     *
     * Interval is a human-readable string such as "1 hour", "15 minutes",
     * "1 day", "1 month".  Column is the bare column name or any SQL expression.
     *
     * Dialect mapping:
     *   TimescaleDB  → time_bucket('interval', column)
     *   PostgreSQL   → date_trunc('precision', column) for standard intervals;
     *                  epoch-arithmetic for non-standard sub-day intervals
     *   MySQL        → DATE_FORMAT / FROM_UNIXTIME arithmetic
     *
     * @param  string $interval  e.g. "1 hour", "15 minutes", "1 day"
     * @param  string $column    column expression (already quoted / raw if needed)
     * @return string            SQL expression fragment (no surrounding parens)
     */
    public function compileTimeBucket(string $interval, string $column): string;
}
