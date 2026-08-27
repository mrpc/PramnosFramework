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
     * The one language object, or null before anything asked for one.
     *
     * A property rather than a `static` local inside `getInstance()`, because a static
     * local is private to its method — {@see setInstance()} could not have reached one.
     *
     * @var Language|null
     */
    private static ?Language $instance = null;

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
            // The requested language across every candidate directory, then English
            // across every candidate directory. Both loops, in that order, because a
            // project with `app/language/` and no `ROOT/language/` used to reach the
            // English fallback only under the latter — so a missing language file
            // returned false and the page rendered untranslated instead of in English.
            //
            // `en` is tried after `english` for the same reason one step further out:
            // the fallback was a single hardcoded filename, and a project that names its
            // catalogues by ISO code — `en.php`, `el.php` — has no `english.php`, so the
            // fallback found nothing and *no* catalogue was loaded at all. Every key
            // rendered as itself, which looks like a site written in English rather than
            // like a language that was never loaded.
            $loaded = false;
            foreach ([$language, 'english', 'en'] as $candidateLanguage) {
                foreach ($this->languageDirectories() as $directory) {
                    $file = $directory . DS . $candidateLanguage . ".php";
                    if (file_exists($file)) {
                        include $file;
                        $loaded = true;
                        break 2;
                    }
                }
            }

            if (!$loaded) {
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
            } else {
                // An explicit $path that holds neither file falls back to the same
                // candidate list, rather than to ROOT/language alone.
                $loaded = false;
                foreach ([$language, 'english', 'en'] as $candidateLanguage) {
                    foreach ($this->languageDirectories() as $directory) {
                        $file = $directory . DS . $candidateLanguage . ".php";
                        if (file_exists($file)) {
                            include $file;
                            $loaded = true;
                            break 2;
                        }
                    }
                }

                if (!$loaded) {
                    return false;
                }
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
            // A miss is formatted like a hit. The key *is* a translation — the framework's
            // own keys are the English wording — so `_('Tokens: %s', $username)` with no
            // catalogue entry has to come back as `Tokens: alice` and not as the literal
            // `Tokens: %s`. It returned the key unformatted, which put a raw `%s` on the
            // page of every installation that had not translated that one string, and read
            // as a broken template rather than as a missing translation.
            //
            // The hook's answer is formatted for the same reason, and that half was
            // already true: the legacy filter this replaces returned its string raw, so a
            // supplied translation containing `%s` lost the caller's arguments — harmless
            // there only because none of its languages used a placeholder.
            $translation = $this->onMissingString($string);
        } else {
            $translation = $this->_strings[$string];
        }

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
     * Last chance to supply a translation for a key that has none.
     *
     * Returns the key unchanged, which is what `_()` did on its own. It exists so an
     * application can override it instead of maintaining a copy of this whole class —
     * the legacy implementation ran every miss through an addon filter, and the two
     * things a consuming application does with it are neither of them decorative:
     *
     *   - **record the key**, with the file it was found in, to feed a translation tool;
     *   - **serve a dialect**, where a regional variant is a secondary string rather
     *     than a language of its own. Without the hook that region silently reads the
     *     wrong dialect.
     *
     * A framework hook rather than a framework dependency on any addon system: what a
     * subclass does here is its business. Whatever it returns is formatted with the
     * caller's arguments exactly as a stored translation would be — see `_()`.
     *
     * @param  string $string The key that was not found
     * @return string A translation, or the key unchanged
     */
    protected function onMissingString(string $string): string
    {
        return $string;
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
        if (!is_object(self::$instance)) {
            self::$instance = new (self::resolveClass())($lang);
        }

        return self::$instance;
    }

    /**
     * Install a language object as *the* language object.
     *
     * For an application that builds its own — with constructor arguments the framework
     * cannot supply, or a subclass it wants to be certain about. Call it from the
     * bootstrap, before anything translates.
     *
     * Answers a different question from {@see resolveClass()}, which is why both exist:
     * that one is *which class*, declared in configuration; this one is *here is the
     * object*, handed over by code that already has it.
     */
    public static function setInstance(?Language $language): void
    {
        self::$instance = $language;
    }

    /**
     * Forget the current instance, so the next `getInstance()` builds a fresh one.
     *
     * A test that changes the configured class or the active language needs this; without
     * it the first test in a run decides for all of them.
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Which class `getInstance()` should build.
     *
     * ## Why this is not just `new static()`
     *
     * The obvious fix for a hardcoded `new Language($lang)` is `new static($lang)`, and it
     * does work — PHP 8.1 and later share a method's static locals with the inherited
     * copies, so a subclass and the base end up with one object. Verified on 8.5.9.
     *
     * But **which** class you get depends on who asks first:
     *
     *     subclass asks first  -> the subclass, and its overrides run
     *     framework asks first -> the base, and they do not
     *
     * `Factory::getLanguage()` is called from seven places inside the framework. Any one
     * of them running before an application's bootstrap gets the base class — silently,
     * with the same symptom the filing described: a page rendering untranslated. That
     * trades a certain bug for a non-deterministic one, which is worse.
     *
     * So the class is **declared**, not raced for. `app.php` names it:
     *
     * ```php
     * // app/app.php
     * 'language_class' => '\App\Language',
     * ```
     *
     * With nothing declared, `\<namespace>\Language` is tried — the same convention
     * {@see \Pramnos\Application\Application::resolveApplicationClass()} uses for the
     * application class — and this class is the fallback.
     *
     * Read through `currentInstance()`, never `getInstance()`: building an application to
     * find out which language class to build would be a factory call inside a factory
     * call, and this framework has been bitten by that before.
     *
     * @return class-string<Language>
     */
    protected static function resolveClass(): string
    {
        $app = \Pramnos\Application\Application::currentInstance();
        $info = is_object($app) ? ($app->applicationInfo ?? []) : [];

        $declared = is_array($info) ? ($info['language_class'] ?? '') : '';
        if (is_string($declared) && $declared !== '' && class_exists($declared)
            && is_subclass_of($declared, self::class)) {
            return $declared;
        }

        $namespace = is_array($info) ? ($info['namespace'] ?? '') : '';
        if (is_string($namespace) && $namespace !== '') {
            $candidate = '\\' . $namespace . '\\Language';
            if (class_exists($candidate) && is_subclass_of($candidate, self::class)) {
                return $candidate;
            }
        }

        return self::class;
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
        // **A flag has to be web-servable, which is a narrower question than "does the
        // file exist".**
        //
        // Filed as part of FW-020 alongside `load()`, on the grounds that this names
        // `ROOT/language` while everything else searches the candidate directories. Half
        // right: the search was too narrow, but widening it the same way would return a
        // URL for a file no browser can fetch. `app/language/` is not under the document
        // root in the layout `init` creates, and `sURL . 'language/…'` describes exactly
        // one location.
        //
        // So both servable locations are checked and each returns its own URL, and a flag
        // sitting somewhere unservable answers `false` — which is the truth about it.
        $servable = [];
        if (defined('ROOT')) {
            // The web root, where a flag shipped as an asset belongs.
            $servable[sURL . 'assets/flags/' . $lang . '.png']
                = ROOT . DS . 'www' . DS . 'assets' . DS . 'flags' . DS . $lang . '.png';
            // The historical location, still served on an older layout.
            $servable[sURL . 'language/' . $lang . '.png']
                = ROOT . DS . 'language' . DS . $lang . '.png';
        }

        foreach ($servable as $url => $file) {
            if (file_exists($file)) {
                return $url;
            }
        }

        return false;
    }

    /**
     * Where language files may live, most specific first.
     *
     * The list {@see getLanguages()} already searched, extracted so its two neighbours
     * can search the same one. `load()`'s fallbacks and `getFlag()` named
     * `ROOT . DS . 'language'` and nothing else, while the constructor resolves
     * `LANGPATH` or `app/language` — so on the layout `init` generates, the fallbacks
     * pointed at a directory that does not exist.
     *
     * That was worse than a dead fallback in `load()`: the **English default** existed
     * only under `ROOT/language`, so a project laid out the modern way and missing one
     * language file did not fall back to English — it returned `false` and rendered
     * untranslated.
     *
     * @return list<string> Candidate directories, existing or not — callers test each
     */
    protected function languageDirectories(): array
    {
        $candidates = [];

        if ($this->languagePath !== '') {
            $candidates[] = $this->languagePath;
        }
        if (defined('LANGPATH')) {
            $candidates[] = LANGPATH;
        }
        if (defined('ROOT')) {
            $candidates[] = ROOT . DS . 'app' . DS . 'language';
            $candidates[] = ROOT . DS . 'language';
        }

        return array_values(array_unique($candidates));
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
