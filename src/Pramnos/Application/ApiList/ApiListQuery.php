<?php

// NOTE: no declare(strict_types=1) — this orchestration was extracted verbatim
// from Pramnos\Application\Model::_getApiList() (coercive mode) and its callers
// rely on scalar coercion (e.g. a bool false $join coerced to '').
namespace Pramnos\Application\ApiList;

/**
 * The list-query engine: orchestrates a list request (parse → validate → build →
 * fetch → shape) over any {@see ApiListSource}, so {@see \Pramnos\Application\Model}
 * and (Phase 4) {@see \Pramnos\User\User} share one implementation instead of
 * maintaining parallel copies.
 *
 * Stateless SQL fragment building is delegated to {@see ApiListSqlBuilder}; the
 * schema, search conditions, row fetching, JSON processing and record counting
 * come from the injected {@see ApiListSource}; the response envelopes come from
 * {@see ApiListResponse}. This is the extraction of the former
 * {@see \Pramnos\Application\Model::_getApiList()} body — behaviour is preserved
 * verbatim (guarded by the ModelListApi* characterization suites).
 */
final class ApiListQuery
{
    /**
     * Run a list request against $source and return the API envelope.
     *
     * The parameters mirror {@see \Pramnos\Application\Model::_getApiList()} 1:1.
     *
     * @param ApiListSource $source              The listable object.
     * @param array|string  $fields              Field list (array, CSV or JSON).
     * @param array|string  $search              Global term, or per-field map/JSON.
     * @param string        $order               Order spec (+/-, ASC/DESC tokens).
     * @param array|string  $filter              Raw WHERE string or structured array.
     * @param string        $join                Raw JOIN clause.
     * @param string        $group               GROUP BY clause.
     * @param string|null   $table               Table override.
     * @param string|null   $key                 Primary-key override.
     * @param int           $page                Page (0 = no pagination).
     * @param int           $itemsPerPage        Page size.
     * @param bool          $debug               Dump SQL and die (debug only).
     * @param bool          $returnAsModels      Hydrate rows into model objects.
     * @param bool          $useGetData          Return getData() payloads.
     * @param string|false  $customGetListMethod Custom per-row method name.
     * @param array|false   $addedfields         Extra fields to keep when pruning.
     * @param string        $format              '' (standard) or 'datatables'.
     * @return array
     */
    public static function run(
        ApiListSource $source,
        $fields = array(), $search = '', $order = '', $filter = '', $join = '', $group = '',
        $table = null, $key = null,
        $page = 0, $itemsPerPage = 10, $debug = false, $returnAsModels = false, $useGetData = false,
        $customGetListMethod = false, $addedfields = false, $format = ''
    ): array {
        // Handle unified search parameter
        $globalSearch = '';
        $fieldSearches = array();

        if (is_string($search)) {
            // Check if the string is a JSON object with field-specific searches
            $decodedSearch = json_decode(urldecode($search), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedSearch)) {
                $fieldSearches = $decodedSearch;
            } else {
                $globalSearch = $search;
            }
        } elseif (is_array($search)) {
            $fieldSearches = $search;
        }

        if (is_string($fields) && trim($fields) != '') {
            // check if it's a json array
            $decodedFields = json_decode(urldecode($fields), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedFields)) {
                $fields = $decodedFields;
            } else {
                // If not JSON, assume it's a comma-separated string
                $fields = array_map('trim', explode(',', $fields));
            }
        }

        $availableFields = $source->apiListSchemaFields($join);
        // Default to the source's default field set when none were specified
        // (a source may curate this to a safe subset of its full schema).
        if (empty($fields)) {
            $fields = $source->apiListDefaultFields($join);
        }

        // Validate and sanitize fields
        $validFields = array();
        foreach ($fields as $field) {
            $field = trim($field);
            if (!empty($field)) {
                if (in_array($field, $availableFields)) {
                    $validFields[] = $field;
                } else {
                    // Try to find the field matching ignoring table prefix (alias)
                    foreach ($availableFields as $avail) {
                        if (strpos($avail, '.') !== false) {
                            $unprefixed = substr($avail, strpos($avail, '.') + 1);
                            if ($unprefixed === $field) {
                                $validFields[] = $avail;
                                break;
                            }
                        }
                    }
                }
            }
        }

        if (empty($validFields)) {
            $validFields = $source->apiListDefaultFields($join);
        }

        $returnedFields = array();

        // remove table prefixes from validFields
        foreach ($validFields as $field) {
            if (strpos($field, '.') !== false) {
                $returnedFields[substr($field, strpos($field, '.') + 1)] = substr($field, strpos($field, '.') + 1);
            } else {
                $returnedFields[$field] = $field;
            }
        }
        $returnedFields = array_values($returnedFields);

        // Always ensure primary key is included
        if ($key !== null && $key != "") {
            $primaryKey = $key;
        } else {
            $primaryKey = $source->apiListPrimaryKey();
        }

        if (!in_array($primaryKey, $validFields)) {
            array_unshift($validFields, $primaryKey);
        }

        // Build field selection for query
        $selectFields = ApiListSqlBuilder::buildSelectFields($validFields, $join);

        // Build search conditions
        $searchConditions = $source->apiListSearchConditions($validFields, $globalSearch, $fieldSearches, $join);

        // Validate and build order clause
        $validatedOrder = ApiListSqlBuilder::validateAndBuildOrder($order, $validFields, $join, $primaryKey);

        // If $filter is an array, build a safe SQL fragment from structured conditions.
        // Each entry: ['field' => 'name', 'op' => '=', 'value' => 'x']
        // Operators without a value: IS NULL, IS NOT NULL
        // For IN / NOT IN, value must be an array.
        // Unknown fields are silently skipped.
        // If $filter is a string it is passed through as-is (backward compatible).
        if (is_array($filter)) {
            $filter = ApiListSqlBuilder::buildFilterFromConditions($filter, $availableFields, $join);
        }

        // Combine filter and search conditions.
        // combineFilters returns '' when both inputs are empty, or 'where ...' otherwise.
        $finalFilter = ApiListSqlBuilder::combineFilters($filter, $searchConditions);

        // Check if pagination is requested
        if ($page > 0) {

            try {
                $result = $source->apiListPaginate(
                    $itemsPerPage, $page, $finalFilter, $validatedOrder, $table, $key, $debug,
                    $join, $selectFields, $group, $returnAsModels, $useGetData, $customGetListMethod, $addedfields
                );
            } catch (\Exception $ex) {
                return ApiListResponse::error(
                    'Database query failed: ' . $ex->getMessage(),
                    $returnedFields,
                    array('filter' => $finalFilter, 'order' => $validatedOrder, 'selectFields' => $selectFields)
                );
            }

            if (isset($result['items']) && is_array($result['items'])) {
                $result['items'] = array_values($result['items']);

                // Process JSON fields for each item
                foreach ($result['items'] as $index => $item) {
                    if (is_array($item)) {
                        $result['items'][$index] = $source->apiListProcessRow($item, $join);
                    }
                }
            }

            $standardResponse = ApiListResponse::paginated(
                $result['items'], $page, $itemsPerPage, $result['total'], $result['pages'],
                $returnedFields,
                array('filter' => $finalFilter, 'order' => $validatedOrder, 'selectFields' => $selectFields)
            );

            if ($format === 'datatables') {
                // DataTables distinguishes the grand total (recordsTotal, the
                // count BEFORE the search box) from the filtered total
                // (recordsFiltered, AFTER it). $result['total'] was counted with
                // filter + search, so it IS recordsFiltered. recordsTotal must
                // exclude the search — recompute it from the base $filter only.
                // When no search is active the two are identical, so skip the
                // extra query.
                $recordsFiltered = (int) $result['total'];
                $recordsTotal    = $searchConditions !== ''
                    ? $source->apiListRecordsTotal(
                        ApiListSqlBuilder::combineFilters($filter, ''),
                        $table, $key, $join, $selectFields, $group, $addedfields
                    )
                    : $recordsFiltered;
                return ApiListResponse::datatables(
                    $standardResponse['data'] ?? [], $recordsTotal, $recordsFiltered
                );
            }

            return $standardResponse;
        } else {
            // Get all results without pagination
            $result = $source->apiListFetchAll(
                $finalFilter, $validatedOrder, $table, $key, $debug,
                $join, $selectFields, $group, $returnAsModels, $useGetData,
                $customGetListMethod, $addedfields
            );
            if (empty($result) && $source->apiListLastError()) {
                return ApiListResponse::error(
                    $source->apiListLastError(),
                    $returnedFields,
                    array('filter' => $finalFilter, 'order' => $validatedOrder, 'selectFields' => $selectFields)
                );
            }

            if (isset($result) && is_array($result)) {
                $result = array_values($result);

                // Process JSON fields for each item
                foreach ($result as $index => $item) {
                    if (is_array($item)) {
                        $result[$index] = $source->apiListProcessRow($item, $join);
                    }
                }
            }

            // Format response for API without pagination
            $standardResponse = ApiListResponse::unpaginated(
                $result, $returnedFields,
                array('filter' => $finalFilter, 'order' => $validatedOrder, 'selectFields' => $selectFields)
            );

            if ($format === 'datatables') {
                $data = $standardResponse['data'] ?? [];
                // Unpaginated: $data holds every row matching filter + search, so
                // its count is recordsFiltered. recordsTotal excludes the search
                // (base $filter only); identical when no search is active.
                $recordsFiltered = is_array($data) ? count($data) : 0;
                $recordsTotal    = $searchConditions !== ''
                    ? $source->apiListRecordsTotal(
                        ApiListSqlBuilder::combineFilters($filter, ''),
                        $table, $key, $join, $selectFields, $group, $addedfields
                    )
                    : $recordsFiltered;
                return ApiListResponse::datatables(
                    is_array($data) ? $data : [], $recordsTotal, $recordsFiltered
                );
            }

            return $standardResponse;
        }
    }
}
