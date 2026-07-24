<?php

// NOTE: no declare(strict_types=1) — the implementers (Model / User) run in
// coercive mode and the pass-through parameters here mirror their loose,
// untyped list-method signatures. Enabling strict types would break the
// bool-coerced $join/$order arguments those methods tolerate.
namespace Pramnos\Application\ApiList;

/**
 * The contract a "listable" object exposes to {@see ApiListQuery} so the engine
 * can orchestrate a list request without knowing how the object stores its
 * schema, builds search conditions, fetches rows or counts them.
 *
 * {@see \Pramnos\Application\Model} implements this by delegating to its existing
 * internals ({@see \Pramnos\Application\Model::_getAllTableFields()},
 * `_buildSearchConditions()`, `_getPaginated()`, `_getList()`,
 * `_processJsonFields()`, `_datatablesRecordsTotal()`, `$sqlError`). A future
 * {@see \Pramnos\User\User} implementation (Phase 4) will provide its flat
 * users-table equivalents so it shares the exact same orchestration and response
 * shaping instead of re-implementing them.
 *
 * The genuinely stateless SQL fragment building (SELECT / ORDER BY / structured
 * filter / combine) is NOT part of this contract — the engine uses
 * {@see ApiListSqlBuilder} directly for that.
 */
interface ApiListSource
{
    /**
     * All selectable field names for the list (bare, or "table.field" for joins).
     *
     * @param string $join The JOIN clause (joined-table columns are included).
     * @return array
     */
    public function apiListSchemaFields($join = ''): array;

    /**
     * The field list to use when the caller requested none (or only invalid
     * ones). Separate from {@see self::apiListSchemaFields()} so a source can
     * validate against its full schema yet default to a safe/curated subset —
     * e.g. a user picker defaults to id/username/email and never dumps every
     * column (including sensitive ones) just because no fields were requested.
     * A source with no such distinction returns the same as its schema.
     *
     * @param string $join The JOIN clause.
     * @return array
     */
    public function apiListDefaultFields($join = ''): array;

    /**
     * The primary-key column name (used to force-include the PK and as the
     * default order key).
     *
     * @return string
     */
    public function apiListPrimaryKey(): string;

    /**
     * Build the search WHERE body (without the WHERE keyword) for a global search
     * term and/or per-field searches over the validated field list.
     *
     * @param array  $validFields   The validated, selectable fields.
     * @param string $globalSearch  Global search term ('' when none).
     * @param array  $fieldSearches Per-field search map ([] when none).
     * @param string $join          The JOIN clause.
     * @return string
     */
    public function apiListSearchConditions(array $validFields, $globalSearch, array $fieldSearches, $join): string;

    /**
     * Fetch one page of rows plus the total/pages counts (filter + search
     * applied). Mirrors {@see \Pramnos\Application\Model::_getPaginated()} and
     * returns its ['total','pages','items'] shape.
     *
     * @return array
     */
    public function apiListPaginate(
        $itemsPerPage, $page, $filter, $order, $table, $key, $debug,
        $join, $selectFields, $group, $returnAsModels, $useGetData, $customGetListMethod, $addedfields
    ): array;

    /**
     * Fetch every matching row (no pagination). Mirrors
     * {@see \Pramnos\Application\Model::_getList()} (returns its array, or a
     * falsy value on failure — inspect {@see self::apiListLastError()}).
     *
     * @return mixed
     */
    public function apiListFetchAll(
        $filter, $order, $table, $key, $debug,
        $join, $selectFields, $group, $returnAsModels, $useGetData, $customGetListMethod, $addedfields
    );

    /**
     * Decode/normalise any JSON columns in a single result row.
     *
     * @param array  $row  One result row.
     * @param string $join The JOIN clause (affects field-type resolution).
     * @return array
     */
    public function apiListProcessRow(array $row, $join): array;

    /**
     * The last query error message, or null/'' when the last fetch succeeded.
     *
     * @return mixed
     */
    public function apiListLastError();

    /**
     * The unfiltered (search-less) row count for the DataTables `recordsTotal`
     * field — the grand total with any base $filter/$join/$group still in effect.
     *
     * @return int
     */
    public function apiListRecordsTotal($baseFilter, $table, $key, $join, $selectFields, $group, $addedfields): int;
}
