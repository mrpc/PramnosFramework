<?php

namespace Pramnos\Database\Grammar;

use Pramnos\Database\Blueprint;
use Pramnos\Database\ColumnDefinition;

/**
 * MySQL 5.7+ / 8.0 DDL grammar.
 *
 * - Identifier quoting: backtick
 * - Auto-increment: AUTO_INCREMENT keyword
 * - Unsigned integers: UNSIGNED modifier
 * - Table options: ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
 * - Foreign keys: declared inline inside CREATE TABLE
 *
 * @package     PramnosFramework
 * @subpackage  Database\Grammar
 */
class MySQLSchemaGrammar extends SchemaGrammar
{
    // =========================================================================
    // Quoting
    // =========================================================================

    public function quoteTable(string $table): string
    {
        return '`' . $table . '`';
    }

    public function quoteColumn(string $column): string
    {
        return '`' . $column . '`';
    }

    // =========================================================================
    // Column type mapping
    // =========================================================================

    public function compileColumnType(ColumnDefinition $col): string
    {
        $unsigned = $col->get('unsigned') ? ' UNSIGNED' : '';

        switch ($col->type) {
            case 'tinyInteger':
                return 'TINYINT' . $unsigned;
            case 'smallInteger':
                return 'SMALLINT' . $unsigned;
            case 'integer':
                return 'INT' . $unsigned;
            case 'bigInteger':
                return 'BIGINT' . $unsigned;
            case 'increments':
                return 'INT UNSIGNED';
            case 'bigIncrements':
                return 'BIGINT UNSIGNED';
            case 'unsignedInteger':
                return 'INT UNSIGNED';
            case 'unsignedBigInteger':
                return 'BIGINT UNSIGNED';
            case 'char':
                return 'CHAR(' . ($col->get('length', 1)) . ')';
            case 'string':
                return 'VARCHAR(' . ($col->get('length', 255)) . ')';
            case 'text':
                return 'TEXT';
            case 'mediumText':
                return 'MEDIUMTEXT';
            case 'longText':
                return 'LONGTEXT';
            case 'float':
                return 'FLOAT';
            case 'double':
                return 'DOUBLE';
            case 'decimal':
                $total  = $col->get('total', 8);
                $places = $col->get('places', 2);
                return "DECIMAL({$total}, {$places})";
            case 'boolean':
                return 'TINYINT(1)';
            case 'date':
                return 'DATE';
            case 'time':
                return 'TIME';
            case 'dateTime':
                return 'DATETIME';
            case 'timestamp':
            case 'timestampTz':
                return 'TIMESTAMP';
            case 'year':
                return 'YEAR';
            case 'binary':
                return 'BLOB';
            case 'json':
            case 'jsonb':
                return 'JSON';
            case 'uuid':
                return 'CHAR(36)';
            case 'enum':
                $values = array_map(fn($v) => "'" . addslashes($v) . "'", $col->get('values', []));
                return 'ENUM(' . implode(', ', $values) . ')';
            case 'geometry':
                return 'GEOMETRY';
            case 'point':
                return 'POINT';
            default:
                return strtoupper($col->type);
        }
    }

    // =========================================================================
    // Auto-increment
    // =========================================================================

    protected function compileAutoIncrement(ColumnDefinition $col): string
    {
        return $col->get('autoIncrement') ? ' AUTO_INCREMENT' : '';
    }

    // =========================================================================
    // Default values (MySQL uses 1/0 for booleans, b'1' for BIT, etc.)
    // =========================================================================

    protected function compileDefaultValue($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return parent::compileDefaultValue($value);
    }

    // =========================================================================
    // Column comment (MySQL supports inline COMMENT)
    // =========================================================================

    protected function compileColumnComment(string $comment): string
    {
        return " COMMENT '" . addslashes($comment) . "'";
    }

    // =========================================================================
    // Table options
    // =========================================================================

    protected function compileTableOptions(Blueprint $blueprint): string
    {
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    // =========================================================================
    // Inline UNIQUE (MySQL wants UNIQUE KEY name (...))
    // =========================================================================

    protected function compileInlineUnique(string $name, array $quotedCols): string
    {
        return "UNIQUE KEY `{$name}` (" . implode(', ', $quotedCols) . ')';
    }

    // =========================================================================
    // Foreign keys inline in CREATE TABLE
    // =========================================================================

    protected function inlineForeignKeys(): bool
    {
        return true;
    }

    // =========================================================================
    // Column position (AFTER / FIRST — MySQL only)
    // =========================================================================

    protected function compileColumnPosition(ColumnDefinition $col): string
    {
        if ($after = $col->get('after')) {
            return ' AFTER ' . $this->quoteColumn($after);
        }
        if ($col->get('first')) {
            return ' FIRST';
        }
        return '';
    }

    // =========================================================================
    // Index DDL (MySQL: DROP INDEX uses table reference)
    // =========================================================================

    public function compileDropIndex(string $table, string $name): string
    {
        if ($name === 'PRIMARY') {
            return 'ALTER TABLE ' . $this->quoteTable($table) . ' DROP PRIMARY KEY';
        }
        return 'ALTER TABLE ' . $this->quoteTable($table)
            . ' DROP INDEX `' . $name . '`';
    }

    // =========================================================================
    // DROP FOREIGN KEY (MySQL syntax)
    // =========================================================================

    protected function compileDropForeignKey(string $table, string $name): string
    {
        return 'ALTER TABLE ' . $this->quoteTable($table)
            . ' DROP FOREIGN KEY `' . $name . '`';
    }

    // =========================================================================
    // RENAME COLUMN (MySQL 8.0+)
    // =========================================================================

    protected function compileRenameColumn(string $table, string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->quoteTable($table)
            . ' RENAME COLUMN ' . $this->quoteColumn($from)
            . ' TO ' . $this->quoteColumn($to);
    }

    // =========================================================================
    // Rename table (MySQL syntax)
    // =========================================================================

    public function compileRename(string $from, string $to): string
    {
        return 'RENAME TABLE ' . $this->quoteTable($from) . ' TO ' . $this->quoteTable($to);
    }

    // =========================================================================
    // Introspection (MySQL restricts schema via table_schema)
    // =========================================================================

    public function compileHasTable(string $table, string $schema): string
    {
        $where = "table_name = '" . addslashes($table) . "'";
        if ($schema !== '') {
            $where .= " AND table_schema = '" . addslashes($schema) . "'";
        }
        return "SELECT 1 FROM information_schema.tables WHERE {$where} LIMIT 1";
    }

    public function compileHasColumn(string $table, string $column, string $schema): string
    {
        $where = "table_name = '" . addslashes($table) . "'"
            . " AND column_name = '" . addslashes($column) . "'";
        if ($schema !== '') {
            $where .= " AND table_schema = '" . addslashes($schema) . "'";
        }
        return "SELECT 1 FROM information_schema.columns WHERE {$where} LIMIT 1";
    }

    // =========================================================================
    // Materialized views — not supported; fall back to regular VIEW
    // =========================================================================

    public function compileCreateMaterializedView(string $name, string $sql): string
    {
        return $this->compileCreateView($name, $sql, false);
    }

    public function compileRefreshMaterializedView(string $name, bool $concurrently): string
    {
        return ''; // no-op on MySQL
    }

    public function compileDropMaterializedView(string $name, bool $ifExists): string
    {
        return $this->compileDropView($name, $ifExists);
    }
}
