<?php

namespace Pramnos\Database;

/**
 * Fluent descriptor for a FOREIGN KEY constraint.
 *
 * Returned by Blueprint::foreign(). Chain references(), on(), onDelete(), onUpdate()
 * to fully specify the constraint.
 *
 * @package     PramnosFramework
 * @subpackage  Database
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2026 Yannis - Pastis Glaros, Pramnos Hosting
 */
class ForeignKeyDefinition
{
    /** @var string  Local column name */
    public string $column;

    /** @var string  Referenced table */
    public string $referencedTable = '';

    /** @var string  Referenced column */
    public string $referencedColumn = '';

    /** @var string  ON DELETE action */
    public string $onDelete = 'RESTRICT';

    /** @var string  ON UPDATE action */
    public string $onUpdate = 'RESTRICT';

    /** @var string|null  Explicit constraint name (auto-generated when null) */
    public ?string $constraintName = null;

    public function __construct(string $column)
    {
        $this->column = $column;
    }

    // -------------------------------------------------------------------------
    // Fluent setters
    // -------------------------------------------------------------------------

    public function references(string $column): static
    {
        $this->referencedColumn = $column;
        return $this;
    }

    public function on(string $table): static
    {
        $this->referencedTable = $table;
        return $this;
    }

    public function onDelete(string $action): static
    {
        $this->onDelete = strtoupper($action);
        return $this;
    }

    public function onUpdate(string $action): static
    {
        $this->onUpdate = strtoupper($action);
        return $this;
    }

    /** Override the auto-generated constraint name. */
    public function constraintName(string $name): static
    {
        $this->constraintName = $name;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Cascade shortcuts
    // -------------------------------------------------------------------------

    public function cascadeOnDelete(): static
    {
        return $this->onDelete('CASCADE');
    }

    public function cascadeOnUpdate(): static
    {
        return $this->onUpdate('CASCADE');
    }

    public function nullOnDelete(): static
    {
        return $this->onDelete('SET NULL');
    }

    public function noActionOnDelete(): static
    {
        return $this->onDelete('NO ACTION');
    }
}
