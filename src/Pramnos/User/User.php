<?php
namespace Pramnos\User;
/**
 * User class
 * Dynamic loading of new user information  :-)
 * @copyright   (c) 2005 - 2015 Yannis - Pastis Glaros, Pramnos Hosting
 * @package     PramnosFramework
 * @subpackage  User
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class User extends \Pramnos\Framework\Base
{

    private $_userstable = DB_USERSTABLE;
    private $_userdetailstable = DB_USERSTABLE;
    /**
     * User ID
     * @var int
     */
    public $userid = 1;
    /**
     * Username
     * @var string
     */
    public $username = "Anonymous";
    /**
     * First Name
     * @var string
     */
    public $firstname = '';
    /**
     * Last Name
     * @var string
     */
    public $lastname = '';
    /**
     * Registration Completion in unix timestamp
     * @var int
     */
    public $regcompletion = null;
    /**
     * Last agreement to the terms of use, in unix timestamp
     * @var int
     */
    public $lasttermsagreed = null;
    /**
     * User type
     * @var int
     */
    public $usertype;
    /**
     * User gender. 0: Unknown 1: Male 2: Female
     * @var int
     */
    public $sex = 0;

    public $photo = null;

    public $phone = '';
    public $fax = '';
    public $mobile = '';
    public $birthdate = 0;

    public $website = '';
    /**
     * Last modification in unix timestamp
     * @var int
     */
    public $modified = 0;

    public $password = "";
    public $email = "";
    public $regdate = 0;
    public $lastlogin = 0;
    public $maingroup = 1;
    public $active = 1;
    public $validated = 1;
    public $language = "";
    public $timezone = "+2";
    public $dateformat = "";
    public $otherinfo = array();
    protected $originalOtherinfo = array();
    protected $_isnew = 0;
    protected static $_usercache = NULL;
    protected static $usersCache = array();

    public function __construct($userid = 0)
    {
        if ($userid === 0) {
            $this->_isnew = 1;
        }
        else {
            return $this->load($userid);
        }
        parent::__construct();
    }

    /**
     * Delete the selected user
     */
    function deleteuser()
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($this->_isnew == false) {
            $sql = $database->prepareQuery(
                "delete from `#PREFIX#users` "
                . "where `userid` = %d ", $this->userid
            );
            $database->query($sql);
            $this->_isnew = 1;
        }
        return $this;
    }

    /**
     * Activate the selected user
     */
    function activate()
    {
        if ($this->_isnew == false) {
            $this->active = true;
            $database = \Pramnos\Framework\Factory::getDatabase();
            if ($database->type == 'postgresql') {
                $sql = $database->prepareQuery(
                    "update `#PREFIX#users`"
                    . " set `active` = 't' where `userid` = %d", $this->userid
                );
            } else {
                $sql = $database->prepareQuery(
                    "update `#PREFIX#users`"
                    . " set `active` = 1 where `userid` = %d", $this->userid
                );
            }
            
            $database->query($sql);
        }
        else {
            $this->active = true;
        }
    }

    /**
     * Deactivate the selected user
     */
    function deactivate()
    {
        if ($this->_isnew == false) {
            $this->active = 0;
            $database = \Pramnos\Framework\Factory::getDatabase();
            $sql = $database->prepareQuery(
                "update `#PREFIX#users` "
                . "set `active` = 0 where `userid` = %d", $this->userid
            );
            $database->query($sql);
        }
        else {
            $this->active = 0;
        }
    }

    /**
     * Get a user by it's user id
     * @param int $userid
     * @return User User Object
     */
    public static function getUser($userid)
    {

        if (isset(self::$usersCache[$userid])) {
            return self::$usersCache[$userid];
        }

        $app = \Pramnos\Application\Application::getInstance();

         // Try to find an override user class
        if (isset($app->applicationInfo['namespace'])
            && $app->applicationInfo['namespace'] != ''
            && class_exists(
                '\\'
                . $app->applicationInfo['namespace']
                . '\\User'
            )) {
            $className = '\\'
                . $app->applicationInfo['namespace']
                . '\\User';
            $user = new $className($userid);
        } else {
            $user = new User($userid);
        }
        if ($user->userid > 1) {
            self::$usersCache[$userid] = $user;
        }
        return $user;
    }

    /**
     * Returns an array with all users (altered by the $where filter)
     * @param string $where
     * @return User[]
     */
    static function getUsers($where = '')
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery("select `userid` from `#PREFIX#users`");
        if ($where != '') {
            $sql .= ' where ' . $database->prepareInput($where);
        }
        $users = $database->query($sql, 1, 10, 'userlist');
        $return = array();
        while ($users->fetch()) {
            $theuser = new User($users->fields['userid']);
            $theuser->userid = $users->fields['userid'];
            $theuser->load($users->fields['userid']);
            $return[$users->fields['userid']] = $theuser;
            unset($theuser);
        }
        return $return;
    }

    /**
     * Get a non-standard user field
     * @param string $name
     * @return mixed
     */
    function __get($name)
    {
        if (isset($this->otherinfo[$name])) {
            return $this->otherinfo[$name];
        }
        else {
            return NULL;
        }
    }

    /**
     *
     * @param string $name
     * @param string $value
     * @return mixed
     */
    function __set($name, $value)
    {
        $this->otherinfo[$name] = $value;
    }

    /**
     * Check user's access
     * @param string $moduletype
     * @param string $moduleid
     * @param string $what
     * @param string $elementid
     * @param string $extraflag
     * @return boolean
     */
    function hasaccess($moduletype, $moduleid, $what = 'read',
        $elementid = '', $extraflag = '')
    {
        $auth = \Pramnos\Framework\Factory::getAuth();
        return $auth->useraccess(
            $this->userid, $moduletype, $moduleid, $what,
            $elementid, 'user', $extraflag, true
        );
    }

    /**
     * Set user's access
     * @param boolean $value
     * @param string $moduletype
     * @param string $moduleid
     * @param string $what
     * @param string $elementid
     * @param string $extraflag
     * @return boolean
     */
    function setaccess($value, $moduletype, $moduleid, $what = 'read',
        $elementid = '', $extraflag = '')
    {
        $auth = \Pramnos\Framework\Factory::getAuth();
        return $auth->setaccess(
            $this->userid, $moduletype, $moduleid, $what,
            $elementid, 'user', $extraflag, $value
        );
    }

    /**
     * Sets the password of this user
     * @param string $password
     */
    public function setPassword($password = '')
    {
        if ($this->userid > 1) {
            $pwd = $password
                . md5(
                \Pramnos\Application\Settings::getSetting('securitySalt')
                . $this->userid
            );
            $this->password = password_hash($pwd, PASSWORD_DEFAULT);
        } else {
            $this->password = md5($password);
        }
    }

    /**
     * Returns an array with all groups the user is subscribed to
     * @return \pramnos_user_group
     */
    public function getGroups()
    {
        if (DB_USERGROUPSUBSCRIPTIONS == false) {
            return array();
        }
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "select * from `"
            . DB_USERGROUPSUBSCRIPTIONS
            . " `where `userid` = %d", $this->userid
        );
        try {
            $result = $database->query($sql, true, 60);
        }
        catch (Exception $exc) {
            pramnos_logs::log($exc->getMessage());
            return array();
        }
        $return = array();
        $return[$this->maingroup] = new pramnos_user_group($this->maingroup);
        while ($result->fetch()) {
            if (!isset($return[$result->fields['group_id']])) {
                $return[$result->fields['group_id']] = new pramnos_user_group(
                    $result->fields['group_id']
                );
            }
        }
        return $return;
    }

    protected function _alterFields($fields)
    {
        return $fields;
    }

    /**
     * This is the actual save function, to be extended
     * @param boolean $groupSupport
     * @return User
     * @throws Exception
     */
    protected function _save($groupSupport = TRUE, $debug = false)
    {
        if (trim($this->username) == '' || trim($this->email) == '') {
            throw new \Exception(
                'Invalid username or email address. Username: '
                . $this->username
                . '. Email address: '
                . $this->email
            );
        }
        $database = \Pramnos\Framework\Factory::getDatabase();
        $itemdata = array(
            array(
                'fieldName' => 'username',
                'value' => $this->username,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'password',
                'value' => $this->password,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'email',
                'value' => $this->email,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'regdate',
                'value' => $this->regdate,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'lastlogin',
                'value' => $this->lastlogin,
                'type' => 'integer'
            ),
            
            array(
                'fieldName' => 'validated',
                'value' => $this->validated,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'language',
                'value' => $this->language,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'lastname',
                'value' => $this->lastname,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'firstname',
                'value' => $this->firstname,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'timezone',
                'value' => $this->timezone,
                'type' => 'string'
            ),

            array(
                'fieldName' => 'dateformat',
                'value' => $this->dateformat,
                'type' => 'string'
            ),

            array(
                'fieldName' => 'regcompletion',
                'value' => $this->regcompletion,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'lasttermsagreed',
                'value' => $this->lasttermsagreed,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'usertype',
                'value' => $this->usertype,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'sex',
                'value' => $this->sex,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'birthdate',
                'value' => $this->birthdate,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'photo',
                'value' => $this->photo,
                'type' => 'integer'
            ),
            array(
                'fieldName' => 'phone',
                'value' => $this->phone,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'fax',
                'value' => $this->fax,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'mobile',
                'value' => $this->mobile,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'website',
                'value' => $this->website,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'modified',
                'value' => $this->modified,
                'type' => 'string'
            )


        );
        if ($database->type !=  'postgresql'){
            $itemdata[] = array(
                'fieldName' => 'active',
                'value' => $this->active,
                'type' => 'integer'
            );
        }

        if ($groupSupport == true) {
            $itemdata[] = array(
                'fieldName' => 'maingroup',
                'value' => $this->maingroup,
                'type' => 'integer'
            );
        }

        $itemdata = $this->_alterFields($itemdata);
        if ($this->_isnew === 1 || $this->userid == 1) {
            $this->_isnew = 0;
            if ($this->userid != 1) {
                $itemdata[] = array(
                    'fieldName' => 'userid',
                    'value' => $this->userid,
                    'type' => 'integer');
            }

            if ($database->type == 'postgresql') {
                $dbresult = $database->insertDataToTable(
                    $database->prefix . "users", $itemdata, 'userid'
                );
                if (!$dbresult) {
                    $error = $database->getError();
                    $this->addError($error['message']);
                    return $this;
                }
                $this->userid = pg_fetch_result($dbresult, 0, 'userid');
            } else {
                if (!$database->insertDataToTable(
                    $database->prefix . "users", $itemdata, 'userid'
                )) {
                    $error = $database->getError();
                    $this->addError($error['message']);
                    return $this;
                }
                $this->userid = $database->getInsertId();
            }

            
        } else {
            if (!$database->updateTableData(
                $database->prefix . "users", $itemdata,
                "`userid` = " . $this->userid
            )) {
                $error = $database->getError();
                $this->addError($error['message']);
                return $this;
            }
        }

        foreach (array_keys($this->otherinfo) as $fieldname) {
            $fixname = substr($fieldname, 3);
            if ($this->$fieldname === NULL) {
                $sql = $database->prepareQuery(
                    "DELETE FROM `#PREFIX#userdetails` "
                    . "where `userid` = %d and `fieldname` = %s ", 
                    $this->userid, $fieldname
                );
            } elseif (is_object($this->$fieldname)
                || is_array($this->$fieldname)) {

                if ($fixname != 'originalOtherinfo'
                    && substr($fixname, 0, 1) != '_'
                    && substr($fieldname, 0, 1) != '_') {

                    if ($database->type == 'postgresql') {
                        $sql = $database->prepareQuery(
                            "insert into #PREFIX#userdetails "
                            . " (userid, fieldname, value) "
                            . " values (%d, %s, %s) "
                            . " ON CONFLICT (userid, fieldname) DO UPDATE SET value = %s ",
                            $this->userid, $fieldname,
                            serialize($this->$fieldname),
                            serialize($this->$fieldname)
                        );
                    } else {
                        $sql = $database->prepareQuery(
                            "insert into `#PREFIX#userdetails` "
                            . " (`userid`, `fieldname`, `value`) "
                            . " values (%d, %s, %s) "
                            . " ON DUPLICATE KEY UPDATE `value` = %s ",
                            $this->userid, $fieldname,
                            serialize($this->$fieldname),
                            serialize($this->$fieldname)
                        );
                    }
                }

            } elseif (!isset($this->originalOtherinfo[$fieldname])
                || $this->originalOtherinfo[$fieldname] != $this->$fieldname
                && substr($fixname, 0, 1) != '_'
                && substr($fieldname, 0, 1) != '_') {
                if ($database->type == 'postgresql') {
                    $sql = $database->prepareQuery(
                        "insert into #PREFIX#userdetails "
                        . " (userid, fieldname, value) "
                        . " values (%d, %s, %s) "
                        . " ON CONFLICT (userid, fieldname) DO UPDATE SET value = %s ",
                        $this->userid, $fieldname,
                        $this->$fieldname, $this->$fieldname
                    );
                } else {
                    $sql = $database->prepareQuery(
                        "insert into `#PREFIX#userdetails` "
                        . " (`userid`, `fieldname`, `value`) "
                        . " values (%d, %s, %s) "
                        . " ON DUPLICATE KEY UPDATE `value` = %s ",
                        $this->userid, $fieldname,
                        $this->$fieldname, $this->$fieldname
                    );
                }
            }

            try {
                if (isset($sql)) {
                    $database->query($sql);
                    unset($sql);
                }
            } catch (\Exception $ex) {
                $error = $database->getError();
                $this->addError($error['message']);
                \Pramnos\Logs\Logger::log($ex->getMessage());
            }

        }
        $database->cacheflush('userlist');
        return $this;
    }

    /**
     * Save user data into database
     */
    public function save()
    {
        return $this->_save(false);
    }

    /**
     * Get user data from database
     */
    public function load($uid = 0)
    {
        if ($uid === 0) {
            if (isset($_SESSION['uid'])) {
                $uid = $_SESSION['uid'];
            }
            else {
                return false;
            }
        }
        if (is_array(self::$_usercache) && isset(self::$_usercache[$uid])) {
            foreach (self::$_usercache[$uid] as $key => $value) {
                $this->$key = $value;
            }
            return $this;
        }
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "SELECT * FROM #PREFIX#users WHERE `userid` = %d LIMIT 1", $uid
        );
        $result = $database->query($sql, 1, 10, 'userlist');
        if ($result->numRows == 0) {
            return false;
        }
        $this->_isnew = false;
        foreach (array_keys($result->fields) as $key) {
            $this->$key = $result->fields[$key];
        }


        $sql = $database->prepareQuery(
            "SELECT * FROM #PREFIX#userdetails WHERE `userid` = %d", $uid
        );
        $result = $database->query($sql);
        while ($result->fetch()) { //This should load all special settings
            $fixname = substr($result->fields['fieldname'], 3);
            if ($fixname != 'originalOtherinfo'
                && substr($fixname, 0, 1) != '_'
                && substr($result->fields['fieldname'], 0, 1) != '_') {
                $this->otherinfo[$result->fields['fieldname']]
                    = $result->fields['value'];
            }
        }
        $this->originalOtherinfo = $this->otherinfo;

        if ($this->avatarurl === '' or $this->avatarurl === NULL) {
            $this->avatarurl = sURL . 'media/img/pramnoscms/noavatar.jpg';
        }

        if ($this->_isnew == false) {
            if (!is_array(self::$_usercache)) {
                self::$_usercache = array();
            }
            self::$_usercache[$uid] = (array) $this;
        }
        return $this;
    }

    /**
     * Get all users with a specific parameter
     * @param string $param
     * @param string $value
     */
    static function getbyparam($param, $value)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "select `userid` from `#PREFIX#userdetails` "
            . "where `fieldname` = %s and `value` = %s", $param, $value
        );
        $result = $database->query($sql);
        $return = array();
        while ($result->fetch()) {
            $return[] = $result->fields['userid'];
        }
        return $return;
    }

    /**
     * Get a user ID by username
     * @param string $username Username
     */
    static function getuserid($username, $by = 'username')
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($by != 'username' && $by != 'email') {
            return false;
        }
        $sql = $database->prepareQuery(
            "SELECT `userid` FROM `#PREFIX#users` "
            . " WHERE `$by` = %s limit 1",
            $username
        );
        $result = $database->query($sql);
        if ($result->numRows == 1) {
            return $result->fields['userid'];
        } else {
            return false;
        }
    }

    /**
     * Makes two users friends
     * @global array $config
     * @param int $usera
     * @param int $userb
     */
    function makefriends($usera, $userb)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        self::removefriends($usera, $userb);
        $sql = "INSERT INTO `"
            . $database->prefix
            . "userfriends` (`from_userid`, `to_userid`, `confirm`) values "
            . "( '"
            . (int) $usera
            . "', '"
            . (int) $userb
            . "', '1')";
        $database->query($sql);
    }

    /**
     * Removes two users from friends
     * @global array $config
     * @param int $usera
     * @param int $userb
     */
    function removefriends($usera, $userb)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = "delete from `" . $database->prefix . "userfriends` "
                . "where (`from_userid` = '$usera' and `to_userid`='$userb') "
                . "or (`from_userid` = '$userb' and `to_userid`='$usera')";
        $database->query($sql);
    }

    /**
     * Return true if users are friends
     * @global array $config
     * @param int $usera
     * @param int $userb
     * @return boolean
     */
    function arefriends($usera, $userb)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = "select * from `" . $database->prefix . "userfriends` "
                . "where `confirm` = 1 "
                . "and ((`from_userid` = '$usera' and `to_userid`='$userb') "
                . "or (`from_userid` = '$userb' and `to_userid`='$usera'))";
        $result = $database->query($sql);
        if ($result->numRows == 1) {
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * Get an array with all user's friends
     * @global array $config
     * @param int $userid ID of the user
     * @return array All friend's IDs
     */
    public static function getfriends($userid)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $return = array();
        $sql = "select * from `#PREFIX#userfriends` "
                . "where `confirm` = 1 "
                . "and (`from_userid` = '$userid' or `to_userid`='$userid')";
        $result = $database->query($sql);
        while ($result->fetch()) {

            if ($result->fields['from_userid'] == $userid) {
                $return[] = $result->fields['to_userid'];
            } else {
                $return[] = $result->fields['from_userid'];
            }

        }
        return $return;
    }

    public function getFeed($limit = 10)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $friends = array();
        $sql = $database->prepareQuery(
            "select * "
            . "from `#PREFIX#userfriends` "
            . "where `confirm` = 1 "
            . "and (`from_userid` = %d or `to_userid`=%d)", $this->userid,
            $this->userid
        );
        $result = $database->query($sql);
        while ($result->fetch()) {
            if ($result->fields['from_userid'] == $this->userid) {
                $friends[] = $result->fields['to_userid'];
            } else {
                $friends[] = $result->fields['from_userid'];
            }
        }

        $in = '0';
        foreach ($friends as $friendid) {
            $in .= ', ' . $friendid;
        }
        $secondSql = $database->prepareQuery(
                "select * from `#PREFIX#feed` "
                . "where `userid` in (" . $in . ") "
                . "and itemprivacy=0 "
                . "order by `date` desc limit " . $limit
        );
        $finalResult = $database->query($secondSql);
        $return = array();
        while ($finalResult->fetch()) {
            if (trim($result->fields['itemtext']) != '') {
                $return[$result->fields['itemid']] = array(
                    'date' => $result->fields['date'],
                    'itemtext' => $result->fields['itemtext'],
                    'user' => new pramnoscms_user($result->fields['userid'])
                );
            }
        }
        return $return;
    }

    public function addFeed($text, $privacy = 0)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "insert into `#PREFIX#feed` "
            . "(`date`, `userid`, `usertype`, `itemprivacy`, `itemtext`) "
            . "values "
            . "(%d, %d, %d, %d, %s)", time(), $this->userid, 0, $privacy,
            $text
        );
        $database->query($sql);
        return $this;
    }

    public function changeStatus($text)
    {
        $text = trim($text);
        $this->addFeed($text);
        $this->profilestatus = $text;
    }

    /**
     * Returns an array with the database tables this class uses
     * @return array
     */
    public function getTableNames()
    {
        return array(
            'users' => $this->_userstable,
            'userdetails' => $this->_userdetailstable
        );
    }

    /**
     * Return the current logged user
     * @return User|boolean
     */
    public static function getCurrentUser()
    {
        if (\Pramnos\Http\Session::staticIsLogged() == true) {
            $app = \Pramnos\Application\Application::getInstance();
            if (is_object($app->currentUser)) {
                if (!isset($_SESSION['ad_minlogin'])
                    || (int) $_SESSION['adminlogin'] == 0
                    || (int) $_SESSION['adminlogin']
                    == $app->currentUser->userid) {
                    $lang = \Pramnos\Framework\Factory::getLanguage();
                    $language = $lang->currentlang();
                    if ($app->currentUser->language != $language) {
                        $app->currentUser->language = $language;
                        $app->currentUser->save();
                    }
                }

                return $app->currentUser;
            }
             // Try to find an override user class
            if (isset($app->applicationInfo['namespace'])
                && $app->applicationInfo['namespace'] != ''
                && class_exists(
                    '\\'
                    . $app->applicationInfo['namespace']
                    . '\\User'
                )) {
                $className = '\\'
                    . $app->applicationInfo['namespace']
                    . '\\User';
                $app->currentUser = new $className($_SESSION['uid']);
            } else {
                $app->currentUser = new User($_SESSION['uid']);
            }
            return $app->currentUser;
        }

        return false;
    }

    /**
     * Add a token to the database
     * @param string $tokentype
     * @param string $token
     * @param string $notes
     * @return $this
     */
    public function addToken($tokentype, $token, $notes='',
        $parentToken = null)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($database->type == 'postgresql') {
            
            $table = '#PREFIX#usertokens';
            
            $sql = $database->prepareQuery(
                "insert into " . $table
                . " (`userid`, `tokentype`, `token`, `created`, `notes`, `status`,"
                . " `lastused`, `actions`, `removedate`, `deviceinfo`, `scope`)"
                . " values"
                . " (%d, %s, %s, %d, %s, 1, %d, 0, 0, '', '') ON CONFLICT (userid, tokentype, token) DO UPDATE SET "
                . " `lastused` = %d, `status` = 1",
                $this->userid, $tokentype, $token, time(), $notes, time(),
                time()
            );
        } else {
            $sql = $database->prepareQuery(
                "insert into `#PREFIX#usertokens` "
                . " (`userid`, `tokentype`, `token`, `created`, `notes`, `status`,"
                . " `parentToken`)"
                . " values"
                . " (%d, %s, %s, %d, %s, 1, %d) on duplicate key update"
                . " `lastused` = %d, `status` = 1, `parentToken` = %d",
                $this->userid, $tokentype, $token, time(), $notes, $parentToken,
                time(), $parentToken
            );
        }
        $database->query($sql);
        return $this;
    }


    /**
     * Delete a token from this user
     * @param int $tokenid
     * @return $this
     */
    public function deleteToken($tokenid)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($database->type == 'postgresql') {
            $sql = $database->prepareQuery(
                "update `#PREFIX#usertokens` set `status` = 2, `removedate` = %d "
                . "where (`tokenid` = %d)"
                . "  and `userid` = %d",
                time(), $tokenid, $this->userid
            );
        } else {
            $sql = $database->prepareQuery(
                "update `#PREFIX#usertokens` set `status` = 2, `removedate` = %d "
                . "where (`tokenid` = %d or `parentToken` = %d)"
                . "  and `userid` = %d",
                time(), $tokenid, $tokenid, $this->userid
            );
        }
        
        $database->query($sql);
        return $this;
    }

    /**
     * Clear ALL tokens from this user
     * @return $this
     */
    public function clearTokens()
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "update `#PREFIX#usertokens` set `status` = 2, `removedate` = %d "
            . "where `userid` = %d ",
            time(), $this->userid
        );
        $database->query($sql);
        return $this;
    }

    /**
     * Return an active auth token for the user
     * @return string|bool
     */
    public function getToken()
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "SELECT * FROM #PREFIX#usertokens WHERE `tokentype` = 'auth' and `status` = 1 and `userid` = %d LIMIT 1", $this->userid
        );
        $result = $database->query($sql, true);
        if ($result->numRows == 0) {
            return false;
        }
        return $result->fields['token'];
    }

    /**
     * Get all tokens for the user
     * @return array Array of tokens with their details
     */
    public function getAllTokens()
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "SELECT * FROM #PREFIX#usertokens WHERE `userid` = %d ORDER BY created DESC", 
            $this->userid
        );
        $result = $database->query($sql);
        
        $tokens = [];
        while ($result->fetch()) {
            $tokens[] = [
                'tokenid' => isset($result->fields['tokenid']) ? (int)$result->fields['tokenid'] : 0,
                'token' => $result->fields['token'],
                'tokentype' => $result->fields['tokentype'],
                'created' => isset($result->fields['created']) ? (int)$result->fields['created'] : 0,
                'status' => isset($result->fields['status']) ? (int)$result->fields['status'] : 0,
                'lastused' => isset($result->fields['lastused']) ? (int)$result->fields['lastused'] : 0,
                'expires' => isset($result->fields['expires']) ? (int)$result->fields['expires'] : 0,
                'ipaddress' => $result->fields['ipaddress']
            ];
        }
        
        return $tokens;
    }
    
    /**
     * Deactivate a user token
     * @param int $tokenId Token ID to deactivate
     * @return bool Success status
     */
    public function deactivateToken($tokenId)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "UPDATE #PREFIX#usertokens SET `status` = 0 WHERE `tokenid` = %d AND `userid` = %d",
            $tokenId, $this->userid
        );
        $database->query($sql);
        
        return true;
    }

    /**
     * Expire a user token (set expiration to current time)
     * @param int $tokenId Token ID to expire
     * @return bool Success status
     */
    public function expireToken($tokenId)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepareQuery(
            "UPDATE #PREFIX#usertokens SET `expires` = %d, `status` = 0 WHERE `tokenid` = %d AND `userid` = %d",
            time(), $tokenId, $this->userid
        );
        $database->query($sql);
        
        return true;
    }
    
    
    

    
    /**
     * Cleanup unused auth tokens older than a month
     * Removes any auth tokens that haven't been used in over a month
     * @param int $days Number of days to keep tokens (default: 30)
     * @return bool Success status
     */
    public function cleanupAuthTokens(int $days = 30)
    {
        $oneMonthAgo = time() - ($days * 24 * 60 * 60); // 30 days in seconds
        $database = \Pramnos\Framework\Factory::getDatabase();
        
        // Delete the auth tokens only
        $sql = $database->prepareQuery(
            "UPDATE #PREFIX#usertokens SET `status` = 2 WHERE `userid` = %d AND `lastused` < %d AND `tokentype` in ('auth', 'access_token') ",
            $this->userid, $oneMonthAgo
        );
        $database->query($sql);
        
        return true;
    }
    
    /**
     * Static method to clean up all unused auth tokens older than a specified number of days
     * @param int $days Number of days to keep tokens (default: 30)
     * @return bool Success status
     */
    public static function cleanupAllAuthTokens(int $days = 30)
    {
        $oneMonthAgo = time() - ($days * 24 * 60 * 60); // 30 days in seconds
        $database = \Pramnos\Framework\Factory::getDatabase();
        
        // Delete the auth tokens only
        $sql = $database->prepareQuery(
            "UPDATE #PREFIX#usertokens SET `status` = 2 WHERE `lastused` < %d AND `tokentype` in ('auth', 'access_token') ",
            $oneMonthAgo
        );
        $database->query($sql);
        
        return true;
    }

    /**
     * Load a user based on user token (useful for the API)
     * @param string $token
     * @param string $tokentype
     * @param boolean $setSessionApi
     * @return $this
     */
    public function loadByToken($token, $tokentype='auth', $setSessionApi=true)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($tokentype == 'auth') {
            $sql = $database->prepareQuery(
                "select * from `#PREFIX#usertokens`"
                . " where `token` = %s and `tokentype` in ('auth', 'access_token') "
                . " and `status` = 1 and (`expires` = 0 or `expires` > %d or `expires` is null) limit 1",
                $token, time()
            );
        } else {
            $sql = $database->prepareQuery(
                "select * from `#PREFIX#usertokens`"
                . " where `token` = %s and `tokentype` = %s "
                . " and `status` = 1 and (`expires` = 0 or `expires` > %d or `expires` is null) limit 1",
                $token, $tokentype, time()
            );
        }
        
        $result = $database->query($sql);
        if ($result->numRows > 0) {
            $this->load($result->fields['userid']);
            if ($setSessionApi) {
                $tokenObj = new Token($result->fields);
                $_SESSION['usertoken'] = $tokenObj;
            }

            return $this;
        }


    }


    /**
     * Get user data as array
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
        foreach ($this->otherinfo as $key=>$value) {
            if (!isset($data[$key])) {
                if (is_numeric($value) || is_string($value)) {
                    $data[$key] = $value;
                }
            }
        }
        unset($data['_isnew']);
        unset($data['_userdetailstable']);
        unset($data['_userstable']);

        unset($data['_messages']);
        unset($data['_messages']);
        unset($data['_messages']);

        ksort($data);
        return $data;
    }

    /**
     * Get data usage statistics
     * 
     * @return array
     */
    public function getDataUsageStats(): array
    {
        if ($this->userid < 2) {
            return [
                'total_tokens' => 0,
                'unique_apps' => 0,
                'active_days' => 0,
                'account_created' => null
            ];
        }
        $database = \Pramnos\Framework\Factory::getDatabase();

        // Get token count
        $sql = $database->prepareQuery("
            SELECT COUNT(*) as token_count
            FROM usertokens
            WHERE userid = %d
        ", $this->userid);
        $tokenResult = $database->query($sql);
        $tokenCount = $tokenResult->fields['token_count'] ?? 0;

        // Get app count
        $sql = $database->prepareQuery("
            SELECT COUNT(DISTINCT applicationid) as app_count
            FROM usertokens
            WHERE userid = %d
        ", $this->userid);
        try {
            $appResult = $database->query($sql);
            $appCount = $appResult->fields['app_count'] ?? 0;
        } catch (\Exception $e) {
            $appCount = 0;
        }
        

        return [
            'total_tokens' => $tokenCount,
            'unique_apps' => $appCount,
            'active_days' => 0, // Can be calculated if needed
            'account_created' => $this->regdate
        ];
    }


    /**
     * Get active sessions (simplified - would need session table)
     * 
     * @return array
     */
    public function getActiveSessions(): array
    {
        // This would need a proper session table implementation
        // For now, return basic info
        return [
            [
                'session_id' => session_id(),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'last_activity' => date('Y-m-d H:i:s'),
                'is_current' => true
            ]
        ];
    }
    
    /**
     * Verify user password
     * 
     * @param string $password
     * @return bool
     */
    public function verifyPassword(string $password): bool
    {
        if ($this->userid < 2) {
            return false;
        }
        $pwd = $password
            . md5(
            \Pramnos\Application\Settings::getSetting('securitySalt')
            . $this->userid
        );
        if (password_verify($pwd, $this->password)) {
            return true;
        } elseif (md5($password) == $this->password) {
            return true;
        } 

        return false;
    }

}
