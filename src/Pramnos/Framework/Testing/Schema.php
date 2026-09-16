<?php

declare(strict_types=1);

namespace Pramnos\Framework\Testing;

use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * Build a table in a test from the migration that builds it in production.
 *
 * **Why this is worth a class of its own.** A test that writes its own `CREATE TABLE` is
 * testing against a schema nothing else has, and the difference is invisible until it is
 * expensive. This repository has already paid for it once: a fixture declared
 * `applications.redirect_uri`, a column no migration has ever created — production stores
 * the registered URI in `callback` and only a *view* aliases it — so the tests set a field
 * nothing consulted, exercised the "no redirect URI registered" branch, and passed. Three
 * more fixtures still carried the same column when this was written.
 *
 * The cost argument that used to favour hand-rolled DDL does not survive measurement.
 * On this project's MySQL container, for `applications`:
 *
 * | | per call |
 * |---|---|
 * | hand-rolled `DROP` + `CREATE` | 9.56 ms |
 * | canonical migration, `drop` + `up()` | 12.18 ms |
 * | canonical migration, table already present | **0.23 ms** |
 *
 * So converting a fixture costs about 2.6 ms the first time and **saves 9.3 ms every time
 * after**, because a migration's `up()` opens with `if (hasTable()) return;`. Ten classes
 * that each rebuild the same table pay for one build instead of ten.
 *
 * ```php
 * // In any test, whatever it extends:
 * Schema::ensure([CreateApplicationsTable::class, WidenApplicationsCallback::class]);
 * ```
 *
 * **It does not drop anything**, on purpose. Dropping is how one test breaks the next:
 * `usertokens` carries a foreign key to `applications`, and a test that dropped the child
 * left the constraint dangling and broke thirty-eight unrelated tests. A test that needs a
 * table rebuilt because something else left it in the wrong shape drops it itself, having
 * first checked that nothing points at it — see the Testing Guide.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
final class Schema
{
    /**
     * The migrations that add up to a table's production shape, in order.
     *
     * A recipe rather than a list at each call site, because the list is the part that
     * gets written wrong. `applications` is created by one migration, gains `systemuser`
     * from a second and a wider `callback` from a third — and the first attempt at
     * converting a fixture named only the first and the third, so the table came out
     * without `systemuser` and a test that assigns one failed. A caller that says
     * `table('applications')` cannot make that mistake; a caller that hand-lists three
     * class names can, and will, every time a fourth is added.
     *
     * @var array<string, list<class-string<\Pramnos\Database\Migration>>>
     */
    private const RECIPES = [
        'applications' => [
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\AddSystemuserToApplications::class,
            \Pramnos\Framework\Migrations\Applications\WidenApplicationsCallback::class,
        ],
    ];

    /**
     * Build a table in its production shape, by name.
     *
     * ```php
     * Schema::table('applications', $this->db);
     * ```
     *
     * @throws \InvalidArgumentException when the table has no recipe — deliberately, so
     *         a typo is an error rather than a silent no-op that leaves the old fixture
     *         shape in place and the test passing against it
     */
    public static function table(string $name, ?Database $db = null): void
    {
        if (!isset(self::RECIPES[$name])) {
            throw new \InvalidArgumentException(
                'No canonical recipe for table "' . $name . '". Add one to '
                . self::class . '::RECIPES, naming every migration that shapes it.'
            );
        }

        self::ensure(self::RECIPES[$name], $db);
    }

    /**
     * Ensure every one of these migrations has been applied to this connection.
     *
     * Idempotent, because each migration's `up()` already begins by returning when its
     * table exists. That is what makes this cheap enough to call from `setUp()` without
     * memoising anything here — and a memo would be wrong anyway, since a test may drop a
     * table between calls and this has no way to know.
     *
     * @param list<class-string<\Pramnos\Database\Migration>> $migrationClasses
     * @param Database|null $db The connection, or the current one
     */
    public static function ensure(array $migrationClasses, ?Database $db = null): void
    {
        $db ??= Factory::getDatabase();

        /*
         * A migration only ever touches `$this->application->database`, so a real
         * Application is not needed — and constructing one in a unit test pulls in
         * settings, a session and a request. `newInstanceWithoutConstructor()` rather
         * than a PHPUnit mock so this works outside a TestCase too.
         */
        $application = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        $application->database = $db;

        foreach ($migrationClasses as $class) {
            (new $class($application))->up();
        }
    }
}
