<?php

declare(strict_types=1);

namespace Pramnos\Framework\Testing;

use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * A live database connection for a test, built from the settings that test just loaded.
 *
 * **Why this is not simply `Factory::getDatabase()`.** That hands back whatever instance
 * already exists, and it was built from whatever settings were loaded when the first class
 * in the run asked for one. A class that loads its own settings and then calls it gets the
 * *old* object: `connected` says true while nothing behind it is usable, or `server` is
 * empty and the next `connect()` falls through to a unix socket. The symptom is
 *
 * ```
 * RuntimeException: No such file or directory
 * ```
 *
 * in a class that has touched none of this — and it is order-dependent, so it appears when
 * a filter or a new test changes what runs first, and vanishes when the class is run alone.
 * Three test classes had each worked it out separately and written the same two lines.
 *
 * ```php
 * Settings::clearSettings();
 * Settings::loadSettings($settingsFile);
 * $db = Connection::fresh();
 * ```
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
final class Connection
{
    /**
     * Discard the current singleton and return a connected instance from current settings.
     *
     * The reference has to be nulled rather than reassigned: `Database::getInstance()`
     * returns by reference precisely so a caller can replace what everything else will
     * see, and only `null` makes the next call build a new one.
     *
     * @param bool $throwOnFailure Passed to `connect()`. True by default, because a test
     *                             that cannot reach its database should say so where it
     *                             happened rather than fail later on an empty result.
     */
    public static function fresh(bool $throwOnFailure = true): Database
    {
        $reference = &Database::getInstance();
        $reference = null;

        $db = Factory::getDatabase();

        if (!$db->connected) {
            $db->connect($throwOnFailure);
        }

        return $db;
    }
}
