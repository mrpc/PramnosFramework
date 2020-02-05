<?php

namespace Pramnos\Addon;

/**
 * Parent class for all addons and addon controller.
 * Default addon types are:
 * -auth
 * -content
 * -editors
 * -search
 * -system
 * -user
 * -xmlrpc
 * -apps
 *
 * @package     PramnosFramework
 * @subpackage  Addons
 * @copyright   (c) 2005 - 2015 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Addon extends \Pramnos\Framework\Base
{
    /**
     * Addon name
     * @var string
     */
    public $name = 'addon';
    /**
     * Addon type (or category)
     * @var string
     */
    public $type = 'system';
    protected $_form;
    private static $_addons = array();
    private static $_actions = array();
    private static $_filters = array();

    /**
     * Class constructor
     * @return Addon
     */
    public function __construct()
    {
        if (is_numeric(substr($this->name, 0, 1))) {
            #$this->_form = new pramnos_html_form('_' . $this->name, false);
        } else {
            #$this->_form = new pramnos_html_form($this->name, false);
        }
        parent::__construct();
        return $this;
    }

    /**
     * Hooks a function on to a specific action.
     * @param string $tag The name of the action to which $function_to_add is
     * hooked. Can also be the name of an action inside a theme or plugin file,
     * or the special tag "all", in which case the function will be called for
     * all hooks.
     * @param string $functionToAdd Name of the function you wish to be called.
     * @param integer $priority Used to specify the order in which the
     * functions associated with a particular action are executed. Lower
     * numbers correspond with earlier execution, and functions with the
     * same priority are executed in the order in which they were added to
     * the action.
     * @param integer $acceptedArgs Number of arguments the function accepts.
     * @return boolean True for added, false for already exists
     */
    public static function addAction($tag, $functionToAdd,
        $priority = 10, $acceptedArgs = 1)
    {
        $priorityNumber = (int) $priority;
        if (!isset(Addon::$_actions[$tag])) {
            Addon::$_actions[$tag] = array();
        }
        if (!isset(Addon::$_actions[$tag][$priorityNumber])) {
            Addon::$_actions[$tag][$priorityNumber] = array();
        }

        foreach (Addon::$_actions[$tag][$priorityNumber] as $check) {
            if (isset($check['function'])
                && $check['function'] == $functionToAdd) {
                return false;
            }
        }

        Addon::$_actions[$tag][$priorityNumber][] = array(
            'function' => $functionToAdd,
            'acceptedArgs' => $acceptedArgs,
            'counter' => 0
        );

        ksort(Addon::$_actions[$tag]);
        return true;
    }

    /**
     * Hooks a function on to a specific filter action.
     * @param string $tag The name of the action to which $function_to_add
     * is hooked. Can also be the name of an action inside a theme or plugin
     * file, or the special tag "all", in which case the function will be
     * called for all hooks.
     * @param string $functionToAdd name of the function you wish to be called.
     * @param integer $priority Used to specify the order in which the
     * functions associated with a particular action are executed. Lower
     * numbers correspond with earlier execution, and functions with the
     * same priority are executed in the order in which they were added to
     *  the action.
     * @param integer $acceptedArgs number of arguments the function accepts.
     * @return boolean True for added, false for already exists
     */
    public static function addFilter($tag, $functionToAdd,
        $priority = 10, $acceptedArgs = 1)
    {
        $priorityNumber = (int) $priority;
        if (!isset(Addon::$_filters[$tag])) {
            Addon::$_filters[$tag] = array();
        }
        if (!isset(Addon::$_filters[$tag][$priorityNumber])) {
            Addon::$_filters[$tag][$priorityNumber] = array();
        }

        foreach (Addon::$_filters[$tag][$priorityNumber] as $check) {
            if (isset($check['function'])
                && $check['function'] == $functionToAdd) {
                return false;
            }
        }

        Addon::$_filters[$tag][$priorityNumber][] = array(
            'function' => $functionToAdd,
            'acceptedArgs' => $acceptedArgs,
            'counter' => 0
        );
        ksort(Addon::$_filters[$tag]);
        return true;
    }

    /**
     * Executes a hook created by Addon::addAction
     * @param string $tag The name of the hook.
     * @param mixed $arg
     */
    public static function doAction($tag, $arg = array())
    {
        if (func_num_args() > 3 || !is_array($arg)) {
            $arg = array_slice(func_get_args(), 2);
        }
        if (isset(Addon::$_actions[$tag])) {
            foreach (Addon::$_actions[$tag] as $priority => $act) {
                foreach ($act as $key => $functionToRun) {
                    if (
                            (is_string($functionToRun['function'])
                            && function_exists($functionToRun['function']))

                            ||

                            (is_array($functionToRun['function'])
                                    && count($functionToRun['function']) == 2
                                    && method_exists(
                                        $functionToRun['function'][0],
                                        $functionToRun['function'][1]
                                    ))

                            ) {
                        call_user_func_array(
                            $functionToRun['function'],
                            array_slice(
                                $arg, 0,
                                (int)$functionToRun['acceptedArgs']
                            )
                        );
                        Addon
                        ::$_actions[$tag][$priority][$key]['counter']+=1;
                    } else {
                        if (is_array($functionToRun['function'])) {
                            throw new Exception(
                                "Function "
                                . \Pramnos\General\Helpers::varDumpToString(
                                    $functionToRun['function']
                                ) . " doesn't exist."
                            );
                        } else {
                            throw new Exception(
                                "Function "
                                . $functionToRun['function']
                                . " doesn't exist."
                            );
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * This function removes a function attached to a specified action hook.
     * This method can be used to remove default functions attached to a
     * specific action hook and possibly replace them with a substitute.
     * @param string $tag Action hook to which the function
     * to be removed is hooked.
     * @param string $functionToRemove Name of the function to remove
     * @param integer $priority Priority of the function
     * (as defined when the function was originally hooked).
     * @param integer $acceptedArgs Number of arguments the function accepts.
     * @return boolean
     */
    public function removeAction($tag, $functionToRemove,
        $priority = 10, $acceptedArgs = 1)
    {
        if (isset(Addon::$_actions[$tag][$priority])) {
            foreach (Addon::$_actions[$tag][$priority]
                as $key => $function) {
                if ($function['function'] == $functionToRemove
                    && $function['acceptedArgs'] = $acceptedArgs) {
                    unset(Addon::$_actions[$tag][$priority][$key]);
                    return true;
                }
            }
            return false;
        }
        return false;
    }

    /**
     * This function removes a function attached to a specified action filter
     * hook. This method can be used to remove default functions attached to a
     * specific action hook and possibly replace them with a substitute.
     * @param string $tag The action hook to which the function to
     * be removed is hooked.
     * @param string $functionToRemove The name of the function
     * which should be removed.
     * @param integer $priority The priority of the function
     * (as defined when the function was originally hooked).
     * @param integer $acceptedArgs number of arguments the function accepts.
     * @return boolean
     */
    public function removeFilter($tag, $functionToRemove,
        $priority = 10, $acceptedArgs = 1)
    {
        if (isset(
            Addon::$_filters[$tag][$priority]
        )) {
            foreach (Addon::$_filters[$tag][$priority]
                as $key => $function) {
                if ($function['function'] == $functionToRemove
                    && $function['acceptedArgs'] = $acceptedArgs) {
                    unset(Addon::$_filters[$tag][$priority][$key]);
                    return true;
                }
            }
            return false;
        }
        return false;
    }

    /**
     * Executes a hook created by Addon::addAction
     * @param string $tag   The name of the filter hook.
     * @param string $value Value which the filters hooked to $tag may modify.
     * @param mixed  $arg   One or more additional
     *                      variables passed to functions.
     */
    public static function applyFilters($tag, $value, $arg = array())
    {
        if (func_num_args() > 3 || !is_array($arg)) {
            $arg = array_slice(func_get_args(), 2);
        }
        if (isset(Addon::$_filters[$tag])) {
            foreach (Addon::$_filters[$tag] as $priority => $act) {
                foreach ($act as $key => $functionToRun) {
                    if ((is_string($functionToRun['function'])
                        && function_exists($functionToRun['function'])) ||
                            (
                            is_array($functionToRun['function'])
                            && count($functionToRun['function']) == 2
                            && method_exists(
                                $functionToRun['function'][0],
                                $functionToRun['function'][1]
                            )
                        )
                    ) {
                        $value = call_user_func_array(
                            $functionToRun['function'],
                            array_merge(
                                array($value),
                                array_slice(
                                    $arg, 0,
                                    (int)$functionToRun['acceptedArgs']
                                )
                            )
                        );
                        Addon
                        ::$_filters[$tag][$priority][$key]['counter']+=1;
                    } else {
                        if (is_array($functionToRun['function'])) {
                            throw new Exception(
                                "Function "
                                . \Pramnos\General\Helpers::varDumpToString(
                                    $functionToRun['function']
                                )
                                . " doesn't exist."
                            );
                        } else {
                            throw new Exception(
                                "Function "
                                . $functionToRun['function']
                                . " doesn't exist."
                            );
                        }
                    }
                }
            }
        }
        return $value;
    }

    /**
     * Execute a filter method to modify content
     * @param   string  $action Method to execute
     * @param   string  $type   Type of the addon
     * @param   string  $content Content to parse
     * @return  string
     */
    public static function filter($action, $type, $content)
    {
        $args = func_get_args();
        $action = 'filter' . $action;
        array_shift($args); //Remove action
        array_shift($args); //Remove type
        if ($type !== '') {
            if (isset(self::$_addons[$type])) {
                foreach (self::$_addons[$type] as $addon) {
                    if (method_exists($addon, $action)) {
                        $content = call_user_func_array(
                            array(&$addon, $action), $args
                        );
                    }
                }
            }
        } else {
            foreach (self::getaddons() as $addon) {
                if (method_exists($addon, $action)) {
                    $content = call_user_func_array(
                        array(&$addon, $action), $args
                    );
                }
            }
        }
        return $content;
    }

    /**
     * Execute a method in all the addons of specific type, or if no type
     * is set, execute the method in all addons
     * @param   string  $action Method to execute
     * @param   string  $type   Type of the addon
     * @param   mixed   $args   Arguments to the addon
     * @return  mixed
     */
    public static function triger($action, $type = '')
    {
        $args = func_get_args();
        $action = 'on' . $action;
        array_shift($args); //Remove action
        array_shift($args); //Remove type
        $result = array();
        if ($type !== '') {
            if (isset(self::$_addons[$type])) {
                foreach (self::$_addons[$type] as $addon) {
                    if (method_exists($addon, $action)) {
                        $result[] = call_user_func_array(
                            array(&$addon, $action), $args
                        );
                    }
                }
            }
        } else {
            foreach (self::getaddons() as $addon) {
                if (method_exists($addon, $action)) {
                    $result[] = call_user_func_array(
                        array(&$addon, $action),
                        $args
                    );
                }
            }
        }
        return $result;
    }

    static function isActive($type, $addon)
    {
        if (isset(self::$_addons[$type])) {
            if (isset(self::$_addons[$type][$addon])) {
                return true;
            }
        }
        return false;
    }

    static function getAddon($type, $addon)
    {
        if (isset(self::$_addons[$type])) {
            if (isset(self::$_addons[$type][$addon])) {
                return self::$_addons[$type][$addon];
            }
        }
        return false;
    }

    static function trigerAddon($action, $type, $addon)
    {
        $args = func_get_args();
        $action = 'on' . $action;
        array_shift($args); //Remove action
        array_shift($args); //Remove type
        array_shift($args); //Remove addon
        if (isset(self::$_addons[$type])) {
            if (isset(self::$_addons[$type][$addon])) {
                if (method_exists(self::$_addons[$type][$addon], $action)) {
                    return call_user_func_array(
                        array(&self::$_addons[$type][$addon], $action), $args
                    );
                }
            }
        }
        return false;
    }

    /**
     * Return all addons of a specific type or all addons if no type is set
     * @param   string  $type   Type of addons to return
     * @return  array   An array with all the addon objects
     */
    static function getaddons($type = '')
    {
        if ($type == '') {
            $return = array();
            foreach (self::$_addons as $type => $addon) {
                foreach ($addon as $name => $object) {
                    $return[$name] = $object;
                }
            }
            return $return;
        } else {
            if (isset(self::$_addons[$type])) {
                return self::$_addons[$type];
            } else {
                return array();
            }
        }
    }

    /**
     * Adds a field to the form
     * @param string $name Name of the setting
     * @param string $title Title of the setting (will appear in label)
     * @param string $type Type of the setting. Valid options: textfied,
     * checkbox, number, image, textarea, selectbox, email, url
     * @param string $options Options of selectbox, seperate by comma
     * @param string $description  A little description of the setting
     * @param boolean $required Is the setting required?
     * @param string $default Default value
     * @param string $value A value
     * @return pramnos_settings_field
     */
    public function addSetting($name, $title = NULL, $type = 'textfield',
        $options = NULL, $description = NULL, $required = false,
        $default = NULL, $value = NULL, $multilanguage=false)
    {
        if (is_numeric(substr($name, 0, 1))) {
            $name = '_' . $name;
        }
        $this->_form->addField(
            $name, $title, $type, $options, $description,
            $required, $default, $value, $multilanguage
        );
        return $this;
    }

    /**
     * Remove an addon
     * @param mixed $addon
     * @param string $type
     * @return bool
     */
    static function unload($addon, $type = 'system')
    {
        if (isset(self::$_addons[$type][$addon])) {
            unset(self::$_addons[$type][$addon]);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Load an addon file and register the addon
     * @param   string  $addon  Addon name to load
     * @param   string  $type   Addon type
     * @return  bool    True if addon is loaded and registered
     */
    static function load($addon, $type = 'system')
    {
        if (class_exists($addon)) {
            $addonObject = new $addon;
            $addonObject->type = $type;
            $addonObject->name = (new \ReflectionClass($addonObject))
                ->getShortName();
            if (!isset(self::$_addons[$type][$addon])) {
                self::$_addons[$type][$addon] = & $addonObject;
            }
            return true;
        }
        if (defined('ADDONS_PATH')) {
            $afile = ADDONS_PATH . DS . $type . DS . $addon . '.php';
        } else {
            $afile = ROOT . DS . 'addons' . DS . $type . DS . $addon . '.php';
        }
        if (file_exists($afile)) {
            include_once($afile);
            $classname = 'addon_' . $type . '_' . $addon;
            if (class_exists($classname)) {
                $addonObject = new $classname;
                $addonObject->type = $type;
                $addonObject->name = $addon;
                if (!isset(self::$_addons[$type][$addon])) {
                    self::$_addons[$type][$addon] = & $addonObject;
                }
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * Επιστρέφει ένα property στην τρέχουσα γλώσσα
     * @param  string $property
     * @param  string $language
     * @return string Η τιμή του property
     */
    public function getProperty($property, $language=null)
    {
        if ($language === null) {
            $lang = \Pramnos\Translator\Language::getInstance();
            $language = $lang->currentlang();
        }

        if (isset($this->_form->_multilanguageFields[$language])
            && isset(
                $this->_form->_multilanguageFields[$language][$property]
            )) {
            return $this->_form->_multilanguageFields[$language][$property]
                ->value;
        } else {
            return $this->$property;
        }
    }

}
