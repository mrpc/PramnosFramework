<?php

namespace Pramnos\Framework;

/**
 * This class provides easy access to all factory methods of the framework
 * and a registry for sigleton pattern.
 * @package     PramnosFramework
 * @copyright   2015 Yannis - Pastis Glaros, Pramnos Hosting
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Factory
{

    /**
     * Get an instance of pramnos_database object or create one
     * This function doesn't need a static variable to store the object because
     * pramnos_database has it's own factory method.
     * @return \Pramnos\Database\Database
     */
    public static function &getDatabase($name = 'default')
    {
        return \Pramnos\Database\Database::getInstance(null, $name);
    }

    /**
     * Get an instance of pramnos_session object or create one
     * @staticvar pramnos_session $instance
     * @return \Pramnos\Http\Session
     */
    public static function &getSession()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = \Pramnos\Http\Session::getInstance();
        }
        return $instance;
    }

    /**
     * Get an instance of pramnos_settings object or create one
     * @staticvar pramnos_settings $instance
     * @return \Pramnos\Application\Settings
     */
    public static function &getSettings()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & \Pramnos\Application\Settings::getInstance();
        }
        return $instance;
    }

    /**
     * Get an instance of pramnos_filesystem object or create one
     * @staticvar pramnos_filesystem $instance
     * @return pramnos_filesystem
     */
    public static function &getFilesystem()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & pramnos_filesystem::getInstance();
        }
        return $instance;
    }


    /**
     * Get an instance of pramnos_cache
     * @param string $category
     * @param string $type
     * @return \pramnos_cache
     */
    public static function getCache($category=NULL, $type=NULL)
    {
        return new pramnos_cache($category, $type);
    }

    /**
     * Get an instance of pramnos_document object or create one
     * @var    string   $type Document Type. For example: pdf. Default is html
     * @var    boolean  $setDefault Set the document type as default
     * @return pramnos_document
     */
    public static function &getDocument($type = '', $setDefault = true)
    {
        return \Pramnos\Document\Document::getInstance($type, $setDefault);
    }





    /**
     * Get pramnos_search
     * @staticvar pramnos_search $instance
     * @return pramnos_search
     */
    public static function &getSearch()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & pramnos_search::getInstance();
        }
        return $instance;
    }

    /**
     * Return a pramnos_html_form object
     * @staticvar pramnos_html_form $instance
     * @return pramnos_html_form
     */
    public static function &getForm()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & pramnos_html_form::getInstance();
        }
        return $instance;
    }

    /**
     * Returns a pramnos_permissions object
     * @staticvar pramnos_permissions $instance
     * @param string $storageMethod
     * @return \Pramnos\Auth\Permissions
     */
    public static function &getPermissions($storageMethod = 'database')
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & \Pramnos\Auth\Permissions::getInstance(
                $storageMethod
            );
        }
        return $instance;
    }

    /**
     * Return a pramnos_auth object
     * @staticvar pramnos_auth $instance
     * @return \Pramnos\Auth\Auth
     */
    public static function &getAuth()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & \Pramnos\Auth\Auth::getInstance();
        }
        return $instance;
    }

    /**
     * Return a pramnos_language object
     * @staticvar pramnos_language $instance
     * @param string $lang Website language
     * @return \Pramnos\Translator\Language
     */
    public static function &getLanguage($lang = '')
    {
        static $instance=null;

        if (!is_object($instance)) {
            $instance = \Pramnos\Translator\Language::getInstance($lang);
        }
        return $instance;
    }

    /**
     * Return a pramnos_request object
     * @staticvar pramnos_request $instance
     * @return \Pramnos\Http\Request
     */
    public static function &getRequest()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = \Pramnos\Http\Request::getInstance();
        }
        return $instance;
    }

    /**
     * Return a pramnos_jquery object
     * @staticvar pramnos_jquery $instance
     * @return pramnos_jquery
     */
    public static function &getJquery()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & pramnos_jquery::getInstance();
        }
        return $instance;
    }

    /**
     * Return a pramnos_email object
     * @staticvar pramnos_email $instance
     * @return pramnos_email
     */
    public static function &getEmail()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & pramnos_email::getInstance();
        }
        return $instance;
    }


}
