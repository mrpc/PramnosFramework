<?php
namespace Pramnos\General;
/**
 * Includes all validation and filter functions
 * @package     PramnosFramework
 * @subpackage  Validation
 */
class Validator extends \Pramnos\Framework\Base
{

    /**
     * Check if an email address is valid and sanitize it
     * @todo   PHP has a built in function called checkdnsrr() which will
     *         take an  email address and check if it resolves as an IP
     *         address. This is very  cool when sending emails for example.
     *         Should checkdnsrr() return false  while you are trying to send
     *         an email with this function, you can return  an error informing
     *         the user that the domain probably doesn't exist  before you do
     *         anything else.
     * @param  string $email
     * @return mixed  Returns the sanitized email address or FALSE for invalid
     */
    public static function checkEmail($email)
    {
        $emailFixed = filter_var(
            strtolower(trim($email)), FILTER_SANITIZE_EMAIL
        );

        if (!filter_var($emailFixed, FILTER_VALIDATE_EMAIL)) {
            return false;
        } else {
            return $emailFixed;
        }
    }

    /**
     * Check if a string is a valid JSON
     * @param string $string
     * @return boolean true if the string is a valid JSON
     */
    public static function isJson($string)
    {
        if (version_compare(PHP_VERSION, '8.3.0', '>=')) {
            return json_validate($string);
        }
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    /**
     * Check if a link is valid
     * @param string $url Url to parse
     * @return boolean true if the url is valid
     */
    public static function checkLink($url)
    {
        $urlToCheck = trim($url);
        $reg_exUrl = "/^(http|https|ftp|ftps)\:\/\/(.*)/i";
        // Check if there is a url in the text
        if (!preg_match($reg_exUrl, $urlToCheck)) {
            $urlToCheck = "http://" . $urlToCheck;
        }
        $finalUrl = filter_var($urlToCheck, FILTER_SANITIZE_URL);
        //FILTER_VALIDATE_URL allows url without dot
        if (strpos($finalUrl, ".") == 0) {
            return false;
        }
        if (!filter_var($finalUrl, FILTER_VALIDATE_URL)) {
            return false;
        }
        return $finalUrl;
    }

    /**
     * Factory function
     * @staticvar Validator $instance
     * @return Validator
     */
    public static function &getInstance()
    {
        static $instance;
        if (!is_object($instance)) {
            $instance = new Validator();
        }
        return $instance;
    }

}