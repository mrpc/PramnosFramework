<?php
namespace Pramnos\Application\Api;
/**
 * API Key Class
 *
 * @package     PramnosFramework
 * @subpackage Application
 * @copyright   Copyright (C) 2017  Yannis - Pastis Glaros, Pramnos Hosting Ltd.
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Apikey extends \Pramnos\Framework\Base
{
    /**
     * Application ID (primary key)
     * @var int
     */
    public $appid = 0;
    /**
     * Application name
     * @var string
     */
    public $name = '';
    /**
     * Api Key of the application
     * @var string
     */
    public $apikey = '';
    /**
     * Api secret
     * @var string
     */
    public $apisecret = '';
    /**
     * 0: Inactive 1: active 2: deleted 3: Waiting
     * @var int
     */
    public $status = 0;
    /**
     * Create date (Unix Timestamp)
     * @var int
     */
    public $added = 0;
    /**
     * Application Description
     * @var string
     */
    public $description = '';
    /**
     * Organization Name
     * @var string
     */
    public $organization = '';
    /**
     * Organization Website
     * @var string
     */
    public $organizationurl = '';
    /**
     * Application Website
     * @var string
     */
    public $url = '';
    /**
     * 0: Website 1: Mobile Device 2: Both
     * @var int
     */
    public $apptype = 0;
    /**
     * 0: REST Api 1: oAuth 2
     * @var int
     */
    public $accesstype = 0;
    /**
     * Api Version
     * @var string
     */
    public $apiversion = '';
    /**
     * Application Scope
     * @var string
     */
    public $scope = '';
    /**
     * Is the application public in app directory
     * @var int
     */
    public $public = 0;
    /**
     * Callback url for oAuth2
     * @var string
     */
    public $callback = '';
    /**
     * Owner User ID
     * @var int
     */
    public $owner = null;
    /**
     * State for database
     * @var bool
     */
    protected $_isnew = true;


    /**
     * An API user application
     * @param int|array $appid
     */
    public function __construct($appid = null)
    {
        if ($appid !== null) {
            if (is_numeric($appid) || is_string($appid)) {
                $this->load($appid);
            } elseif (is_array($appid)) {
                $this->fillProperties($appid);
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
        
    }

    /**
     * Load a token from the database
     * @param int $appid
     * @return Apikey
     */
    public function load($appid)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if (is_numeric($appid)) {
            $sql = $database->prepareQuery(
                "SELECT * FROM `#PREFIX#applications` "
                . " WHERE `appid` = %d limit 1",
                $appid
            );
        } else {
            $sql = $database->prepareQuery(
                "SELECT * FROM `#PREFIX#applications`"
                . " WHERE `apikey` = %s limit 1",
                $appid
            );
        }
        $result = $database->query($sql, false, 3600, 'apps');
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
        $data['added'] = date('c', $this->added);
        $statusArray = array('Inactive', 'Active', 'Deleted', 'Pending');
        $data['status'] = '';
        if (isset($statusArray[(int) $this->status])) {
            $data['status'] = $statusArray[(int) $this->status];
        }
        if (isset($data['owner']) && (int) $data['owner'] != 0) {
            $owner = \Pramnos\User\User::getUser($data['owner']);
            $data['owner'] = $owner->getData();
        }
        return $data;
    }

    /**
     * Returns a list of all applications
     * @return array Array of applications
     */
    public function getList()
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "select * from `#PREFIX#applications`"
        );
        $result = $database->query($sql);
        $applications = array();
        foreach ($result as $app) {
            $applications[] = new Apikey($app->fields);
        }

        return $applications;
    }


    /**
     * Αποθήκευση του api key στη βάση δεδομένων
     * @return Apikey
     */
    public function save()
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($this->_isnew == true) {
            $this->added = time();
        }
        if ((int)$this->owner == 0) {
            $this->owner = null;
        }
        if ($this->apikey == '') {
            $this->apikey = md5($this->name . time() . rand(0, time()));
        }

        $itemdata = array(
            array(
                'fieldName' => 'name',
                'value' => $this->name, 'type' => 'string'
            ),
            array(
                'fieldName' => 'apikey',
                'value' => $this->apikey, 'type' => 'string'
            ),
            array(
                'fieldName' => 'apisecret',
                'value' => $this->apisecret, 'type' => 'string'
            ),
            array(
                'fieldName' => 'status',
                'value' => $this->status, 'type' => 'integer'
            ),
            array(
                'fieldName' => 'added',
                'value' => $this->added, 'type' => 'integer'
            ),
            array(
                'fieldName' => 'description',
                'value' => $this->description, 'type' => 'string'
            ),
            array(
                'fieldName' => 'organization',
                'value' => $this->organization, 'type' => 'string'
            ),
            array(
                'fieldName' => 'organizationurl',
                'value' => $this->organizationurl, 'type' => 'string'
            ),
            array(
                'fieldName' => 'url',
                'value' => $this->url, 'type' => 'string'
            ),
            array(
                'fieldName' => 'apptype',
                'value' => $this->apptype, 'type' => 'integer'
            ),
            array(
                'fieldName' => 'accesstype',
                'value' => $this->accesstype, 'type' => 'integer'
            ),
            array(
                'fieldName' => 'apiversion',
                'value' => $this->apiversion, 'type' => 'string'
            ),
            array(
                'fieldName' => 'scope',
                'value' => $this->scope, 'type' => 'string'
            ),
            array(
                'fieldName' => 'public',
                'value' => $this->public, 'type' => 'integer'
            ),
            array(
                'fieldName' => 'callback',
                'value' => $this->callback, 'type' => 'string'
            ),
            array(
                'fieldName' => 'owner',
                'value' => $this->owner, 'type' => 'integer'
            ),
        );
        $database->cacheflush('applications');
        if ($this->_isnew == true) {
            $this->_isnew = false;

            if (!$database->insertDataToTable(
                $database->prefix . "applications", $itemdata
            )) {
                $error = $database->getError();
                $this->addError($error['message']);
            } else {
                $this->appid = $database->getInsertId();
            }
            return $this;
        }
        if (!$database->updateTableData(
            $database->prefix . "applications", $itemdata,
            "`appid` = '" . (int) $this->appid . "'"
        )) {
            $error = $database->getError();
            $this->addError($error['message']);
        }

        return $this;
    }

}