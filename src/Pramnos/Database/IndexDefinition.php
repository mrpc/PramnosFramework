<?php

declare(strict_types=1);

namespace Pramnos\Database;

/**
 * Fluent handle returned by Blueprint::index() / Blueprint::unique() so an index
 * can be refined after it is declared — currently to make it a PARTIAL index with
 * a WHERE predicate:
 *
 * ```php
 * $table->index(['banned_until'], 'idx_active')->where('banned_until IS NOT NULL');
 * $table->unique('email', 'uq_email')->where('email IS NOT NULL');
 * ```
 *
 * Column ordering (e.g. "created_at DESC") is expressed directly in the column
 * list — the grammar quotes the identifier and preserves a trailing ASC/DESC.
 *
 * Backward compatible: index()/unique() previously returned void, so existing
 * callers that ignore the return value are unaffected.
 */
class IndexDefinition
{
    public function __construct(
        private readonly Blueprint $blueprint,
        private readonly string $kind,   // 'index' | 'unique'
        private readonly int $key
    ) {
    }

    /**
     * Make this a partial index: only rows matching the raw SQL predicate are
     * indexed. Passed through verbatim, so use dialect-appropriate SQL.
     */
    public function where(string $condition): static
    {
        $this->blueprint->setIndexWhere($this->kind, $this->key, $condition);
        return $this;
    }
}
