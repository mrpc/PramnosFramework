<?php
namespace Pramnos\User;
/**
 * User tokens
 * @package     CaptainBook
 * @copyright   Copyright (C) 2017  Yannis - Pastis Glaros, Pramnos Hosting Ltd.
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 *
 */


class Token extends \Pramnos\Framework\Base
{
    /**
     * Token ID (primary key)
     * @var int
     */
    public $tokenid = 0;
    /**
     * User id of token's owner
     * @var int
     */
    public $userid = null;
    /**
     * Token type. Auth, apns, gcm
     * @var string
     */
    public $tokentype = '';
    /**
     * The actual token
     * @var string
     */
    public $token = '';
    /**
     * When it was created (unix timestamp)
     * @var int
     */
    public $created = 0;
    /**
     * Token notes
     * @var string
     */
    public $notes = '';
    /**
     * When it was last used
     * @var int
     */
    public $lastused = 0;
    /**
     * Token status. 0: inactive 1: active 2: removed - will delete
     * @var int
     */
    public $status = 0;
    /**
     * Parent token (if parent gets deleted, some children will be deleted too)
     * @var int
     */
    public $parentToken = null;
    /**
     * Application ID
     * @var int
     */
    public $applicationid = null;
    /**
     * Actions counter for stats
     * @var int
     */
    public $actions = 0;
    /**
     * When it was removed
     * @var int
     */
    public $removedate = 0;
    /**
     * Device information
     * @var array
     */
    public $deviceinfo = array();
    /**
     * Scope of the token
     * @var array
     */
    public $scope = array();
    /**
     * IP address of the user
     * @var string
     */
    public $ipaddress = '';
    /**
     * When the token expires in unix timestamp`
     * @var int
     */
    public $expires = null;

    /**
     * Token state for database
     * @var bool
     */
    protected $_isnew = true;


    /**
     * A user token
     * @param int|array $tokenidOrDataArray
     */
    public function __construct($tokenidOrDataArray = null)
    {
        if ($tokenidOrDataArray !== null) {
            if (is_numeric($tokenidOrDataArray)
                || is_string(($tokenidOrDataArray))) {
                $this->load($tokenidOrDataArray);
            } elseif (is_array($tokenidOrDataArray)) {
                $this->fillProperties($tokenidOrDataArray);
            }
        }
        parent::__construct();
    }

    /**
     * Fill properties of the object, based on an array of data
     * @param array $dataArray
     */
    protected function fillProperties($dataArray)
    {
        foreach (array_keys($dataArray) as $field) {
            $this->$field = $dataArray[$field];
        }
        $this->_isnew = false;
        if (\Pramnos\General\Helpers::checkUnserialize($this->scope)) {
            $this->scope = unserialize($this->scope);
        } elseif ($this->scope && json_decode($this->scope) !== null) {
            $this->scope = json_decode($this->scope, true);
        } else {
            $this->scope = array();
        }
        if (\Pramnos\General\Helpers::checkUnserialize($this->deviceinfo)) {
            $this->deviceinfo = unserialize($this->deviceinfo);
        } elseif ($this->deviceinfo && json_decode($this->deviceinfo) !== null) {
            $this->deviceinfo = json_decode($this->deviceinfo, true);
        } else {
            $this->deviceinfo = array();
        }
    }

    /**
     * Load a token from the database
     * @param int|string $tokenid
     * @return \captainbook_token
     */
    public function load($tokenid)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if (is_numeric($tokenid)) {
            $sql = $database->prepareQuery(
                "SELECT * FROM `#PREFIX#usertokens` "
                . "WHERE `tokenid` = %d limit 1",
                $tokenid
            );
        } else {
            $sql = $database->prepareQuery(
                "SELECT * FROM `#PREFIX#usertokens` "
                . "WHERE `token` = %s limit 1",
                $tokenid
            );
        }
        $result = $database->query($sql, true, 3600, 'usertokens');
        if ($result->numRows != 0) {
            $this->fillProperties($result->fields);
        }

        return $this;
    }

    /**
     * Returns an array of data
     * @return array
     */
    public function getData()
    {
        $data = array();
        foreach (get_object_vars($this) as $key=>$value) {
            if (is_numeric($value) || is_string($value)) {
                $data[$key] = $value;
            }
        }
        $data['created'] = date('c', $this->created);
        if ($data['removedate'] == 0) {
            $data['removedate'] = null;
        } else {
            $data['removedate'] = date('c', $this->removedate);
        }
        if ($data['lastused'] == 0) {
            $data['lastused'] = null;
        } else {
            $data['lastused'] = date('c', $this->lastused);
        }
        $statusArray = array('Inactive', 'Active', 'Deleted');
        $data['status'] = '';
        if (isset($statusArray[(int) $this->status])) {
            $data['status'] = $statusArray[(int) $this->status];
        }
        if ((is_array($this->deviceinfo) && count($this->deviceinfo) > 0)
            || is_object($this->deviceinfo)) {
            $data['deviceinfo'] = $this->deviceinfo;
        }
        return $data;
    }

    /**
     * Add an action to the token log
     */
    public function addAction()
    {
        $request = \Pramnos\Framework\Factory::getRequest();
        $url = $request->getURL(false);
        $urlHash = crc32($url);
        $database = \Pramnos\Framework\Factory::getDatabase();
        $findUrlSql = $database->prepareQuery(
            "select `urlid` from `#PREFIX#urls` "
            . " where `hash` = %s limit 1",
            $urlHash
        );
        $findUrlResult = $database->query($findUrlSql);
        if ($findUrlResult->numRows == 0) {
            $urlInsertSql = $database->prepareQuery(
                "insert into `#PREFIX#urls` (`url`, `hash`) "
                . " values (%s, %s)",
                $url, $urlHash
            );
            $database->query($urlInsertSql);
            $urlid = $database->getInsertId();
        } else {
            $urlid = $findUrlResult->fields['urlid'];
        }

        \Pramnos\Framework\Factory::getRequest();
        switch (\Pramnos\Http\Request::$requestMethod) {
            case "POST":
                $inputData = json_encode($_POST);
                break;
            case "DELETE":
                $inputData = json_encode(\Pramnos\Http\Request::$deleteData);
                break;
            case "PUT":
                $inputData = json_encode(\Pramnos\Http\Request::$putData);
                break;
            default:
                $inputData = file_get_contents("php://input");
                break;

        }

        if ($urlid > 0) {
            $sql = $database->prepareQuery(
                "insert into `#PREFIX#tokenactions` "
                . "(`tokenid`, `urlid`, `method`, `params`, `servertime`)"
                . " values "
                . "(%d, %d, %s, %s, %d)",
                $this->tokenid,
                $urlid,
                \Pramnos\Http\Request::$requestMethod,
                $inputData,
                time()
            );
            @$database->query($sql);
        }
        $this->actions +=1;
        $this->lastused = time();
        $remoteip = '';
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $this->deviceinfo = \Pramnos\General\Helpers::getBrowser(
                $_SERVER['HTTP_USER_AGENT']
            );
        }
        $remoteip = '';
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $remoteip = $_SERVER['REMOTE_ADDR'];
        }
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $remoteip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if ($remoteip != '') {
            $this->ipaddress = $remoteip;
        }

        $this->save();

        return $this;
    }

    /**
     * Save token to the database
     * @return Token
     */
    public function save()
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($this->_isnew == true) {
            $this->added = time();
        }
        if ($this->expires == 0) {
            $this->expires = null;
        }
        $itemdata = array(
            array(
                'fieldName' => 'userid',
                'value' => $this->userid, 'type' => 'integer'
            ),
            array(
                'fieldName' => 'tokentype',
                'value' => $this->tokentype, 'type' => 'string'
            ),
            array(
                'fieldName' => 'token',
                'value' => $this->token, 'type' => 'string'
            ),
            array(
                'fieldName' => 'created',
                'value' => $this->created, 'type' => 'integer'
            ),
            array(
                'fieldName' => 'notes',
                'value' => $this->notes, 'type' => 'string'
            ),
            array(
                'fieldName' => 'lastused',
                'value' => $this->lastused, 'type' => 'integer'
            ),
            array(
                'fieldName' => 'status',
                'value' => $this->status, 'type' => 'integer'
            ),
            array(
                'fieldName' => 'applicationid',
                'value' => $this->applicationid, 'type' => 'integer'
            ),
            array(
                'fieldName' => 'actions',
                'value' => $this->actions, 'type' => 'integer'
            ),
            array(
                'fieldName' => 'removedate',
                'value' => $this->removedate, 'type' => 'integer'
            ),
            array(
                'fieldName' => 'deviceinfo',
                'value' => json_encode($this->deviceinfo), 'type' => 'string'
            ),
            array(
                'fieldName' => 'scope',
                'value' => json_encode($this->scope), 'type' => 'string'
            )
        );
        if ($database->type != 'postgresql') {
            $itemdata[] = array(
                'fieldName' => 'parentToken',
                'value' => $this->parentToken, 'type' => 'integer'
            );
        } else {
            $itemdata[] = array(
                'fieldName' => 'ipaddress',
                'value' => $this->ipaddress, 'type' => 'string'
            );
            $itemdata[] = array(
                'fieldName' => 'expires',
                'value' => $this->expires, 'type' => 'integer'
            );
        }
        #$database->sql_cache_flush_cache('usertokens');
        if ($this->_isnew == true) {
            $this->_isnew = false;

            if (!$database->insertDataToTable(
                $database->prefix . "usertokens", $itemdata
            )) {
                $error = $database->getError();
                $this->addError($error['message']);
            } else {
                $this->tokenid = $database->getInsertId();
            }
            return $this;
        }
        if ((int) $this->tokenid == 0) {
            $this->addError('Token ID is not set');
            return $this;
        }
        if (!$database->updateTableData(
            $database->prefix . "usertokens", $itemdata,
            "`tokenid` = '" . (int) $this->tokenid . "'", false
        )) {

            if ($database->type == 'postgresql' 
                && strpos($database->getError()['message'], 'column "ipaddress"') !== false) {

                $database->query($database->prepareQuery('ALTER TABLE #PREFIX#usertokens ADD "expires" integer NULL, ADD "ipaddress" inet NULL;'));
                if (!$database->updateTableData(
                    $database->prefix . "usertokens", $itemdata,
                    "`tokenid` = '" . (int) $this->tokenid . "'", false
                )) {
                    $error = $database->getError();
                    $this->addError($error['message']);
                }   
            } else {
                $error = $database->getError();
                $this->addError($error['message']);
            }




            
        }

        return $this;
    }



}