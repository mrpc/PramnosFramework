<?php

/*
 * Copyright (c) 2005-2013 Yannis - Pastis Glaros, Pramnos Hosting
 * All Rights Reserved.
 * This software is the proprietary information of Pramnos Hosting.
 */

defined('SP') or die('No startpoint defined...');

/**
 * Database Select
 * @package     PramnosFramework
 * @subpackage  Database
 * @copyright   Copyright (C) 2005 - 2013 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class pramnos_database_statement_select extends pramnos_base {
    /**
     * What Database is used
     * @var string
     */
    public $database=NULL;
    private $_from=array();
    private $_where=array();
    private $_order=array();
    private $_fields='';
    private $_join=array();
    private $_leftjoin=array();
    private $_rightjoin=array();
    private $_orWhere=array();
    private $_groupBy=array();
    private $_distinct=false;
    private $_limit='';
    private $_limitStart=0;

    public $statement=NULL;

    public function __construct($fields="*") {
        parent::__construct();
        $this->_fields=$fields;
    }


    public function from($from){
        $this->_from[]=$from;
        return $this;
    }

    public function join($join){
        $this->_join[]=$join;
        return $this;
    }

    public function leftJoin($leftJoin){
        $this->_leftjoin[]=$leftJoin;
        return $this;
    }

    public function rightJoin($rightJoin){
        $this->_rightjoin[]=$rightJoin;
        return $this;
    }

    public function where($where){
        $this->_where[]=$where;
        return $this;
    }

    public function orWhere($where){
        $this->_orWhere[]=$where;
        return $this;
    }

    public function groupBy($groupBy){
        $this->_groupBy[]=$groupBy;
        return $this;
    }

    public function orderBy($orderBy){
        $this->_order[]=$orderBy;
        return $this;
    }

    public function limit($limit, $from=0){
        $this->_limit=$limit;
        $this->_limitStart=$from;
        return $this;
    }

    public function distinct(){
        $this->_distinct=true;
        return $this;
    }


    private function _renderMySQL(){
        $sql = "SELECT ";
        if ($this->_distinct == true){
            $sql .= " DISTINCT ";
        }

        $sql .= $this->_fields;

        $sql .= " FROM ";
        $comma = '';
        foreach ($this->_from as $from){
            $sql .= $comma;
            if (is_array($from)){
                $sc='';
                foreach ($from as $key=>$value){
                    if ($this->database instanceof pramnos_pdo || $this->database instanceof pramnos_database){
                        $value = str_replace('#PREFIX#', $this->database->prefix, $value);
                    }
                    if (strpos($value, '`') === false ){
                        $sql .= $sc . '`' . $value . '` ' . $key;
                    }
                    else {
                        $sql .= $sc . $value . ' ' . $key;
                    }
                    $sc = ', ';
                }
            }
            else {
                if ($this->database instanceof pramnos_pdo || $this->database instanceof pramnos_database){
                        $from = str_replace('#PREFIX#', $this->database->prefix, $from);
                    }
                if (strpos($from, '`') === false ){
                    $sql .= '`'.$from.'`';
                }
                else {
                    $sql .= $from;
                }
            }
            $comma = ', ';
        }



        $where = ' WHERE ' ;
        $comma = '';
        foreach ($this->_where as $w){
            $sql .= $where;
            $sql .= $comma . ' ( ';
            $where='';
            if (is_array($w)){
                $sc='';
                foreach ($w as $key=>$value){
                        $sql .= $sc . $value . ' ' . $key;
                    $sc = ', ';
                }
            }
            else {

                    $sql .= $w;

            }
            $comma = ' AND ';
            $sql .= ' ) ';
        }
        if ($comma == ' AND '){
            $comma = ' OR ';
        }
        else {
            $comma = '';
        }
        foreach ($this->_orWhere as $w){
            $sql .= $where;
            $sql .= $comma . ' ( ';
            $where='';
            if (is_array($w)){
                $sc='';
                foreach ($w as $key=>$value){
                        $sql .= $sc . $value . ' ' . $key;
                    $sc = ', ';
                }
            }
            else {

                    $sql .= $w;

            }
            $comma = ' OR ';
            $sql .= ' ) ';
        }




        $orderby = ' ORDER BY ' ;
        $comma = '';
        foreach ($this->_order as $order){
            $sql .= $orderby;
            $sql .= $comma;
            $orderby='';
            if (is_array($order)){
                $sc='';
                foreach ($order as $key=>$value){


                        $sql .= $sc . $value . ' ' . $key;

                    $sc = ', ';
                }
            }
            else {

                    $sql .= $order;

            }
            $comma = ', ';
        }



        if ($this->_limit != ''){
            $sql .= " LIMIT " . (int)$this->_limitStart . ', ' . (int)$this->_limit;
        }
        return $sql;
    }

    /**
     * Render the select statement to SQL
     * @return string
     */
    public function renderSql(){
        if ($this->database instanceof pramnos_pdo || $this->database instanceof pramnos_database){
            switch ($this->database->driver){
                default;
                    return $this->_renderMySQL();
                    break;
            }
        }
        elseif (is_string($this->database)){
            switch ($this->database){
                default;
                    return $this->_renderMySQL();
                    break;
            }
        }
    }

    public function __toString(){
        return $this->renderSql();
    }

    public function reset(){

    }

    /**
     * Binds a parameter to the specified variable name
     * @param type $parameter
     * @param type $variable
     * @param type $data_type
     * @param type $length
     * @param type $driver_options
     */
    public function bindParam($parameter , &$variable, $data_type = PDO::PARAM_STR , $length=NULL, $driver_options=NULL){
        if ($this->statement=== NULL){
            $this->statement=$this->database->prepare($this->renderSql());
        }
        $this->statement->bindParam($parameter , $variable, $data_type, $length, $driver_options);
    }

    /**
     * Binds a value to a parameter
     * @param type $parameter
     * @param type $value
     * @param type $data_type
     */
    public function bindValue($parameter, $value, $data_type = PDO::PARAM_STR){
        if ($this->statement=== NULL){
            $this->statement=$this->database->prepare($this->renderSql());
        }
        $this->statement->bindParam($parameter , $value);
    }

    /**
     * Executes a prepared statement
     * @param array $input_parameters An array of values with as many elements as there are bound parameters in the SQL statement being executed. All values are treated as PDO::PARAM_STR.<br />You cannot bind multiple values to a single parameter; for example, you cannot bind two values to a single named parameter in an IN() clause.<br />You cannot bind more values than specified; if more keys exist in input_parameters than in the SQL specified in the PDO::prepare(), then the statement will fail and an error is emitted.
     */
    public function Execute(array $input_parameters=array()){
        if ($this->statement=== NULL){
            $this->statement=$this->database->prepare($this->renderSql());
        }
    }

}

