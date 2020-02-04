<?php

namespace Pramnos\Application;

/**
 * The main settings class. Can be used as static or be a parrent class.
 * @package        PramnosFramework
 * @subpackage     Application
 * @copyright      Copyright (C) 2005 - 2020 Yannis - Pastis Glaros, Pramnos Hosting
 * @author         Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Settings extends \Pramnos\Framework\Base
{
    /**
     * The settings array
     * @var array
     */
    static protected $settings = array();
    /**
     * Is a settings file loaded?
     * @var bool
     */
    static protected $loaded = false;

    /**
     * Database to access dynamic settings
     * @var \Pramnos\Database\Database
     */
    static protected $database = null;



    /**
     * Initialize the settings object
     * @param string $file Settings file to load
     * @param string $onNoSettings function to run if no settings file is found
     * @param array $args Arguments to pass to the onNoSettings function
     */
    public function __construct($file = '', $onNoSettings = '', $args = array())
    {
        if (self::$loaded == false) {
            self::loadSettings($file, $onNoSettings, $args);
        }
        parent::__construct();
    }

    /**
     * Singleton factory method
     * @staticvar Settings $instance
     * @param string $file Settings file to load
     * @param string $onNoSettings function to run if no settings file is found
     * @param array $args Arguments to pass to the onNoSettings function
     * @return \Pramnos\Application\Settings
     */
    public static function &getInstance($file = '', $onNoSettings = '',
        $args = array())
    {
        static $instance = null;
        if (!is_object($instance)) {
            $instance = new Settings($file, $onNoSettings, $args);
        }
        return $instance;
    }

    /**
     * Load the global settings file
     * @param string $file Settings file to load
     * @param string $onNoSettings function to run if no settings file is found
     * @param array $args Arguments to pass to the onNoSettings function
     * @return boolean
     */
    public static function loadSettings(
        $file = '', $onNoSettings = '', $args = array()
    )
    {
        if ($file === '') {
            if (defined('CONFIG')) {
                $file = ROOT . DS . CONFIG . DS . 'settings.php';
            } else {
                $file = ROOT . DS . 'app'
                    . DS . 'settings' . DS . 'settings.php';
            }
        }
        if (file_exists($file)) {
            $settings = include($file);
            self::$loaded = true;
            foreach ($settings as $key => $value){
                self::setSetting($key, $value, false);
            }
            return true;
        }
        else {
            if (function_exists($onNoSettings)) {
                if (!is_array($args)) {
                    $args = array();
                }
                return call_user_func_array($onNoSettings, $args);
            }
            else {
                return false;
            }
        }
    }

    /**
     * Set the database object
     * @param \Pramnos\Database\Database $database
     * @param bool $loadSettings Should we load the settings from database?
     */
    public static function setDatabase(\Pramnos\Database\Database $database,
        $loadSettings = true)
    {
        self::$database = $database;
    }



    /**
     * Set a setting and it's value. It doesn't record to database
     * @param string $setting Name of the setting
     * @param mixed $value Value of the setting
     */
    public function __set($setting, $value)
    {
        self::setSetting($setting, $value, false);
    }

    /**
     * Get the value of a setting
     * @param string $setting Name of the setting
     * @return mixed The value of the setting or False if it's not set
     */
    public function __get($setting)
    {
        return self::getSetting($setting);
    }

    /**
     * Get a setting
     * @param string $setting Setting to return
     * @param mixed $defaultValue Default value to return if no setting is set
     * @param bool $force Force reloading the setting from database if database
     *                    is set
     * @return mixed Return Value or False if not set
     */
    static function getSetting($setting, $defaultValue = false, $force = false)
    {
        if (isset(self::$settings[$setting]) && $force == false) {
            if (is_array(self::$settings[$setting])) {
                return (object) self::$settings[$setting];
            }
            return self::$settings[$setting];
        }
        if (is_object(self::$database)) {
            $sql = self::$database->prepare(
                "select `value` from `#PREFIX#settings` "
                . " where `setting` = %s limit 1",
                $setting
            );
            $result = self::$database->Execute(
                $sql, true, 600, 'settings'
            );
            if ($result->numRows != 0) {
                self::$settings[$setting] = $result->fields['value'];
                return self::$settings[$setting];
            }
        }



        return $defaultValue;
    }

    /**
     *
     * @param string $setting
     * @param string $value
     * @param bool $writeToDatabase Write the setting to database if exists
     * @return boolean
     */
    static function setSetting($setting, $value, $writeToDatabase = true)
    {
        self::$settings[$setting] = $value;
        if ($writeToDatabase == true && is_object(self::$database)) {

            $sql = $db->prepare("select * from `#PREFIX#settings` where `setting` = %s limit 1", $setting);
            $num = $db->Execute($sql);
            if ($num->numRows != 0) {
                $sql = $db->prepare("update `#PREFIX#settings` set `value` = %s where `setting` = %s limit 1", $value, $setting);
                $return = $db->Execute($sql);
            }
            else {
                $sql = $db->prepare("insert into `#PREFIX#settings`
                    (`setting`, `value`) values (%s, %s)
                    on duplicate key update `value` = %s", $setting, $value, $value);
                $return = $db->Execute($sql);
            }
            $db->flushCache('settings');
        }
    }

    /**
     * Delete a setting
     * @param string $setting
     * @return boolean
     */
    static function deleteSetting($setting)
    {
        return $db->Execute($db->prepare("delete from `#PREFIX#settings` where `setting` = %s limit 1", $setting));
    }

}
