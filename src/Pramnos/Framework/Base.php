<?php

namespace Pramnos\Framework;

/**
 * Basic class. All other classes of the framework must be based on this one.
 * Contains: startpoint protection
 * an array with errors and magic methods to set/get properties.
 * @package     PramnosFramework
 * @copyright   2005 - 2015 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Base
{

    public $_errors = array();
    public $_messages = array();
    protected $_data = array();
    protected $_parentObject = NULL;

    /**
     * Set an object as parent object for trees
     * @param object $object\
     */
    public function _setParentObject(&$object)
    {
        $this->_parentObject = &$object;
    }

    /**
     * Get the parent object, if set with _setParentObject
     * @return object
     */
    public function &_getParentObject()
    {
        return $this->_parentObject;
    }

    /**
     * Magic method overload to set a property to the object
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        $this->_data[$name] = $value;
    }

    /**
     * Magic method overload to get a property. If it doesn't exist,
     * it returns  null.
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        if (isset($this->_data[$name])) {
            return $this->_data[$name];
        }
        else {
            return null;
        }
    }

    /**
     * Magic method to give build-in protection against direct calls to all
     * classes.
     */
    function __construct()
    {
        #defined('SP') or die('No startpoint defined...');
    }

    /**
     * Add an error to session storage
     * @param string $error
     * @return $this
     */
    protected function addError($error)
    {
        $this->_errors[] = $error;
        if (isset($_SESSION)) {
            $_SESSION['_errors'] = $this->_errors;
        }
        return $this;
    }

    /**
     * Add a message to session storage
     * @param string $message
     * @return $this
     */
    protected function addMessage($message)
    {
        $this->_messages[] = $message;
        if (isset($_SESSION)) {
            $_SESSION['_messages'] = $this->_messages;
        }
        return $this;
    }
    /**
     * Returns an array of errors or false if no messages exist
     * @param bool $session Check in session data
     * @return array|boolean
     */
    protected function _getErrors($session = true)
    {
        if ($session == true && isset($_SESSION)) {
            if (isset($_SESSION['_errors'])
                && is_array($_SESSION['_errors'])) {
                $return = $_SESSION['_errors'];
                unset($_SESSION['_errors']);
                return $return;
            }
            else {
                return false;
            }
        }
        else {
            if (count($this->_errors) == 0) {
                return false;
            }
            return $this->_errors;
        }
    }

    /**
     * Returns an array of messages or false if no messages exist
     * @param bool $session Check in session data
     * @return array|boolean
     */
    protected function _getMessages($session = true)
    {
        if ($session == true && isset($_SESSION)) {
            if (isset($_SESSION['_messages'])
                && is_array($_SESSION['_messages'])) {
                $return = $_SESSION['_messages'];
                unset($_SESSION['_messages']);
                return $return;
            }
            else {
                return false;
            }
        }
        else {
            if (count($this->_messages) == 0) {
                return false;
            }
            return $this->_messages;
        }
    }

    /**
     * Display all messages
     * @param string $class
     * @return string
     */
    protected function _printMessages($class = 'pramnosMessage')
    {
        $return = '';
        $messages = $this->_getMessages();
        if ($messages != false) {
            foreach ($messages as $message) {
                $return .= '<span class="'
                    . $class
                    . '">'
                    . $message
                    . "</span>";
            }
        }
        return $return;
    }

    /**
     * Display all errors
     * @param string $class
     * @return string
     */
    protected function _printErrors($class = 'pramnosError')
    {
        $return = '';
        $messages = $this->_getErrors();
        if ($messages != false) {
            foreach ($messages as $message) {
                $return .= '<span class="'
                    . $class
                    . '">'
                    . $message
                    . "</span>";
            }
        }
        return $return;
    }

    /**
     * Check if there is any reported error.
     * @return boolean
     */
    protected function hasErrors()
    {
        if (isset($_SESSION['_errors'])
            && is_array($_SESSION['_errors'])
            && count($_SESSION['_errors']) > 0) {
            return true;
        }
        if (count($this->_errors) != 0) {
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * Check if there is any message to display.
     * @return boolean
     */
    protected function hasMessages()
    {
        if (isset($_SESSION['_messages'])
            && is_array($_SESSION['_messages'])
            && count($_SESSION['_messages']) > 0) {
            return true;
        }

        if (count($this->_messages) != 0) {
            return true;
        }
        else {
            return false;
        }
    }

    /**
     * Set a class parameter and return $this. Useful for better code syntax.
     * @param string $field
     * @param mixed $value
     * @return pramnos_base
     */
    public function _set($field, $value)
    {
        $this->$field = $value;
        return $this;
    }

}