<?php
namespace Pramnos\Auth;
/**
 * Store and manage permissions.
 *
 * Storage moved, the API did not. This class historically read
 * `<prefix>permissions`, a table **no migration creates** and which nothing
 * calls `setupDb()` for — so on a stock installation it did not exist, and every
 * lookup failed. Rather than retire a working API, it now answers from whichever
 * store the installation actually has:
 *
 *   1. `<prefix>permissions` when that table exists. An installation that has it
 *      has been writing to it, so it stays authoritative — nothing changes.
 *   2. Otherwise `authserver.permissions`, the RBAC system the framework
 *      maintains, read through PermissionResolver.
 *   3. Neither: the class says so instead of guessing. That distinction is the
 *      one that used to be missing — a failed lookup was reported as `false`,
 *      indistinguishable from a deny, so callers refused everything.
 *
 * The new store understands more than this API can express (roles, priorities,
 * expiry, audience scoping, ABAC conditions). Reading it through these methods
 * gives the subset they describe; use PermissionResolver directly for the rest.
 *
 * Moving old rows across: docs/Pramnos_Legacy_Permissions_Migration.md.
 */
class Permissions extends \Pramnos\Framework\Base
{

    protected $_storageMethod = 'database';

    /** @var string|null Resolved store: legacy|authserver|none */
    protected $_store = null;
    protected $_cache = array();
    protected $_defaut = array();

    /**
     * Factory method
     * @staticvar pramnos_permissions $instance
     * @return Permissions
     */
    public static function &getInstance($storageMethod = 'database')
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = new Permissions($storageMethod);
        }
        return $instance;
    }

    /**
     * Instance constructor
     * @param string $storageMethod Defaults to database
     */
    public function __construct($storageMethod = 'database')
    {
        $this->_storageMethod = $storageMethod;
        parent::__construct();
    }

    /**
     * Set a default privilege. For example, view can be always true.
     * @param string $privilege
     * @param boolean $value
     * @return \pramnos_permissions
     */
    public function setDefaultPermission($privilege, $value)
    {
        $this->_defaut[$privilege] = (bool) $value;
        return $this;
    }

    /**
     * Remove a permission
     * @param string $subject
     * @param string $resource
     * @param string $privilege
     * @param string $resourceElement
     * @param string $resourceType
     * @param string $subjectType
     * @return \pramnos_permissions
     */
    public function removePermission($subject, $resource, $privilege,
        $resourceElement = '', $resourceType = 'module', $subjectType = 'user')
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($subjectType == 'user') {
            $sql = $database->prepareQuery(
                "DELETE FROM `" . DB_PERMISSIONSTABLE . "` "
                . " WHERE `userid` = %d "
                . " AND `resource` = %s AND `resourcetype`= %s "
                . " AND `privilege` = %s AND `resourceelement` = %s "
                . " AND `subjecttype` = 'user'",
                $subject, $resource, $resourceType, $privilege,
                $resourceElement
            );
        } else {
            $sql = $database->prepareQuery(
                "DELETE FROM `" . DB_PERMISSIONSTABLE . "`
                WHERE `subject` = %s
                AND `resource` = %s
                AND `resourcetype`= %s
                AND `privilege` = %s
                AND `subjecttype` = %s
                AND `resourceelement` = %s",
                $subject, $resource, $resourceType, $privilege,
                $subjectType, $resourceElement
            );
        }

        $database->query($sql);
        $database->cacheflush('permissions');
        return $this;
    }

    /**
     * Set a permission
     * @param string $subject
     * @param string $resource
     * @param string $privilege The privilege of the resource
     * that we allow the subject to use
     * @param string $resourceElement If we want to set a
     * permission for a specific element of a resource
     * @param string $resourceType A module, a menu, whatever you want
     * @param string $subjectType Can be a user, a group or whatever
     * else you want
     * @param boolean $value True or false. Otherwise the permission record
     * will be deleted.
     * @return \pramnos_permissions
     */
    protected function setPermission($subject, $resource, $privilege,
            $resourceElement = '', $resourceType = 'module',
            $subjectType = 'user', $value = false)
    {
        if ($value === '' or $value === NULL) {
            return $this->removePermission(
                $subject, $resource, $privilege,
                $resourceElement, $resourceType, $subjectType
            );
        }
        
        $database = \Pramnos\Framework\Factory::getDatabase();
        $database->cacheflush('permissions');
        $this->_cache = array(); // Invalidate local instance cache

        // Delete any existing permission record first (since permissions table lacks a unique index)
        if ($subjectType == 'user') {
            $sql = "DELETE FROM `" . DB_PERMISSIONSTABLE . "` WHERE `userid` = %d AND `resource` = %s AND `resourcetype` = %s AND `privilege` = %s AND `resourceelement` = %s AND `subjecttype` = %s";
            $database->query($database->prepareQuery($sql, $subject, $resource, $resourceType, $privilege, $resourceElement, $subjectType));
        } else {
            $sql = "DELETE FROM `" . DB_PERMISSIONSTABLE . "` WHERE `subject` = %s AND `resource` = %s AND `resourcetype` = %s AND `privilege` = %s AND `resourceelement` = %s AND `subjecttype` = %s";
            $database->query($database->prepareQuery($sql, $subject, $resource, $resourceType, $privilege, $resourceElement, $subjectType));
        }

        $data = [
            ['fieldName' => 'resource', 'value' => $resource, 'type' => 'string'],
            ['fieldName' => 'resourcetype', 'value' => $resourceType, 'type' => 'string'],
            ['fieldName' => 'privilege', 'value' => $privilege, 'type' => 'string'],
            ['fieldName' => 'resourceelement', 'value' => $resourceElement, 'type' => 'string'],
            ['fieldName' => 'subjecttype', 'value' => $subjectType, 'type' => 'string'],
            ['fieldName' => 'value', 'value' => (int) $value, 'type' => 'integer']
        ];
        
        if ($subjectType == 'user') {
            $data[] = ['fieldName' => 'userid', 'value' => (int)$subject, 'type' => 'integer'];
        } else {
            $data[] = ['fieldName' => 'subject', 'value' => $subject, 'type' => 'string'];
        }

        $table = str_replace('#PREFIX#', $database->prefix, DB_PERMISSIONSTABLE);
        $database->insertDataToTable($table, $data);
        $database->cacheflush('permissions');
        
        return $this;
    }

    /**
     * Allow to a subject a privilege on a resource or an element of a resource
     * @param string $subject
     * @param string $resource
     * @param array|string $privilege The privilege of the
     * resource that we allow the subject to use
     * @param string $resourceElement If we want to set a
     * permission for a specific element of a resource
     * @param string $resourceType A module, a menu, whatever you want
     * @param string $subjectType Can be a user, a group or
     * whatever else you want
     * @return \pramnos_permissions
     */
    public function allow($subject, $resource, $privilege,
        $resourceElement = '', $resourceType = 'module', $subjectType = 'user')
    {
        if (is_array($privilege)) { //Quick allow mass privileges
            foreach ($privilege as $priv) {
                $this->allow(
                    $subject, $resource, $priv, $resourceType,
                    $subjectType
                );
            }
            return $this;
        }
        return $this->setPermission(
            $subject, $resource, $privilege,
            $resourceElement, $resourceType, $subjectType, true
        );
    }

    /**
     * Deny to a subject a privilege on a resource or an element of a resource
     * @param string $subject
     * @param string $resource
     * @param array|string $privilege The privilege of the resource that
     * we allow the subject to use
     * @param string $resourceElement If we want to set a permission for
     * a specific element of a resource
     * @param string $resourceType A module, a menu, whatever you want
     * @param string $subjectType Can be a user, a group or whatever
     * else you want
     * @return \pramnos_permissions
     */
    public function deny($subject, $resource, $privilege,
        $resourceElement = '', $resourceType = 'module', $subjectType = 'user')
    {
        if (is_array($privilege)) { //Quick allow mass privileges
            foreach ($privilege as $priv) {
                $this->deny(
                    $subject, $resource, $priv, $resourceType,
                    $subjectType
                );
            }
            return $this;
        }
        return $this->setPermission(
            $subject, $resource, $privilege,
            $resourceElement, $resourceType, $subjectType, false
        );
    }

    /**
     * Check if a subject has access to a privilege of an element
     * of a resource.
     * @param string $subject A user id, a group id, or whatever
     * we want to check for access on something
     * @param string $resource
     * @param string $privilege The privilege of the resource that
     * we check if the subject can use
     * @param string $resourceElement If we want to get a permission
     * for a specific element of a resource
     * @param string $resourceType defaults to module
     * @param string $subjectType defaults to user
     * @param bool $nonExistEqualsFalse If set to true, if a permission
     * doesn't exist, we return false.
     * @return  boolean|NULL
     */
    public function isAllowed($subject, $resource, $privilege,
            $resourceElement = '', $resourceType = 'module',
            $subjectType = 'user', $nonExistEqualsFalse = true)
    {
        if ($nonExistEqualsFalse == false) {
            return $this->_isAllowed(
                $subject, $resource, $privilege,
                $resourceElement, $resourceType, $subjectType, false
            );
        }

        if (isset($this->_cache[$subject][$resource][$privilege]
            [$resourceElement][$resourceType][$subjectType])) {
            return $this->_cache[$subject][$resource][$privilege]
                [$resourceElement][$resourceType][$subjectType];
        }
        $this->_cache[$subject][$resource]
            [$privilege][$resourceElement][$resourceType]
            [$subjectType] = (bool) $this->_isAllowed(
                $subject,
                $resource, $privilege, $resourceElement, $resourceType,
                $subjectType
            );
        return $this->_cache[$subject][$resource][$privilege]
            [$resourceElement][$resourceType][$subjectType];
    }

    /**
     *
     * @param type $subject
     * @param type $resource
     * @param type $privilege
     * @param type $resourceElement
     * @param type $resourceType
     * @param type $subjectType
     * @param bool $nonExistEqualsFalse If set to true,
     * if a permission doesn't exist, we return false.
     */

    /**
     * Which permission store this installation actually has.
     *
     * Cached per instance: schema does not change mid-request, and every
     * permission question would otherwise repeat the same lookup.
     *
     * @return string legacy|authserver|none
     */
    protected function activeStore()
    {
        if ($this->_store !== null) {
            return $this->_store;
        }

        $database = \Pramnos\Framework\Factory::getDatabase();

        // The legacy table wins when present: an installation that has it has
        // been writing to it, and silently reading somewhere else would make its
        // existing grants disappear.
        try {
            $database->query(
                'SELECT 1 FROM ' . DB_PERMISSIONSTABLE . ' LIMIT 1',
                false
            );
            return $this->_store = 'legacy';
        } catch (\Throwable) {
            // Not there — fall through to the maintained system.
        }

        try {
            $database->query('SELECT 1 FROM authserver.permissions LIMIT 1', false);
            return $this->_store = 'authserver';
        } catch (\Throwable) {
            return $this->_store = 'none';
        }
    }

    /**
     * Answer from `authserver.permissions` in the terms this API speaks.
     *
     * The mapping is the same one the migration guide documents: resource is
     * object_type, resourceElement is object_id (empty meaning "any"), privilege
     * is action (with `admin` matching the `*` wildcard), and grant_type gives
     * the boolean. Deny-over-allow is already applied by the resolver.
     *
     * Grants carrying ABAC conditions are skipped: the resolver hands conditions
     * to the application to evaluate against its own request context, and this
     * API has no way to express or receive one — treating a conditional grant as
     * unconditional would hand out access the rule did not give.
     *
     * @return bool|null true allow, false deny, null no matching grant
     */
    protected function _isAllowedFromAuthserver($subject, $resource, $privilege,
        $resourceElement = '', $subjectType = 'user')
    {
        try {
            $resolver = new \Pramnos\Auth\PermissionResolver(
                \Pramnos\Framework\Factory::getDatabase()
            );
            $result = $resolver->resolve((int) $subject, null);
        } catch (\Throwable $ex) {
            \Pramnos\Logs\Logger::logError($ex->getMessage(), $ex);
            return null;
        }

        // The legacy API addresses users and groups; the new one calls the
        // latter roles, and the resolver already folds a user's roles in.
        if ($subjectType !== 'user' && $subjectType !== 'group') {
            return null;
        }

        $verdict = null;
        foreach ($result['permissions'] ?? [] as $grant) {
            if (($grant['object_type'] ?? '') !== $resource) {
                continue;
            }

            $objectId = $grant['object_id'] ?? null;
            if ($resourceElement !== '' && $objectId !== null && $objectId !== $resourceElement) {
                continue;
            }
            if ($resourceElement === '' && $objectId !== null) {
                continue;
            }

            $action = (string) ($grant['action'] ?? '');
            if ($action !== $privilege && $action !== '*'
                && !($privilege === 'admin' && $action === '*')) {
                continue;
            }
            if (($grant['conditions'] ?? null) !== null) {
                continue;
            }

            if (($grant['grant'] ?? '') === 'deny') {
                return false;
            }
            $verdict = true;
        }

        return $verdict;
    }

    public function _isAllowed($subject, $resource, $privilege,
            $resourceElement = '', $resourceType = 'module',
            $subjectType = 'user', $nonExistEqualsFalse = true)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();

        $store = $this->activeStore();

        if ($store === 'authserver') {
            $verdict = $this->_isAllowedFromAuthserver(
                $subject, $resource, $privilege, $resourceElement, $subjectType
            );
            if ($verdict !== null) {
                return $verdict;
            }
            // No matching grant. With the tri-state flag that is "no opinion";
            // otherwise the documented contract is still "unknown means no".
            return $nonExistEqualsFalse == false
                ? null
                : ($this->_defaut[$privilege] ?? false);
        }

        if ($store === 'none') {
            // Nothing to consult. Saying "denied" here is what made a stock
            // installation refuse everything: the caller cannot tell a real deny
            // from a store that was never provisioned.
            return $nonExistEqualsFalse == false
                ? null
                : ($this->_defaut[$privilege] ?? false);
        }

        //If we are dealing with a module and subject is admin of this module,
        // then we can grand access to everything
        if ($resourceType == 'module'
            && ($resourceElement != '' || $privilege != 'admin')) {
            if ($this->_isAllowed(
                $subject, $resource, 'admin', '', 'module', $subjectType
            )) {
                return true;
            }
        }
        $permission = NULL;
        if ($subjectType == 'user' && $subject != "1"
            && $nonExistEqualsFalse == true) {
            //If we check for a user, first check the annonymous user
            $permission = $this->_isAllowed(
                '1', $resource, $privilege,
                $resourceElement, $resourceType, $subjectType
            );
        }
        if ($permission == NULL && $nonExistEqualsFalse == true) {
            //Check for default permissions
            if (isset($this->_defaut[$privilege])) {
                $permission = $this->_defaut[$privilege];
            }
        }

        if ($subjectType == 'user') {
            // First, we have to check if this user has a defined permission

            try {
                $sql = $database->prepareQuery(
                    "select `value` from `" . DB_PERMISSIONSTABLE . "`
                    WHERE `userid` = %d
                    AND `resource` = %s
                    AND `resourcetype`= %s
                    AND `privilege` = %s
                    AND `subjecttype` = %s
                    AND `resourceelement` = %s", $subject, $resource, $resourceType, $privilege,
                    $subjectType, $resourceElement
                );
                $result = $database->query($sql, true, 600, 'permissions');
            }
            catch (\Exception $exc) {
                return false;
            }

            if ($result->numRows != 0) {
                return (bool) $result->fields['value'];
            }
            if ($nonExistEqualsFalse == false) {
                return $permission;
            }
            $user = new \Pramnos\User\User($subject);
            if ($user->userid != 0) {
                $groups = $user->getGroups();

                $deny = false;
                foreach ($groups as $group) {
                    $temp = $this->_isAllowed(
                        $group->group_id, $resource,
                        $privilege, $resourceElement, $resourceType,
                        'group', false
                    );
                    if ($temp === false) {
                        $deny = true;
                    }
                    if ($temp === true) {
                        $permission = true;
                    }
                }
                if ($deny == true) {
                    $permission = false;
                }
            }
            return $permission;
        } else {
            // We are not looking for a user. Just run the check.
            try {
                $sql = $database->prepareQuery(
                    "select `value` from `" . DB_PERMISSIONSTABLE . "`
                    WHERE `subject` = %s
                    AND `resource` = %s
                    AND `resourcetype`= %s
                    AND `privilege` = %s
                    AND `subjecttype` = %s
                    AND `resourceelement` = %s", $subject, $resource, $resourceType, $privilege,
                    $subjectType, $resourceElement
                );
                $result = $database->query($sql, true, 600, 'permissions');
            }
            catch (Exception $exc) {
                return false;
            }

            if ($result->numRows == 0) {
                if ($permission == NULL && $nonExistEqualsFalse == true)
                    return false;
                elseif ($nonExistEqualsFalse == true)
                    return (bool) $permission;
                else
                    return $permission;
            } else {
                return (bool) $result->fields['value'];
            }
        }
    }

    /**
     * Setups the needed database table in case of db storage
     * @param boolean $foreignKeys Setup a foreign key for userid
     */
    public static function setupDb($foreignKeys = true)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        if ($database->type == 'postgresql') {
            $statements = [
                "CREATE TABLE IF NOT EXISTS " . DB_PERMISSIONSTABLE . " (
                    userid bigint DEFAULT NULL,
                    subject varchar(80) DEFAULT NULL,
                    resource varchar(255) NOT NULL DEFAULT '',
                    resourceelement varchar(255) NOT NULL DEFAULT '',
                    value smallint NOT NULL DEFAULT 0,
                    privilege varchar(80) NOT NULL DEFAULT '',
                    resourcetype varchar(80) NOT NULL DEFAULT 'module',
                    subjecttype varchar(80) NOT NULL DEFAULT 'user'
                );",
                "CREATE INDEX IF NOT EXISTS idx_permissions_userid ON " . DB_PERMISSIONSTABLE . " (userid);",
                "CREATE INDEX IF NOT EXISTS idx_permissions_resource ON " . DB_PERMISSIONSTABLE . " (resource);",
                "CREATE INDEX IF NOT EXISTS idx_permissions_resourcetype ON " . DB_PERMISSIONSTABLE . " (resourcetype);"
            ];
            foreach ($statements as $sql) {
                $database->query($database->prepareQuery($sql));
            }
        } else {
            $statements = [
                "CREATE TABLE IF NOT EXISTS `" . DB_PERMISSIONSTABLE . "` (
                `userid` bigint(20) DEFAULT NULL,
                `subject` varchar(80) DEFAULT NULL,
                `resource` varchar(255) NOT NULL DEFAULT '',
                `resourceelement` varchar(255) NOT NULL DEFAULT '',
                `value` TINYINT(1) NOT NULL DEFAULT '0',
                `privilege` varchar(80) NOT NULL DEFAULT '',
                `resourcetype` varchar(80) NOT NULL DEFAULT 'module',
                `subjecttype` varchar(80) NOT NULL DEFAULT 'user',
                KEY `userid` (`userid`),
                KEY `resource` (`resource`),
                KEY `resourcetype` (`resourcetype`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8; "
            ];
            foreach ($statements as $sql) {
                $database->query($database->prepareQuery($sql));
            }
        }

        if ($foreignKeys == true) {
            try {
                if ($database->type == 'postgresql') {
                    $fkSql = "ALTER TABLE " . DB_PERMISSIONSTABLE
                        . " ADD CONSTRAINT permissions_ibfk_1 "
                        . "FOREIGN KEY (userid) REFERENCES #PREFIX#users (userid)  "
                        . "ON DELETE CASCADE ON UPDATE CASCADE;";
                } else {
                    $fkSql = "ALTER TABLE `" . DB_PERMISSIONSTABLE
                            . "` ADD CONSTRAINT `permissions_ibfk_1` "
                            . "FOREIGN KEY (`userid`) REFERENCES `#PREFIX#users` (`userid`)  "
                            . "ON DELETE CASCADE ON UPDATE CASCADE;";
                }
                $database->query($database->prepareQuery($fkSql));
            }
            catch (\Exception $exc) {
                 // Handle or log trace
            }
        }
    }

}
