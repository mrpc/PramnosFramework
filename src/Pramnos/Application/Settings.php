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

    public static $prefix = "pramnos_";
    static private $sharedsettings = array();
    private $settings = array();
    static private $loaded = false;

    /**
     * A settings handler to overide the default functions
     * @var mixed
     */
    static private $handler = NULL;

    function __construct($file = '', $onNoSettings = '', $args = array())
    {
        parent::__construct();
        if ($this->loaded == false) {
            self::loadSettings($file, $onNoSettings, $args);
        }
    }

    public static function &getInstance($file = '', $onNoSettings = '', $args = array())
    {
        static $instance;
        if (!is_object($instance)) {
            $instance = new Settings($file, $onNoSettings, $args);
        }
        return $instance;
    }

    public static function loadSettings($file = '', $onNoSettings = '', $args = array())
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
            include($file);
            self::$loaded = true;
            self::baseset("debuglevel", $debuglevel);
            self::baseset("db_server", $hostname_dbconnection);
            self::baseset("db_database", $database_dbconnection);
            self::baseset("db_user", $username_dbconnection);
            self::baseset("db_password", $password_dbconnection);
            self::baseset("db_prefix", $prefix_dbconnection . "_");
            self::baseset("db_collation", $collation_dbconnection);
            self::baseset("db_persistency", false);
            self::$prefix = self::baseget("db_prefix");
            self::baseset("smtp_host", $smtp_host);
            self::baseset("smtp_user", $smtp_user);
            self::baseset("smtp_pass", $smtp_pass);
            if (isset($settings) && is_array($settings)) {
                foreach ($settings as $key => $value) {
                    self::baseset($key, $value);
                }
            }

            if (isset($collation_dbconnection)) {
                self::baseset("db_collation", $collation_dbconnection);
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
     * Set a shared static setting
     * @param string $setting
     * @param mixed $value
     */
    public static function baseset($setting, $value)
    {
        self::$sharedsettings[$setting] = $value;
    }

    /**
     *
     * Return a static setting
     * @param string $setting
     * @return mixed The value of the setting
     */
    public static function baseget($setting)
    {
        if (isset(self::$sharedsettings[$setting])) {
            return self::$sharedsettings[$setting];
        }
        else {
            return false;
        }
    }

    /**
     * Set a setting and it's value
     * @param string $setting Name of the setting
     * @param mixed $value Value of the setting
     */
    function __set($setting, $value)
    {
        $this->settings[$setting] = $value;
    }

    /**
     * Get the value of a setting
     * @param string $setting Name of the setting
     * @return mixed The value of the setting or False if it's not set
     */
    function __get($setting)
    {
        if (isset($this->settings[$setting])) {
            return $this->settings[$setting];
        }
        else {
            return false;
        }
    }

    /**
     * Get a setting
     * @param string $setting
     * @return mixed Return Value or False if not set
     */
    static function getSetting($setting)
    {
        $db = &pramnos_factory::getDatabase();
        $sql = $db->prepare("select `value` from `#PREFIX#settings` where `setting` = %s limit 1", $setting);
        $result = $db->Execute($sql, true, 600, 'settings');
        if ($result->numRows != 0) {
            return $result->fields['value'];
        }
        return false;
    }

    /**
     *
     * @param string $setting
     * @param string $value
     * @return boolean
     */
    static function setSetting($setting, $value)
    {
        $db = &pramnos_factory::getDatabase();

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
        return $return;
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
