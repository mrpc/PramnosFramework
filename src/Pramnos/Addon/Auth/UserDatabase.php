<?php
namespace Pramnos\Addon\Auth;
/**
 * Καταγράφει την τρέχουσα κίνηση του site και δημιουργεί logs για ότι
 * χρειάζεται
 * @package     PramnosFramework
 * @copyright   2005 - 2020 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */

class UserDatabase extends \Pramnos\Addon\Addon
{

    /**
     *
     * @param string $username
     * @param string $password
     * @param boolean $remember
     * @param boolean $encryptedPassword
     * @return array
     */
    public function onAuth($username, $password,
        $remember = true, $encryptedPassword = false)
    {
        $database = \Pramnos\Framework\Factory::getDatabase();
        $return = array();
        $return['status'] = false;
        $return['statusCode'] = 0;
        $return['message'] = '';
        $return['username'] = '';
        $return['uid'] = '';
        $return['auth'] = '';
        $return['email'] = '';
        $return['remember'] = $remember;
        $sql = $database->prepareQuery(
            "SELECT `userid`, `username`, `password`, `email`, "
            . " `active`, `validated` "
            . "FROM `#PREFIX#users` "
            . "WHERE ( `username` = %s or `email` = %s) "
            . "  limit 1",
            $username, $username
        );

        $result = $database->query($sql);
        if ($result->numRows == 0) {
            $return['message'] = "User doesn't exist";
            $return['statusCode'] = 404;
            return $return;
        }
        $checkPassword = $password;
        $pwd = $password
            . md5(
            \Pramnos\Application\Settings::getSetting('securitySalt')
            . $result->fields['userid']
        );
        if ($encryptedPassword == false) {
            $checkPassword = password_hash($pwd, PASSWORD_DEFAULT);
        }
        if ($result->fields['active'] == 0 && $result->fields['active'] != 't') {
            $return['message'] = 'Inactive User';
            $return['statusCode'] = 0;
            return $return;
        }
        if ($result->fields['active']  == 2) {
            $return['message'] = 'Deleted User';
            $return['statusCode'] = 2;
            return $return;
        }
        if ($result->fields['active']  == 5) {
            $return['message'] = 'Banned User';
            $return['statusCode'] = 5;
            return $return;
        }
        if ($result->fields['validated']  == 0) {
            $return['message'] = 'Please Validate your email';
            $return['statusCode'] = 0;
            return $return;
        }
        if (password_verify($pwd, $result->fields['password'])
            && !$encryptedPassword) {
            $return['status'] = true;
            $return['statusCode'] = $result->fields['active'];
            $return['username'] = $result->fields['username'];
            $return['uid'] = $result->fields['userid'];
            $return['email'] = $result->fields['email'];
            $return['auth'] = $result->fields['password'];
            return $return;
        } elseif (md5($password) == $result->fields['password']) {
            $return['status'] = true;
            $return['statusCode'] = $result->fields['active'];
            $return['username'] = $result->fields['username'];
            $return['uid'] = $result->fields['userid'];
            $return['email'] = $result->fields['email'];
            $return['auth'] = $result->fields['password'];
            return $return;
        } elseif ($encryptedPassword
            && $checkPassword == $result->fields['password']) {
            $return['status'] = true;
            $return['statusCode'] = $result->fields['active'];
            $return['username'] = $result->fields['username'];
            $return['uid'] = $result->fields['userid'];
            $return['email'] = $result->fields['email'];
            $return['auth'] = $result->fields['password'];
            return $return;
        } else {
            $return['message'] = 'Wrong Password!';
            $return['statusCode'] = 400;
            return $return;
        }
    }

    /**
     * Αν ο χρήστης έχει cookie αλλά δεν έχει κάνει login
     */
    public function onAuthCheck()
    {
        $session = \Pramnos\Framework\Factory::getSession();
        $auth = \Pramnos\Framework\Factory::getAuth();
        if ($session->cookieget('auth') !== null
            && $session->cookieget('username') !== null) {

            $auth->auth(
                $session->cookieget('username'),
                $session->cookieget('auth'), true, true
            );
        }
    }

}
