<?php

declare(strict_types=1);

namespace Pramnos\Application\Orm\Concerns;

/**
 * Soft-delete support for OrmModel.
 *
 * When `$softDelete = true`, calling `delete()` (or the internal `_delete()`)
 * sets `deleted_at` to the current timestamp instead of issuing a hard DELETE.
 * Records with a non-null `deleted_at` are automatically excluded from queries
 * unless `withTrashed()` or `onlyTrashed()` is called.
 *
 * ```php
 * class Post extends OrmModel {
 *     protected bool $softDelete = true;
 * }
 *
 * $post->delete();                    // sets deleted_at, NOT a hard DELETE
 * $post->restore();                   // clears deleted_at
 * $post->forceDelete();               // actual hard DELETE
 * $post->trashed();                   // true / false
 * ```
 *
 */
trait HasSoftDeletes
{
    /** Enable soft-delete behaviour.  Off by default (opt-in). */
    protected bool $softDelete = false;

    /** Column that stores the soft-delete timestamp. */
    protected string $deletedAtColumn = 'deleted_at';

    /**
     * If true, soft-deleted records are included in queries.
     * Toggle via withTrashed() / onlyTrashed().
     */
    protected bool $withTrashedFlag  = false;

    /** If true, only soft-deleted records are returned. */
    protected bool $onlyTrashedFlag  = false;

    // -------------------------------------------------------------------------
    // Query scope helpers
    // -------------------------------------------------------------------------

    /**
     * Include soft-deleted records in the next query.
     *
     * @return static
     */
    public function withTrashed(): static
    {
        $this->withTrashedFlag = true;
        $this->onlyTrashedFlag = false;
        return $this;
    }

    /**
     * Return only soft-deleted records in the next query.
     *
     * @return static
     */
    public function onlyTrashed(): static
    {
        $this->onlyTrashedFlag = true;
        $this->withTrashedFlag = false;
        return $this;
    }

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /**
     * Return true if this instance has been soft-deleted (deleted_at is set).
     */
    public function trashed(): bool
    {
        $col = $this->deletedAtColumn;
        return $this->softDelete && !empty($this->$col);
    }

    // -------------------------------------------------------------------------
    // Operations
    // -------------------------------------------------------------------------

    /**
     * Soft-delete this record (set deleted_at = now).
     * The record remains in the database; queries filter it out automatically.
     *
     * @return static
     */
    public function softDelete(): static
    {
        if (!$this->softDelete) {
            return $this;
        }
        $col        = $this->deletedAtColumn;
        $this->$col = date('Y-m-d H:i:s');
        $this->_save();
        return $this;
    }

    /**
     * Restore a soft-deleted record by clearing deleted_at.
     *
     * @return static
     */
    public function restore(): static
    {
        if (!$this->softDelete) {
            return $this;
        }
        $col        = $this->deletedAtColumn;
        $this->$col = null;
        $this->_save();
        return $this;
    }

    /**
     * Build the soft-delete WHERE fragment for use in _getList() / _load().
     * Returns an empty string when soft-deletes are not enabled.
     */
    protected function buildSoftDeleteFilter(): string
    {
        if (!$this->softDelete) {
            return '';
        }
        $col = $this->deletedAtColumn;
        if ($this->onlyTrashedFlag) {
            return "{$col} IS NOT NULL";
        }
        if ($this->withTrashedFlag) {
            return '';
        }
        return "{$col} IS NULL";
    }

    /**
     * Combine an existing user filter with the soft-delete clause.
     */
    protected function mergeSoftDeleteFilter(?string $filter): string
    {
        $softFilter = $this->buildSoftDeleteFilter();
        if ($softFilter === '') {
            return $filter ?? '';
        }
        if ($filter === null || $filter === '') {
            return $softFilter;
        }
        return "({$filter}) AND ({$softFilter})";
    }
}
