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
     * @return pramnos_database
     */
    public function &getDatabase($name = 'default')
    {
        return pramnos_database::getInstance($name);
    }

    /**
     * Get an instance of pramnos_session object or create one
     * @staticvar pramnos_session $instance
     * @return pramnos_session
     */
    public function &getSession()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & pramnos_session::getInstance();
        }
        return $instance;
    }

    /**
     * Get an instance of pramnos_settings object or create one
     * @staticvar pramnos_settings $instance
     * @return pramnos_settings
     */
    public function &getSettings()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & pramnos_settings::getInstance();
        }
        return $instance;
    }

    /**
     * Get an instance of pramnos_filesystem object or create one
     * @staticvar pramnos_filesystem $instance
     * @return pramnos_filesystem
     */
    public function &getFilesystem()
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
    public function getCache($category=NULL, $type=NULL)
    {
        return new pramnos_cache($category, $type);
    }

    /**
     * Get an instance of pramnos_document object or create one
     * @var    string   $type Document Type. For example: pdf. Default is html
     * @var    boolean  $setDefault Set the document type as default
     * @return pramnos_document
     */
    public function &getDocument($type = '', $setDefault = true)
    {
        return pramnos_document::getInstance($type, $setDefault);
    }

    /**
     * Get an instance of pramnos_theme object or create one
     * @param string $theme
     * @param string $path
     * @return pramnos_theme
     */
    public function getTheme($theme = 'default', $path = '')
    {
        return pramnos_theme::getTheme($theme, $path);
    }

    /**
     * Return a pramnos_html object
     * @staticvar pramnos_html $instance
     * @return pramnos_html
     */
    public function &getHtml()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & pramnos_html::getInstance();
        }
        return $instance;
    }

    /**
     * Get pramnos_search
     * @staticvar pramnos_search $instance
     * @return pramnos_search
     */
    public function &getSearch()
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
    public function &getForm()
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
     * @return pramnos_permissions
     */
    public function &getPermissions($storageMethod = 'database')
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & pramnos_permissions::getInstance($storageMethod);
        }
        return $instance;
    }

    /**
     * Return a pramnos_auth object
     * @staticvar pramnos_auth $instance
     * @return pramnos_auth
     */
    public function &getAuth()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & pramnos_auth::getInstance();
        }
        return $instance;
    }

    /**
     * Return a pramnos_language object
     * @staticvar pramnos_language $instance
     * @param string $lang Website language
     * @return pramnos_language
     */
    public function &getLanguage($lang = '')
    {
        static $instance=null;

        if (!is_object($instance)) {
            $instance = & pramnos_language::getInstance($lang);
        }
        return $instance;
    }

    /**
     * Return a pramnos_request object
     * @staticvar pramnos_request $instance
     * @return pramnos_request
     */
    public function &getRequest()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & pramnos_request::getInstance();
        }
        return $instance;
    }

    /**
     * Return a pramnos_jquery object
     * @staticvar pramnos_jquery $instance
     * @return pramnos_jquery
     */
    public function &getJquery()
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
    public function &getEmail()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & pramnos_email::getInstance();
        }
        return $instance;
    }


}
