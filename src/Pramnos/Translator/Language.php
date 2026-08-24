<?php

namespace Pramnos\Translator;

use Pramnos\Framework\Base;

/**
 * Language / translation functions
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
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
     * Language files path
     * @var string
     */
    private $languagePath = '';

    /**
     * If a language is set, load it
     * @param string $lang
     * @param string $path Default language path
     */
    function __construct($lang = '', $path = null)
    {
        if ($path != null) {
            $this->languagePath = $path;
        } else {
            if (defined('LANGPATH')) {
                $this->languagePath = LANGPATH;
            } else {
                $this->languagePath = ROOT . DS . "app" . DS . "language";
            }
        }
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
     * @return Language
     * @throws \Exception if $language is not string
     */
    public function setLang($language = 'english')
    {
        if (!is_string($language)) {
            throw new \Exception(
                'Method Language::setLang accepts strings, '
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
                $this->languagePath . DS . $language . ".php"
            )) {
                include $this->languagePath . DS . $language . ".php";

            } elseif (file_exists(
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
     * there is no translation.
     *
     * Any arguments after the key are used to format the translation, in the
     * printf sense:
     *
     * <code>
     * $lang->_('%s is on air');            // no arguments — returned as it is
     * $lang->_('%s is on air', 'Aroma');   // 'Aroma is on air'
     * </code>
     *
     * **Formatting only happens when arguments are given.** That is not a
     * detail: this method used to call sprintf() unconditionally, and with an
     * *array* as its single argument. Both halves were wrong and they
     * compounded. A translation containing '%s' looked up with no arguments
     * became sprintf('%s', []) — an ArgumentCountError, fatal on PHP 8 — and a
     * caller that did pass arguments got 'Array' printed for the first
     * placeholder. So a key with a placeholder could not be looked up at all,
     * and only once a translation for it existed: the string worked in
     * development against the source language and answered 500 the day the
     * language file gained the key.
     *
     * A mismatch between the placeholders in a translation and the arguments a
     * call site passes is caught rather than raised. Translations are content,
     * edited by translators, and a stray '%s' in a language file must not be
     * able to take a page down; the unformatted translation is returned and the
     * mismatch is logged.
     *
     * @param string $string Key to translate.
     * @param mixed  $args   First format argument; further arguments are read
     *                       with func_get_args().
     * @return string
     */
    public function _($string = '', $args = '')
    {
        if (!isset($this->_strings[$string])) {
            return $string;
        }

        $translation = $this->_strings[$string];

        $_args = func_get_args();
        array_shift($_args);
        if ($_args === []) {
            return $translation;
        }

        try {
            return vsprintf($translation, $_args);
        } catch (\ArgumentCountError | \ValueError $e) {
            \Pramnos\Logs\Logger::logError(
                'Translation of "' . $string . '" could not be formatted: '
                . $e->getMessage() . ' — the translation was returned '
                . 'unformatted. Check that its placeholders match the '
                . 'arguments the call site passes.',
                $e
            );

            return $translation;
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
     * @staticvar Language $instance
     * @param <strung $lang
     * @return Language
     */
    public static function &getInstance($lang = '')
    {
        static $instance=NULL;
        if (!is_object($instance)) {
            $instance = new Language($lang);
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
     * Every language this installation ships, by name.
     *
     * **Looks where {@see load()} looks.** It used to scan `ROOT/language` and
     * nothing else, while `load()` reads `LANGPATH`, then `app/language`, then
     * `ROOT/language` — so on the layout `init` actually generates, this method
     * threw "Languages directory does not exist" for a project with a perfectly
     * good catalogue in `app/language/`. Anything asking *which* languages exist
     * was told none, and then a caller offering a language picker had nothing to
     * put in it while `_()` was translating happily.
     *
     * All the directories are scanned and the results merged, because a project
     * may legitimately have both: `app/language/` for its own strings and
     * `ROOT/language/` inherited from an older layout.
     *
     * @return array<int, string> Language names, sorted, without the .php
     * @throws \Exception When no language directory exists at all.
     */
    public static function getLanguages()
    {
        $directories = [];
        if (defined('LANGPATH')) {
            $directories[] = LANGPATH;
        }
        $directories[] = ROOT . DS . 'app' . DS . 'language';
        $directories[] = ROOT . DS . 'language';

        $found = [];
        $anyDirectory = false;
        foreach (array_unique($directories) as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            $anyDirectory = true;
            foreach ((array) glob($directory . DS . '*.php') as $file) {
                if (is_file($file)) {
                    $found[basename($file, '.php')] = true;
                }
            }
        }

        if (!$anyDirectory) {
            throw new \Exception('Languages directory does not exist');
        }

        $list = array_keys($found);
        sort($list);

        return $list;
    }

}
