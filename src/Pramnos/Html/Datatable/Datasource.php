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

    /**
     * The SQL of the last list query this class executed, across all instances.
     *
     * A debug and test hook, and the only way to see what a filter actually produced:
     * an admin screen that logs it can show the query behind a list, and a test can
     * assert on an `ORDER BY` without standing up a dataset that makes the ordering
     * observable. Nothing reads it to decide anything.
     *
     * Static because the caller does not hold the instance — `getList()` builds one,
     * uses it and drops it.
     *
     * @var string
     */
    public static $lastQuery = '';

    /**
     * Declare a column, and how the global search should treat it.
     *
     * **Every parameter has to be declared, not just accepted.** `render()`
     * passes a field's details to this method with `call_user_func_array()`, and
     * an associative array becomes *named arguments* on PHP 8 — so a key this
     * signature does not name is an `Unknown named parameter` error rather than
     * a silent extra. A consuming application's suite failed on
     * `$ignoreOnOthertypes` the moment it tried the modern class.
     *
     * @param string  $name          Column, optionally `table.column` or `expr as alias`
     * @param string  $format        `text` (default), `email`, `phone`, `numeric`/`number`/`int`, `date`
     * @param string  $formatdetails Format argument — for `date`, the output format
     * @param bool    $startWildcard Put a `%` **before** the search term for this column.
     *                               Off by default, and that default is the real rule
     *                               almost everywhere: `render()` calls this with a bare
     *                               column name for any field declared as a plain string,
     *                               which is how most applications declare all of them.
     *                               A leading wildcard turns `LIKE 'term%'` into
     *                               `LIKE '%term%'` — the index stops being usable, the
     *                               range scan becomes a full one, and the result set
     *                               changes. Pass `true` per column where matching
     *                               mid-word is worth that.
     * @param bool    $endWildcard   Put a `%` after it
     * @param bool    $ignoreOnOthertypes Leave this column out of the global search when
     *                               the term is a number or an email address. For a wide
     *                               list, searching a free-text column for `12345` costs a
     *                               scan and returns noise; the numeric columns answer that
     *                               query.
     * @param int|null $min          Lower bound. For a numeric column, on the **value**;
     *                               otherwise on the term's **length** — a one-character
     *                               term against a large text column is a full scan.
     * @param int|null $max          Upper bound, read the same way.
     */
    public function addField($name, $format = 'text', $formatdetails = '',
        $startWildcard = false, $endWildcard = true,
        $ignoreOnOthertypes = false, $min = null, $max = null)
    {
        $this->fields[] = $name;
        $this->fielddetails[$name] = array(
            'format' => $format,
            'formatdetails' => $formatdetails,
            'startWildcard' => $startWildcard,
            'endWildcard' => $endWildcard,
            'ignoreOnOthertypes' => $ignoreOnOthertypes,
            'min' => $min,
            'max' => $max
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
        $distinctField='', $whereWord = 'where', $groupBy = '')
    {
        $data = new Datasource();
        return $data->render(
                $table, $fields, $encode, $where, $join, $cache, $cachetime,
                $cachecategory, $debug, $iconv, $distinctField, $whereWord,
                $groupBy
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
     * @param string $groupBy The `GROUP BY` part, without the keyword — e.g.
     *                        `a.userid` or `a.userid, a.type`.
     *
     *                        Its own parameter because the alternative is putting
     *                        it inside `$whereStatement` and hoping the string
     *                        lands in the right place, which is how a consuming
     *                        application ended up maintaining **two forks** of
     *                        this class whose only difference was this argument.
     *
     *                        It applies to the counts as well as to the rows: a
     *                        grouped query returns fewer rows than it counts, so
     *                        a pager built on an ungrouped `COUNT(*)` promises
     *                        pages that are not there.
     * @return mixed a Json string or an array of data
     */
    public function render($table = '', $queryFields = NULL, $encode = true,
        $whereStatement = '', $join = '', $cache = true, $cachetime = 5,
        $cachecategory = "datatables", $debug = false, $iconv = NULL,
        $distinctField='', $whereWord = 'where', $groupBy = '')
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
                $details = $this->fielddetails;
                /**
                 * One term, and each column decides whether it applies.
                 *
                 * This used to be `LIKE '%term%'` on every searchable column,
                 * which ignored everything `addField()` was told: a column's
                 * wildcards, its format, its bounds. So a numeric id column was
                 * searched as text, a free-text column was scanned for `12345`,
                 * and the `%` prefix that makes an index unusable was applied
                 * whether or not the caller asked for it.
                 *
                 * A column that declines contributes no clause. If every column
                 * declines the term, the `where` group is empty — which is why
                 * the clauses are collected first and the group is only added
                 * when there is something in it: an empty closure would produce
                 * `WHERE ()`.
                 */
                $clauses = [];
                foreach ($fields as $i => $field) {
                    if (!isset($_POST['bSearchable_' . $i])
                        || $_POST['bSearchable_' . $i] != "true") {
                        continue;
                    }

                    $detail = $details[$field] ?? [];
                    $format = strtolower((string) ($detail['format'] ?? 'text'));
                    $start  = !empty($detail['startWildcard']) ? '%' : '';
                    $end    = !empty($detail['endWildcard']) ? '%' : '';
                    $min    = $detail['min'] ?? null;
                    $max    = $detail['max'] ?? null;
                    $ignoreOnOthertypes = !empty($detail['ignoreOnOthertypes']);

                    $bare = strpos($field, ' as ') !== false
                        ? explode(' as ', $field)[0]
                        : $field;
                    $column = strpos($bare, '.') === false ? "a.`$bare`" : $bare;

                    switch ($format) {
                        case 'email':
                            // Only when the term is one. Searching an address
                            // column for a fragment of a name matches nothing
                            // and costs a scan.
                            $email = \Pramnos\Validation\Validator::checkEmail($searchTerm);
                            if ($email !== false) {
                                $clauses[] = [$column, 'LIKE', $start . $email . $end];
                            }
                            break;

                        case 'phone':
                            // Digits, spaces, dashes, parentheses and an
                            // optional +, at a length a phone number has.
                            if (preg_match('/^\+?[0-9\s\-()]+$/', $searchTerm)
                                && strlen($searchTerm) > 9 && strlen($searchTerm) < 15) {
                                $clauses[] = [$column, 'LIKE', $start . $searchTerm . $end];
                            }
                            break;

                        case 'numeric':
                        case 'number':
                        case 'int':
                            // Equality, not LIKE: `LIKE '%5%'` on an integer
                            // column matches 5, 15, 50 and 1523.
                            if (is_numeric($searchTerm)
                                && ($min === null || $searchTerm >= $min)
                                && ($max === null || $searchTerm <= $max)) {
                                $clauses[] = [$column, '=', $searchTerm];
                            }
                            break;

                        default:
                            if (($ignoreOnOthertypes === false
                                    || (!is_numeric($searchTerm)
                                        && \Pramnos\Validation\Validator::checkEmail($searchTerm) === false))
                                && ($min === null || strlen($searchTerm) >= $min)
                                && ($max === null || strlen($searchTerm) <= $max)) {
                                $clauses[] = [$column, 'LIKE', $start . $searchTerm . $end];
                            }
                            break;
                    }
                }

                if ($clauses !== []) {
                    $isFiltered = true;
                    $qb->where(function ($query) use ($clauses) {
                        foreach ($clauses as [$column, $operator, $value]) {
                            $query->orWhere($column, $operator, $value);
                        }
                    });
                }
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

        /**
         * How many rows the query answers with — groups, when it groups.
         *
         * `QueryBuilder::count()` preserves the `GROUP BY`, which it documents:
         * so on a grouped query `COUNT(*)` returns the size of the *first group*
         * rather than the number of groups, and a pager built on it promises
         * pages that are not there. Counting is therefore done before the group
         * by is applied, as `COUNT(DISTINCT <the grouped columns>)`.
         *
         * $groupBy is interpolated, at the same trust level as $join and
         * $whereStatement above it: a column list from the calling code, never
         * from a request.
         */
        $countRows = static function ($builder) use (
            $groupBy, $cache, $cachetime, $cachecategory
        ) {
            if ($groupBy === '') {
                return $builder->count($cache, $cachetime, $cachecategory);
            }
            $counter = clone $builder;
            $counter->select(['COUNT(DISTINCT ' . $groupBy . ') AS aggregate'])
                    ->clearOrderingAndPaging();
            $result = $counter->get($cache, $cachetime, $cachecategory);

            return (int) ($result->fields['aggregate'] ?? 0);
        };

        // First count: Total records without filtering.
        $totalQb = $database->queryBuilder()->from($table . ' a');
        if ($join != '') $totalQb->joinRaw($join);
        if ($where != '') $totalQb->whereRaw($where);
        try {
            // Cached on the same terms as the rows it counts. A caller that
            // asked for caching got its page of results from cache and then
            // paid for a full COUNT(*) anyway — which on a large table is the
            // expensive half of the request.
            $total = $countRows($totalQb);
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
                $totalDisplay = $countRows($qb);
            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::log('Error in Datasource filtered count: ' . $ex->getMessage());
                $totalDisplay = 0;
            }
        }

        // Applied after the counts, which need the ungrouped query — see
        // $countRows above.
        if ($groupBy !== '') {
            $qb->groupByRaw($groupBy);
        }

        // Published before the call, so it is available whether the query succeeds,
        // returns nothing or throws — the failing query is the one worth reading.
        self::$lastQuery = $qb->toSql();

        if ($debug) {
            echo '<pre>Final Query: ' . self::$lastQuery . "\n\n</pre>";
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
