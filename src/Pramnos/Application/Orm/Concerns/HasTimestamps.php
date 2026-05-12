<?php

declare(strict_types=1);

namespace Pramnos\Application\Orm\Concerns;

/**
 * Automatic `created_at` / `updated_at` timestamp management.
 *
 * When `$timestamps = true` (the default), OrmModel::_save() will:
 * - Set `created_at` to the current time when inserting a new record.
 * - Set `updated_at` to the current time on every save.
 *
 * Override the column names by redefining the constants in your model:
 * ```php
 * const CREATED_AT = 'created_on';
 * const UPDATED_AT = 'modified_on';
 * ```
 *
 * @package     PramnosFramework
 * @subpackage  Application\Orm\Concerns
 */
trait HasTimestamps
{
    /** Whether to auto-manage created_at / updated_at. */
    protected bool $timestamps = true;

    /** Column name for the creation timestamp. */
    protected string $createdAtColumn = 'created_at';

    /** Column name for the last-update timestamp. */
    protected string $updatedAtColumn = 'updated_at';

    /**
     * Touch the timestamp columns appropriate to the operation.
     *
     * Called from OrmModel::_save() before the actual DB write.
     *
     * @param bool $isNew  True for INSERT, false for UPDATE.
     */
    protected function touchTimestamps(bool $isNew): void
    {
        if (!$this->timestamps) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        if ($isNew && $this->createdAtColumn !== '') {
            $col = $this->createdAtColumn;
            if ($this->$col === null || $this->$col === '') {
                $this->$col = $now;
            }
        }

        if ($this->updatedAtColumn !== '') {
            $col = $this->updatedAtColumn;
            $this->$col = $now;
        }
    }

    /**
     * Disable automatic timestamp management for a single operation.
     * Returns $this for fluent chaining.
     */
    public function withoutTimestamps(): static
    {
        $this->timestamps = false;
        return $this;
    }
}
