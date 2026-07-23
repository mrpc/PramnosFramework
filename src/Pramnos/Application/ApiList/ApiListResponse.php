<?php

// NOTE: no declare(strict_types=1) — matches the coercive mode of the callers
// (Pramnos\Application\Model / Pramnos\User\User) that this shapes responses for.
namespace Pramnos\Application\ApiList;

/**
 * Builds the response envelopes shared by every list endpoint — the standard
 * `{data, pagination, fields[, debug]}` shape, the DataTables 2.x
 * `{draw, data, recordsTotal, recordsFiltered}` shape, and the error shape.
 *
 * Phase 3 of the ApiListQuery extraction. The envelope construction was
 * previously duplicated across {@see \Pramnos\Application\Model::_getApiList()}
 * (four return sites) and {@see \Pramnos\User\User::_getApiList()} (its
 * hand-written re-implementation). Centralising it here gives one source of
 * truth for the keys, the `draw` echo and the recordsTotal/recordsFiltered
 * placement — the exact area where the two copies had previously drifted (the
 * recordsTotal fix had to be applied twice). The row counts themselves are
 * still computed by each caller and passed in, so data-access behaviour is
 * unchanged.
 *
 * `$debug` is nullable so the byte-for-byte shape of both callers is preserved:
 * Model includes a `debug` sub-array, the User override does not.
 */
final class ApiListResponse
{
    /**
     * The standard paginated envelope.
     *
     * @param array      $data         The result rows.
     * @param int        $page         Current page (1-based).
     * @param int        $itemsPerPage Page size.
     * @param int        $total        Total matching rows (filter + search).
     * @param int        $pages        Total page count.
     * @param array      $fields       The returned field names.
     * @param array|null $debug        Optional debug sub-array; omitted when null.
     * @return array
     */
    public static function paginated($data, $page, $itemsPerPage, $total, $pages, array $fields, ?array $debug = null): array
    {
        $response = array(
            'data' => $data,
            'pagination' => array(
                'currentpage'  => $page,
                'itemsperpage' => $itemsPerPage,
                'totalitems'   => $total,
                'totalpages'   => $pages,
                'hasnext'      => $page < $pages,
                'hasprevious'  => $page > 1,
            ),
            'fields' => $fields,
        );
        if ($debug !== null) {
            $response['debug'] = $debug;
        }
        return $response;
    }

    /**
     * The standard un-paginated envelope (pagination is null).
     *
     * @param array      $data   The result rows.
     * @param array      $fields The returned field names.
     * @param array|null $debug  Optional debug sub-array; omitted when null.
     * @return array
     */
    public static function unpaginated($data, array $fields, ?array $debug = null): array
    {
        $response = array(
            'data'       => $data,
            'pagination' => null,
            'fields'     => $fields,
        );
        if ($debug !== null) {
            $response['debug'] = $debug;
        }
        return $response;
    }

    /**
     * The DataTables 2.x envelope. `draw` is echoed from the request (anti-CSRF
     * counter). recordsTotal is the grand total before the search box;
     * recordsFiltered is the count after it — both supplied by the caller.
     *
     * @param array $data            The result rows.
     * @param int   $recordsTotal    Grand total (search-less).
     * @param int   $recordsFiltered Total after the search filter.
     * @return array
     */
    public static function datatables($data, $recordsTotal, $recordsFiltered): array
    {
        return array(
            'draw'            => (int) ($_REQUEST['draw'] ?? 0),
            'data'            => $data,
            'recordsTotal'    => (int) $recordsTotal,
            'recordsFiltered' => (int) $recordsFiltered,
        );
    }

    /**
     * The error envelope (empty data, null pagination).
     *
     * @param string     $message The error message.
     * @param array      $fields  The returned field names.
     * @param array|null $debug   Optional debug sub-array; omitted when null.
     * @return array
     */
    public static function error(string $message, array $fields, ?array $debug = null): array
    {
        $response = array(
            'error'      => $message,
            'data'       => array(),
            'pagination' => null,
            'fields'     => $fields,
        );
        if ($debug !== null) {
            $response['debug'] = $debug;
        }
        return $response;
    }
}
