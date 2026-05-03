<?php

namespace Pramnos\Database;

/**
 * Accumulates column, index, and constraint definitions for a single table.
 *
 * Passed to the callback in SchemaBuilder::createTable() / alterTable().
 * The resulting Blueprint is handed to the SchemaGrammar for SQL compilation.
 *
 * @package     PramnosFramework
 * @subpackage  Database
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2026 Yannis - Pastis Glaros, Pramnos Hosting
 */
class Blueprint
{
    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    protected string $table;
    protected string $mode; // 'create' | 'alter'

    /** @var ColumnDefinition[] */
    protected array $columns = [];

    /** @var string[] */
    protected array $droppedColumns = [];

    /** @var array<array{from:string,to:string}> */
    protected array $renamedColumns = [];

    /** @var string[] Primary key columns (Blueprint-level, not per-column) */
    protected array $primaryKey = [];

    /** @var array<array{name:string,columns:string[]}> */
    protected array $uniqueConstraints = [];

    /** @var array<array{name:string,columns:string[]}> */
    protected array $indexes = [];

    /** @var ForeignKeyDefinition[] */
    protected array $foreignKeys = [];

    /** @var string[] */
    protected array $droppedIndexes = [];

    /** @var string[] */
    protected array $droppedForeigns = [];

    /** @var bool  Add TEMPORARY to CREATE TABLE */
    protected bool $temporary = false;

    // -------------------------------------------------------------------------
    // Construction
    // -------------------------------------------------------------------------

    public function __construct(string $table, string $mode = 'create')
    {
        $this->table = $table;
        $this->mode  = $mode;
    }

    // =========================================================================
    // Column type helpers
    // =========================================================================

    // -------------------------------------------------------------------------
    // Integer types
    // -------------------------------------------------------------------------

    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'tinyInteger');
    }

    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'smallInteger');
    }

    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'integer');
    }

    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'bigInteger');
    }

    public function unsignedInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'integer', ['unsigned' => true]);
    }

    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'bigInteger', ['unsigned' => true]);
    }

    // -------------------------------------------------------------------------
    // Auto-increment shortcuts
    // -------------------------------------------------------------------------

    /**
     * Unsigned auto-incrementing INT primary key.
     */
    public function increments(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'increments', ['autoIncrement' => true, 'primary' => true, 'unsigned' => true]);
    }

    /**
     * Unsigned auto-incrementing BIGINT primary key.
     */
    public function bigIncrements(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'bigIncrements', ['autoIncrement' => true, 'primary' => true, 'unsigned' => true]);
    }

    // -------------------------------------------------------------------------
    // String / text types
    // -------------------------------------------------------------------------

    public function char(string $name, int $length = 1): ColumnDefinition
    {
        return $this->addColumn($name, 'char', ['length' => $length]);
    }

    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn($name, 'string', ['length' => $length]);
    }

    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'text');
    }

    public function mediumText(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'mediumText');
    }

    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'longText');
    }

    // -------------------------------------------------------------------------
    // Numeric types
    // -------------------------------------------------------------------------

    public function float(string $name, int $total = 8, int $places = 2): ColumnDefinition
    {
        return $this->addColumn($name, 'float', ['total' => $total, 'places' => $places]);
    }

    public function double(string $name, ?int $total = null, ?int $places = null): ColumnDefinition
    {
        return $this->addColumn($name, 'double', ['total' => $total, 'places' => $places]);
    }

    public function decimal(string $name, int $total = 8, int $places = 2): ColumnDefinition
    {
        return $this->addColumn($name, 'decimal', ['total' => $total, 'places' => $places]);
    }

    // -------------------------------------------------------------------------
    // Boolean
    // -------------------------------------------------------------------------

    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'boolean');
    }

    // -------------------------------------------------------------------------
    // Date / time types
    // -------------------------------------------------------------------------

    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'date');
    }

    public function time(string $name, int $precision = 0): ColumnDefinition
    {
        return $this->addColumn($name, 'time', ['precision' => $precision]);
    }

    public function dateTime(string $name, int $precision = 0): ColumnDefinition
    {
        return $this->addColumn($name, 'dateTime', ['precision' => $precision]);
    }

    public function timestamp(string $name, int $precision = 0): ColumnDefinition
    {
        return $this->addColumn($name, 'timestamp', ['precision' => $precision]);
    }

    /** TIMESTAMP WITH TIME ZONE (PostgreSQL). Falls back to TIMESTAMP on MySQL. */
    public function timestampTz(string $name, int $precision = 0): ColumnDefinition
    {
        return $this->addColumn($name, 'timestampTz', ['precision' => $precision]);
    }

    public function year(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'year');
    }

    // -------------------------------------------------------------------------
    // Composite timestamp helpers
    // -------------------------------------------------------------------------

    /** Add nullable created_at and updated_at TIMESTAMP columns. */
    public function timestamps(int $precision = 0): void
    {
        $this->timestamp('created_at', $precision)->nullable();
        $this->timestamp('updated_at', $precision)->nullable();
    }

    /** Add nullable created_at and updated_at TIMESTAMPTZ columns (PostgreSQL). */
    public function timestampsTz(int $precision = 0): void
    {
        $this->timestampTz('created_at', $precision)->nullable();
        $this->timestampTz('updated_at', $precision)->nullable();
    }

    /** Add nullable deleted_at TIMESTAMP for soft-delete patterns. */
    public function softDeletes(int $precision = 0): void
    {
        $this->timestamp('deleted_at', $precision)->nullable();
    }

    public function softDeletesTz(int $precision = 0): void
    {
        $this->timestampTz('deleted_at', $precision)->nullable();
    }

    // -------------------------------------------------------------------------
    // Binary
    // -------------------------------------------------------------------------

    public function binary(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'binary');
    }

    // -------------------------------------------------------------------------
    // JSON types
    // -------------------------------------------------------------------------

    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'json');
    }

    /** JSONB (PostgreSQL). Falls back to JSON on MySQL. */
    public function jsonb(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'jsonb');
    }

    // -------------------------------------------------------------------------
    // UUID
    // -------------------------------------------------------------------------

    public function uuid(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'uuid');
    }

    // -------------------------------------------------------------------------
    // Enum
    // -------------------------------------------------------------------------

    /**
     * MySQL: inline ENUM column type.
     * PostgreSQL: VARCHAR with CHECK constraint (or existing named type if created separately).
     *
     * @param string   $name
     * @param string[] $values
     */
    public function enum(string $name, array $values): ColumnDefinition
    {
        return $this->addColumn($name, 'enum', ['values' => $values]);
    }

    // -------------------------------------------------------------------------
    // Geometry / spatial
    // -------------------------------------------------------------------------

    public function geometry(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'geometry');
    }

    public function point(string $name): ColumnDefinition
    {
        return $this->addColumn($name, 'point');
    }

    // =========================================================================
    // Index / constraint helpers
    // =========================================================================

    /**
     * Set the primary key for this table (table-level, not per-column).
     *
     * @param string|string[] $columns
     * @param string|null     $name
     */
    public function primary($columns, ?string $name = null): void
    {
        $this->primaryKey = array_merge($this->primaryKey, (array)$columns);
    }

    /**
     * Add a UNIQUE constraint.
     *
     * @param string|string[] $columns
     * @param string|null     $name  Auto-generated when null.
     */
    public function unique($columns, ?string $name = null): void
    {
        $columns = (array)$columns;
        $this->uniqueConstraints[] = [
            'name'    => $name ?? $this->generateIndexName('unique', $columns),
            'columns' => $columns,
        ];
    }

    /**
     * Add a non-unique index.
     *
     * @param string|string[] $columns
     * @param string|null     $name  Auto-generated when null.
     */
    public function index($columns, ?string $name = null): void
    {
        $columns = (array)$columns;
        $this->indexes[] = [
            'name'    => $name ?? $this->generateIndexName('index', $columns),
            'columns' => $columns,
        ];
    }

    /**
     * Add a FOREIGN KEY constraint.
     *
     * Returns a ForeignKeyDefinition for chaining references() / on() / onDelete().
     */
    public function foreign(string $column): ForeignKeyDefinition
    {
        $fk = new ForeignKeyDefinition($column);
        $this->foreignKeys[] = $fk;
        return $fk;
    }

    // =========================================================================
    // ALTER TABLE helpers (used in alterTable() callback)
    // =========================================================================

    /**
     * Drop one or more columns.
     *
     * @param string|string[] $columns
     */
    public function dropColumn($columns): void
    {
        foreach ((array)$columns as $col) {
            $this->droppedColumns[] = $col;
        }
    }

    public function renameColumn(string $from, string $to): void
    {
        $this->renamedColumns[] = ['from' => $from, 'to' => $to];
    }

    public function dropIndex(string $name): void
    {
        $this->droppedIndexes[] = $name;
    }

    public function dropUnique(string $name): void
    {
        $this->droppedIndexes[] = $name;
    }

    public function dropForeign(string $name): void
    {
        $this->droppedForeigns[] = $name;
    }

    public function dropPrimary(?string $name = null): void
    {
        $this->droppedIndexes[] = $name ?? 'PRIMARY';
    }

    // =========================================================================
    // Table options
    // =========================================================================

    public function temporary(bool $value = true): void
    {
        $this->temporary = $value;
    }

    // =========================================================================
    // Getters for SchemaGrammar
    // =========================================================================

    public function getTable(): string      { return $this->table; }
    public function getMode(): string       { return $this->mode; }
    public function isTemporary(): bool     { return $this->temporary; }
    public function getColumns(): array     { return $this->columns; }
    public function getDroppedColumns(): array  { return $this->droppedColumns; }
    public function getRenamedColumns(): array  { return $this->renamedColumns; }
    public function getPrimaryKey(): array  { return $this->primaryKey; }
    public function getUniqueConstraints(): array { return $this->uniqueConstraints; }
    public function getIndexes(): array     { return $this->indexes; }
    public function getForeignKeys(): array { return $this->foreignKeys; }
    public function getDroppedIndexes(): array  { return $this->droppedIndexes; }
    public function getDroppedForeigns(): array { return $this->droppedForeigns; }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    protected function addColumn(string $name, string $type, array $attributes = []): ColumnDefinition
    {
        $col = new ColumnDefinition($name, $type, $attributes);
        $this->columns[] = $col;
        return $col;
    }

    protected function generateIndexName(string $type, array $columns): string
    {
        $table = str_replace(['#PREFIX#', '.'], ['', '_'], $this->table);
        return $table . '_' . implode('_', $columns) . '_' . $type;
    }
}
