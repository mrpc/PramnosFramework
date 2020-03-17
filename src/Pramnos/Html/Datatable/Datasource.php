<?php

namespace Pramnos\Html\Datatable;

use Pramnos\Framework\Base;

/**
 * Data feed for database
 * @todo        Add Edit Functions
 * @todo        Add callback functions
 * @todo        Alternative method to count rows
 * @todo        Documentation ρε
 * @package     PramnosFramework
 * @subpackage  JSON
 * @copyright   2005 - 2014 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Datasource extends Base
{

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
        #$whereWord = 'where';

        $fields = $this->fields;
        #if (is_array($fields)) {
        #    foreach ($fields as $fieldToTest) {
        #        if (stripos($fieldToTest, 'concat') !== false) {
        #            $whereWord = 'having';
        #        }
        #    }
        #}
        $where = str_ireplace('where', ' ', $whereStatement);
        if ($debug == true) {
            echo "<pre>DEBUG MODE\n\n</pre>";
        }
        $db = \Pramnos\Framework\Factory::getDatabase();
        $request = \Pramnos\Framework\Factory::getRequest();
        if ($iconv !== NULL) {
            foreach ($_POST as $key => $value) {
                $_POST[$key] = iconv('utf-8', $iconv . '//IGNORE', $value);
            }
        }
        $Awhere = "";
        if ($join != '') {
            $join = $db->prepare($join);
        }
        if ($where != '') {
            $Awhere = ' '. $whereWord . ' ' . $where;
        }
        if (!is_array($fields)) {
            $field = $fields;
            $fields = array();
            $fields[] = $field;
        }

        /* Paging */
        $sLimit = "";
        if (isset($_POST['iDisplayStart'])) {
            if ($request->get('iDisplayLength', '', 'post') != "-1") {
                $sLimit = "LIMIT " . $database->prepareInput(
                    $request->get('iDisplayStart', '0', 'post')
                    ) . ", " . $database->prepareInput(
                        $request->get(
                            'iDisplayLength', $this->maxlimit, 'post'
                        )
                    );
            }
        } else {
            $sLimit = "LIMIT " . $database->prepareInput($this->maxlimit);
        }
        $sOrder = '';
        /* Ordering */
        if (isset($_POST['iSortCol_0'])) {
            $sOrder = "ORDER BY  ";
            for ($i = 0; $i < $database->prepareInput(
                $request->get('iSortingCols', '', 'post')
            ); $i++) {
                $sortcol = '';
                if (isset($fields[$database->prepareInput(
                    $request->get('iSortCol_' . $i, '', 'post'))]
                )) {
                    $sortcol = $fields[$database->prepareInput(
                        $request->get('iSortCol_' . $i, '', 'post')
                    )];
                } else {
                    $sortcol = $fields[0];
                }
                if (strpos($sortcol, ' as ') !== false) {
                    $sortcol = substr($sortcol, 0, strpos($sortcol, ' as '));
                }
                $sOrder .= $sortcol . " " . $database->prepareInput(
                    $request->get('sSortDir_' . $i, '', 'post')
                ) . ", ";
            }
            $sOrder = substr_replace($sOrder, "", -2);
        }

        $sql = $db->prepare(
            "SELECT COUNT(a.`" . $fields[0] . "`) as 'num' from `"
            . $table . "` a  $join  " . $Awhere
        );

        if ($debug == true) {
            echo '<pre>First Count: ' . $sql . "\n\n</pre>";
        }
        try {
            $num = $db->Execute($sql, $cache, $cachetime, $cachecategory);
        } catch (Exception $ex) {
            $message = 'Error in getJsonList (first count): '
                . $ex->getMessage() . '. Sql Query:'
                . str_replace(array("\n", "\t", "\r"), " ", $sql);
            \Pramnos\Logs\Logs::log(
                $message
            );
        }

        if (!isset($num->fields['num'])) {
            $num->fields['num'] = 0;
        }
        $total = $num->fields['num'];
        /* Filtering - NOTE this does not match the
         * built-in DataTables filtering which does it
         * word by word on any field. It's possible to do here,
         * but concerned about efficiency
         * on very large tables, and MySQL's regex
         * functionality is very limited
         */
        $sWhere = "";
        if (isset($_POST['sSearch']) && $_POST['sSearch'] != "") {
            if ($where != '') {
                $sWhere = $whereWord . ' ' . $where . ' AND (';
            } else {
                $sWhere = $whereWord . ' (';
            }
            $comma = '';
            $i = 0;
            foreach ($fields as $field) {

                if (isset($_POST['bSearchable_' . $i])
                    && $_POST['bSearchable_' . $i] == "true") {
                    $startWildcard = '%';
                    $endWildcard = '%';
                    if (strpos($field, ' as ') !== false) {
                        //Αφαίρεση πεδίου " as "
                        $fieldTmpArray = explode(' as ', $field);
                        $field = $fieldTmpArray[0];
                    }

                    if (strpos($field, '.') === false) {
                        $sWhere .= $comma . " a.`" . $field
                            . "` LIKE '" . $startWildcard
                            . trim(
                                $database->prepareInput($_POST['sSearch'])
                            ) . $endWildcard . "'";
                    } else {
                        $sWhere .= $comma . " " . $field . " LIKE '"
                            . $startWildcard . trim(
                                $database->prepareInput($_POST['sSearch'])
                            ) . $endWildcard . "'";
                    }
                    $comma = ' OR ';
                    }
                $i++;
            }

            $sWhere .= ' )';

        } elseif ($where != '') {
            $sWhere = ' ' . $whereWord . ' ' . $where;
        }

        $selectfields = '';
        $comma = '';
        foreach ($fields as $field) {
            if ($field == $distinctField) {
                continue;
            }
            if (strpos($field, '.') === false) {
                $selectfields .= $comma . " a.`" . $field . "`";
            } else {
                $selectfields .= $comma . " " . $field;
            }
            $comma = ',';
        }
        if ($distinctField != '' && strpos($distinctField, '.') === false) {
            $distinctField = 'a.`' . $distinctField . '`';
        }


        /* Individual column filtering */
        for ($i = 0; $i < count($fields); $i++) {
            if (@$this->fielddetails[$fields[$i]]['startWildcard'] == true) {
                $startWildcard = '%';
            } else {
                $startWildcard = '';
            }
            if (@$this->fielddetails[$fields[$i]]['endWildcard'] == true) {
                $endWildcard = '%';
            } else {
                $endWildcard = '';
            }
            if (isset($_POST['bSearchable_' . $i])
                && $_POST['bSearchable_' . $i] == "true"
                && $_POST['sSearch_' . $i] != '') {
                if ($sWhere == "") {
                    $sWhere = $whereWord . " ";
                } else {
                    $sWhere .= " AND ";
                }
                $sField = explode(' as ', $fields[$i]);
                if (strpos($fields[$i], '.') === false) {
                    $sWhere .= 'a.`' . $sField[0] . "` LIKE '"
                        . $startWildcard . $database->prepareInput(
                            $_POST['sSearch_' . $i]
                        ) . $endWildcard . "' ";
                } else {
                    $sWhere .= $sField[0] . " LIKE '"
                        . $startWildcard
                        . $database->prepareInput($_POST['sSearch_' . $i])
                        . $endWildcard . "' ";
                }
            }
        }


        if ($distinctField != '') {
            $sql = $db->prepare(
                'select distinct('.$distinctField.'), '
                . $selectfields . ' from `'
                . $table . '` a   ' . $join . '   '
            ) . $sWhere . ' ' . $sOrder . ' ' . $sLimit;
        } else {
            $sql = $db->prepare(
                'select distinct ' . $selectfields . ' from `'
                . $table . '` a   ' . $join . '   '
            ) . $sWhere . ' ' . $sOrder . ' ' . $sLimit;
        }



        if ($debug == true) {
            echo '<pre>Distinct: ' . $sql . "\n\n</pre>";
        }

        try {
            $result = $db->Execute($sql, $cache, $cachetime, $cachecategory);
        } catch (Exception $ex) {
            $message = 'Error in getJsonList: '
                . $ex->getMessage() . '. Sql Query:'
                . str_replace(array("\n", "\t", "\r"), " ", $sql);
            \Pramnos\Logs\Logs::log(
                $message
            );
            die($message);
        }

        if ($debug == true) {

            echo '<pre>';
            var_dump($result);
            die('</pre>');
        }

        if (strpos($sWhere, 'group by') !== false) {
            $sql = $db->prepare(
            "select COUNT(distinct(a.`" . $fields[0] . "`)) "
            . "as 'num' from `" . $table . "` a  " . $join . "  "
        ) . str_replace('group by a.`' . $fields[0] . '`', '', $sWhere);
        } else {
            $sql = $db->prepare(
            "select COUNT(a.`" . $fields[0] . "`) "
            . "as 'num' from `" . $table . "` a  " . $join . "  "
        ) . $sWhere;
        }

        try {
            $sQueryR = $db->Execute($sql, $cache, $cachetime, $cachecategory);
        } catch (Exception $ex) {
            $message = 'Error in getJsonList: '
                . $ex->getMessage() . '. Sql Query:'
                . str_replace(array("\n", "\t", "\r"), " ", $sql);
            \Pramnos\Logs\Logs::log(
                $message
            );
            die($message);
        }


        if (!isset($sQueryR->fields['num'])) {
            $sQueryR->fields['num'] = 0;
        }
        $totalDisplay = $sQueryR->fields['num'];


        $return = array();
        while (!$result->eof) {
            $fielddetails = array_keys($this->fielddetails);
            $i = 0;
            foreach ($result->fields as $field) {

                $field = trim(
                    str_replace(array("\n", "\t", "\r"), " ", $field)
                ); //Fixed for exporting to Excel
                if ($iconv !== NULL && !is_numeric($field) && $iconv != 'utf-8') {
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
            $result->MoveNext();
        }

        $return['sEcho'] = intval($request->get('sEcho'));
        $return['iTotalRecords'] = $total;
        $return['iTotalDisplayRecords'] = $totalDisplay;
        if (!isset($return['aaData'])) {
            $return['aaData'] = array();
        }
        if ($encode === true) {
            return json_encode($return);
        } else {
            return $return;
        }
    }

}
