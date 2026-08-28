<?php
namespace Pramnos\User;
/**
 * User class
 * Dynamic loading of new user information  :-)
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class User extends \Pramnos\Framework\Base implements \Pramnos\Application\ApiList\ApiListSource
{

    private $_userstable = null;
    private $_userdetailstable = null;
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
    public $usertype = 0;
    /**
     * User gender. 0: Unknown 1: Male 2: Female
     * @var int
     */
    public $sex = 0;

    public $photo = null;

    public $phone = '';
    public $mobile = '';
    public $fax = '';
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
    public $avatarurl = '';
    public $otherinfo = array();
    protected $originalOtherinfo = array();
    protected $_isnew = 0;
    protected static $_usercache = NULL;
    protected static $usersCache = array();
    /** @var string|null Plaintext held between setPassword() and first INSERT so _save() can rehash with the real userid. */
    private ?string $_pendingPlainPassword = null;

    public function __construct($userid = 0)
    {
        if ($userid === 0) {
            $this->_isnew = 1;
        }
        else {
            return $this->load($userid);
        }
        if ($this->_userstable === null) {
            $this->_userstable = self::usersTable();
        }
        if ($this->_userdetailstable === null) {
            $this->_userdetailstable = self::userDetailsTable();
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
            $database->queryBuilder()
                ->table(self::usersTable())
                ->where('userid', $this->userid)
                ->delete();
            $this->_isnew = 1;
            $database->cacheflush('userlist');
            if (is_array(self::$_usercache) && isset(self::$_usercache[$this->userid])) {
                unset(self::$_usercache[$this->userid]);
            }
            if (is_array(self::$usersCache) && isset(self::$usersCache[$this->userid])) {
                unset(self::$usersCache[$this->userid]);
            }
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
            $database->queryBuilder()
                ->table(self::usersTable())
                ->where('userid', $this->userid)
                ->update(['active' => 1]);
            $database->cacheflush('userlist');
            if (is_array(self::$_usercache) && isset(self::$_usercache[$this->userid])) {
                unset(self::$_usercache[$this->userid]);
            }
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
            $database->queryBuilder()
                ->table(self::usersTable())
                ->where('userid', $this->userid)
                ->update(['active' => 0]);
            $database->cacheflush('userlist');
            if (is_array(self::$_usercache) && isset(self::$_usercache[$this->userid])) {
                unset(self::$_usercache[$this->userid]);
            }
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

        // `currentInstance()`: `getInstance()` is a factory, and deciding which class name
        // to instantiate is not a reason to build an application, a database connection and
        // a session. With no application the `isset()` below is simply false and the
        // framework's own User class is used, which is the right answer — an application
        // that has not been created cannot have declared an override.
        $app = \Pramnos\Application\Application::currentInstance();

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
        $qb = $database->queryBuilder()->table(self::usersTable())->select('userid');
        if ($where != '') {
            // Backward-compatible raw where string: sanitised via prepareInput
            // before being passed to whereRaw to preserve legacy call sites.
            $qb->whereRaw($database->prepareInput($where));
        }
        $users = $qb->get(true, 10, 'userlist');
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
     * Validate user credentials and return user info.
     *
     * @param string $username
     * @param string $password
     * @return array{userid: int, username: string, email: string}|false
     */
    public static function validateUserCredentials(string $username, string $password)
    {
        $auth = \Pramnos\Framework\Factory::getAuth();
        $response = $auth->verifyCredentials($username, $password);

        if ($response === false) {
            return false;
        }

        return [
            'userid'   => (int) ($response['uid'] ?? 0),
            'username' => (string) ($response['username'] ?? ''),
            'email'    => (string) ($response['email'] ?? ''),
        ];
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
        } else {
            if (strpos($name, 'getinfo_') === 0) {
                $setName = 'setinfo_' . substr($name, 8);
                if (isset($this->otherinfo[$setName])) {
                    return $this->otherinfo[$setName];
                }
            }
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
     * Is a non-standard field set?
     *
     * On `$otherinfo`, which is where {@see __get()} and {@see __set()} keep it. The
     * inherited pair from {@see \Pramnos\Framework\Base} answered from `$_data`, a
     * store this class never writes — so `isset($user->anything)` was `false` for every
     * field `__get()` would have returned.
     *
     * **The consequence was not an inaccurate `isset()`.** `??` asks `__isset()` first
     * and calls `__get()` only when the class declares no `__isset()`. So
     * `$user->preference ?? ''` returned `''` for a value that was in the object and in
     * the database all along — no error, no warning. It was found by an unrelated test
     * on a project reading every notification preference that way.
     *
     * @param string $name
     */
    public function __isset($name)
    {
        if (isset($this->otherinfo[$name])) {
            return true;
        }

        // The same `getinfo_` → `setinfo_` alias `__get()` resolves, so the pair
        // agrees about what exists.
        if (strpos($name, 'getinfo_') === 0) {
            return isset($this->otherinfo['setinfo_' . substr($name, 8)]);
        }

        return false;
    }

    /**
     * Unset a non-standard field, from the store the other three use.
     *
     * @param string $name
     */
    public function __unset($name)
    {
        unset($this->otherinfo[$name]);

        if (strpos($name, 'getinfo_') === 0) {
            unset($this->otherinfo['setinfo_' . substr($name, 8)]);
        }
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
            // The scheme lives in PasswordHash, not here: this method used to compose the
            // peppered input itself, which is why the pepper's 72-byte problem could not
            // be fixed in one place. See PasswordHash::make().
            $this->password = \Pramnos\Auth\PasswordHash::make($password, (int) $this->userid);
            $this->_pendingPlainPassword = null;
        } else {
            // userid not yet assigned — store MD5 as placeholder and keep the
            // plaintext so _save() can rehash with the real userid after INSERT.
            $this->password = md5($password);
            $this->_pendingPlainPassword = $password;
        }
    }

    /**
     * Returns an array with all groups the user is subscribed to
     * @return \stdClass
     */
    public function getGroups()
    {
        if (DB_USERGROUPSUBSCRIPTIONS == false) {
            return array();
        }
        $database = \Pramnos\Framework\Factory::getDatabase();
        // DB_USERGROUPSUBSCRIPTIONS is a fully-qualified table name supplied by
        // the application. Use a raw FROM so the QB does not double-prefix it.
        try {
            $result = $database->queryBuilder()
                ->from(DB_USERGROUPSUBSCRIPTIONS)
                ->where('userid', $this->userid)
                ->get(true, 60);
        }
        catch (\Exception $exc) {
            \Pramnos\Logs\Logger::log($exc->getMessage());
            return array();
        }
        $return = array();
        $maingroup = new \stdClass();
        $maingroup->group_id = $this->maingroup;
        $return[$this->maingroup] = $maingroup;

        while ($result->fetch()) {
            $groupId = isset($result->fields['groupid']) ? $result->fields['groupid'] : (isset($result->fields['group_id']) ? $result->fields['group_id'] : null);
            if ($groupId !== null && !isset($return[$groupId])) {
                $obj = new \stdClass();
                $obj->group_id = $groupId;
                $return[$groupId] = $obj;
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
                'fieldName' => 'mobile',
                'value' => $this->mobile,
                'type' => 'string'
            ),
            array(
                'fieldName' => 'fax',
                'value' => $this->fax,
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
                'type' => 'integer'
            )
        );
        if ($database->type !=  'postgresql'){
            $itemdata[] = array(
                'fieldName' => 'active',
                'value' => $this->active,
                'type' => 'integer'
            );
        }

        /*
        if ($groupSupport == true) {
            $itemdata[] = array(
                'fieldName' => 'maingroup',
                'value' => $this->maingroup,
                'type' => 'integer'
            );
        }
        */

        $itemdata = $this->_alterFields($itemdata);
        // _isnew is the authoritative flag for INSERT vs UPDATE.
        // The old "|| $this->userid == 1" condition was wrong: after the first
        // INSERT, _isnew is set to 0 and userid becomes 1 (first auto-ID), so
        // the condition would fire again on every subsequent save of user 1,
        // inserting a duplicate row instead of updating the existing one.
        if ($this->_isnew === 1) {
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
                $this->userid = $dbresult->fields['userid'];
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

            // Rehash the password with the real userid now that it is known.
            // setPassword() stored MD5 as a placeholder when userid was <= 1.
            if ($this->_pendingPlainPassword !== null && $this->userid > 1) {
                $plain = $this->_pendingPlainPassword;
                $this->_pendingPlainPassword = null;
                $this->setPassword($plain);
                $database->updateTableData(
                    $database->prefix . "users",
                    [['fieldName' => 'password', 'value' => $this->password, 'type' => 'string']],
                    "`userid` = " . (int) $this->userid
                );
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
                try {
                    $database->queryBuilder()
                        ->table(self::userDetailsTable())
                        ->where('userid', $this->userid)
                        ->where('fieldname', $fieldname)
                        ->delete();
                } catch (\Exception $ex) {
                    \Pramnos\Logs\Logger::log($ex->getMessage());
                }
            } elseif (is_object($this->$fieldname)
                || is_array($this->$fieldname)) {

                if ($fixname != 'originalOtherinfo'
                    && substr($fixname, 0, 1) != '_'
                    && substr($fieldname, 0, 1) != '_') {

                    $upsertData = [
                        ['fieldName' => 'userid', 'value' => $this->userid, 'type' => 'integer'],
                        ['fieldName' => 'fieldname', 'value' => $fieldname, 'type' => 'string'],
                        ['fieldName' => 'value', 'value' => serialize($this->$fieldname), 'type' => 'string']
                    ];
                    $database->upsert(self::userDetailsTable(), $upsertData, ['userid', 'fieldname']);
                }

            } elseif (!isset($this->originalOtherinfo[$fieldname])
                || $this->originalOtherinfo[$fieldname] != $this->$fieldname
                && substr($fixname, 0, 1) != '_'
                && substr($fieldname, 0, 1) != '_') {
                
                $upsertData = [
                    ['fieldName' => 'userid', 'value' => $this->userid, 'type' => 'integer'],
                    ['fieldName' => 'fieldname', 'value' => $fieldname, 'type' => 'string'],
                    ['fieldName' => 'value', 'value' => $this->$fieldname, 'type' => 'string']
                ];
                $database->upsert(self::userDetailsTable(), $upsertData, ['userid', 'fieldname']);
            }
        }
        $database->cacheflush('userlist');
        if (is_array(self::$_usercache) && isset(self::$_usercache[$this->userid])) {
            unset(self::$_usercache[$this->userid]);
        }
        if (is_array(self::$usersCache) && isset(self::$usersCache[$this->userid])) {
            unset(self::$usersCache[$this->userid]);
        }
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

        /**
         * `null` is a normal argument, not a caller's mistake.
         *
         * `new User($record->userid)` on a record that did not load passes `null`, and
         * the next line is usually a `userid < 2` check and a redirect. It reached this
         * method as an array offset — *Using null as an array offset is deprecated* on
         * PHP 8.1+, twice per call, on a path that was about to be handled correctly.
         *
         * Refused rather than coerced: `0` already means "load whoever is in the
         * session", which is a different question, and no user has a null id.
         */
        if ($uid === null || $uid === '') {
            return false;
        }

        if (is_array(self::$_usercache) && isset(self::$_usercache[$uid])) {
            foreach (self::$_usercache[$uid] as $key => $value) {
                $this->$key = $value;
            }
            return $this;
        }
        $database = \Pramnos\Framework\Factory::getDatabase();
        $result = $database->queryBuilder()
            ->table(self::usersTable())
            ->where('userid', $uid)
            ->get(true, 10, 'userlist');
        if ($result === false || $result === null || $result->numRows == 0) {
            return false;
        }
        $this->_isnew = false;
        foreach (array_keys($result->fields) as $key) {
            $this->$key = $result->fields[$key];
        }


        // Cached alongside the users row, in the same category and for the same
        // ten seconds. They are read together for the same user on every
        // request that loads one, and caching one but not the other meant every
        // such request still paid a round trip — the saving was halved for no
        // reason. The category is flushed wherever a user is written, so the
        // two cannot disagree.
        $result = $database->queryBuilder()
            ->table(self::userDetailsTable())
            ->where('userid', $uid)
            ->get(true, 10, 'userlist');
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
            // Until 2026-08-14 this was `sURL . 'media/img/pramnoscms/noavatar.jpg'` — a path
            // into a deprecated CMS's asset folder for a file the framework has never
            // shipped. Every user without an avatar got a URL that 404s.
            //
            // The framework cannot supply a default image it does not have, so the fallback
            // is configuration: set `defaultAvatarUrl` in the application settings to a file
            // that exists. Left unset, this stays empty and the template decides — which is
            // the only honest answer, and lets a view render initials or an inline SVG
            // instead of an image.
            $this->avatarurl = (string) \Pramnos\Application\Settings::getSetting(
                'defaultAvatarUrl'
            );
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
        $result = $database->queryBuilder()
            ->table(self::userDetailsTable())
            ->select('userid')
            ->where('fieldname', $param)
            ->where('value', $value)
            ->get();
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
        $result = $database->queryBuilder()
            ->table(self::usersTable())
            ->select('userid')
            ->where($by, $username)
            ->limit(1)
            ->get();
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
        $database->queryBuilder()
            ->table(self::userFriendsTable())
            ->insert([
                'from_userid' => (int) $usera,
                'to_userid'   => (int) $userb,
                'confirm'     => 1,
            ]);
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
        $usera = (int) $usera;
        $userb = (int) $userb;
        $database->queryBuilder()
            ->table(self::userFriendsTable())
            ->where(function ($q) use ($usera, $userb) {
                $q->where(function ($q2) use ($usera, $userb) {
                    $q2->where('from_userid', $usera)
                       ->where('to_userid', $userb);
                })->orWhere(function ($q2) use ($usera, $userb) {
                    $q2->where('from_userid', $userb)
                       ->where('to_userid', $usera);
                });
            })
            ->delete();
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
        $usera = (int) $usera;
        $userb = (int) $userb;
        $result = $database->queryBuilder()
            ->table(self::userFriendsTable())
            ->where('confirm', 1)
            ->where(function ($q) use ($usera, $userb) {
                $q->where(function ($q2) use ($usera, $userb) {
                    $q2->where('from_userid', $usera)
                       ->where('to_userid', $userb);
                })->orWhere(function ($q2) use ($usera, $userb) {
                    $q2->where('from_userid', $userb)
                       ->where('to_userid', $usera);
                });
            })
            ->get();
        return $result->numRows == 1;
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
        $userid = (int) $userid;
        $return = array();
        $result = $database->queryBuilder()
            ->table(self::userFriendsTable())
            ->where('confirm', 1)
            ->where(function ($q) use ($userid) {
                $q->where('from_userid', $userid)
                  ->orWhere('to_userid', $userid);
            })
            ->get();
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
            . "from `" . self::userFriendsTable() . "` "
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
            if (trim($finalResult->fields['itemtext']) != '') {
                $return[$finalResult->fields['itemid']] = array(
                    'date' => $finalResult->fields['date'],
                    'itemtext' => $finalResult->fields['itemtext'],
                    'user' => new User($finalResult->fields['userid'])
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
     * The users table, honouring `DB_USERSTABLE`.
     *
     * Static because two of this class's queries are — `getUsers()` and
     * `getuserid()` — and ten queries in here wrote the bare literal `'users'`
     * while six lines referenced the resolved name. `QueryBuilder::table()`
     * substitutes `#PREFIX#` and leaves a bare name alone, so on **every**
     * installation with a prefix those ten hit a table that does not exist. It is
     * not an edge case: three of them are in the constructor, so simply
     * constructing a user failed. A consuming application's suite reported 97
     * failures, all `Table '….users' doesn't exist`.
     *
     * The default is `#PREFIX#users`, which is also what the framework defines
     * `DB_USERSTABLE` as — so the bare literal only ever worked on an
     * installation whose prefix was empty.
     */
    private static function usersTable(): string
    {
        return defined('DB_USERSTABLE') ? DB_USERSTABLE : '#PREFIX#users';
    }

    /**
     * The user-details table, honouring `DB_USERDETAILSTABLE`.
     *
     * Same shape as {@see usersTable()} and the same defect: the class computed
     * the configured name into a property and then five queries wrote
     * `'#PREFIX#userdetails'` as a literal. That is right on a default
     * installation and wrong on any that set the constant — which is what the
     * constant is for.
     */
    private static function userDetailsTable(): string
    {
        return defined('DB_USERDETAILSTABLE')
            ? DB_USERDETAILSTABLE
            : '#PREFIX#userdetails';
    }

    /**
     * The user-friends table, honouring `DB_USERFRIENDSTABLE`.
     *
     * Same shape as {@see usersTable()}, and the four friend methods had the defect the
     * other queries had: a **bare** `userfriends`. `QueryBuilder::table()` substitutes
     * `#PREFIX#` and leaves a bare name as written, so on any installation with a table
     * prefix — which is every installation the scaffolder produces — all four addressed
     * a table that does not exist.
     */
    private static function userFriendsTable(): string
    {
        return defined('DB_USERFRIENDSTABLE')
            ? DB_USERFRIENDSTABLE
            : '#PREFIX#userfriends';
    }

    /**
     * The per-user settings table, honouring `DB_USERSETTINGSTABLE`.
     */
    private static function userSettingsTable(): string
    {
        return defined('DB_USERSETTINGSTABLE')
            ? DB_USERSETTINGSTABLE
            : '#PREFIX#usersettings';
    }

    /**
     * One setting for this user, decoded.
     *
     * The framework had two places to keep something about a user and neither fits an
     * operator-visible switch. `users` columns are the schema every application shares,
     * so an application cannot add to them; `$otherinfo` is a blob, which has no list, no
     * per-key delete and nothing an administrator can read. This is the third place, and
     * it is the one with a screen.
     *
     * Values round-trip through JSON, so a list stays a list and a number stays a number.
     * A value that is not valid JSON is returned as the raw string — a row written by
     * hand in a database client is still worth reading.
     *
     * @param  string $setting
     * @param  mixed  $default Returned when the user has no such setting
     * @return mixed
     */
    public function getSetting(string $setting, $default = null)
    {
        if ((int) $this->userid < 2 || $setting === '') {
            return $default;
        }

        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::userSettingsTable())
                ->where('userid', (int) $this->userid)
                ->where('setting', $setting)
                ->first();
        } catch (\Throwable) {
            // No table yet: a project that has not migrated has no settings, which is
            // the same answer as a user with none.
            return $default;
        }

        if (!$result || $result->numRows === 0) {
            return $default;
        }

        $raw = $result->fields['value'] ?? null;
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    /**
     * Write one setting, creating or replacing it.
     *
     * Upserted on `(userid, setting)` rather than checked-then-written: two requests
     * saving the same switch would otherwise race into two rows, and "the value" would
     * stop having an answer.
     *
     * @param  string $setting
     * @param  mixed  $value Anything `json_encode()` accepts; `null` stores a null
     * @param  int|null $by  Who is writing it — a userid, or null for the application
     * @return bool Whether it was written
     */
    public function setSetting(string $setting, $value, ?int $by = null): bool
    {
        if ((int) $this->userid < 2 || $setting === '') {
            return false;
        }

        $row = [
            'userid'     => (int) $this->userid,
            'setting'    => $setting,
            'value'      => $value === null ? null : json_encode($value),
            'updated_at' => time(),
            'updated_by' => $by,
        ];

        try {
            $qb = \Pramnos\Framework\Factory::getDatabase()->queryBuilder();
            $existing = $qb->table(self::userSettingsTable())
                ->where('userid', (int) $this->userid)
                ->where('setting', $setting)
                ->first();

            if ($existing && $existing->numRows > 0) {
                \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                    ->table(self::userSettingsTable())
                    ->where('userid', (int) $this->userid)
                    ->where('setting', $setting)
                    ->update($row);

                return true;
            }

            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::userSettingsTable())
                ->insert($row);

            return true;
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::log(
                'User::setSetting failed for ' . $setting . ': ' . $ex->getMessage()
            );

            return false;
        }
    }

    /**
     * Remove one setting.
     *
     * Deleted rather than set to null, because the two mean different things: a null
     * value is a switch somebody turned off, and no row is a switch nobody has touched —
     * which is what makes the application's own default apply again.
     */
    public function deleteSetting(string $setting): bool
    {
        if ((int) $this->userid < 2 || $setting === '') {
            return false;
        }

        try {
            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::userSettingsTable())
                ->where('userid', (int) $this->userid)
                ->where('setting', $setting)
                ->delete();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every setting on this user, decoded, ordered by name.
     *
     * The list an administration screen shows. Ordered by name because the screen is
     * read by a person looking for one switch, and insertion order tells them nothing.
     *
     * @return array<int, array{setting: string, value: mixed, updated_at: int|null, updated_by: int|null}>
     */
    public function listSettings(): array
    {
        if ((int) $this->userid < 2) {
            return [];
        }

        try {
            $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::userSettingsTable())
                ->where('userid', (int) $this->userid)
                ->orderBy('setting')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        if (!$result) {
            return [];
        }

        $settings = [];
        foreach ((array) $result->fetchAll() as $row) {
            $raw     = $row['value'] ?? null;
            $decoded = $raw === null ? null : json_decode((string) $raw, true);

            $settings[] = [
                'setting'    => (string) ($row['setting'] ?? ''),
                'value'      => $raw !== null && json_last_error() === JSON_ERROR_NONE ? $decoded : $raw,
                'updated_at' => isset($row['updated_at']) ? (int) $row['updated_at'] : null,
                'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            ];
        }

        return $settings;
    }

    /**
     * Returns an array with the database tables this class uses
     * @return array
     */
    public function getTableNames()
    {
        return array(
            // Resolved rather than read off the property: the constructor returns
            // early when it is given a user id — `return $this->load($userid)` —
            // so on that path the property was never assigned and this reported
            // `null` for the table every other method was using.
            'users' => $this->_userstable ?? self::usersTable(),
            'userdetails' => $this->_userdetailstable ?? self::userDetailsTable(),
            'usersettings' => self::userSettingsTable(),
            'userfriends' => self::userFriendsTable()
        );
    }

    /**
     * Return the current logged user
     *
     * Two sources, and the order matters.
     *
     * **A sealed request identity comes first, and alone.** An API request
     * authenticates with a token and nothing else — it must not read a session,
     * and it must not write one. {@see \Pramnos\Http\RequestIdentity} is how a
     * middleware says "this call is user X, or nobody", and a sealed answer
     * stops the search: a browser's login cookie never speaks for an API call,
     * which is what makes `logout` work at all.
     *
     * **Then the session**, unchanged — how a server-rendered page has always
     * known who it is serving. Nothing that does not seal an identity sees any
     * difference, which is deliberate: the sealing is opt-in by the middleware
     * that knows it is serving an API, and everything else keeps its behaviour.
     *
     * **This is a read.** It writes nothing, and that is a promise the method
     * did not keep until 2026-08-23: it used to refresh `users.language` from
     * the interface language and save() the user. See the comment on the cached
     * branch below for what that cost. If you need the stored language changed,
     * change it where the language is chosen.
     *
     * @return User|boolean
     */
    public static function getCurrentUser()
    {
        // An API request settles its own identity and forbids anything else
        // from answering — including the session cookie the same browser is
        // carrying for the website. Without this the two contaminate each other
        // in both directions: a website login authenticates API calls that
        // presented no credential (so `logout` cannot work, because revoking the
        // token leaves the cookie answering), and an API call's user becomes the
        // browser's user on the next page.
        if (\Pramnos\Http\RequestIdentity::isSealed()) {
            $user = \Pramnos\Http\RequestIdentity::user();

            return is_object($user) && (int) ($user->userid ?? 0) > 1 ? $user : false;
        }

        // Otherwise the session, exactly as before — that is how a
        // server-rendered page knows who it is serving, and nothing about it
        // changes here.
        if (\Pramnos\Http\Session::staticIsLogged() == true) {
            // The worst placement of the factory in the framework: *inside the identity
            // lookup*. Asking "who is signed in" would construct an entire application —
            // database, language, session — which is the exact shape of the incident named
            // in `currentInstance()`'s docblock, where a CSRF check that booted an
            // application made a reference application's login tests fail on valid tokens.
            //
            // The `$app &&` below has been here all along, describing a null the factory
            // could not return.
            $app = \Pramnos\Application\Application::currentInstance();
            if ($app && is_object($app->currentUser)) {
                // Returned as it is. This branch used to compare
                // `users.language` with the interface language and, when they
                // differed, overwrite the column and save() — an UPDATE, from a
                // method whose name promises a read.
                //
                // It was reachable through ordinary use, not through some edge:
                // the first call in a request loads the user and caches it here,
                // so every call after it took this branch, and a page that asks
                // who is signed in from both the theme header and its controller
                // asks twice as a matter of course.
                //
                // Two things came of it. `users.language` reads as the user's
                // *preference*, and this treated it as a cache of the interface
                // language for whoever looked at them last — so an operator who
                // chose English in a bilingual admin panel had that choice
                // reverted by opening the Greek-rendered site, with nothing
                // saying so. It bit precisely the accounts that had used the
                // feature, which is the population most likely to be testing it.
                // And on an account with no email address — ordinary for one an
                // admin created — the save could raise from _save()'s address
                // validation, ending a request over a column nobody asked about.
                //
                // Nothing else in the framework writes users.language, so it is
                // now only ever what an application put there. An application
                // that does want the two kept in step should write the column
                // where it sets the interface language, once, where a caller can
                // see it happening.
                return $app->currentUser;
            }
             // Try to find an override user class
            if (!isset($_SESSION['uid'])) {
                return false;
            }
            if ($app && isset($app->applicationInfo['namespace'])
                && $app->applicationInfo['namespace'] != ''
                && class_exists(
                    '\\'
                    . $app->applicationInfo['namespace']
                    . '\\User'
                )) {
                $className = '\\'
                    . $app->applicationInfo['namespace']
                    . '\\User';
                $user = new $className($_SESSION['uid']);
            } else {
                $user = new User($_SESSION['uid']);
            }
            if ($app) {
                $app->currentUser = $user;
            }
            return $user;
        }

        return false;
    }

    /**
     * Add a token to the database
     * @param string $tokentype
     * @param string $token
     * @param string $notes
     * @param int|null $parentToken
     * @param int|null $expires Absolute expiry as a UNIX timestamp. null (the
     *                          default) means the token never expires — preserves
     *                          the historical behaviour. loadByToken() treats a
     *                          NULL/0 expires as non-expiring.
     * @return $this
     */
    /**
     * What the `usertokens.deviceinfo` column has always said it holds.
     *
     * The column is declared as *"JSON-encoded device/client information (browser, OS,
     * IP at token creation)"* and was written as `''` at every call site — so the
     * active-sessions list, which exists so somebody can recognise a session they do
     * not remember, showed nothing to recognise it by. `Token` has decoded this field
     * for years; there was simply never anything in it.
     *
     * Three keys, and the choice of three is the point:
     *
     * - **`device`** — the coarse {@see \Pramnos\Auth\SignInFingerprint}, so two
     *   sessions from the same browser compare equal across browser updates. Storing
     *   the raw user agent instead would make every session look distinct after any
     *   update, which is the failure the fingerprint exists to avoid.
     * - **`label`** — the same thing in words, because `chrome|windows` is not what a
     *   person scanning their sessions needs to read.
     * - **`ip`** — recorded, because an administrator investigating an incident needs
     *   it. It is deliberately **not** used to decide anything: consumer addresses are
     *   dynamic, and comparing them is how a security signal becomes noise.
     *
     * @return string JSON, or an empty string when there is nothing to record
     */
    private static function currentDeviceInfo(): string
    {
        try {
            $fingerprint = \Pramnos\Auth\SignInFingerprint::current();
            $info = array(
                'device' => $fingerprint,
                'label'  => \Pramnos\Auth\SignInFingerprint::describe($fingerprint),
            );

            $ip = \Pramnos\Http\Request::clientIp();
            if ($ip !== null && $ip !== '') {
                $info['ip'] = substr((string) $ip, 0, 45);
            }

            $json = json_encode($info);

            // An unencodable value would put the literal `false` in a TEXT column that
            // Token::load() then tries to decode. A token created without device
            // information is a smaller problem than one that cannot be read back.
            return $json === false ? '' : $json;
        } catch (\Throwable) {
            // Issuing a token must not fail because the request could not be
            // described. Every caller here is in a login or an OAuth exchange.
            return '';
        }
    }

    public function addToken($tokentype, $token, $notes='',
        $parentToken = null, $expires = null)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $now = time();
        // A token is a fresh, unique value, so this is a plain INSERT. (The old
        // upsert used ON CONFLICT (userid, tokentype, token), but usertokens has
        // no unique constraint on those columns — which threw on PostgreSQL and
        // silently degraded to a plain insert on MySQL anyway.)
        $data = [
            'userid'      => $this->userid,
            'tokentype'   => $tokentype,
            'token'       => $token,
            'created'     => $now,
            'notes'       => $notes,
            'status'      => 1,
            'lastused'    => $now,
            'actions'     => 0,
            'removedate'  => 0,
            'deviceinfo'  => self::currentDeviceInfo(),
            'scope'       => '',
        ];
        // MySQL historically also wrote parentToken here; PostgreSQL omitted it.
        if ($database->type != 'postgresql') {
            $data['parentToken'] = $parentToken;
        }
        // Only write `expires` when a TTL was requested. Omitting it lets the
        // column default apply (it is NOT NULL on MySQL) — preserving the prior
        // behaviour where addToken never touched this column. loadByToken()
        // treats 0/NULL as "never expires".
        if ($expires !== null) {
            $data['expires'] = $expires;
        }
        $database->queryBuilder()->table('#PREFIX#usertokens')->insert($data);
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
        $now = time();
        if ($database->type == 'postgresql') {
            // PostgreSQL: no parentToken cascade — update only the exact token
            $database->queryBuilder()
                ->table('#PREFIX#usertokens')
                ->where('tokenid', $tokenid)
                ->where('userid', $this->userid)
                ->update(['status' => 2, 'removedate' => $now]);
        } else {
            // MySQL: also mark child tokens that reference this token via parentToken
            $database->queryBuilder()
                ->table('#PREFIX#usertokens')
                ->where('userid', $this->userid)
                ->where(function ($q) use ($tokenid) {
                    $q->where('tokenid', $tokenid)
                      ->orWhere('parentToken', $tokenid);
                })
                ->update(['status' => 2, 'removedate' => $now]);
        }
        $database->cacheflush('usertokens');
        return $this;
    }

    /**
     * Clear ALL tokens from this user
     * @return $this
     */
    public function clearTokens()
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $database->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('userid', $this->userid)
            ->update(['status' => 2, 'removedate' => time()]);
        $database->cacheflush('usertokens');
        return $this;
    }

    /**
     * Return an active auth token for the user
     * @return string|bool
     */
    public function getToken()
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $result = $database->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->whereIn('tokentype', ['auth', 'access_token'])
            ->where('status', 1)
            ->where('userid', $this->userid)
            ->first();
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
        $result = $database->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('userid', $this->userid)
            ->orderBy('created', 'desc')
            ->get();
        
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
        $database->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('tokenid', $tokenId)
            ->where('userid', $this->userid)
            ->update(['status' => 0]);
        $database->cacheflush('usertokens');
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
        $database->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('tokenid', $tokenId)
            ->where('userid', $this->userid)
            ->update(['expires' => time(), 'status' => 0]);
        $database->cacheflush('usertokens');
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
        $oneMonthAgo = time() - ($days * 24 * 60 * 60);
        $database = \Pramnos\Framework\Factory::getDatabase();
        $database->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('userid', $this->userid)
            ->where('created', '<', $oneMonthAgo)
            ->where('lastused', '<', $oneMonthAgo)
            ->whereIn('tokentype', ['auth', 'access_token'])
            ->update(['status' => 2]);
        $database->cacheflush('usertokens');
        return true;
    }

    /**
     * Static method to clean up all unused auth tokens older than a specified number of days
     * @param int $days Number of days to keep tokens (default: 30)
     * @return bool Success status
     */
    /**
     * Count active web-session and API tokens (tokentype 1 and 3, status = 1).
     * Returns null when no database connection is available.
     */
    public static function countActiveSessions(): ?int
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if (!$database || !$database->connected) {
            return null;
        }
        try {
            $r = $database->queryBuilder()
                ->table('#PREFIX#usertokens')
                ->where('status', '=', 1)
                ->whereIn('tokentype', [1, 3])
                ->count();
            return (int) $r;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Retire every session-bearing token that has been idle for `$days`.
     *
     * `web_session` is in the list. It was not, and nothing else cleaned it
     * either, while `createWebSessionToken()` inserts one per login and gives it
     * no expiry — so the table grew for ever. Measured on a two-day-old
     * development installation with a single user: 7,255 rows, all
     * `web_session`, all with no expiry, arriving at about 230 an hour.
     *
     * It is also the table `tokenactions` points a foreign key at, so those rows
     * are not merely dead weight — see the write spool's parked-row policy for
     * what happens when the two disagree.
     *
     * Safe for a session token by the same rule as for an API one: `lastused` is
     * updated on every request that presents it, so a token idle for a month has
     * no browser behind it. A PHP session expires after `session.gc_maxlifetime`
     * — 24 minutes by default — which is three orders of magnitude sooner.
     *
     * @param  int          $days  Idle days after which a token is retired
     * @param  string[]|null $types Token types to retire; null means every
     *                              session-bearing type
     * @return bool
     */
    public static function cleanupAllAuthTokens(int $days = 30, ?array $types = null)
    {
        $cutoff   = time() - ($days * 24 * 60 * 60);
        $database = \Pramnos\Framework\Factory::getDatabase();
        $database->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('created', '<', $cutoff)
            ->where('lastused', '<', $cutoff)
            ->whereIn('tokentype', $types ?? [
                Token::TYPE_WEB_SESSION,
                Token::TYPE_API,
                Token::TYPE_ACCESS_TOKEN,
            ])
            ->update(['status' => 2]);
        $database->cacheflush('usertokens');
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
        $now = time();
        $qb = $database->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('token', $token)
            ->where('status', 1)
            ->where(function ($q) use ($now) {
                $q->where('expires', 0)
                  ->orWhere('expires', '>', $now)
                  ->orWhereNull('expires');
            });
        if ($tokentype == 'auth') {
            $qb->whereIn('tokentype', ['auth', 'access_token']);
        } else {
            $qb->where('tokentype', $tokentype);
        }
        $result = $qb->first();
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
     * Resolve the real column names of the `users` table (schema-validated).
     *
     * Used by {@see _getApiList()} to reject unknown requested fields. The result
     * is cached in a static for the lifetime of the process (a single test /
     * request runs against a single database, so caching is safe). If schema
     * introspection fails for any reason, a known-safe minimal set is returned
     * so the API never hard-fails.
     *
     * @param \Pramnos\Database\Database $database Active database connection.
     * @return string[] List of column names present in the users table.
     */
    private function _getUsersTableColumns($database): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $columns = array();
        try {
            $result = $database->getColumns('#PREFIX#users');
            if ($result) {
                while ($result->fetch()) {
                    if (isset($result->fields['Field'])
                        && $result->fields['Field'] !== '') {
                        $columns[] = $result->fields['Field'];
                    }
                }
            }
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::log($ex->getMessage());
        }
        if (empty($columns)) {
            // Fallback: schema introspection failed — expose the always-present
            // columns so a user picker still works.
            $columns = array('userid', 'username', 'email');
        }
        return $cache = $columns;
    }

    /**
     * Return an API-formatted, paginated list of users.
     *
     * Signature-compatible with {@see \Pramnos\Application\Model::_getApiList()}
     * so a User foreign key flows through the generated-CRUD `fkOptions()` AJAX
     * endpoint with no special-casing. User extends {@see \Pramnos\Framework\Base},
     * not \Pramnos\Application\Model, so it cannot inherit that method — instead it
     * implements {@see \Pramnos\Application\ApiList\ApiListSource} and delegates to
     * the shared {@see \Pramnos\Application\ApiList\ApiListQuery} engine (see the
     * apiList* methods below), sharing one search/paging/format pipeline rather
     * than a parallel copy.
     *
     * Behaviour on the flat `users` table:
     *  - $fields       array | comma-string | JSON-string; validated against the
     *                  real users schema, unknowns dropped. When none are given it
     *                  defaults to the curated userid/username/email set (never the
     *                  whole schema — the password column is not exposed by
     *                  default). The primary key `userid` is always included.
     *  - $search       case-insensitive search across username + email (ILIKE on
     *                  PostgreSQL, LIKE on MySQL).
     *  - $order        the engine's order syntax (`field`, `+field`, `-field`,
     *                  `field ASC|DESC`, comma-separated); defaults to userid DESC.
     *  - $filter       a raw WHERE fragment (or structured array), now applied to
     *                  the users query (previously ignored).
     *  - $page/$itemsPerPage  pagination; $page <= 0 returns all rows.
     *  - $format       '' → {data, pagination, fields}; 'datatables' →
     *                  {draw, data, recordsTotal, recordsFiltered}.
     *
     * NOT USED by the flat users source (accepted for signature compatibility):
     * $join, $group, $table, $key, $debug, $returnAsModels, $useGetData,
     * $customGetListMethod, $addedfields.
     *
     * @param array|string  $fields
     * @param string|array  $search
     * @param string        $order
     * @param string|array  $filter
     * @param string        $join
     * @param string        $group
     * @param string|null   $table
     * @param string|null   $key
     * @param int           $page
     * @param int           $itemsPerPage
     * @param bool          $debug
     * @param bool          $returnAsModels
     * @param bool          $useGetData
     * @param mixed         $customGetListMethod
     * @param array|bool    $addedfields
     * @param string        $format
     * @return array API response envelope (shape depends on $format).
     */
    public function _getApiList($fields = array(), $search = '',
        $order = '', $filter = '', $join = '', $group = '',
        $table = null, $key = null,
        $page = 0, $itemsPerPage = 10, $debug = false, $returnAsModels = false,
        $useGetData = false, $customGetListMethod = false, $addedfields = false,
        $format = '')
    {
        // Drop-in equivalent of Model::_getApiList(): User is not a Model, so it
        // cannot inherit it, but by implementing ApiListSource it shares the same
        // ApiListQuery engine (search/paging/format/recordsTotal) instead of a
        // parallel copy. Its flat users-table data access lives in the apiList*
        // methods below. Note this now honours $filter (raw WHERE) — the generated
        // FK picker passes none, so that path is unchanged.
        return \Pramnos\Application\ApiList\ApiListQuery::run(
            $this, $fields, $search, $order, $filter, $join, $group, $table, $key,
            $page, $itemsPerPage, $debug, $returnAsModels, $useGetData,
            $customGetListMethod, $addedfields, $format
        );
    }

    // ── ApiListSource — flat users-table implementation ──────────────────────

    /**
     * {@inheritDoc} The full users schema (validation allowlist).
     * @param string $join
     * @return array
     */
    public function apiListSchemaFields($join = ''): array
    {
        return $this->_getUsersTableColumns(\Pramnos\Framework\Factory::getDatabase());
    }

    /**
     * {@inheritDoc} A curated, safe subset for a user picker — never the whole
     * schema (which includes the password hash). Intersected with the real
     * columns so it works even on a reduced users table.
     * @param string $join
     * @return array
     */
    public function apiListDefaultFields($join = ''): array
    {
        $schema = $this->_getUsersTableColumns(\Pramnos\Framework\Factory::getDatabase());
        $curated = array_values(array_intersect(array('userid', 'username', 'email'), $schema));
        return empty($curated) ? $schema : $curated;
    }

    /**
     * {@inheritDoc}
     * @return string
     */
    public function apiListPrimaryKey(): string
    {
        return 'userid';
    }

    /**
     * {@inheritDoc} Case-insensitive search across username + email only
     * (ILIKE on PostgreSQL, LIKE on MySQL). Per-field searches and the field
     * list are ignored — the users picker searches those two columns. Returns a
     * raw WHERE body (no WHERE keyword), or '' when there is nothing to search.
     * @return string
     */
    public function apiListSearchConditions(array $validFields, $globalSearch, array $fieldSearches, $join): string
    {
        $term = is_string($globalSearch) ? trim($globalSearch) : '';
        if ($term === '') {
            return '';
        }
        $database = \Pramnos\Framework\Factory::getDatabase();
        $searchable = array_values(
            array_intersect(array('username', 'email'), $this->_getUsersTableColumns($database))
        );
        if (empty($searchable)) {
            return '';
        }
        $likeOp = ($database->type === 'postgresql') ? 'ILIKE' : 'LIKE';
        $q      = ($database->type === 'postgresql') ? '"' : '`';
        $escaped = $database->prepareInput($term);
        $parts = array();
        foreach ($searchable as $col) {
            $parts[] = $q . $col . $q . ' ' . $likeOp . " '%" . $escaped . "%'";
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * {@inheritDoc} One page of users plus total/pages, applying the engine's
     * raw SELECT/WHERE/ORDER fragments to the flat users table.
     * @return array
     */
    public function apiListPaginate(
        $itemsPerPage, $page, $filter, $order, $table, $key, $debug,
        $join, $selectFields, $group, $returnAsModels, $useGetData, $customGetListMethod, $addedfields
    ): array {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $where = $this->apiListUsersWhere($filter);

        $countQb = $database->queryBuilder()->table(self::usersTable());
        if ($where !== '') {
            $countQb->whereRaw($where);
        }
        $total = (int) $countQb->count();
        $pages = $itemsPerPage > 0 ? (int) ceil($total / $itemsPerPage) : 0;

        $qb = $database->queryBuilder()->table(self::usersTable())->select($selectFields);
        if ($where !== '') {
            $qb->whereRaw($where);
        }
        $orderBy = $this->apiListUsersOrder($order);
        if ($orderBy !== '') {
            $qb->orderByRaw($orderBy);
        }
        $qb->limit((int) $itemsPerPage)->offset(((int) $page - 1) * (int) $itemsPerPage);

        return array('total' => $total, 'pages' => $pages, 'items' => $this->apiListFetchItems($qb));
    }

    /**
     * {@inheritDoc} Every matching user (no pagination).
     * @return array
     */
    public function apiListFetchAll(
        $filter, $order, $table, $key, $debug,
        $join, $selectFields, $group, $returnAsModels, $useGetData, $customGetListMethod, $addedfields
    ) {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $qb = $database->queryBuilder()->table(self::usersTable())->select($selectFields);
        $where = $this->apiListUsersWhere($filter);
        if ($where !== '') {
            $qb->whereRaw($where);
        }
        $orderBy = $this->apiListUsersOrder($order);
        if ($orderBy !== '') {
            $qb->orderByRaw($orderBy);
        }
        return $this->apiListFetchItems($qb);
    }

    /**
     * {@inheritDoc} The users table carries no JSON columns the picker needs
     * decoded, so rows pass through unchanged.
     * @return array
     */
    public function apiListProcessRow(array $row, $join): array
    {
        return $row;
    }

    /**
     * {@inheritDoc} The flat fetch never records a query error separately.
     * @return mixed
     */
    public function apiListLastError()
    {
        return null;
    }

    /**
     * {@inheritDoc} Grand total (before the search box) — all users matching any
     * base $filter. The users picker passes no base filter, so this counts every
     * user.
     * @return int
     */
    public function apiListRecordsTotal($baseFilter, $table, $key, $join, $selectFields, $group, $addedfields): int
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $qb = $database->queryBuilder()->table(self::usersTable());
        $where = $this->apiListUsersWhere($baseFilter);
        if ($where !== '') {
            $qb->whereRaw($where);
        }
        return (int) $qb->count();
    }

    /**
     * Normalise the engine's combined filter ("where ...") into a raw WHERE body
     * for whereRaw(), or '' when empty.
     * @param mixed $filter
     * @return string
     */
    private function apiListUsersWhere($filter): string
    {
        $filter = is_string($filter) ? trim($filter) : '';
        if ($filter === '') {
            return '';
        }
        return \Pramnos\Application\ApiList\ApiListSqlBuilder::stripSqlKeyword($filter, 'WHERE');
    }

    /**
     * Normalise the engine's validated order ("ORDER BY ...") into a raw ORDER
     * body for orderByRaw(), or '' when empty.
     * @param mixed $order
     * @return string
     */
    private function apiListUsersOrder($order): string
    {
        $order = is_string($order) ? trim($order) : '';
        if ($order === '') {
            return '';
        }
        return \Pramnos\Application\ApiList\ApiListSqlBuilder::stripSqlKeyword($order, 'ORDER BY');
    }

    /**
     * Fetch every row of a prepared users query as an assoc array of its selected
     * columns.
     * @param mixed $qb A prepared QueryBuilder.
     * @return array
     */
    private function apiListFetchItems($qb): array
    {
        $items = array();
        $result = $qb->get();
        if ($result) {
            while ($result->fetch()) {
                $items[] = $result->fields;
            }
        }
        return $items;
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

        // Count all tokens for this user via QB (prefix handled automatically)
        $tokenCount = $database->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('userid', $this->userid)
            ->count();

        // COUNT(DISTINCT applicationid) is not directly available via QB count(),
        // so pluck all applicationid values and count unique non-null entries.
        try {
            $appIds = $database->queryBuilder()
                ->table('#PREFIX#usertokens')
                ->select('applicationid')
                ->where('userid', $this->userid)
                ->pluck('applicationid');
            $appCount = count(array_unique(array_filter($appIds, fn($v) => $v !== null && $v !== '')));
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
                'ip_address' => \Pramnos\Http\Request::clientIp('unknown'),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'last_activity' => date('Y-m-d H:i:s'),
                'is_current' => true
            ]
        ];
    }
    
    /**
     * Create a web-session token for the current user and store it in the session.
     *
     * Call this at the end of a successful web login.  The token is persisted
     * in `usertokens` (type: `Token::TYPE_WEB_SESSION`) and placed in
     * `$_SESSION['usertoken']` so that UnifiedAuthMiddleware can accept it on
     * subsequent same-origin AJAX requests without a Bearer token.
     *
     * `$_SESSION['auth']` is kept for BC — existing code that reads it will
     * continue to work, but new code should use the token mechanism.
     *
     * @param  string|null $ipAddress  Optional client IP (stored in the token record).
     * @return Token                   The newly created token object.
     */
    /**
     * Retire the web-session tokens this login replaces.
     *
     * One sign-in mints one token, and nothing used to end the previous one: a browser
     * that signed in twice left two rows marked **Active**, from the same address, for
     * the thirty days of their lifetime. The screen showing them was right and the state
     * was wrong.
     *
     * Two problems in one. The list of a user's active sessions stops meaning anything —
     * an operator reading "three active sessions" cannot tell three devices from one
     * browser that re-authenticated three times, which is exactly the question that list
     * exists to answer. And a token nothing can reach through a session cookie is still a
     * **valid credential**: `loadByToken()` accepts the raw value, so a copy in a log, an
     * old client or a backup keeps working for a month after the session that created it
     * ended.
     *
     * Two cases, and both are handled here rather than by a cleanup job, because a
     * credential should stop being valid at the moment it is replaced and not at the next
     * sweep:
     *
     *   - **The same session.** `$_SESSION['usertoken']` is the token this request came
     *     in with; re-authenticating in place replaces it.
     *   - **The same device.** A fresh session in the same browser has no
     *     `$_SESSION['usertoken']` to compare, so the device fingerprint is matched
     *     instead — `deviceinfo` carries it, and it is what distinguishes one browser
     *     from another on the same address.
     *
     * Tokens from *other* devices are left alone: signing in on a laptop must not sign
     * you out on a phone, which is the whole point of having more than one.
     *
     * Marked inactive rather than deleted, so the token history a screen shows keeps its
     * rows — `status = 0` is what "this was superseded" looks like everywhere else.
     */
    /**
     * End every session for this account except, optionally, one to keep.
     *
     * Both halves of what a session is here, because ending one without the other leaves
     * the account reachable: the `sessions` row a browser is tracked by, and the
     * `web_session` token that authenticates its requests. Revoking only the row leaves a
     * live bearer token; revoking only the token leaves a session the tracker still
     * believes in.
     *
     * The `$keepSid` is the caller's own session — `md5(session_id())`, the form the
     * `sessions` table stores. Passing it is what makes this usable from a password change:
     * the person doing it must not be signed out by their own action, or the feature reads
     * as a bug and they stop using it.
     *
     * @param  string|null $keepSid  `md5(session_id())` to spare, or null to end all
     * @return int How many session rows were ended (tokens are not counted separately)
     */
    public function revokeOtherSessions(?string $keepSid = null): int
    {
        if ((int) $this->userid < 2) {
            return 0;
        }

        $database = \Pramnos\Framework\Factory::getDatabase();
        $ended    = 0;

        try {
            $sessions = $database->queryBuilder()
                ->table('#PREFIX#sessions')
                ->where('userid', (int) $this->userid)
                ->where('logout', 0);

            if ($keepSid !== null && $keepSid !== '') {
                $sessions->where('sid', '!=', $keepSid);
            }

            /*
             * `update()` returns a `Result`, not a row count.
             *
             * `(int) $result` raised "Object of class Result could not be converted to int" and
             * produced a number that means nothing — so this method's documented return value,
             * *how many sessions were ended*, was noise. Nothing broke visibly: the sessions
             * were ended correctly, and only the count was wrong, which is why it survived.
             * Found by a test that asserted the count rather than the effect.
             */
            $result = $sessions->update(['logout' => 1]);
            $ended  = $result instanceof \Pramnos\Database\Result
                ? (int) $result->getAffectedRows()
                : (int) (bool) $result;
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'revokeOtherSessions could not end sessions for ' . (int) $this->userid
                . ': ' . $exception->getMessage()
            );
        }

        try {
            $tokens = $database->queryBuilder()
                ->table('#PREFIX#usertokens')
                ->where('userid', (int) $this->userid)
                ->where('tokentype', Token::TYPE_WEB_SESSION)
                ->where('status', 1);

            // The token this request is holding is the one to keep, when anything is.
            if ($keepSid !== null && $keepSid !== ''
                && isset($_SESSION['usertoken'])
                && is_object($_SESSION['usertoken'])
                && (int) ($_SESSION['usertoken']->tokenid ?? 0) > 0
            ) {
                $tokens->where('tokenid', '!=', (int) $_SESSION['usertoken']->tokenid);
            }

            $tokens->update(['status' => 0, 'removedate' => time()]);
        } catch (\Throwable $exception) {
            \Pramnos\Logs\Logger::log(
                'revokeOtherSessions could not revoke tokens for ' . (int) $this->userid
                . ': ' . $exception->getMessage()
            );
        }

        return $ended;
    }

    private function retireSupersededWebSessionTokens(): void
    {
        if ((int) $this->userid < 2) {
            return;
        }

        $database = \Pramnos\Framework\Factory::getDatabase();
        $now      = time();

        try {
            // The token this request arrived with, if any.
            if (isset($_SESSION['usertoken'])
                && is_object($_SESSION['usertoken'])
                && (int) ($_SESSION['usertoken']->tokenid ?? 0) > 0
            ) {
                $database->queryBuilder()
                    ->table('#PREFIX#usertokens')
                    ->where('tokenid', (int) $_SESSION['usertoken']->tokenid)
                    ->where('userid', (int) $this->userid)
                    ->update(['status' => 0, 'removedate' => $now]);
            }

            // Any other live web-session token from this same device.
            $device = self::currentDeviceInfo();
            if ($device !== '') {
                $database->queryBuilder()
                    ->table('#PREFIX#usertokens')
                    ->where('userid', (int) $this->userid)
                    ->where('tokentype', Token::TYPE_WEB_SESSION)
                    ->where('status', 1)
                    ->where('deviceinfo', $device)
                    ->update(['status' => 0, 'removedate' => $now]);
            }
        } catch (\Throwable $ex) {
            // A login must not fail because of housekeeping. Logged, because a
            // superseded token that stays valid is worth knowing about.
            \Pramnos\Logs\Logger::log(
                'Retiring superseded web-session tokens failed: ' . $ex->getMessage()
            );
        }
    }

    /**
     * How long a web-session token stays valid, in seconds.
     *
     * One is created per login and, until this existed, none of them ever
     * expired: `loadByToken()` reads 0 and NULL as "never", and nothing set
     * anything else. A two-day-old development installation with one user had
     * 7,255 of them.
     *
     * Thirty days by default — generous next to the PHP session it belongs to,
     * whose own idle timeout (`session.gc_maxlifetime`) is 24 minutes out of the
     * box, and short enough that the table stops being append-only. Set
     * `web_session_lifetime` to change it; `0` restores tokens that never
     * expire.
     *
     * @return int Seconds, or 0 for no expiry
     */
    public static function webSessionLifetime(): int
    {
        $configured = \Pramnos\Application\Settings::getSetting('web_session_lifetime');

        if (is_numeric($configured)) {
            return max(0, (int) $configured);
        }

        return 2592000; // 30 days
    }

    public function createWebSessionToken(?string $ipAddress = null): Token
    {
        // The one this browser was already using stops being valid — see
        // retireSupersededWebSessionTokens() for why that is not optional.
        $this->retireSupersededWebSessionTokens();

        $rawToken = bin2hex(random_bytes(32));
        $lifetime = static::webSessionLifetime();
        $this->addToken(
            Token::TYPE_WEB_SESSION,
            $rawToken,
            'web_session',
            null,
            $lifetime > 0 ? time() + $lifetime : null
        );

        $database   = \Pramnos\Framework\Factory::getDatabase();
        $result     = $database->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('token', $rawToken)
            ->where('userid', $this->userid)
            ->first();

        if ($result->numRows > 0) {
            $tokenObj             = new Token($result->fields);
            $tokenObj->ipaddress  = $ipAddress ?? \Pramnos\Http\Request::clientIp();
            $_SESSION['usertoken'] = $tokenObj;
            return $tokenObj;
        }

        // Fallback: build a minimal in-memory token (DB write succeeded but
        // re-read failed — should not happen in practice)
        $tokenObj = new Token([
            'tokentype' => Token::TYPE_WEB_SESSION,
            'token'     => $rawToken,
            'userid'    => $this->userid,
            'status'    => 1,
        ]);
        $_SESSION['usertoken'] = $tokenObj;
        return $tokenObj;
    }

    /**
     * Invalidate the web-session token on logout.
     *
     * Marks the token as inactive in `usertokens` and removes it from the
     * session.  Should be called before `session_destroy()` / `session_regenerate_id()`.
     *
     * @return void
     */
    public function invalidateWebSessionToken(): void
    {
        if (!isset($_SESSION['usertoken']) || !is_object($_SESSION['usertoken'])) {
            return;
        }
        /** @var Token $token */
        $token = $_SESSION['usertoken'];
        if ($token->tokentype !== Token::TYPE_WEB_SESSION || $token->tokenid < 1) {
            unset($_SESSION['usertoken']);
            return;
        }
        try {
            $this->deactivateToken($token->tokenid);
        } catch (\Throwable) {
            // Best-effort — session will be destroyed regardless
        }
        unset($_SESSION['usertoken']);
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

        // An account with no stored password cannot verify one. Without this the
        // null reached `password_verify()`, which PHP 8.4 deprecates and a later
        // version will refuse outright — and the check it was performing was a
        // comparison against nothing. Accounts in this state are real: one
        // created by an administrator or an SSO provisioning run and never given
        // a password of its own.
        if (!is_string($this->password) || $this->password === '') {
            return false;
        }

        /**
         * Every scheme the row might have been written in — see
         * {@see \Pramnos\Auth\PasswordHash::verify()}.
         *
         * Including the plain `password_hash($password)` an **application** writes when it
         * creates its own accounts. The `users` table is shared and either side may have
         * written a row; a check that understood only the framework's own pepper refused
         * every correct password on the other side's rows, and the symptom was "the right
         * password is refused" with nothing pointing at hashing. It was reported from an
         * application whose 2FA could not be switched off by anybody, because the step-up
         * in front of it could not read that application's hashes.
         *
         * Trying several schemes cannot admit a wrong password: each is a comparison
         * against the same stored hash.
         */
        $scheme = \Pramnos\Auth\PasswordHash::verify(
            $password,
            (string) $this->password,
            (int) $this->userid,
            $this->legacyMd5Allowed()
        );

        if ($scheme === null) {
            return false;
        }

        // The password was right; the hash may be old. This is the only moment the
        // plaintext is available, so it is the only moment an upgrade is possible.
        $this->upgradePasswordHash($password, $scheme);

        return true;
    }

    /**
     * Keep the pending plaintext password out of anything serialized.
     *
     * Between `setPassword()` on a user with no id yet and the rehash in
     * `_save()`, the plain password sits on the object. User objects go into
     * `$_SESSION`, and sessions get written to disk, Redis or a database — so
     * without this the password could be persisted in clear text by a step that
     * has nothing to do with passwords.
     *
     * `__serialize()` rather than `__sleep()`, and the difference is not
     * cosmetic. `__sleep()` returns property *names*, and PHP looks each one up
     * on the object being serialized; a private property of this class is
     * stored under a mangled name, so when a subclass instance is serialized —
     * which is the normal case, applications extend this class — PHP cannot
     * find `_userstable` and emits a warning for every private property on
     * every serialize. `__serialize()` returns the data itself and has no such
     * lookup.
     *
     * @return array<string, mixed> The state to serialize
     */
    public function __serialize(): array
    {
        $data = get_object_vars($this);
        unset($data['_pendingPlainPassword']);

        return $data;
    }

    /**
     * Restore the state written by {@see __serialize()}.
     *
     * Only names that were serialized are written back, and they were read from
     * this object in the first place, so each one exists. The pending password
     * is simply absent and stays null — which is correct: a user restored from
     * a session has no password change in flight.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $name => $value) {
            $this->$name = $value;
        }
    }

    /**
     * Rewrite a correct-but-old password hash, if the application asked for it.
     *
     * A login is the only moment the plaintext exists, so it is the only moment an old
     * hash can be replaced. Without this, an installation that raises its bcrypt cost
     * raises it for accounts created afterwards and for nobody else — and one that
     * migrated from a legacy system keeps MD5 for every account whose owner never changes
     * their password, which is most of them.
     *
     * **Opt-in, per application**, because it writes to the users table on a read path and
     * because upgrading changes the stored format:
     *
     * ```php
     * // app/app.php
     * 'auth' => ['rehash_on_login' => 'modern'],   // off | modern | all
     * ```
     *
     *   - `off` — never rewrite. For a replica, or a table another application also reads
     *     with its own expectations.
     *   - `modern` (default) — rewrite when the *preferred scheme's parameters* changed, so
     *     a raised cost reaches existing accounts. Nothing changes about which scheme the
     *     row uses.
     *   - `all` — also upgrade a legacy MD5, a plain application-written bcrypt, or the
     *     older peppered scheme to the current one. This is the migration, and it is
     *     deliberately not the default: a legacy application still reading the same table
     *     would stop recognising the row.
     *
     * Failure is silent by design. A login must not fail because housekeeping could not
     * write, and the user is already authenticated by the time this runs.
     *
     * @param string $plain  The password that just verified
     * @param string $scheme The scheme that matched it
     */
    protected function upgradePasswordHash(string $plain, string $scheme): void
    {
        $policy = $this->rehashPolicy();
        if ($policy === 'off') {
            return;
        }

        if (!\Pramnos\Auth\PasswordHash::needsUpgrade($scheme, (string) $this->password)) {
            return;
        }

        /**
         * What `modern` will and will not take over.
         *
         * It upgrades the schemes **the framework itself wrote** — the pepper-suffix one,
         * which is the scheme with the 72-byte truncation, and its own preferred scheme
         * when the cost has moved. A sign-in is the only moment the plaintext exists, so
         * it is the only moment this can happen at all.
         *
         * It also upgrades md5, which needs no second opt-in: a row can only *be* read as
         * md5 when the application set `auth.legacy_md5`, and that is already the statement
         * "my table has md5 rows in it". Refusing to rewrite them would leave the weakest
         * hashes in the table as the only ones never improved.
         *
         * The one scheme it leaves alone is a plain `password_hash()`, and that is the
         * important half. Such a row may belong to **another writer sharing this table**:
         * an application that creates its own accounts and reads them back with
         * `password_verify($plain, $hash)`. Rewriting it into a digest-based scheme would
         * leave that application unable to verify a password it wrote — the framework
         * would have silently taken ownership of somebody else's rows. `all` is how an
         * application says the table is its own.
         */
        if ($policy !== 'all' && $scheme === \Pramnos\Auth\PasswordHash::SCHEME_PLAIN) {
            return;
        }

        try {
            $hash = \Pramnos\Auth\PasswordHash::make($plain, (int) $this->userid);

            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table(self::usersTable())
                ->where('userid', (int) $this->userid)
                ->update(['password' => $hash]);

            $this->password = $hash;

            // Recorded: a password hash changing without the password changing is the kind
            // of thing an audit should be able to explain later.
            \Pramnos\Auth\ActivityLog::record((int) $this->userid, 'password_hash_upgraded', [
                'from' => $scheme,
                'to'   => \Pramnos\Auth\PasswordHash::PREFERRED,
            ]);
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::log(
                'Password hash upgrade failed for user ' . (int) $this->userid . ': ' . $ex->getMessage()
            );
        }
    }

    /**
     * The application's rehash-on-login policy: `off`, `modern` (default) or `all`.
     */
    protected function rehashPolicy(): string
    {
        $app = \Pramnos\Application\Application::currentInstance();
        $configured = is_object($app)
            ? (string) ($app->applicationInfo['auth']['rehash_on_login'] ?? '')
            : '';

        return in_array($configured, ['off', 'modern', 'all'], true) ? $configured : 'modern';
    }

    /**
     * Does this installation still accept MD5 password hashes?
     *
     * Reads the same `auth.legacy_md5` key as
     * {@see \Pramnos\Auth\Drivers\DatabaseAuthDriver}, so the answer cannot
     * differ between the login and a password confirmation. Default false: an
     * installation that needs it says so.
     */
    protected function legacyMd5Allowed(): bool
    {
        // A config read, so the lookup — see the note in getCurrentUser(). The guard below
        // now guards something: with no application the answer is the documented default,
        // which for legacy MD5 is the secure one.
        $app = \Pramnos\Application\Application::currentInstance();
        if (!is_object($app)) {
            return false;
        }

        return (bool) ($app->applicationInfo['auth']['legacy_md5'] ?? false);
    }

    /**
     * Setup the database tables for the users
     */
    public static function setupDb()
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($database->type == 'postgresql') {
            $statements = [
                "CREATE TABLE IF NOT EXISTS #PREFIX#users (
                    userid bigserial PRIMARY KEY,
                    username varchar(50) NOT NULL DEFAULT '',
                    password varchar(100) NOT NULL DEFAULT '',
                    email varchar(150) NOT NULL DEFAULT '',
                    lastname varchar(128) NOT NULL DEFAULT '',
                    firstname varchar(128) NOT NULL DEFAULT '',
                    regdate integer NOT NULL DEFAULT 0,
                    regcompletion integer DEFAULT NULL,
                    lasttermsagreed integer DEFAULT NULL,
                    lastlogin integer NOT NULL DEFAULT 0,
                    active smallint NOT NULL DEFAULT 1,
                    validated smallint NOT NULL DEFAULT 1,
                    language varchar(50) NOT NULL DEFAULT '',
                    timezone char(3) NOT NULL DEFAULT '',
                    dateformat varchar(15) NOT NULL DEFAULT 'd/m/Y H:i',
                    usertype smallint NOT NULL DEFAULT 0,
                    sex smallint NOT NULL DEFAULT 0,
                    birthdate bigint NOT NULL DEFAULT 0,
                    photo integer DEFAULT NULL,
                    phone varchar(50) NOT NULL DEFAULT '',
                    mobile varchar(50) NOT NULL DEFAULT '',
                    fax varchar(50) NOT NULL DEFAULT '',
                    website varchar(255) NOT NULL DEFAULT '',
                    modified integer NOT NULL DEFAULT 0
                );",
                "CREATE TABLE IF NOT EXISTS #PREFIX#userdetails (
                    userid bigint NOT NULL,
                    fieldname varchar(35) NOT NULL,
                    value varchar(255) NOT NULL,
                    PRIMARY KEY (userid, fieldname)
                );",
                "CREATE TABLE IF NOT EXISTS #PREFIX#usergroups (
                    groupid serial PRIMARY KEY,
                    name varchar(80) NOT NULL,
                    description text NOT NULL,
                    \"order\" smallint DEFAULT NULL
                );",
                "CREATE TABLE IF NOT EXISTS #PREFIX#userstogroups (
                    userid bigint NOT NULL REFERENCES #PREFIX#users(userid) ON DELETE CASCADE ON UPDATE CASCADE,
                    groupid integer NOT NULL REFERENCES #PREFIX#usergroups(groupid) ON DELETE CASCADE ON UPDATE CASCADE,
                    PRIMARY KEY (userid, groupid)
                );",
                "CREATE TABLE IF NOT EXISTS #PREFIX#usertokens (
                    tokenid serial PRIMARY KEY,
                    userid bigint NOT NULL REFERENCES #PREFIX#users(userid) ON DELETE CASCADE,
                    tokentype varchar(20) NOT NULL,
                    token text NOT NULL,
                    created integer NOT NULL DEFAULT 0,
                    notes varchar(255) NOT NULL DEFAULT '',
                    lastused integer NOT NULL DEFAULT 0,
                    status smallint NOT NULL DEFAULT 0,
                    \"parentToken\" integer DEFAULT NULL,
                    applicationid integer DEFAULT NULL,
                    actions integer NOT NULL DEFAULT 0,
                    removedate integer NOT NULL DEFAULT 0,
                    deviceinfo text,
                    scope text,
                    expires integer DEFAULT NULL,
                    ipaddress varchar(45) DEFAULT NULL,
                    code_challenge varchar(128) DEFAULT NULL,
                    code_challenge_method varchar(10) DEFAULT NULL
                );",
                "CREATE INDEX IF NOT EXISTS idx_usertokens_userid_status ON #PREFIX#usertokens (userid, status);",
                "CREATE INDEX IF NOT EXISTS idx_usertokens_type_status ON #PREFIX#usertokens (tokentype, status);",
                "CREATE INDEX IF NOT EXISTS idx_usertokens_applicationid ON #PREFIX#usertokens (applicationid);",
                "INSERT INTO #PREFIX#users (userid, username, active) VALUES (1, 'Guest', 1) ON CONFLICT (userid) DO NOTHING;",
                // Advance the bigserial sequence past the explicitly-inserted Guest row (id=1).
                // Without this, the next auto-generated userid would collide with the Guest user.
                "SELECT setval(pg_get_serial_sequence('#PREFIX#users', 'userid'), (SELECT COALESCE(MAX(userid), 1) FROM #PREFIX#users));"
            ];
        } else {
            $statements = [
                "CREATE TABLE IF NOT EXISTS `#PREFIX#users` (
                    `userid` bigint(20) NOT NULL AUTO_INCREMENT,
                    `username` varchar(50) NOT NULL DEFAULT '',
                    `password` varchar(100) NOT NULL DEFAULT '',
                    `email` varchar(150) NOT NULL DEFAULT '',
                    `lastname` varchar(128) NOT NULL DEFAULT '',
                    `firstname` varchar(128) NOT NULL DEFAULT '',
                    `regdate` int(11) NOT NULL DEFAULT '0',
                    `regcompletion` int(10) UNSIGNED DEFAULT NULL,
                    `lasttermsagreed` int(10) UNSIGNED DEFAULT NULL,
                    `lastlogin` int(11) NOT NULL DEFAULT '0',
                    `active` tinyint(1) NOT NULL DEFAULT '1',
                    `validated` tinyint(4) NOT NULL DEFAULT '1',
                    `language` varchar(50) NOT NULL DEFAULT '',
                    `timezone` char(3) NOT NULL DEFAULT '',
                    `dateformat` varchar(15) NOT NULL DEFAULT 'd/m/Y H:i',
                    `usertype` tinyint(4) NOT NULL DEFAULT '0',
                    `sex` tinyint(3) UNSIGNED NOT NULL DEFAULT '0',
                    `birthdate` bigint(20) NOT NULL DEFAULT '0',
                    `photo` int(11) DEFAULT NULL,
                    `phone` varchar(50) NOT NULL DEFAULT '',
                    `mobile` varchar(50) NOT NULL DEFAULT '',
                    `fax` varchar(50) NOT NULL DEFAULT '',
                    `website` varchar(255) NOT NULL DEFAULT '',
                    `modified` int(11) NOT NULL DEFAULT '0',
                    PRIMARY KEY (`userid`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8;",
                "CREATE TABLE IF NOT EXISTS `#PREFIX#userdetails` (
                  `userid` bigint(20) NOT NULL,
                  `fieldname` varchar(35) NOT NULL,
                  `value` varchar(255) NOT NULL,
                  PRIMARY KEY (`userid`,`fieldname`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8;",
                "CREATE TABLE IF NOT EXISTS `#PREFIX#usergroups` (
                  `groupid` mediumint(8) UNSIGNED NOT NULL AUTO_INCREMENT,
                  `name` varchar(80) NOT NULL COMMENT 'Group Name',
                  `description` text NOT NULL COMMENT 'Group Description',
                  `order` tinyint(4) DEFAULT NULL,
                  PRIMARY KEY (`groupid`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='User Groups';",
                "CREATE TABLE IF NOT EXISTS `#PREFIX#userstogroups` (
                  `userid` bigint(20) NOT NULL,
                  `groupid` mediumint(8) UNSIGNED NOT NULL,
                  PRIMARY KEY (`userid`,`groupid`),
                  KEY `groupid` (`groupid`),
                  FOREIGN KEY (`userid`) REFERENCES `#PREFIX#users` (`userid`) ON DELETE CASCADE ON UPDATE CASCADE,
                  FOREIGN KEY (`groupid`) REFERENCES `#PREFIX#usergroups` (`groupid`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Users to groups';",
                "CREATE TABLE IF NOT EXISTS `#PREFIX#usertokens` (
                  `tokenid` int(11) NOT NULL AUTO_INCREMENT,
                  `userid` bigint(20) NOT NULL,
                  `tokentype` varchar(20) NOT NULL,
                  `token` text NOT NULL,
                  `created` int(11) NOT NULL DEFAULT 0,
                  `notes` varchar(255) NOT NULL DEFAULT '',
                  `lastused` int(11) NOT NULL DEFAULT 0,
                  `status` tinyint(4) NOT NULL DEFAULT 0,
                  `parentToken` int(11) DEFAULT NULL,
                  `applicationid` int(11) DEFAULT NULL,
                  `actions` int(11) NOT NULL DEFAULT 0,
                  `removedate` int(11) NOT NULL DEFAULT 0,
                  `deviceinfo` text,
                  `scope` text,
                  `expires` int(11) DEFAULT NULL,
                  `ipaddress` varchar(45) DEFAULT NULL,
                  `code_challenge` varchar(128) DEFAULT NULL,
                  `code_challenge_method` varchar(10) DEFAULT NULL,
                  PRIMARY KEY (`tokenid`),
                  KEY `idx_usertokens_userid_status` (`userid`,`status`),
                  KEY `idx_usertokens_type_status` (`tokentype`,`status`),
                  KEY `idx_usertokens_applicationid` (`applicationid`),
                  FOREIGN KEY (`userid`) REFERENCES `#PREFIX#users` (`userid`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8;",
                "INSERT IGNORE INTO `#PREFIX#users` (`userid`, `username`, `active`) VALUES (1, 'Guest', 1);"
            ];
        }

        foreach ($statements as $sql) {
            $database->query($database->prepareQuery($sql));
        }
    }
}
