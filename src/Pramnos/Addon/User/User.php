<?php

namespace Pramnos\Addon\User;
/**
 * User related actions
 * @package     PramnosFramework
 * @copyright   2005 - 2020 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */

class User extends \Pramnos\Addon\Addon
{

    public function onLogout()
    {

        $database = \Pramnos\Framework\Factory::getDatabase();
        if (isset($_SESSION['username'])) {
            $sql = $database->prepareQuery(
                "DELETE FROM `#PREFIX#sessions` WHERE `uname` = %s",
                $_SESSION['username']
            );

            try {
                $database->query($sql);
            } catch (\Exception $ex) {
                \Pramnos\Logs\Logger::log($ex->getMessage());
            }
        }
        $session = \Pramnos\Framework\Factory::getSession();
        $cookietime = time() - 1;
        $session->cookieset('logged', '', $cookietime);
        $session->cookieset('uid', '', $cookietime);
        $session->cookieset('username', '', $cookietime);
        $session->cookieset('auth', '', $cookietime);
        $session->cookieset('language', '', $cookietime);
        $session->reset();
    }

    /**
     * Set session information
     * @param array $info
     * @return bool
     */
    public function onLogin($info = array())
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $session = \Pramnos\Framework\Factory::getSession();
        $lang = \Pramnos\Framework\Factory::getLanguage();

        if (!isset($info['status'])
            || !isset($info['username'])
            || !isset($info['uid'])
            || !isset($info['email'])
            || !isset($info['auth'])) {
            return false;
        }
        if ($info['status'] != true) {
            return false;
        }
        $_SESSION['logged'] = true;
        $_SESSION['uid'] = $info['uid'];
        $_SESSION['username'] = $info['username'];
        $_SESSION['auth'] = $info['auth'];
        $ctime = time();
        $remoteIp = $_SERVER["REMOTE_ADDR"];

        if ($info['uid'] > 1) {
            $session->cookieset('logged', true);
            $session->cookieset('uid', $info['uid']);
            $session->cookieset('username', $info['username']);
            $session->cookieset('auth', $info['auth']);
            $session->cookieset(
                'language',
                \Pramnos\Application\Settings::getSetting('default_language')
            );
        }


        $sql = $database->prepareQuery(
            "UPDATE `#PREFIX#sessions` "
            . " SET `uname` = %s, "
            . " `time` = %s, "
            . " `host_addr` = %s, "
            . " `guest`='0' "
            . " WHERE `host_addr` = %s",
             $info['username'], $ctime, $remoteIp, $remoteIp
        );
        try {
            $database->query($sql);
        } catch (\Exception $ex) {
            \Pramnos\Logs\Logger::log($ex->getMessage());
        }
        $sqlLastLogin = $database->prepareQuery(
            "UPDATE `#PREFIX#users` "
            . " SET `lastlogin`= %d, "
            . " `language` = %s "
            . " WHERE `userid` = %d",
            time(), $lang->currentlang(), $info['uid']
        );
        $database->query($sqlLastLogin);





        return true;
    }

}
