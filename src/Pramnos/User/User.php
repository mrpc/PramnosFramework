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
    public $userid = 1;
    public $username = "Anonymous";
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

    function __construct($userid = 0)
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
            $sql = $database->prepare(
                "delete from `#PREFIX#users` "
                . "where `userid` = %d limit 1", $this->userid
            );
            $database->Execute($sql);
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
            $sql = $database->prepare(
                "update `#PREFIX#users`"
                . " set `active` = 1 where `userid` = %d", $this->userid
            );
            $database->Execute($sql);
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
            $sql = $database->prepare(
                "update `#PREFIX#users` "
                . "set `active` = 0 where `userid` = %d", $this->userid
            );
            $database->Execute($sql);
        }
        else {
            $this->active = 0;
        }
    }

    /**
     * Returns an array with all users (altered by the $where filter)
     * @param string $where
     * @return User[]
     */
    static function getUsers($where = '')
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepare("select `userid` from `#PREFIX#users`");
        if ($where != '') {
            $sql .= ' where ' . $database->prepareInput($where);
        }
        $users = $database->Execute($sql, 1, 10, 'userlist');
        $return = array();
        while (!$users->eof) {
            $theuser = new User($users->fields['userid']);
            $theuser->userid = $users->fields['userid'];
            $theuser->load($users->fields['userid']);
            $return[$users->fields['userid']] = $theuser;
            unset($theuser);
            $users->MoveNext();
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
        $this->password = md5($password);
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
        $sql = $database->prepare(
            "select * from `"
            . DB_USERGROUPSUBSCRIPTIONS
            . " `where `userid` = %d", $this->userid
        );
        try {
            $result = $database->Execute($sql, true, 60);
        }
        catch (Exception $exc) {
            pramnos_logs::log($exc->getMessage());
            return array();
        }
        $return = array();
        $return[$this->maingroup] = new pramnos_user_group($this->maingroup);
        while (!$result->eof) {
            if (!isset($return[$result->fields['group_id']])) {
                $return[$result->fields['group_id']] = new pramnos_user_group(
                    $result->fields['group_id']
                );
            }
            $result->MoveNext();
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
            throw new Exception(
                'Invalid username or email address. Username: '
                . $this->username
                . '. Email address: '
                . $this->email
            );
        }
        $database = \Pramnos\Framework\Factory::getDatabase();
        $itemdata = array(
            array('fieldName' => 'username',
                'value' => $this->username, 'type' => 'string'),
            array('fieldName' => 'password',
                'value' => $this->password, 'type' => 'string'),
            array('fieldName' => 'email',
                'value' => $this->email, 'type' => 'string'),
            array('fieldName' => 'regdate',
                'value' => $this->regdate, 'type' => 'integer'),
            array('fieldName' => 'lastlogin',
                'value' => $this->lastlogin, 'type' => 'integer'),
            array('fieldName' => 'active',
                'value' => $this->active, 'type' => 'integer'),
            array('fieldName' => 'validated',
                'value' => $this->validated, 'type' => 'integer'),
            array('fieldName' => 'language',
                'value' => $this->language, 'type' => 'string'),
            array('fieldName' => 'timezone',
                'value' => $this->timezone, 'type' => 'string'),
            array('fieldName' => 'dateformat',
                'value' => $this->dateformat, 'type' => 'string')
        );

        if ($groupSupport == true) {
            $itemdata[] = array(
                'fieldName' => 'maingroup',
                'value' => $this->maingroup,
                'type' => 'integer'
            );
        }

        $itemdata=$this->_alterFields($itemdata);
        if ($this->_isnew === 1 || $this->userid == 1) {
            $this->_isnew = 0;
            if ($this->userid != 1) {
                $itemdata[] = array(
                    'fieldName' => 'userid',
                    'value' => $this->userid,
                    'type' => 'integer');
            }
            if (!$database->perform(
                $database->prefix . "users", $itemdata, 'insert', '', $debug
            )) {
                $error = $database->sql_error();
                $this->addError($error['message']);
                return $this;
            }
            $this->userid = $database->getInsertId();
        } else {
            if (!$database->perform(
                $database->prefix . "users", $itemdata, 'update',
                "`userid` = " . $this->userid, $debug
            )) {
                $error = $database->sql_error();
                $this->addError($error['message']);
                return $this;
            }
        }

        foreach (array_keys($this->otherinfo) as $fieldname) {
            $fixname = substr($fieldname, 3);
            if ($this->$fieldname === NULL) {
                $sql = $database->prepare(
                    "DELETE FROM `#PREFIX#userdetails` "
                    . "where `userid` = %d and `fieldname` = %s "
                    . "limit 1", $this->userid, $fieldname
                );
            } elseif (is_object($this->$fieldname)
                || is_array($this->$fieldname)) {

                if ($fixname != 'originalOtherinfo'
                    && substr($fixname, 0, 1) != '_'
                    && substr($fieldname, 0, 1) != '_') {
                    $sql = $database->prepare(
                        "insert into `#PREFIX#userdetails` "
                        . " (`userid`, `fieldname`, `value`) "
                        . " values (%d, %s, %s) "
                        . " ON DUPLICATE KEY UPDATE `value` = %s ",
                        $this->userid, $fieldname,
                        serialize($this->$fieldname),
                        serialize($this->$fieldname)
                    );
                }

            } elseif (!isset($this->originalOtherinfo[$fieldname])
                || $this->originalOtherinfo[$fieldname] != $this->$fieldname
                && substr($fixname, 0, 1) != '_'
                && substr($fieldname, 0, 1) != '_') {
                $sql = $database->prepare(
                    "insert into `#PREFIX#userdetails` "
                    . " (`userid`, `fieldname`, `value`) "
                    . " values (%d, %s, %s) "
                    . " ON DUPLICATE KEY UPDATE `value` = %s ",
                    $this->userid, $fieldname,
                    $this->$fieldname, $this->$fieldname
                );
            }

            try {
                if (isset($sql)) {
                    $database->Execute($sql);
                    unset($sql);
                }
            } catch (Exception $ex) {
                $error = $database->sql_error();
                $this->addError($error['message']);
                \Pramnos\Logs\Logs::log($ex->getMessage());
            }

        }
        $database->sql_cache_flush_cache('userlist');
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
        $sql = $database->prepare(
            "SELECT * FROM #PREFIX#users WHERE `userid` = %d LIMIT 1", $uid
        );
        $result = $database->execute($sql, 1, 10, 'userlist');
        if ($result->numRows == 0) {
            return false;
        }
        $this->_isnew = false;
        foreach (array_keys($result->fields) as $key) {
            $this->$key = $result->fields[$key];
        }


        $sql = $database->prepare(
            "SELECT * FROM #PREFIX#userdetails WHERE `userid` = %d", $uid
        );
        $result = $database->execute($sql);
        while (!$result->eof) { //This should load all special settings
            $fixname = substr($result->fields['fieldname'], 3);
            if ($fixname != 'originalOtherinfo'
                && substr($fixname, 0, 1) != '_'
                && substr($result->fields['fieldname'], 0, 1) != '_') {
                $this->otherinfo[$result->fields['fieldname']]
                    = $result->fields['value'];
            }
            $result->MoveNext();
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
        $sql = $database->prepare(
            "select `userid` from `#PREFIX#userdetails` "
            . "where `fieldname` = %s and `value` = %s", $param, $value
        );
        $result = $database->Execute($sql);
        $return = array();
        while (!$result->eof) {
            $return[] = $result->fields['userid'];
            $result->MoveNext();
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
        $config['prefix'] = $database->prefix;
        $return = "";
        $sql = "SELECT `userid` FROM `"
            . $config['prefix']
            . "users` WHERE `$by` = '$username' limit 1";
        $result = $database->query($sql);
        $result2 = $database->fetchRow($result);
        if ($database->getNumRows($result) == 1) {
            return $result2['userid'];
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
        if ($database->getNumRows($result) == 1) {
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
    static function getfriends($userid)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $return = array();
        $sql = "select * from `" . $database->prefix . "userfriends` "
                . "where `confirm` = 1 "
                . "and (`from_userid` = '$userid' or `to_userid`='$userid')";
        $result = $database->query($sql);
        while ($row = $database->fetchRow($result)) {
            if ($row['from_userid'] == $userid) {
                $return[] = $row['to_userid'];
            }
            else {
                $return[] = $row['from_userid'];
            }
        }
        return $return;
    }

    function getFeed($limit = 10)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $friends = array();
        $sql = $database->prepare(
            "select * "
            . "from `#PREFIX#userfriends` "
            . "where `confirm` = 1 "
            . "and (`from_userid` = %d or `to_userid`=%d)", $this->userid,
            $this->userid
        );
        $result = $database->query($sql);
        while ($row = $database->fetchRow($result)) {
            if ($row['from_userid'] == $this->userid) {
                $friends[] = $row['to_userid'];
            } else {
                $friends[] = $row['from_userid'];
            }
        }
        $in = '0';
        foreach ($friends as $friendid) {
            $in .= ', ' . $friendid;
        }
        $sql = $database->prepare(
                "select * from `#PREFIX#feed` "
                . "where `userid` in (" . $in . ") "
                . "and itemprivacy=0 "
                . "order by `date` desc limit " . $limit
        );
        $result = $database->Execute($sql);
        $return = array();
        while (!$result->eof) {
            if (trim($result->fields['itemtext']) != '') {
                $return[$result->fields['itemid']] = array(
                    'date' => $result->fields['date'],
                    'itemtext' => $result->fields['itemtext'],
                    'user' => new pramnoscms_user($result->fields['userid'])
                );
            }
            $result->MoveNext();
        }
        return $return;
    }

    public function addFeed($text, $privacy = 0)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $sql = $database->prepare(
            "insert into `#PREFIX#feed` "
            . "(`date`, `userid`, `usertype`, `itemprivacy`, `itemtext`) "
            . "values "
            . "(%d, %d, %d, %d, %s)", time(), $this->userid, 0, $privacy,
            $text
        );
        $database->Execute($sql);
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
     * @return \getsynched_user|boolean
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
            $app->currentUser = new User($_SESSION['uid']);
            return $app->currentUser;
        }

        return false;
    }

}
