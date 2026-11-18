<?php

declare(strict_types=1);

namespace Pramnos\Html\Datatable;

/**
 * Implemented by the model behind a `Datatable` with `backgroundExport = true`.
 *
 * `exportBatch()` is a plain LIMIT/OFFSET fetch — the same rows the grid's own
 * AJAX action already produces, without the DataTables request/response wrapper.
 *
 * **Row shape.** `DatatableExportTask` pulls each column's value from a row by
 * `Column::$exportKey`, which defaults to the column's position among `aoColumns`
 * — the same positional index `renderJs()` injects as `"data": N` for server-side
 * mode. So the row shape that needs no per-column setup is the numeric-indexed
 * array `Datasource::getList()` already returns, i.e. `exportBatch()` delegating
 * straight to the same model method that serves the grid. A method that instead
 * returns assoc rows keyed by field name needs `$exportKey` set per column to
 * that field name.
 *
 * **Isolation level.** `DatatableExportTask` wraps the whole batch loop in one
 * transaction and sets `REPEATABLE READ` as its first statement. An
 * `exportBatch()` that itself issues a session/transaction isolation-level
 * statement (a legacy `getList()` doing its own `SET … ISOLATION LEVEL` before
 * or after the query, say) will break inside that transaction — PostgreSQL
 * refuses a second such statement once any query has run. Guard it with
 * `Database::inTransaction()`, or drop it: the wrapping transaction already set
 * the level every batch needs.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
interface Exportable
{
    /**
     * Fetch one batch of export rows.
     *
     * @param array<string, mixed> $filters The table's current AJAX filters
     *                                       (`DataTable.ajax.params()`, as queued).
     * @param int $limit  Rows to return, at most.
     * @param int $offset Rows to skip.
     * @return array<int, array<int|string, mixed>>
     */
    public function exportBatch(array $filters, int $limit, int $offset): array;
}
