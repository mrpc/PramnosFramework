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
     * Last action ID
     * @var int|null
     */
    public $lastActionId = null;
    /**
     * Last action time in milliseconds
     * @var int|null
     */
    public $lastActionTime = null;


    /**
     * A user token
     * @param int|array $tokenidOrDataArray
     */
    public function __construct($tokenidOrDataArray = null)
    {
        if ($tokenidOrDataArray !== null) {
            if (
                is_numeric($tokenidOrDataArray)
                || is_string(($tokenidOrDataArray))
            ) {
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
        foreach (get_object_vars($this) as $key => $value) {
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
            || is_object($this->deviceinfo)
        ) {
            $data['deviceinfo'] = $this->deviceinfo;
        }
        return $data;
    }

    /**
     * Add an action to the token log
     */
    public function addAction()
    {
        $this->lastActionTime = (int) (microtime(true) * 1000);
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
                $url,
                $urlHash
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
        $this->lastActionId = null;
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
            try {
                $database->query($sql);
            } catch (\Exception $e) {
                // Handle any exceptions that may occur during the query execution
                \Pramnos\Logs\Logger::logError(
                    'Error while adding token action with query: ' 
                    . $sql . ' - Error: ' 
                    . $e->getMessage(),
                    $e
                );
                return $this;
            }
            $this->lastActionId = $database->getInsertId();
        }
        $this->actions += 1;
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
     * Update an action in the token log
     * @param int $actionid
     * @param int $return_status
     * @param int $execution_time_ms
     * @param mixed $return_data
     */
    public function updateAction($actionid, $return_status, $execution_time_ms = 0, $return_data = null)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        // if database is mysql, return 
        if ($database->type == 'mysql') {
            return;
        }
        if ($execution_time_ms == 0 && $this->lastActionTime !== null) {
            $execution_time_ms = (float) (microtime(true) * 1000) - $this->lastActionTime;
        }
        
        if ($return_data === null) {
            $return_data = json_encode(array());
        } elseif (is_array($return_data)) {
            $return_data = json_encode($return_data);
        } elseif (is_object($return_data)) {
            $return_data = json_encode(get_object_vars($return_data));
        } elseif (!is_string($return_data)) {
            $return_data = json_encode(array('data' => $return_data));
        }
        if ($actionid == 0 || $return_status < 0) {
            return;
        }
        
        $sql = $database->prepareQuery(
            "UPDATE `#PREFIX#tokenactions` SET "
                . "`return_status` = %d, "
                . "`execution_time_ms` = %s, "
                . "`return_data` = %s "
                . "WHERE `actionid` = %d",
            $return_status,
            $execution_time_ms,
            $return_data,
            $actionid
        );
        try {
            $database->query($sql);
        } catch (\Exception $e) {
            if ($database->type == 'postgresql' && strpos($e->getMessage(), 'column "return_status"') !== false) {
                $database->query($database->prepareQuery(
                    'ALTER TABLE #PREFIX#tokenactions '
                    . 'ADD COLUMN IF NOT EXISTS return_status INTEGER, '
                    . 'ADD COLUMN IF NOT EXISTS execution_time_ms NUMERIC(10,3), '
                    . 'ADD COLUMN IF NOT EXISTS return_data JSONB;'
                ));
                $database->query($database->prepareQuery(
                    'COMMENT ON COLUMN #PREFIX#tokenactions.return_status IS \'HTTP status code returned (200, 404, 500, etc.)\';'
                ));
                $database->query($database->prepareQuery(
                    'COMMENT ON COLUMN #PREFIX#tokenactions.execution_time_ms IS \'Execution time in milliseconds\';'
                ));
                $database->query($database->prepareQuery(
                    'COMMENT ON COLUMN #PREFIX#tokenactions.return_data IS \'JSON response data - use sparingly for debugging/auditing\';'
                ));
                $database->query($database->prepareQuery(
                    'CREATE INDEX IF NOT EXISTS idx_tokenactions_return_status ON #PREFIX#tokenactions(return_status);'
                ));
                $database->query($database->prepareQuery(
                    'CREATE INDEX IF NOT EXISTS idx_tokenactions_execution_time ON #PREFIX#tokenactions(execution_time_ms);'
                ));

                $database->query($database->prepareQuery(
                    'ALTER TABLE #PREFIX#tokenactions '
                    . 'ADD COLUMN IF NOT EXISTS action_time TIMESTAMP WITH TIME ZONE;'
                ));

                $database->query($database->prepareQuery(
                    'UPDATE #PREFIX#tokenactions '
                    . 'SET action_time = TO_TIMESTAMP(servertime) '
                    . 'WHERE action_time IS NULL AND servertime IS NOT NULL;'
                ));

                $database->query($database->prepareQuery(
                    'ALTER TABLE #PREFIX#tokenactions '
                    . 'ALTER COLUMN action_time SET DEFAULT CURRENT_TIMESTAMP;'
                ));

                $database->query($database->prepareQuery(
                    'CREATE OR REPLACE FUNCTION #PREFIX#sync_tokenactions_time() '
                    . 'RETURNS TRIGGER AS $$ '
                    . 'BEGIN '
                    . 'IF NEW.servertime IS NOT NULL THEN '
                    . 'NEW.action_time = TO_TIMESTAMP(NEW.servertime); '
                    . 'ELSE '
                    . 'NEW.action_time = CURRENT_TIMESTAMP; '
                    . 'NEW.servertime = EXTRACT(EPOCH FROM NEW.action_time)::INTEGER; '
                    . 'END IF; '
                    . 'RETURN NEW; '
                    . 'END; '
                    . '$$ LANGUAGE plpgsql;'
                ));

                $database->query($database->prepareQuery(
                    'CREATE TRIGGER sync_tokenactions_time '
                    . 'BEFORE INSERT OR UPDATE ON #PREFIX#tokenactions '
                    . 'FOR EACH ROW EXECUTE FUNCTION #PREFIX#sync_tokenactions_time();'
                ));
                $database->query($database->prepareQuery(
                    'ALTER TABLE #PREFIX#tokenactions '
                    . 'ALTER COLUMN action_time SET NOT NULL;'
                ));
                try {
                    $database->query($sql);
                } catch (\Exception $e) {
                    // Log the error if the query fails again
                    \Pramnos\Logs\Logger::logError(
                        'Error while updating token action with query: ' 
                        . $sql . ' - Error: ' 
                        . $e->getMessage(),
                        $e
                    );
                    return;
                }
            } elseif ($database->type == 'mysql' && strpos($e->getMessage(), 'Unknown column') !== false) {
                $database->query($database->prepareQuery(
                    'ALTER TABLE #PREFIX#tokenactions '
                    . 'ADD COLUMN IF NOT EXISTS return_status INT, '
                    . 'ADD COLUMN IF NOT EXISTS execution_time_ms DECIMAL(10,3), '
                    . 'ADD COLUMN IF NOT EXISTS return_data JSON, '
                    . 'ADD COLUMN IF NOT EXISTS action_time TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;'
                ));
                $database->query($database->prepareQuery(
                    'CREATE INDEX IF NOT EXISTS idx_tokenactions_return_status ON #PREFIX#tokenactions(return_status);'
                ));
                $database->query($database->prepareQuery(
                    'CREATE INDEX IF NOT EXISTS idx_tokenactions_execution_time ON #PREFIX#tokenactions(execution_time_ms);'
                ));
                try {
                    $database->query($sql);
                } catch (\Exception $e) {
                    \Pramnos\Logs\Logger::logError(
                        'Error while updating token action with query: '
                        . $sql . ' - Error: '
                        . $e->getMessage(),
                        $e
                    );
                    return;
                }
            } else {
                // Handle any exceptions that may occur during the query execution
                \Pramnos\Logs\Logger::logError(
                    'Error while updating token action with query: ' 
                    . $sql . ' - Error: ' 
                    . $e->getMessage(),
                    $e
                );
                return;
            }


            
        }
        
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
                'value' => $this->userid,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'tokentype',
                'value' => $this->tokentype,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'token',
                'value' => $this->token,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'created',
                'value' => $this->created,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'notes',
                'value' => $this->notes,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'lastused',
                'value' => $this->lastused,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'status',
                'value' => $this->status,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'applicationid',
                'value' => $this->applicationid,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'actions',
                'value' => $this->actions,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'removedate',
                'value' => $this->removedate,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'deviceinfo',
                'value' => json_encode($this->deviceinfo),
                'type' => 'string'
            ),
            array(
                'fieldName' => 'scope',
                'value' => json_encode($this->scope),
                'type' => 'string'
            )
        );
        if ($database->type != 'postgresql') {
            $itemdata[] = array(
                'fieldName' => 'parentToken',
                'value' => $this->parentToken,
                'type' => 'integer'
            );
        } else {
            $itemdata[] = array(
                'fieldName' => 'ipaddress',
                'value' => $this->ipaddress,
                'type' => 'string'
            );
            $itemdata[] = array(
                'fieldName' => 'expires',
                'value' => $this->expires,
                'type' => 'integer'
            );
        }
        #$database->sql_cache_flush_cache('usertokens');
        if ($this->_isnew == true) {
            $this->_isnew = false;

            if (!$database->insertDataToTable(
                $database->prefix . "usertokens",
                $itemdata
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
            $database->prefix . "usertokens",
            $itemdata,
            "`tokenid` = '" . (int) $this->tokenid . "'",
            false
        )) {

            if (
                $database->type == 'postgresql'
                && strpos($database->getError()['message'], 'column "ipaddress"') !== false
            ) {

                $database->query($database->prepareQuery('ALTER TABLE #PREFIX#usertokens ADD "expires" integer NULL, ADD "ipaddress" inet NULL;'));
                if (!$database->updateTableData(
                    $database->prefix . "usertokens",
                    $itemdata,
                    "`tokenid` = '" . (int) $this->tokenid . "'",
                    false
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

    /**
     * Get token details
     * @return array
     */
    public function getDetails()
    {
        if ($this->tokenid == 0) {
            return array(
                'tokenid' => 0,
                'userid' => 0,
                'token' => '',
                'tokentype' => '',
                'lastused' => 0,
                'created' => 0,
                'expires' => 0,
                'scope' => '',
                'status' => 0,
                'applicationid' => 0,
                'deviceinfo' => '',
                'ipaddress' => '',
                'notes' => '',
                'username' => '',
                'firstname' => '',
                'lastname' => '',
                'app_name' => ''
            );
        }
        $db = \Pramnos\Framework\Factory::getDatabase();
        // Get token details
        $tokenQuery = "SELECT ut.tokenid, ut.userid, ut.token, ut.tokentype, ut.lastused, ut.created, 
                       ut.expires, ut.scope, ut.status, ut.applicationid, ut.deviceinfo, ut.ipaddress, ut.notes,
                       u.username, u.firstname, u.lastname, a.name as app_name
                       FROM `#PREFIX#usertokens` ut 
                       LEFT JOIN `#PREFIX#users` u ON ut.userid = u.userid 
                       LEFT JOIN `#PREFIX#applications` a ON ut.applicationid = a.appid
                       WHERE ut.tokenid = %d";

        $tokenQuery = $db->prepareQuery($tokenQuery, $this->tokenid);
        $tokenResult = $db->query($tokenQuery);

        return $tokenResult->fields;
    }

    /**
     * Get token statistics
     * @return array
     */
    public function getStatistics()
    {
        if ($this->tokenid == 0) {
            return array(
                'total_actions' => 0,
                'first_action' => null,
                'last_action' => null,
                'active_days' => 0
            );
        }
        $database = \Pramnos\Framework\Factory::getDatabase();

        // Get statistics
        $statsQuery = "";

        // Use different SQL syntax based on the database type
        if ($database->type == 'postgresql') {
            // PostgreSQL version - use to_timestamp function instead of FROM_UNIXTIME
            $statsQuery = "SELECT 
                            COUNT(*) as total_actions,
                            MIN(servertime) as first_action,
                            MAX(servertime) as last_action,
                            COUNT(DISTINCT DATE(to_timestamp(servertime))) as active_days
                          FROM `#PREFIX#tokenactions` 
                          WHERE tokenid = %d";
        } else {
            // MySQL version - use FROM_UNIXTIME
            $statsQuery = "SELECT 
                            COUNT(*) as total_actions,
                            MIN(servertime) as first_action,
                            MAX(servertime) as last_action,
                            COUNT(DISTINCT DATE(FROM_UNIXTIME(servertime))) as active_days
                          FROM `#PREFIX#tokenactions`
                          WHERE tokenid = %d";
        }

        $statsQuery = $database->prepareQuery($statsQuery, $this->tokenid);
        $statsResult = $database->query($statsQuery);

        return $statsResult->fields;
    }

    /**
     * Get token actions
     * @param int $limit Number of records to return
     * @param int $offset Pagination offset
     * @param string $orderBy Field to order by (default: servertime)
     * @param string $orderDir Order direction (ASC or DESC)
     * @return array With 'data' containing the results and 'total' containing the total count
     */
    public function getActions($limit = 100, $offset = 0, $orderBy = 'servertime', $orderDir = 'DESC')
    {
        if ($this->tokenid == 0) {
            return array('data' => array(), 'total' => 0);
        }
        $database = \Pramnos\Framework\Factory::getDatabase();

        // Validate order direction
        $orderDir = strtoupper($orderDir);
        if ($orderDir !== 'ASC' && $orderDir !== 'DESC') {
            $orderDir = 'DESC';
        }

        // Validate order by field
        $allowedFields = ['actionid', 'tokenid', 'urlid', 'method', 'servertime'];
        if (!in_array($orderBy, $allowedFields)) {
            $orderBy = 'servertime';
        }
        
        // First get total count
        $countQuery = "SELECT COUNT(*) as total FROM `#PREFIX#tokenactions` WHERE tokenid = %d";
        $countQuery = $database->prepareQuery($countQuery, $this->tokenid);
        $countResult = $database->query($countQuery);
        $totalCount = 0;
        if ($countResult && $countResult->numRows > 0) {
            $totalCount = (int)$countResult->fields['total'];
        }
        
        // Get token actions - using database type agnostic query
        $actionsQuery = "SELECT ta.actionid, ta.tokenid, ta.urlid, ta.method, ta.servertime,
                        u.url, ta.params as parameters
                        FROM `#PREFIX#tokenactions` ta
                        LEFT JOIN `#PREFIX#urls` u ON ta.urlid = u.urlid
                        WHERE ta.tokenid = %d
                        ORDER BY ta." . $orderBy . " " . $orderDir;
                        
        // Add limit and offset in a database-compatible way
        if ($database->type == 'postgresql') {
            $actionsQuery .= " LIMIT %d OFFSET %d";
        } else {
            // MySQL or other databases
            $actionsQuery .= " LIMIT %d OFFSET %d";
        }
        

        $actionsQuery = $database->prepareQuery(
            $actionsQuery, $this->tokenid, $limit, $offset
        );
        
        $actionsResult = $database->query($actionsQuery);

        $actionData = [];

        if ($actionsResult && $actionsResult->numRows > 0) {
            while ($actionsResult->fetch()) {
                $action = $actionsResult->fields;

                // check if $action['parameters'] is a json object, and if it is, decode it
                if (is_string($action['parameters'])) {
                    $action['parameters'] = json_decode($action['parameters'], true);
                }

                $actionData[] = $action;
            }
        }
        return array('data' => $actionData, 'total' => $totalCount);
    }
}
