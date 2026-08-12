<?php

namespace Pramnos\Html\Datatable;

use Pramnos\Framework\Base;

/**
 * Data feed for database
 * @todo        Add Edit Functions
 * @todo        Add callback functions
 * @todo        Alternative method to count rows
 * @todo        Documentation ρε
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Datasource extends Base
{

    /**
     * Request key used to mark that the current $_POST has already been
     * translated from the DataTables 1.10+ parameter format to the legacy one.
     * @var string
     */
    const MODERN_REQUEST_FLAG = 'pramnosDatatableModernRequest';

    public $fields = array();
    public $fielddetails = array();
    public $maxlimit = '50';
    public $idrow = 0;

    public function addField($name, $format = 'text', $formatdetails = '',
        $startWildcard = true, $endWildcard = true)
    {
        $this->fields[] = $name;
        $this->fielddetails[$name] = array(
            'format' => $format,
            'formatdetails' => $formatdetails,
            'startWildcard' => $startWildcard,
            'endWildcard' => $endWildcard
        );
    }

    /**
     * Normalizes a DataTables 1.10+ boolean column flag (searchable/orderable)
     * to the "true"/"false" strings the legacy parameter format uses.
     *
     * DataTables posts form-encoded, so flags normally arrive as the strings
     * "true"/"false", but a JSON body or a hand-crafted request may send real
     * booleans or 0/1 - all of them have to map to the same two strings.
     * @param mixed $value Value as received in the request
     * @return string Either "true" or "false"
     */
    protected static function dtFlagToString($value)
    {
        if ($value === false || $value === 0 || $value === '0'
            || $value === 'false' || $value === '') {
            return 'false';
        }
        return 'true';
    }

    /**
     * Automates the proccess of getting data
     * from a database table to feed a datatables object
     * @param string $table Database table
     * @param array $fields An array with all the fields that you need
     * @param boolean $encode True if you want to return as a json object
     * @param string $where The "where" part of the sql statement
     * @param string $join The Join part of the sql statement
     * @param boolean $cache Use cache or no
     * @param integer $cachetime Cache time to live, in seconds
     * @param string $cachecategory Cache category
     * @param boolean $debug Show debug information
     * @param string $iconv If webpage is not encoded in utf8, specify encoding
     * @param string $distinctField Select a field to be distinct
     * @param string $whereWord
     * @return mixed a Json string or an array of data
     */
    public static function getList($table, $fields = NULL, $encode = true,
        $where = '', $join = '', $cache = true, $cachetime = 5,
        $cachecategory = "datatables",  $debug = false, $iconv = NULL,
        $distinctField='', $whereWord = 'where')
    {
        $data = new Datasource();
        return $data->render(
                $table, $fields, $encode, $where, $join, $cache, $cachetime,
                $cachecategory, $debug, $iconv, $distinctField, $whereWord
        );
    }

    /**
     * Automates the proccess of getting data
     * from a database table to feed a datatables object
     * @param string $table Database table
     * @param array $queryFields An array with all the fields that you need
     * @param boolean $encode True if you want to return as a json object
     * @param string $whereStatement The "where" part of the sql statement
     * @param string $join The Join part of the sql statement
     * @param boolean $cache Use cache or no
     * @param integer $cachetime Cache time to live, in seconds
     * @param string $cachecategory Cache category
     * @param boolean $debug Show debug information
     * @param string $iconv If webpage is not encoded in utf8, specify encoding
     * @param string $distinctField Select a field to be distinct
     * @param string $whereWord
     * @return mixed a Json string or an array of data
     */
    public function render($table = '', $queryFields = NULL, $encode = true,
        $whereStatement = '', $join = '', $cache = true, $cachetime = 5,
        $cachecategory = "datatables", $debug = false, $iconv = NULL,
        $distinctField='', $whereWord = 'where')
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if (is_array($queryFields)) {
            foreach ($queryFields as $field) {
                if (is_array($field)) {
                    call_user_func_array(array($this, 'addField'), $field);
                } else {
                    $this->addField($field);
                }
            }
        }
        
        $fields = $this->fields;
        if (!is_array($fields)) {
            $fields = [$fields];
        }

        $qb = $database->queryBuilder()->from($table . ' a');
        
        // Select fields with aliases if needed
        $selectFields = [];
        foreach ($fields as $field) {
            if ($field == $distinctField) continue;
            if (strpos($field, '.') === false) {
                $selectFields[] = "a.`$field`";
            } else {
                $selectFields[] = $field;
            }
        }
        
        if ($distinctField != '') {
            $qb->distinct();
            if (strpos($distinctField, '.') === false) {
                array_unshift($selectFields, "a.`$distinctField`");
            } else {
                array_unshift($selectFields, $distinctField);
            }
        }
        $qb->select($selectFields);

        $where = str_ireplace('where', ' ', $whereStatement);
        if ($debug == true) {
            echo "<pre>DEBUG MODE\n\n</pre>";
        }
        $request = \Pramnos\Framework\Factory::getRequest();
        if ($iconv !== NULL) {
            foreach ($_POST as $key => $value) {
                $_POST[$key] = iconv('utf-8', $iconv . '//IGNORE', $value);
            }
        }

        // Translate DataTables 1.10+ params (draw/start/length/search/order/columns)
        // into the legacy format (sEcho/iDisplayStart/iDisplayLength/sSearch/
        // sSearch_N/iSortCol_N/bSearchable_N) that the rest of this method reads.
        // BC-safe: legacy callers keep working.
        //
        // The translation writes legacy keys into $_POST, so it cannot use one of
        // them (sEcho) as its own "already translated" marker: a second render()
        // in the same request would then look like a legacy call and would answer
        // a modern client in the legacy format. A dedicated marker keeps repeat
        // calls idempotent.
        $alreadyTranslated = isset($_POST[self::MODERN_REQUEST_FLAG]);
        $isModernDT = isset($_POST['draw'])
            && ($alreadyTranslated || !isset($_POST['sEcho']));
        if ($isModernDT && !$alreadyTranslated) {
            $_POST[self::MODERN_REQUEST_FLAG] = true;
            $_POST['sEcho']         = (int)($_POST['draw'] ?? 1);
            $_POST['iDisplayStart'] = (int)($_POST['start'] ?? 0);
            // Page length: honor the modern `length`, else fall back to any
            // pre-set legacy `iDisplayLength` (tolerates the DT1.9→1.10 transition
            // where a caller mixes `draw` with the legacy param), else the default
            // page size. A length of -1 is DataTables' "show all" — preserve it so
            // the paging block below leaves the query unlimited, exactly as the
            // legacy path does. Any other non-positive value falls back to maxlimit.
            $dtLength               = (int)($_POST['length'] ?? $_POST['iDisplayLength'] ?? (int)$this->maxlimit);
            $_POST['iDisplayLength']= ($dtLength === -1 || $dtLength > 0) ? $dtLength : (int)$this->maxlimit;
            $dtSearch               = is_array($_POST['search'] ?? null)
                                    ? ($_POST['search']['value'] ?? '')
                                    : (string)($_POST['search'] ?? '');
            $_POST['sSearch']       = $dtSearch;
            if (isset($_POST['order']) && is_array($_POST['order'])) {
                $orders = array_values($_POST['order']);
                $_POST['iSortingCols'] = count($orders);
                foreach ($orders as $i => $o) {
                    $_POST['iSortCol_' . $i] = (int)($o['column'] ?? 0);
                    $_POST['sSortDir_' . $i] = ($o['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
                }
            }
            if (isset($_POST['columns']) && is_array($_POST['columns'])) {
                foreach ($_POST['columns'] as $i => $col) {
                    if (!is_array($col)) {
                        continue;
                    }
                    $_POST['bSearchable_' . $i] = self::dtFlagToString(
                        $col['searchable'] ?? true
                    );
                    $_POST['bSortable_' . $i] = self::dtFlagToString(
                        $col['orderable'] ?? true
                    );
                    // Per-column filtering: a column search box, or fnFilter()
                    // with a column index, arrives as columns[N][search][value]
                    // and feeds the "Individual column filtering" block below.
                    // Never overwrite a value the caller pre-set in $_POST: that
                    // is how applications inject their own server-side filters.
                    if (!isset($_POST['sSearch_' . $i])) {
                        $_POST['sSearch_' . $i] = is_array($col['search'] ?? null)
                            ? (string)($col['search']['value'] ?? '')
                            : (string)($col['search'] ?? '');
                    }
                }
            }
        }

        if ($join != '') {
            $qb->joinRaw($join);
        }
        if ($where != '') {
            $qb->whereRaw($where);
        }

        /* Paging */
        if (isset($_POST['iDisplayStart'])) {
            $length = $request->get('iDisplayLength', $this->maxlimit, 'post');
            if ($length != "-1") {
                $qb->limit((int)$length)->offset((int)$request->get('iDisplayStart', '0', 'post'));
            }
        } else {
            $qb->limit((int)$this->maxlimit);
        }

        /* Ordering */
        if (isset($_POST['iSortCol_0'])) {
            $sortingCols = (int)$request->get('iSortingCols', '0', 'post');
            for ($i = 0; $i < $sortingCols; $i++) {
                $sortColIndex = (int)$request->get('iSortCol_' . $i, '0', 'post');
                $sortDir = $request->get('sSortDir_' . $i, 'asc', 'post');
                
                if (isset($fields[$sortColIndex])) {
                    $sortField = $fields[$sortColIndex];
                    if (strpos($sortField, ' as ') !== false) {
                        $sortField = substr($sortField, 0, strpos($sortField, ' as '));
                    }
                    $qb->orderBy($sortField, $sortDir);
                }
            }
        }

        /* Filtering */
        // Whether anything narrowed the result set. When nothing did, the
        // filtered count is by definition the unfiltered one, and asking the
        // database the same question twice is exactly what it looks like: two
        // identical COUNT(*) statements, back to back, on every page of every
        // datatable that nobody has typed a search into — which is most of them.
        $isFiltered = false;

        $searchTerm = $request->get('sSearch', '', 'post');
        if ($searchTerm != "") {
            $hasSearchable = false;
            foreach ($fields as $i => $field) {
                if (isset($_POST['bSearchable_' . $i]) && $_POST['bSearchable_' . $i] == "true") {
                    $hasSearchable = true;
                    break;
                }
            }
            if ($hasSearchable) {
                $isFiltered = true;
                $qb->where(function($query) use ($fields, $searchTerm, $database) {
                    foreach ($fields as $i => $field) {
                        if (isset($_POST['bSearchable_' . $i]) && $_POST['bSearchable_' . $i] == "true") {
                            if (strpos($field, ' as ') !== false) {
                                $field = explode(' as ', $field)[0];
                            }

                            $column = strpos($field, '.') === false ? "a.`$field`" : $field;
                            $query->orWhere($column, 'LIKE', '%' . $searchTerm . '%');
                        }
                    }
                });
            }
        }

        /* Individual column filtering */
        foreach ($fields as $i => $field) {
            $colSearch = $request->get('sSearch_' . $i, '', 'post');
            if ($colSearch != "" && isset($_POST['bSearchable_' . $i]) && $_POST['bSearchable_' . $i] == "true") {
                $startWildcard = (@$this->fielddetails[$field]['startWildcard'] == true) ? '%' : '';
                $endWildcard = (@$this->fielddetails[$field]['endWildcard'] == true) ? '%' : '';
                
                if (strpos($field, ' as ') !== false) {
                    $field = explode(' as ', $field)[0];
                }
                
                $column = strpos($field, '.') === false ? "a.`$field`" : $field;
                $isFiltered = true;
                $qb->where($column, 'LIKE', $startWildcard . $colSearch . $endWildcard);
            }
        }

        // First count: Total records without filtering.
        $totalQb = $database->queryBuilder()->from($table . ' a');
        if ($join != '') $totalQb->joinRaw($join);
        if ($where != '') $totalQb->whereRaw($where);
        try {
            // Cached on the same terms as the rows it counts. A caller that
            // asked for caching got its page of results from cache and then
            // paid for a full COUNT(*) anyway — which on a large table is the
            // expensive half of the request.
            $total = $totalQb->count($cache, $cachetime, $cachecategory);
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::log('Error in Datasource total count: ' . $ex->getMessage());
            $total = 0;
        }

        // Second count: Total records with filtering (but no limit).
        // QB::count() clones internally, so ORDER BY / LIMIT / OFFSET are stripped automatically.
        //
        // Skipped entirely when nothing was filtered: the two queries would be
        // character-for-character identical, and on a large table the count is
        // the most expensive statement the request makes.
        if (!$isFiltered) {
            $totalDisplay = $total;
        } else {
            try {
                $totalDisplay = $qb->count($cache, $cachetime, $cachecategory);
            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::log('Error in Datasource filtered count: ' . $ex->getMessage());
                $totalDisplay = 0;
            }
        }

        if ($debug) {
            echo '<pre>Final Query: ' . $qb->toSql() . "\n\n</pre>";
        }

        try {
            $result = $qb->get($cache, $cachetime, $cachecategory);
        } catch (\Throwable $ex) {
            $message = 'Error in Datasource render: ' . $ex->getMessage() . '. SQL: ' . $qb->toSql();
            \Pramnos\Logs\Logger::log($message);
            throw new \Exception($message, (int)$ex->getCode(), $ex);
        }

        if ($result === false || $result === null) {
            $message = 'Error in Datasource render: query returned no result. SQL: ' . $qb->toSql();
            \Pramnos\Logs\Logger::log($message);
            throw new \Exception($message);
        }

        $return = array();
        while ($result->fetch(true)) {
            $fielddetails = array_keys($this->fielddetails);
            $i = 0;
            foreach ($result->fields as $field) {
                if (is_string($field)) {
                    $field = trim(
                        str_replace(array("\n", "\t", "\r"), " ", $field ?? '')
                    ); //Fixed for exporting to Excel
                } elseif (is_null($field)) {
                    $field = '';
                }
                
                if (is_bool($field)) {
                    $field = $field ? 't' : 'f';
                } elseif ($iconv !== NULL && !is_numeric($field) && $iconv != 'utf-8') {
                    $field = iconv($iconv, 'utf-8//IGNORE', $field);
                }          
                if (@$this->fielddetails[$fielddetails[$i]]['format'] == 'date') {
                    if ($field > 0) {
                        $field = date(
                            @$this->fielddetails[$fielddetails[$i]]['formatdetails'],
                            $field
                        );
                    } else {
                        $field = '';
                    }
                }
                $tf[] = $field;
                $i++;
            }
            $tf['DT_RowId'] = $tf[0];
            $return['aaData'][] = $tf;
            unset($tf);
        }

        $return['sEcho'] = intval($request->get('sEcho'));
        $return['iTotalRecords'] = $total;
        $return['iTotalDisplayRecords'] = $totalDisplay;
        if (!isset($return['aaData'])) {
            $return['aaData'] = array();
        }

        // Return DataTables 1.10+ format when the request used modern params.
        if ($isModernDT) {
            $modernReturn = [
                'draw'            => (int)($_POST['draw'] ?? 1),
                'recordsTotal'    => $total,
                'recordsFiltered' => $totalDisplay,
                'data'            => $return['aaData'],
            ];
            return $encode ? json_encode($modernReturn) : $modernReturn;
        }

        if ($encode === true) {
            return json_encode($return);
        } else {
            return $return;
        }
    }

}
