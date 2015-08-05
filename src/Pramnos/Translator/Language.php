<?php

namespace Pramnos\Http;

use Pramnos\Framework\Base;

/**
 * Language / translation functions
 * @package     PramnosFramework
 * @subpackage  Language
 * @copyright   2005 - 2015 Yannis - Pastis Glaros, Pramnos Hosting
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 */
class Language extends Base
{

    /**
     * Current language
     * @var string
     */
    private $_lang = 'english';
    /**
     * Array of all translation strings
     * @var array
     */
    private $_strings = array();

    /**
     * If a language is set, load it
     * @param string $lang
     */
    function __construct($lang = '')
    {
        if ($lang <> '') {
            $this->_lang = $lang;
            $this->load($lang);
        } else {
            $this->load($this->_lang);
        }
        parent::__construct();
    }

    /**
     * Return the strings array
     * @return array
     */
    public function getlang()
    {
        return $this->_strings;
    }

    /**
     * Set current language
     * @param string $language
     * @return \pramnos_language
     * @throws Exception if $language is not string
     */
    public function setLang($language = 'english')
    {
        if (!is_string($language)) {
            throw new Exception(
                'Method pramnos_language_setLang accepts strings, '
                . gettype($language)
                . ' given.'
            );
        }
        $this->_lang = $language;
        return $this;
    }

    /**
     * Merge the language strings array to a new one
     * @param array $strings an array with language strings
     */
    public function addlang($strings)
    {
        $this->_strings = array_merge($this->_strings, $strings);
    }

    /**
     * Load a language file
     * @param string $language  Language to load
     * @param string $path      Path to load from
     * @param bool $setDefault  Set this language as default
     * @return bool
     */
    public function load($language = '', $path = '', $setDefault=true)
    {
        if ($language == '') {
            $language = $this->_lang;
        }

        if ($path == '') {
            if (file_exists(
                ROOT . DS . "language" . DS . $language . ".php"
            )) {
                include ROOT . DS . "language" . DS . $language . ".php";
            } elseif (
                file_exists(
                    ROOT . DS . "language" . DS . 'english' . ".php"
                )) {
                //Load the default language strings if current language
                //does not exist
                include ROOT . DS . "language" . DS . 'english' . ".php";
            } else {
                return false;
            }
        } else {
            if (file_exists(
                $path . DS . "language" . DS . $language . ".php"
            )) {
                include $path . DS . "language" . DS . $language . ".php";
            } elseif (file_exists(
                $path . DS . "language" . DS . 'english' . ".php"
            )) {
                //Load the default language strings if current language
                //does not exist
                include $path . DS . "language" . DS . 'english' . ".php";
            } elseif (file_exists(
                ROOT . DS . "language" . DS . $language . ".php"
            )) {
                include ROOT . DS . "language" . DS . $language . ".php";
            } elseif (file_exists(
                ROOT . DS . "language" . DS . 'english' . ".php"
            )) {
                //Load the default language strings if current language
                //does not exist
                include ROOT . DS . "language" . DS . 'english' . ".php";
            } else {
                return false;
            }
        }
        if (isset($lang)) {
            if ($setDefault == true) {
                $this->setLang($language);
            }
            $this->addlang($lang);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Return the translation of a string if exists, or the string itself if
     * there is no translation
     * @param string $string
     * @param mixed $args
     * @return string
     */
    public function _($string = '', $args = '')
    {
        if (isset($this->_strings[$string])) {
            $_args = func_get_args();
            array_shift($_args);
            return sprintf($this->_strings[$string], $_args);
        } else {
            return $string;
        }
    }

    /**
     * Return the current language
     * @return string
     */
    public function currentlang()
    {
        return $this->_lang;
    }

    /**
     * Factory method
     * @staticvar pramnos_language $instance
     * @param <strung $lang
     * @return pramnos_language
     */
    public static function &getInstance($lang = '')
    {
        static $instance=NULL;
        if (!is_object($instance)) {
            $instance = new pramnos_language($lang);
        }
        return $instance;
    }

    /**
     * Get the flag icon of a language
     * @param string $lang The language. If left empty, function returns current
     * active languages flag.
     * @return boolean|string
     */
    public function getFlag($lang = '')
    {
        if ($lang == '') {
            $lang = $this->_lang;
        }
        if (file_exists(ROOT . DS . 'language' . DS . $lang . '.png')) {
            return sURL . 'language/' . $lang . '.png';
        } else {
            return false;
        }
    }

    /**
     * Returns an array with all available languages
     * @return array
     */
    public static function getLanguages()
    {
        $langdir = ROOT . DS . "language";
        if (is_dir($langdir)) {

            $directoryHandler = @opendir($langdir);

            $list = array();
            while (false !== ($filename = readdir($directoryHandler))) {
                $files[] = $filename;
            }
            foreach ($files as $file) {
                if (is_file($langdir . DS . $file)
                    && strpos($file, '.php') !== false) {
                    $list[] = str_replace(".php", "", $file);
                }
            }
            return $list;
        } else {
            throw new Exception('Languages directory does not exist');
        }
    }

}
