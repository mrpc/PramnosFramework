<?php

namespace Pramnos\Database\Grammar;

use Pramnos\Database\Blueprint;
use Pramnos\Database\ColumnDefinition;

/**
 * PostgreSQL 12+ DDL grammar.
 *
 * - Identifier quoting: double-quote
 * - Auto-increment: SERIAL / BIGSERIAL types (no AUTO_INCREMENT keyword)
 * - No UNSIGNED modifier
 * - Foreign keys: separate ALTER TABLE … ADD CONSTRAINT statements
 * - Materialized views: full support
 * - RENAME TABLE: ALTER TABLE … RENAME TO
 *
 * @package     PramnosFramework
 * @subpackage  Database\Grammar
 */
class PostgreSQLSchemaGrammar extends SchemaGrammar
{
    // =========================================================================
    // Quoting
    // =========================================================================

    public function quoteTable(string $table): string
    {
        return '"' . $table . '"';
    }

    public function quoteColumn(string $column): string
    {
        return '"' . $column . '"';
    }

    // =========================================================================
    // Column type mapping
    // =========================================================================

    public function compileColumnType(ColumnDefinition $col): string
    {
        switch ($col->type) {
            case 'tinyInteger':
            case 'smallInteger':
                return 'SMALLINT';
            case 'integer':
                return 'INTEGER';
            case 'bigInteger':
                return 'BIGINT';
            case 'increments':
                return 'SERIAL';
            case 'bigIncrements':
                return 'BIGSERIAL';
            case 'unsignedInteger':
                return 'INTEGER'; // PostgreSQL has no UNSIGNED
            case 'unsignedBigInteger':
                return 'BIGINT';
            case 'char':
                return 'CHAR(' . ($col->get('length', 1)) . ')';
            case 'string':
                return 'VARCHAR(' . ($col->get('length', 255)) . ')';
            case 'text':
            case 'mediumText':
            case 'longText':
                return 'TEXT';
            case 'float':
                return 'REAL';
            case 'double':
                return 'DOUBLE PRECISION';
            case 'decimal':
                $total  = $col->get('total', 8);
                $places = $col->get('places', 2);
                return "DECIMAL({$total}, {$places})";
            case 'boolean':
                return 'BOOLEAN';
            case 'date':
                return 'DATE';
            case 'time':
                return 'TIME';
            case 'dateTime':
                return 'TIMESTAMP';
            case 'timestamp':
                return 'TIMESTAMP';
            case 'timestampTz':
                return 'TIMESTAMPTZ';
            case 'year':
                return 'INTEGER'; // PostgreSQL has no YEAR type
            case 'binary':
                return 'BYTEA';
            case 'json':
                return 'JSON';
            case 'jsonb':
                return 'JSONB';
            case 'uuid':
                return 'UUID';
            case 'enum':
                // Use VARCHAR with a CHECK constraint (no inline type creation here)
                $max = max(array_map('strlen', $col->get('values', [''])));
                return 'VARCHAR(' . max($max, 50) . ')';
            case 'geometry':
                return 'GEOMETRY'; // requires PostGIS
            case 'point':
                return 'POINT';    // native PG type
            default:
                return strtoupper($col->type);
        }
    }

    // =========================================================================
    // Auto-increment — PostgreSQL uses SERIAL/BIGSERIAL types, no keyword
    // =========================================================================

    protected function compileAutoIncrement(ColumnDefinition $col): string
    {
        // SERIAL/BIGSERIAL already encode auto-increment — no extra keyword needed
        return '';
    }

    // =========================================================================
    // Default values (PostgreSQL uses TRUE/FALSE literals for booleans)
    // =========================================================================

    protected function compileDefaultValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        return parent::compileDefaultValue($value);
    }

    // =========================================================================
    // ENUM — PostgreSQL needs a CHECK constraint for the values list
    // =========================================================================

    public function compileColumn(ColumnDefinition $col): string
    {
        $sql = parent::compileColumn($col);

        // For enum columns, append a CHECK constraint on allowed values
        if ($col->type === 'enum' && !$col->get('check')) {
            $values  = array_map(fn($v) => "'" . addslashes($v) . "'", $col->get('values', []));
            $sql .= ' CHECK (' . $this->quoteColumn($col->name) . ' IN (' . implode(', ', $values) . '))';
        }

        return $sql;
    }

    // =========================================================================
    // Foreign keys are NOT inline in PostgreSQL — separate ALTER TABLE
    // =========================================================================

    protected function inlineForeignKeys(): bool
    {
        return false;
    }

    // =========================================================================
    // Index DDL (PostgreSQL: DROP INDEX is standalone, not per-table)
    // =========================================================================

    public function compileDropIndex(string $table, string $name): string
    {
        if ($name === 'PRIMARY') {
            // Primary key constraint name is typically <table>_pkey
            $constraintName = str_replace(['"'], '', $this->quoteTable($table)) . '_pkey';
            return 'ALTER TABLE ' . $this->quoteTable($table)
                . ' DROP CONSTRAINT "' . $constraintName . '"';
        }
        return 'DROP INDEX IF EXISTS "' . $name . '"';
    }

    // =========================================================================
    // DROP FOREIGN KEY (PostgreSQL: DROP CONSTRAINT)
    // =========================================================================

    protected function compileDropForeignKey(string $table, string $name): string
    {
        return 'ALTER TABLE ' . $this->quoteTable($table)
            . ' DROP CONSTRAINT "' . $name . '"';
    }

    // =========================================================================
    // Introspection
    // =========================================================================

    public function compileHasTable(string $table, string $schema): string
    {
        $schemaFilter = $schema !== ''
            ? " AND table_schema = '" . addslashes($schema) . "'"
            : " AND table_schema NOT IN ('pg_catalog','information_schema')";

        return "SELECT 1 FROM information_schema.tables"
            . " WHERE table_name = '" . addslashes($table) . "'"
            . $schemaFilter . " LIMIT 1";
    }

    public function compileHasColumn(string $table, string $column, string $schema): string
    {
        $schemaFilter = $schema !== ''
            ? " AND table_schema = '" . addslashes($schema) . "'"
            : " AND table_schema NOT IN ('pg_catalog','information_schema')";

        return "SELECT 1 FROM information_schema.columns"
            . " WHERE table_name = '" . addslashes($table) . "'"
            . " AND column_name = '" . addslashes($column) . "'"
            . $schemaFilter . " LIMIT 1";
    }

    // =========================================================================
    // Materialized views (PostgreSQL full support)
    // =========================================================================

    public function compileCreateMaterializedView(string $name, string $sql): string
    {
        return "CREATE MATERIALIZED VIEW {$name} AS {$sql}";
    }

    public function compileRefreshMaterializedView(string $name, bool $concurrently): string
    {
        $c = $concurrently ? 'CONCURRENTLY ' : '';
        return "REFRESH MATERIALIZED VIEW {$c}{$name}";
    }

    public function compileDropMaterializedView(string $name, bool $ifExists): string
    {
        $guard = $ifExists ? 'IF EXISTS ' : '';
        return "DROP MATERIALIZED VIEW {$guard}{$name}";
    }
}
