<?php

namespace Pramnos\Database\Grammar;

use Pramnos\Database\Blueprint;
use Pramnos\Database\ColumnDefinition;

/**
 * Contract for all DDL (Schema) grammars.
 *
 * Each grammar translates a Blueprint into dialect-specific DDL statements.
 * Every method returns either a string (single statement) or an array of
 * strings (multiple statements — e.g. CREATE TABLE followed by CREATE INDEX).
 *
 * @package     PramnosFramework
 * @subpackage  Database\Grammar
 */
interface SchemaGrammarInterface
{
    // -------------------------------------------------------------------------
    // Quoting
    // -------------------------------------------------------------------------

    public function quoteTable(string $table): string;
    public function quoteColumn(string $column): string;

    // -------------------------------------------------------------------------
    // Table DDL
    // -------------------------------------------------------------------------

    /** Returns one or more SQL statements needed to fully create the table. */
    public function compileCreate(Blueprint $blueprint, string $table): array;

    /** Returns ALTER TABLE statements for the operations in the Blueprint. */
    public function compileAlter(Blueprint $blueprint, string $table): array;

    public function compileDrop(string $table): string;
    public function compileDropIfExists(string $table): string;
    public function compileRename(string $from, string $to): string;

    // -------------------------------------------------------------------------
    // Index DDL
    // -------------------------------------------------------------------------

    public function compileCreateIndex(string $table, string $name, array $columns, bool $unique): string;
    public function compileDropIndex(string $table, string $name): string;

    // -------------------------------------------------------------------------
    // View DDL
    // -------------------------------------------------------------------------

    public function compileCreateView(string $name, string $sql, bool $orReplace): string;
    public function compileDropView(string $name, bool $ifExists): string;

    // -------------------------------------------------------------------------
    // Materialized view DDL (PostgreSQL / TimescaleDB only)
    // -------------------------------------------------------------------------

    public function compileCreateMaterializedView(string $name, string $sql): string;
    public function compileRefreshMaterializedView(string $name, bool $concurrently): string;
    public function compileDropMaterializedView(string $name, bool $ifExists): string;

    // -------------------------------------------------------------------------
    // Introspection helpers
    // -------------------------------------------------------------------------

    public function compileHasTable(string $table, string $schema): string;
    public function compileHasColumn(string $table, string $column, string $schema): string;

    // -------------------------------------------------------------------------
    // Column type compilation
    // -------------------------------------------------------------------------

    public function compileColumnType(ColumnDefinition $column): string;
}
