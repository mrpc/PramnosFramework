<?php

namespace Pramnos\Application;

/**
 * The main settings class. Can be used as static or be a parrent class.
 * @copyright      (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author         Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Settings extends \Pramnos\Framework\Base
{
    /**
     * The settings array
     * @var array
     */
    static protected $settings = array();
    /**
     * Is a settings file loaded?
     * @var bool
     */
    static protected $loaded = false;

    /**
     * Have all database settings been bulk-loaded this request?
     * @var bool
     */
    static protected $bulkLoaded = false;

    /**
     * How long a settings read stays in the SQL cache, in seconds.
     *
     * One value for both the bulk read and the single-key read. They used to
     * differ — 300 and 600 — which meant the same data had two lifetimes, and a
     * per-key entry could outlive the bulk one and answer differently for the
     * rest of its window.
     */
    const CACHE_TTL = 300;

    /**
     * Database to access dynamic settings
     * @var \Pramnos\Database\Database
     */
    static protected $database = null;

    /**
     * Clear all settings and reset state
     */
    public static function clearSettings()
    {
        self::$settings = array();
        self::$loaded = false;
        self::$bulkLoaded = false;
        self::$database = null;
    }



    /**
     * Initialize the settings object
     * @param string $file Settings file to load
     * @param string $onNoSettings function to run if no settings file is found
     * @param array $args Arguments to pass to the onNoSettings function
     */
    public function __construct($file = '', $onNoSettings = '', $args = array())
    {
        if (self::$loaded == false) {
            self::loadSettings($file, $onNoSettings, $args);
        }
        parent::__construct();
    }

    /**
     * Singleton factory method
     * @staticvar Settings $instance
     * @param string $file Settings file to load
     * @param string $onNoSettings function to run if no settings file is found
     * @param array $args Arguments to pass to the onNoSettings function
     * @return \Pramnos\Application\Settings
     */
    public static function &getInstance($file = '', $onNoSettings = '',
        $args = array())
    {
        static $instance = null;
        if (!is_object($instance)) {
            $instance = new Settings($file, $onNoSettings, $args);
        }
        return $instance;
    }

    /**
     * Load the global settings file
     * @param string $file Settings file to load
     * @param string $onNoSettings function to run if no settings file is found
     * @param array $args Arguments to pass to the onNoSettings function
     * @return boolean
     */
    public static function loadSettings(
        $file = '', $onNoSettings = '', $args = array()
    )
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
            $settings = include($file);
            self::$loaded = true;
            foreach ($settings as $key => $value){
                self::setSetting($key, $value, false);
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
     * Set the database object
     * @param \Pramnos\Database\Database $database
     * @param bool $loadSettings Should we load the settings from database?
     */
    public static function setDatabase(\Pramnos\Database\Database $database,
        $loadSettings = true)
    {
        self::$database = $database;
    }



    /**
     * Set a setting and it's value. It doesn't record to database
     * @param string $setting Name of the setting
     * @param mixed $value Value of the setting
     */
    public function __set($setting, $value)
    {
        self::setSetting($setting, $value, false);
    }

    /**
     * Get the value of a setting
     * @param string $setting Name of the setting
     * @return mixed The value of the setting or False if it's not set
     */
    public function __get($setting)
    {
        return self::getSetting($setting);
    }

    /**
     * Settings whose value is encrypted in the database.
     *
     * A credential the application has to *use* — SMTP AUTH needs the password
     * itself, not a hash of it — so it is encrypted rather than hashed, and this
     * list is where the two ends of that agree. {@see getSetting()} decrypts on the
     * way out and {@see setSetting()} encrypts on the way in, so nothing else in
     * the framework or in an application has to know: the value handed around is
     * the plaintext it always was.
     *
     * Conversion needs no migration. A row written before this existed carries no
     * marker, {@see \Pramnos\Security\Encrypter::maybeDecrypt()} returns it
     * unchanged, and the row converts itself the next time it is saved.
     *
     * @var string[]
     */
    protected const ENCRYPTED_SETTINGS = [
        'smtp_pass',
    ];

    /**
     * Get a setting
     *
     * Values named in {@see ENCRYPTED_SETTINGS} are decrypted here, so a caller
     * always receives the plaintext regardless of how the row is stored.
     *
     * @param string $setting Setting to return
     * @param mixed $defaultValue Default value to return if no setting is set
     * @param bool $force Force reloading the setting from database if database
     *                    is set
     * @return mixed Return Value or False if not set
     */
    static function getSetting($setting, $defaultValue = false, $force = false)
    {
        $value = self::readSetting($setting, $defaultValue, $force);

        if (!is_string($value)
            || !in_array($setting, static::ENCRYPTED_SETTINGS, true)
        ) {
            return $value;
        }

        try {
            return \Pramnos\Security\Encrypter::maybeDecrypt($value);
        } catch (\RuntimeException) {
            // A value that will not open — APP_KEY rotated, or the column
            // truncated. Returning the ciphertext would be worse than returning
            // nothing: it would be handed to an SMTP server as a password and the
            // failure would surface as a mail problem, three layers from the cause.
            return $defaultValue;
        }
    }

    /**
     * Read a setting from the in-memory store, the settings file, or the database.
     *
     * The whole of the former getSetting() body. Split out so encryption has one
     * place to happen: this method has a dozen return points and the public one has
     * exactly one.
     *
     * @param string $setting Setting to return
     * @param mixed $defaultValue Default value to return if no setting is set
     * @param bool $force Force reloading the setting from database
     * @return mixed
     */
    protected static function readSetting($setting, $defaultValue = false, $force = false)
    {
        if (isset(self::$settings[$setting]) && $force == false) {
            if (is_array(self::$settings[$setting])) {
                return (object) self::$settings[$setting];
            }
            return self::$settings[$setting];
        }

        $databaseSettingKeys = [
            'hostname',
            'database',
            'schema',
            'user',
            'password',
            'collation',
            'prefix',
            'type',
            'cache', // Added cache to avoid recursion in Database::cacheStore/Read
        ];

        // Skip database query for connection-related or recursion-prone settings
        if (in_array($setting, $databaseSettingKeys, true)) {
            // Backward compatibility for legacy configs where DB settings are nested
            if (isset(self::$settings['database']) && is_array(self::$settings['database'])) {
                if (array_key_exists($setting, self::$settings['database'])) {
                    return self::$settings['database'][$setting];
                }
            }
            return $defaultValue;
        }

        // Skip database query if DB settings lookup is disabled
        if (isset(self::$settings['dbsettings']) && self::$settings['dbsettings'] == false && $setting !== 'dbsettings') {
            return $defaultValue;
        }

        if (is_object(self::$database)) {
            // Bulk-load every setting once per request (single cached query),
            // then serve subsequent lookups from the in-memory store.
            // A forced read bypasses the bulk store and hits the DB per-key.
            if ($force == false && self::$bulkLoaded === false) {
                self::loadAllSettings();
                if (isset(self::$settings[$setting])) {
                    if (is_array(self::$settings[$setting])) {
                        return (object) self::$settings[$setting];
                    }
                    return self::$settings[$setting];
                }
            }

            // The bulk load read *every* row in the table, so a key still
            // missing afterwards is not there at all — and no amount of asking
            // again will change that.
            //
            // Without this, a setting that does not exist costs one query on
            // every read, on every request, for ever: the store never records
            // the miss, so the next call repeats it. Two such lookups on the
            // page-render path is what made this visible, but the shape of the
            // bug is worse than the count — the cost grows with how often an
            // absent setting is consulted, which is exactly the thing a caller
            // has no reason to think about.
            //
            // A forced read still goes to the database, which is what `$force`
            // is for.
            if ($force == false && self::$bulkLoaded === true) {
                return $defaultValue;
            }

            try {
                // Same TTL and category as the bulk read: two different
                // lifetimes for the same data meant one could outlive the other
                // and answer differently for the rest of its window.
                $result = self::$database->queryBuilder()
                    ->table('#PREFIX#settings')
                    ->select(['value'])
                    ->where('setting', $setting)
                    ->limit(1)
                    ->get(true, self::CACHE_TTL, 'settings');
                if ($result->numRows != 0) {
                    self::$settings[$setting] = $result->fields['value'];
                    return self::$settings[$setting];
                }
            } catch (\Throwable $e) {
                // The settings table may not exist yet (fresh install before migrations).
                // Return the default value so the application can still boot and run migrations.
            }
        }

        return $defaultValue;
    }

    /**
     * Bulk-load every setting from the database in a single cached query and
     * merge it into the in-memory store. Config-file values (already present in
     * self::$settings) always take precedence over database values.
     *
     * Replaces N per-key round-trips with one read. The underlying query is
     * cached under the "settings" SQL-cache category (300s) and evicted on
     * write by setSetting()/deleteSetting() via Database::cacheflush('settings').
     */
    protected static function loadAllSettings()
    {
        // Mark as attempted up-front so a failure/miss doesn't retry every call.
        self::$bulkLoaded = true;
        if (!is_object(self::$database)) {
            return;
        }
        // Respect the explicit "no database settings" opt-out.
        if (isset(self::$settings['dbsettings'])
            && self::$settings['dbsettings'] == false
        ) {
            return;
        }
        try {
            // Query builder, not a raw string: this went straight to query()
            // without passing through prepareQuery(), so the MySQL backticks
            // reached PostgreSQL untranslated and every request logged
            //
            //   syntax error at or near "," … select `setting`, `value` …
            //
            // The catch below then swallowed it, so the bulk read silently did
            // nothing and every lookup fell back to a query of its own — the
            // exact N round-trips this method exists to replace. A failure that
            // only costs performance is the kind that survives for months.
            $result = self::$database->queryBuilder()
                ->table('#PREFIX#settings')
                ->select(['setting', 'value'])
                ->get(true, self::CACHE_TTL, 'settings');
            foreach ($result->fetchAll() as $row) {
                if (!isset($row['setting'])) {
                    continue;
                }
                // Config-file / already-set values win over database values.
                if (!isset(self::$settings[$row['setting']])) {
                    self::$settings[$row['setting']] = $row['value'];
                }
            }
        } catch (\Throwable $e) {
            // The settings table may not exist yet (fresh install before
            // migrations). Leave the store as-is so the app can still boot.
        }
    }

    /**
     *
     * @param string $setting
     * @param string $value
     * @param bool $writeToDatabase Write the setting to database if exists
     * @return boolean
     */
    static function setSetting($setting, $value, $writeToDatabase = true)
    {
        // The in-memory store keeps whatever the caller passed; only the row is
        // encrypted, and getSetting() decrypts on the way back out. Storing the
        // ciphertext here too would mean the value read within this same request
        // depended on whether it had already been written.
        self::$settings[$setting] = $value;

        if (is_string($value)
            && $value !== ''
            && in_array($setting, static::ENCRYPTED_SETTINGS, true)
            && \Pramnos\Security\Encrypter::isAvailable()
        ) {
            // Not encrypted when APP_KEY is missing: an installation that never
            // ran key:generate must still be able to save its mail settings, and
            // an unreadable credential is a worse outcome than the one this
            // protects against. The row converts itself once a key exists.
            $value = \Pramnos\Security\Encrypter::encrypt($value);
        }

        if ($writeToDatabase == true && is_object(self::$database)) {
            // The builder is the only layer that knows the dialect: it resolves
            // the table's prefix, quotes identifiers per driver and binds the
            // values instead of interpolating them. The hand-written version of
            // these three statements is what put MySQL backticks in front of
            // PostgreSQL.
            $exists = self::$database->queryBuilder()
                ->table('#PREFIX#settings')
                ->where('setting', $setting)
                ->exists();

            if ($exists) {
                self::$database->queryBuilder()
                    ->table('#PREFIX#settings')
                    ->where('setting', $setting)
                    ->update(['value' => $value]);
            } else {
                self::$database->queryBuilder()
                    ->table('#PREFIX#settings')
                    ->insert(['setting' => $setting, 'value' => $value]);
            }

            self::invalidateCache();
        }
    }

    /**
     * Evict the cached settings after a write so other requests never read a
     * stale value. Clears the whole "settings" SQL-cache category (the bulk
     * blob plus any per-key entries) and forces a fresh bulk-load next miss.
     */
    protected static function invalidateCache()
    {
        self::$bulkLoaded = false;
        if (is_object(self::$database)
            && method_exists(self::$database, 'cacheflush')
        ) {
            try {
                self::$database->cacheflush('settings');
            } catch (\Throwable $e) {
                // Cache backend may be unavailable; in-memory store stays correct.
            }
        }
    }

    /**
     * Delete a setting
     * @param string $setting
     * @return boolean
     */
    static function deleteSetting($setting)
    {
        unset(self::$settings[$setting]);
        if (!is_object(self::$database)) {
            return false;
        }
        // Use the query builder: a raw "DELETE ... LIMIT 1" is invalid on
        // PostgreSQL. `setting` is unique, so no LIMIT is needed anyway.
        $return = self::$database->queryBuilder()
            ->table('#PREFIX#settings')
            ->where('setting', $setting)
            ->delete();
        self::invalidateCache();
        return $return;
    }

}
