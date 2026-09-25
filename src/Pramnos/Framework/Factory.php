<?php

namespace Pramnos\Framework;

/**
 * This class provides easy access to all factory methods of the framework
 * and a registry for sigleton pattern.
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
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
     * @return \Pramnos\Filesystem\Filesystem
     */
    public static function &getFilesystem()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & \Pramnos\Filesystem\Filesystem::getInstance();
        }
        return $instance;
    }


    /**
     * Get an instance of pramnos_cache
     * @param string $category
     * @param string $type
     * @return \Pramnos\Cache\Cache
     */
    public static function getCache($category=NULL, $type=NULL)
    {
        return \Pramnos\Cache\Cache::getInstance($category, $type);
    }

    /**
     * Get an instance of pramnos_document object or create one
     * @var    string   $type Document Type. For example: pdf. Default is html
     * @var    boolean  $setDefault Set the document type as default
     * @return \Pramnos\Document\Document
     */
    public static function &getDocument($type = '', $setDefault = true)
    {
        return \Pramnos\Document\Document::getInstance($type, $setDefault);
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
        $instance = & \Pramnos\Auth\Auth::getInstance();
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
        // Straight through, with no cache of its own. There was one — a `static $instance`
        // filled on the first call and never looked at again — and it made this the second
        // place that answered "which Language object is *the* Language object".
        //
        // `Language::setInstance()` then could not do what it promises: an application that
        // installs its own Language, or switches the active one, changed the object
        // `Language::getInstance()` returns while `t()` and `l()` kept translating through
        // the one this method had cached. Which is not a crash — it is a page, or an email,
        // rendered in the language nobody asked for.
        //
        // `Language::getInstance()` is itself a singleton, so nothing is constructed twice.
        $instance = \Pramnos\Translator\Language::getInstance($lang);

        return $instance;
    }

    /**
     * The shared request, as a **class** static so it can be reached from outside.
     *
     * It was a function static, which nothing outside that method can clear — and
     * {@see \Pramnos\Http\Request::resetInstance()} has to. See {@see resetRequest()}.
     *
     * @var \Pramnos\Http\Request|null
     */
    private static $requestInstance = null;

    /**
     * Return the shared request object.
     *
     * Returned **by reference**, and that is an API rather than an accident: a test
     * substitutes a mock with `$r = &Factory::getRequest(); $r = $mock;`, which only
     * works while the reference points at something that outlives the call. So the cache
     * stays — what changed is that it is now reachable. {@see resetRequest()}
     *
     * @return \Pramnos\Http\Request
     */
    public static function &getRequest()
    {
        if (!is_object(self::$requestInstance)) {
            self::$requestInstance = \Pramnos\Http\Request::getInstance();
        }

        return self::$requestInstance;
    }

    /**
     * Forget the shared request, so the next call takes the current one.
     *
     * Called by {@see \Pramnos\Http\Request::resetInstance()}, which is where the need
     * came from: that method clears `Request::$instance` and the derived statics and says
     * so in its own doc-block, and it could not clear a **function** static held here. So
     * after a reset there were two request objects — a fresh one, and the bootstrap's still
     * cached in this class — and the second was the one `Api::exec()` handed to the
     * middleware pipeline.
     *
     * Almost nothing noticed, because routing reads `$_GET['r']`. `ApiAuthMiddleware` did:
     * `isPublicPath()` reads the request's **own** URI and treats an empty one as no match,
     * deliberately, so a caller that cannot tell takes the closed branch. Under a test
     * runner the bootstrap's request had no `REQUEST_URI` at all, so its own URI was `''`
     * for ever and **every declared-public endpoint answered 403** — in tests only, while
     * production answered correctly.
     *
     * That is the expensive shape of quiet: an application testing an endpoint it declared
     * open gets a 403 it cannot explain, and the natural next move is to decide the
     * declaration is wrong and widen it. A security change made to fix a harness artefact.
     *
     * @return void
     */
    public static function resetRequest(): void
    {
        self::$requestInstance = null;
    }

    /**
     * Return a \Pramnos\Email\Email object
     * @staticvar \Pramnos\Email\Email $instance
     * @return \Pramnos\Email\Email
     */
    public static function &getEmail()
    {
        static $instance=null;
        if (!is_object($instance)) {
            $instance = & \Pramnos\Email\Email::getInstance();
        }
        return $instance;
    }


}
