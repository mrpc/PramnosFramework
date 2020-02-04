<?php
namespace Pramnos\Auth;
/**
 * Authentication class
 * @package     PramnosFramework
 * @copyright   2005 - 2014 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Auth extends \Pramnos\Framework\Base
{

    /**
     * Factory method
     * @staticvar \pramnos_auth $instance
     * @return \pramnos_auth
     */
    public static function &getInstance()
    {
        static $instance=NULL;
        if (!is_object($instance)) {
            $instance = new Auth();
        }
        return $instance;
    }

    /**
     * Logout current user
     */
    public function logout()
    {
        \Pramnos\Addon\Addon::triger('Logout', 'user');
        $_SESSION['logged'] = false;
    }

    /**
     * Runs authentication checks on every authentication module to set user
     * as loged if needed.
     */
    public function authCheck()
    {
        \Pramnos\Addon\Addon::triger('AuthCheck', 'auth');
    }

    /**
     * Authenticate and login
     * @param string $username Username
     * @param string $password Password
     * @param boolean $remember Set cookie to remember user
     * @param boolean $md5password Is the password already an md5 hash?
     * @param boolean $validate
     * @return boolean True on success
     */
    public function auth($username, $password = '',
        $remember = true, $md5password = false, $validate = true)
    {
        //Another method to do that:
        //echo 'Running test: ';
        //var_dump(\Pramnos\Addon\Addon::triger('Auth', 'auth', $username, $password));
        //echo '<br />';
        $addons = \Pramnos\Addon\Addon::getaddons('auth');
        foreach ($addons as $addon) {
            if (method_exists($addon, 'onAuth')) {
                $response = $addon->onAuth(
                    $username, $password, $remember, $md5password, $validate
                );
                if ($response['status'] == true) {
                    #echo "Loged!";
                    //Triger onLogin
                    \Pramnos\Addon\Addon::triger('Login', 'user', $response);
                    return true;
                }
                else {
                    #echo $response['message'];
                }
            }
        }
        return false;
    }

    /**
     * Set a user or a group permitions for an action
     * @todo  Upgrade code to use $db->prepare stuff
     * @param int $id User or Group id
     * @param string $moduletype Type of the module (module/admin)
     * @param string $moduleid id of the module
     * @param string $what Action to set permitions for
     * @param int $elementid Mostly unused
     * @param string $onwhat User/Group
     * @param string $extraflag DEPRECATED
     * @param bool $value The value - 1: Allowed 2: Denied
     * @return int 1: updated permition, 2: inserted permition 0: Error
     */
    function setaccess($id, $moduletype, $moduleid, $what,
        $elementid, $onwhat, $extraflag, $value)
    {
        $permissions = pramnos_factory::getPermissions();
        if ($value == 1) {
            $permissions->allow(
                $id, $moduleid, $what, $elementid, $moduletype, $onwhat
            );
        }
        elseif ($value == 2) {
            $permissions->removePermission(
                $id, $moduleid, $what, $elementid, $moduletype, $onwhat
            );
        }
        else {
            $permissions->deny(
                $id, $moduleid, $what, $elementid, $moduletype, $onwhat
            );
        }
    }

    /**
     * Check if a user or a group is permited for an action.
     * @see groupaccess
     * @global array $config
     * @param int $userid User id or Group id
     * @param string $moduletype
     * @param string $moduleid
     * @param string $what (what action to check for)
     * @param int $elementid Mostly unused
     * @param string $check User/Group
     * @return bool True if user has access
     * @todo Some caching to avoid multiple database queries
     */
    function useraccess($userid, $moduletype, $moduleid,
        $what = 'read', $elementid = '', $check = 'user')
    {
        $permissions = & pramnos_factory::getPermissions();
        return $permissions->isAllowed(
            $userid, $moduleid, $what, $elementid, $moduletype, $check
        );
    }

    /**
     * Check a group's permitions
     * @global array $config
     * @param int $groupid
     * @param string $moduletype
     * @param string $moduleid
     * @param string $what
     * @param int $elementid
     * @return int 0=deny 1=grand 2=not specified
     */
    function groupaccess($groupid, $moduletype, $moduleid,
        $what = 'read', $elementid = '')
    {
        $permissions = & pramnos_factory::getPermissions();
        return $permissions->isAllowed(
            $groupid, $moduleid, $what, $elementid, $moduletype, 'group'
        );
    }

}