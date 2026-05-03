<?php

namespace Pramnos\Database;

/**
 * Fluent descriptor for a single table column.
 *
 * Returned by all Blueprint column-type methods. Every modifier returns $this
 * so calls can be chained: $table->string('name', 100)->nullable()->default('').
 *
 * @package     PramnosFramework
 * @subpackage  Database
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2026 Yannis - Pastis Glaros, Pramnos Hosting
 */
class ColumnDefinition
{
    /** @var string Column name */
    public string $name;

    /**
     * Blueprint type name (e.g. 'integer', 'string', 'bigIncrements').
     * The SchemaGrammar maps this to a dialect-specific SQL type.
     *
     * @var string
     */
    public string $type;

    /** @var array<string, mixed> Modifier bag */
    public array $attributes;

    public function __construct(string $name, string $type, array $attributes = [])
    {
        $this->name       = $name;
        $this->type       = $type;
        $this->attributes = $attributes;
    }

    // -------------------------------------------------------------------------
    // Nullability
    // -------------------------------------------------------------------------

    public function nullable(bool $value = true): static
    {
        $this->attributes['nullable'] = $value;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Default value
    // -------------------------------------------------------------------------

    /**
     * @param  mixed $value  Scalar value, null, or a raw SQL string wrapped in
     *                       Expression.  Booleans are handled by the Grammar.
     */
    public function default($value): static
    {
        $this->attributes['default']    = $value;
        $this->attributes['hasDefault'] = true;
        return $this;
    }

    public function useCurrent(): static
    {
        return $this->default(new Expression('CURRENT_TIMESTAMP'));
    }

    // -------------------------------------------------------------------------
    // Numeric modifiers
    // -------------------------------------------------------------------------

    public function unsigned(): static
    {
        $this->attributes['unsigned'] = true;
        return $this;
    }

    public function autoIncrement(): static
    {
        $this->attributes['autoIncrement'] = true;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Key / constraint modifiers
    // -------------------------------------------------------------------------

    /** Mark this column as (part of) the primary key. */
    public function primary(): static
    {
        $this->attributes['primary'] = true;
        return $this;
    }

    /** Add a UNIQUE constraint on this column. */
    public function unique(): static
    {
        $this->attributes['unique'] = true;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Positioning (MySQL only — silently ignored by PostgreSQL grammar)
    // -------------------------------------------------------------------------

    public function after(string $column): static
    {
        $this->attributes['after'] = $column;
        return $this;
    }

    public function first(): static
    {
        $this->attributes['first'] = true;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------------

    public function comment(string $text): static
    {
        $this->attributes['comment'] = $text;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Check constraint
    // -------------------------------------------------------------------------

    public function check(string $expression): static
    {
        $this->attributes['check'] = $expression;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Computed columns
    // -------------------------------------------------------------------------

    public function storedAs(string $expression): static
    {
        $this->attributes['storedAs'] = $expression;
        return $this;
    }

    public function virtualAs(string $expression): static
    {
        $this->attributes['virtualAs'] = $expression;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Character set (MySQL only)
    // -------------------------------------------------------------------------

    public function charset(string $charset): static
    {
        $this->attributes['charset'] = $charset;
        return $this;
    }

    public function collation(string $collation): static
    {
        $this->attributes['collation'] = $collation;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Attribute accessor
    // -------------------------------------------------------------------------

    /**
     * @param  string $attribute
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $attribute, $default = null)
    {
        return $this->attributes[$attribute] ?? $default;
    }
}
