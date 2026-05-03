<?php

namespace Pramnos\Database\Grammar;

use Pramnos\Database\Blueprint;
use Pramnos\Database\ColumnDefinition;
use Pramnos\Database\Expression;
use Pramnos\Database\ForeignKeyDefinition;

/**
 * Abstract base for DDL grammars — shared compilation logic via Template Method.
 *
 * Concrete grammars override:
 *   - quoteTable() / quoteColumn()   — identifier quoting
 *   - compileColumnType()            — SQL type name mapping
 *   - compileTableOptions()          — trailing options (MySQL: ENGINE=InnoDB …)
 *   - compileAutoIncrement()         — AUTO_INCREMENT vs SERIAL vs nothing
 *   - compileDefaultValue()          — TRUE vs 1 for booleans, etc.
 *   - compileForeignKeyInline()      — inline FK in CREATE TABLE (MySQL: yes, PG: no)
 *   - compileColumnPosition()        — AFTER col / FIRST (MySQL only)
 *   - compileCreateMaterializedView/compileRefreshMaterializedView (PG only)
 *
 * @package     PramnosFramework
 * @subpackage  Database\Grammar
 */
abstract class SchemaGrammar implements SchemaGrammarInterface
{
    // =========================================================================
    // Abstract / overridable hooks
    // =========================================================================

    abstract public function quoteTable(string $table): string;
    abstract public function quoteColumn(string $column): string;
    abstract public function compileColumnType(ColumnDefinition $column): string;

    /** Returns trailing table options (e.g. ENGINE=InnoDB for MySQL). */
    protected function compileTableOptions(Blueprint $blueprint): string
    {
        return '';
    }

    /**
     * Returns the AUTO_INCREMENT keyword if the column is auto-increment.
     * PostgreSQL uses SERIAL/BIGSERIAL types instead, so this returns ''.
     */
    protected function compileAutoIncrement(ColumnDefinition $col): string
    {
        return $col->get('autoIncrement') ? ' AUTO_INCREMENT' : '';
    }

    /**
     * Serialises a DEFAULT value to its SQL representation.
     * Subclasses override to handle booleans (TRUE/FALSE vs 1/0).
     *
     * @param mixed $value
     */
    protected function compileDefaultValue($value): string
    {
        if ($value instanceof Expression) {
            return (string)$value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_null($value)) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return "'" . addslashes((string)$value) . "'";
    }

    /**
     * Returns AFTER / FIRST positioning clause (MySQL only; base returns '').
     */
    protected function compileColumnPosition(ColumnDefinition $col): string
    {
        return '';
    }

    /**
     * Returns true when foreign keys can be declared inline inside CREATE TABLE.
     * MySQL supports this; PostgreSQL requires separate ALTER TABLE statements.
     */
    protected function inlineForeignKeys(): bool
    {
        return false;
    }

    // =========================================================================
    // CREATE TABLE
    // =========================================================================

    public function compileCreate(Blueprint $blueprint, string $table): array
    {
        $statements = [];

        // ---- inline column definitions ----
        $columnSqls = [];
        foreach ($blueprint->getColumns() as $col) {
            $columnSqls[] = $this->compileColumn($col);
        }

        // ---- table-level PRIMARY KEY ----
        $pk = $this->collectPrimaryKey($blueprint);
        if (!empty($pk)) {
            $quotedPk = array_map(fn($c) => $this->quoteColumn($c), $pk);
            $columnSqls[] = 'PRIMARY KEY (' . implode(', ', $quotedPk) . ')';
        }

        // ---- UNIQUE constraints — from explicit blueprint->unique() calls ----
        foreach ($blueprint->getUniqueConstraints() as $u) {
            $quotedCols = array_map(fn($c) => $this->quoteColumn($c), $u['columns']);
            $columnSqls[] = $this->compileInlineUnique($u['name'], $quotedCols);
        }

        // ---- UNIQUE constraints — from per-column ->unique() modifier ----
        foreach ($blueprint->getColumns() as $col) {
            if ($col->get('unique') && !$col->get('primary')) {
                $name        = $this->generateIndexName('unique', [$col->name], $blueprint->getTable());
                $quotedCols  = [$this->quoteColumn($col->name)];
                $columnSqls[] = $this->compileInlineUnique($name, $quotedCols);
            }
        }

        // ---- inline FOREIGN KEYS (MySQL) ----
        if ($this->inlineForeignKeys()) {
            foreach ($blueprint->getForeignKeys() as $fk) {
                $columnSqls[] = $this->compileForeignKeyInline($fk, $table);
            }
        }

        $tmp = $blueprint->isTemporary() ? 'TEMPORARY ' : '';
        $sql = "CREATE {$tmp}TABLE " . $this->quoteTable($table)
            . " (\n    " . implode(",\n    ", $columnSqls) . "\n)"
            . $this->compileTableOptions($blueprint);

        $statements[] = $sql;

        // ---- post-CREATE: non-unique indexes ----
        foreach ($blueprint->getIndexes() as $idx) {
            $statements[] = $this->compileCreateIndex($table, $idx['name'], $idx['columns'], false);
        }

        // ---- post-CREATE: FOREIGN KEYS (PostgreSQL: ALTER TABLE ADD CONSTRAINT) ----
        if (!$this->inlineForeignKeys()) {
            foreach ($blueprint->getForeignKeys() as $fk) {
                $statements[] = $this->compileForeignKeyAlter($fk, $table);
            }
        }

        return $statements;
    }

    /** Inline UNIQUE syntax — overridden by MySQL to emit UNIQUE KEY name (...). */
    protected function compileInlineUnique(string $name, array $quotedCols): string
    {
        return 'UNIQUE (' . implode(', ', $quotedCols) . ')';
    }

    /**
     * Compile an inline FOREIGN KEY clause (used inside CREATE TABLE by MySQL).
     */
    protected function compileForeignKeyInline(ForeignKeyDefinition $fk, string $localTable): string
    {
        return $this->compileForeignKeySql($fk, $localTable, true);
    }

    /**
     * Compile a standalone ALTER TABLE … ADD CONSTRAINT … FOREIGN KEY statement.
     */
    protected function compileForeignKeyAlter(ForeignKeyDefinition $fk, string $localTable): string
    {
        $constraintSql = $this->compileForeignKeySql($fk, $localTable, false);
        return 'ALTER TABLE ' . $this->quoteTable($localTable) . ' ADD ' . $constraintSql;
    }

    /**
     * Build the CONSTRAINT … FOREIGN KEY … SQL fragment (shared between inline and ALTER).
     *
     * @param bool $inline  true = omit "CONSTRAINT name" preamble for MySQL inline
     */
    private function compileForeignKeySql(ForeignKeyDefinition $fk, string $localTable, bool $inline): string
    {
        $name = $fk->constraintName ?? $this->generateFkName($localTable, $fk->column);
        $quotedLocal = $this->quoteColumn($fk->column);
        $quotedRef   = $this->quoteColumn($fk->referencedColumn ?: $fk->column);
        $quotedTable = $this->quoteTable($fk->referencedTable);

        $sql = "CONSTRAINT {$name} FOREIGN KEY ({$quotedLocal})"
            . " REFERENCES {$quotedTable} ({$quotedRef})"
            . " ON DELETE {$fk->onDelete}"
            . " ON UPDATE {$fk->onUpdate}";

        return $sql;
    }

    // =========================================================================
    // ALTER TABLE
    // =========================================================================

    public function compileAlter(Blueprint $blueprint, string $table): array
    {
        $statements = [];

        // ADD COLUMN
        foreach ($blueprint->getColumns() as $col) {
            $colSql = $this->compileColumn($col) . $this->compileColumnPosition($col);
            $statements[] = 'ALTER TABLE ' . $this->quoteTable($table)
                . ' ADD COLUMN ' . $colSql;
        }

        // DROP COLUMN
        foreach ($blueprint->getDroppedColumns() as $col) {
            $statements[] = 'ALTER TABLE ' . $this->quoteTable($table)
                . ' DROP COLUMN ' . $this->quoteColumn($col);
        }

        // RENAME COLUMN
        foreach ($blueprint->getRenamedColumns() as $rename) {
            $statements[] = $this->compileRenameColumn($table, $rename['from'], $rename['to']);
        }

        // DROP INDEX
        foreach ($blueprint->getDroppedIndexes() as $idx) {
            $statements[] = $this->compileDropIndex($table, $idx);
        }

        // DROP FOREIGN KEY
        foreach ($blueprint->getDroppedForeigns() as $fk) {
            $statements[] = $this->compileDropForeignKey($table, $fk);
        }

        // ADD UNIQUE
        foreach ($blueprint->getUniqueConstraints() as $u) {
            $statements[] = $this->compileCreateIndex($table, $u['name'], $u['columns'], true);
        }

        // ADD INDEX
        foreach ($blueprint->getIndexes() as $idx) {
            $statements[] = $this->compileCreateIndex($table, $idx['name'], $idx['columns'], false);
        }

        // ADD FOREIGN KEY
        foreach ($blueprint->getForeignKeys() as $fk) {
            $statements[] = $this->compileForeignKeyAlter($fk, $table);
        }

        return array_values(array_filter($statements));
    }

    protected function compileRenameColumn(string $table, string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->quoteTable($table)
            . ' RENAME COLUMN ' . $this->quoteColumn($from)
            . ' TO ' . $this->quoteColumn($to);
    }

    protected function compileDropForeignKey(string $table, string $name): string
    {
        return 'ALTER TABLE ' . $this->quoteTable($table)
            . ' DROP FOREIGN KEY ' . $name;
    }

    // =========================================================================
    // Column compilation
    // =========================================================================

    public function compileColumn(ColumnDefinition $col): string
    {
        $sql = $this->quoteColumn($col->name) . ' ' . $this->compileColumnType($col);

        // Computed columns short-circuit normal modifiers
        if ($stored = $col->get('storedAs')) {
            return $sql . " GENERATED ALWAYS AS ({$stored}) STORED";
        }
        if ($virtual = $col->get('virtualAs')) {
            return $sql . " AS ({$virtual})";
        }

        $sql .= $this->compileAutoIncrement($col);

        $nullable = $col->get('nullable', false);
        $sql .= $nullable ? ' NULL' : ' NOT NULL';

        if ($col->get('hasDefault')) {
            $sql .= ' DEFAULT ' . $this->compileDefaultValue($col->get('default'));
        }

        if ($check = $col->get('check')) {
            $sql .= " CHECK ({$check})";
        }

        if ($comment = $col->get('comment')) {
            $sql .= $this->compileColumnComment($comment);
        }

        return $sql;
    }

    /** Returns inline COMMENT clause for dialects that support it (MySQL). */
    protected function compileColumnComment(string $comment): string
    {
        return '';
    }

    // =========================================================================
    // DROP / RENAME TABLE
    // =========================================================================

    public function compileDrop(string $table): string
    {
        return 'DROP TABLE ' . $this->quoteTable($table);
    }

    public function compileDropIfExists(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quoteTable($table);
    }

    public function compileRename(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->quoteTable($from) . ' RENAME TO ' . $this->quoteTable($to);
    }

    // =========================================================================
    // Index DDL
    // =========================================================================

    public function compileCreateIndex(string $table, string $name, array $columns, bool $unique): string
    {
        $type    = $unique ? 'UNIQUE INDEX' : 'INDEX';
        $quotedCols = array_map(fn($c) => $this->quoteColumn($c), $columns);
        return "CREATE {$type} {$name} ON " . $this->quoteTable($table)
            . ' (' . implode(', ', $quotedCols) . ')';
    }

    public function compileDropIndex(string $table, string $name): string
    {
        return "DROP INDEX {$name}";
    }

    // =========================================================================
    // View DDL
    // =========================================================================

    public function compileCreateView(string $name, string $sql, bool $orReplace): string
    {
        $or = $orReplace ? 'OR REPLACE ' : '';
        return "CREATE {$or}VIEW {$name} AS {$sql}";
    }

    public function compileDropView(string $name, bool $ifExists): string
    {
        $guard = $ifExists ? 'IF EXISTS ' : '';
        return "DROP VIEW {$guard}{$name}";
    }

    // =========================================================================
    // Materialized views (base = unsupported; PostgreSQL grammar overrides)
    // =========================================================================

    public function compileCreateMaterializedView(string $name, string $sql): string
    {
        // Dialect does not support materialized views — fall through to regular VIEW
        return $this->compileCreateView($name, $sql, false);
    }

    public function compileRefreshMaterializedView(string $name, bool $concurrently): string
    {
        return '';
    }

    public function compileDropMaterializedView(string $name, bool $ifExists): string
    {
        return $this->compileDropView($name, $ifExists);
    }

    // =========================================================================
    // Introspection helpers
    // =========================================================================

    public function compileHasTable(string $table, string $schema): string
    {
        return "SELECT 1 FROM information_schema.tables WHERE table_name = '"
            . addslashes($table) . "'";
    }

    public function compileHasColumn(string $table, string $column, string $schema): string
    {
        return "SELECT 1 FROM information_schema.columns WHERE table_name = '"
            . addslashes($table) . "' AND column_name = '" . addslashes($column) . "'";
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function collectPrimaryKey(Blueprint $blueprint): array
    {
        // Explicit blueprint-level primary key takes precedence
        if (!empty($blueprint->getPrimaryKey())) {
            return $blueprint->getPrimaryKey();
        }

        // Columns marked ->primary() in their definition
        $pks = [];
        foreach ($blueprint->getColumns() as $col) {
            if ($col->get('primary')) {
                $pks[] = $col->name;
            }
        }
        return $pks;
    }

    protected function generateFkName(string $table, string $column): string
    {
        $table = str_replace(['#PREFIX#', '.'], ['', '_'], $table);
        return "fk_{$table}_{$column}";
    }

    protected function generateIndexName(string $type, array $columns, string $table = ''): string
    {
        $t = str_replace(['#PREFIX#', '.'], ['', '_'], $table);
        $base = $t !== '' ? $t . '_' . implode('_', $columns) : implode('_', $columns);
        return $base . '_' . $type;
    }
}
