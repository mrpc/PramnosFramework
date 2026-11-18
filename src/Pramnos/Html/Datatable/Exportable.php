<?php

declare(strict_types=1);

namespace Pramnos\Html\Datatable;

/**
 * Implemented by the model behind a `Datatable` with `backgroundExport = true`.
 *
 * `exportBatch()` is a plain LIMIT/OFFSET fetch — the same rows the grid's own
 * AJAX action already produces, without the DataTables request/response wrapper.
 * Rows are assoc arrays keyed however the application likes; those same keys
 * are what `DatatableExportTask`'s declared columns pull from each row.
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
     * @return array<int, array<string, mixed>>
     */
    public function exportBatch(array $filters, int $limit, int $offset): array;
}
