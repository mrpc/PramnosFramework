<?php

namespace Pramnos\Framework;

/**
 * Basic class. All other classes of the framework must be based on this one.
 * Contains: startpoint protection
 * an array with errors and magic methods to set/get properties.
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
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
     * Magic isset — makes empty() and isset() work correctly for properties
     * stored via __set() (in _data).  Without this, empty($obj->magicProp)
     * always returns true even when the property has a non-empty value.
     * @param string $name
     * @return bool
     */
    public function __isset($name)
    {
        return isset($this->_data[$name]);
    }

    /**
     * Magic unset — removes a property stored in _data so that isset() returns
     * false afterwards.  Completes the __set/__get/__isset quartet.
     * @param string $name
     */
    public function __unset($name)
    {
        unset($this->_data[$name]);
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
        // A flash message exists to survive a redirect, so it needs a session to survive
        // in. The `isset()` guard below used to make this a silent no-op whenever there
        // was none — almost never, because init() always started one. Under lazy sessions
        // it would become common, and an error the user is never shown is worse than a
        // cookie on a page that was about to redirect anyway.
        //
        // The cost of being wrong here is a session on a page that could have been
        // cached, so it was worth checking who actually calls this: all 107 call sites in
        // the framework are controllers flashing before a redirect, none are models on a
        // render path. A page that must stay cacheable should not be flashing.
        \Pramnos\Http\Session::getInstance()->ensureStarted();
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
        // Same reasoning as addError(): a message nobody sees is the worse failure.
        \Pramnos\Http\Session::getInstance()->ensureStarted();
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

            // …or from the per-request capture, if something already drained the session.
            //
            // `Request` captures `_errors` and `_messages` once per request and unsets them
            // immediately, so that a flash survives exactly one redirect. `View::__construct()`
            // triggers that capture on essentially every request — which would leave this
            // method returning `false` for errors that were flashed perfectly well.
            //
            // That is not hypothetical: **consuming applications read their flash through this
            // method**, which is the point of it living on `Base`. It was nearly shipped as a
            // silent regression, where an API response that used to carry `errors` would carry
            // `false` instead, and nothing would have looked wrong.
            $captured = \Pramnos\Http\Request::getInstance()->takeFlashErrors();

            return $captured === array() ? false : $captured;
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

            // …or from the per-request capture, if something already drained the session.
            //
            // `Request` captures `_errors` and `_messages` once per request and unsets them
            // immediately, so that a flash survives exactly one redirect. `View::__construct()`
            // triggers that capture on essentially every request — which would leave this
            // method returning `false` for errors that were flashed perfectly well.
            //
            // That is not hypothetical: **consuming applications read their flash through this
            // method**, which is the point of it living on `Base`. It was nearly shipped as a
            // silent regression, where an API response that used to carry `errors` would carry
            // `false` instead, and nothing would have looked wrong.
            $captured = \Pramnos\Http\Request::getInstance()->takeMessages();

            return $captured === array() ? false : $captured;
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
    protected function _printMessages($class = 'message')
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
    protected function _printErrors($class = 'error')
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

        // …or in the per-request capture, which `Request` drained the session into.
        //
        // **This is the half that was missed the first time.** The destructive readers got the
        // fallback; these gates did not — and a reference application gates *every* flash it
        // displays on them: `if ($this->hasErrors()) { echo $this->_printErrors(); }` in its
        // theme header and in five views. So the whole flash UI went silent, with nothing
        // failing anywhere. Its own 5497-test suite could not see it either; it took three real
        // HTTP requests against two framework versions to find.
        //
        // Non-destructive on purpose: a gate that consumed the flash would leave the printer
        // that follows it with nothing.
        if (\Pramnos\Http\Request::getInstance()->flashErrors() !== array()) {
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

        // …or in the per-request capture, which `Request` drained the session into.
        //
        // **This is the half that was missed the first time.** The destructive readers got the
        // fallback; these gates did not — and a reference application gates *every* flash it
        // displays on them: `if ($this->hasErrors()) { echo $this->_printErrors(); }` in its
        // theme header and in five views. So the whole flash UI went silent, with nothing
        // failing anywhere. Its own 5497-test suite could not see it either; it took three real
        // HTTP requests against two framework versions to find.
        //
        // Non-destructive on purpose: a gate that consumed the flash would leave the printer
        // that follows it with nothing.
        if (\Pramnos\Http\Request::getInstance()->messages() !== array()) {
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