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
            \Pramnos\Framework\Migrations\AuthServer\AddExtendedInfoToApplications::class,
            \Pramnos\Framework\Migrations\AuthServer\AddBroadcastSecretToApplications::class,
            \Pramnos\Framework\Migrations\AuthServer\AddIsConfidentialToApplications::class,
            \Pramnos\Framework\Migrations\AuthServer\AddTrustedToApplications::class,
            \Pramnos\Framework\Migrations\AuthServer\AddTokenLifetimesToApplications::class,
        ],
        /*
         * The table's own shape, and not the foreign keys.
         *
         * `usertokens` gains its keys from the `core/` sweeps that add missing keys and
         * indexes across the *whole* schema — running one of those from a fixture would
         * reach into every other table to fix up something this test never mentioned.
         * A real installation has them because it has run every migration, and so does a
         * database the suite has migrated; a fixture's job is the columns.
         *
         * `token_lookup` is not optional among them. `User\Token::storageFor()` writes it
         * on every insert, so a stub without it fails every write with
         * `Unknown column 'token_lookup'` — which is how it was found.
         */
        'usertokens' => [
            \Pramnos\Framework\Migrations\Auth\CreateUsertokensTable::class,
            \Pramnos\Framework\Migrations\Auth\AddTokenLookupToUsertokens::class,
            \Pramnos\Framework\Migrations\Auth\AddOidcContextToUsertokens::class,
        ],
        /*
         * Keys and indexes come from the same `core/` sweeps as usertokens', for the same
         * reason. `usertype`, `sex`, `birthdate` and `modified` have no default here, so a
         * seed gives them, as registration does.
         */
        'users' => [
            \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
        ],
        // The usergroups feature: both tables come from one migration.
        'usergroups' => [
            \Pramnos\Framework\Migrations\UserGroups\CreateUsergroupsTables::class,
            \Pramnos\Framework\Migrations\UserGroups\CreateAuthserverGroupRolesTable::class,
        ],
        // Read by every `User::load()`, so a sign-in cannot happen without it.
        'userdetails' => [
            \Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class,
        ],
        /*
         * The authserver permission store. Several classes hand-built a smaller copy to test
         * one read against, and a write that names `granted_by` was then refused by whichever
         * copy happened to be there.
         */
        'authserver.permissions' => [
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverPermissionsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\AddAudienceAndConditionsToPermissions::class,
        ],
        /*
         * `Settings::setSetting()` writes here, so any test that changes a setting persistently
         * needs it — and a suite that dropped it earlier in the run left those tests answering
         * "the database refused the query" with nothing to say which table.
         */
        'settings' => [
            \Pramnos\Framework\Migrations\Core\CreateSettingsTable::class,
            \Pramnos\Framework\Migrations\Core\AddUniqueConstraintToSettingsTable::class,
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

        $db ??= Factory::getDatabase();

        // What its foreign keys point at, first. On an empty database `usertokens` failed on
        // PostgreSQL with "relation users does not exist", because its create migration adds
        // the key; on a kept one, some earlier class had always built `users` already.
        foreach (self::REQUIRES[$name] ?? [] as $required) {
            self::table($required, $db);
        }

        /*
         * Built already, and still as it was left: one catalogue query instead of every
         * migration's own hasTable()/hasColumn() round trips. Those cost about 280 ms a call
         * for `usertokens` and what it requires, and classes call this from setUp().
         *
         * A table dropped or reshaped since — a stub, a test that added a column — has
         * different columns, and is built again.
         *
         * ponytail: compares columns only. A test that drops an index or a foreign key and
         * keeps the columns is not noticed; such a test rebuilds what it changed itself, or
         * calls DatabaseTestCase::schemaChanged().
         */
        $key      = self::connectionKey($db) . '|' . $name;
        $columns  = self::columnsOf($name, $db);
        if ($columns !== '' && (self::$built[$key] ?? null) === $columns) {
            return;
        }

        /*
         * A table that exists without its defining column is somebody's stub, not this one.
         *
         * Migration tests build `applications (appid, name)` to test one ALTER against, and one
         * that failed before its cleanup left it behind. Every `up()` below then returned early
         * because the table existed, the add-column migrations filled in a few columns, and the
         * next test inserting an `apikey` was told the statement could not be prepared.
         */
        $sentinel = self::SENTINELS[$name] ?? null;
        if ($sentinel !== null && $db->schema()->hasTable($name) && !$db->schema()->hasColumn($name, $sentinel)) {
            $db->query($db->type === 'postgresql'
                ? 'DROP TABLE IF EXISTS ' . $db->schema()->quoteTable($name) . ' CASCADE'
                : 'DROP TABLE IF EXISTS ' . $db->schema()->quoteTable($name));
        }

        self::ensure(self::RECIPES[$name], $db);
        self::$built[$key] = self::columnsOf($name, $db);
    }

    /**
     * The columns each table had when this process last built it, by connection and name.
     *
     * @var array<string, string>
     */
    private static array $built = [];

    /** Which database a table lives in, so two connections never share an entry. */
    private static function connectionKey(Database $db): string
    {
        return implode('|', [$db->type, $db->server, $db->port ?? '', $db->database, $db->schema ?? '', $db->prefix ?? '']);
    }

    /**
     * The table's column names in order, or '' when there is no such table.
     *
     * Raw SQL: `information_schema` introspection, which the query builder does not express.
     */
    private static function columnsOf(string $name, Database $db): string
    {
        $table = $db->schema()->resolveTableName($name);

        if ($db->type === 'postgresql') {
            $schema = null;
            if (str_contains($table, '.')) {
                [$schema, $table] = explode('.', $table, 2);
            } elseif (($db->schema ?? '') !== '') {
                $schema = $db->schema;
            }
            $where = $schema !== null
                ? $db->prepareQuery('table_schema = %s', $schema)
                : 'table_schema = current_schema()';
        } else {
            $where = $db->prepareQuery('table_schema = %s', $db->database);
        }

        $result = $db->query(
            'SELECT column_name AS name FROM information_schema.columns WHERE ' . $where
            . $db->prepareQuery(' AND table_name = %s', $table)
            . ' ORDER BY ordinal_position'
        );

        $names = [];
        while ($result && $result->fetch()) {
            $names[] = (string) $result->fields['name'];
        }

        return implode(',', $names);
    }

    /**
     * The recipe tables another recipe table's foreign keys point at.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRES = [
        'usertokens'  => ['users', 'applications'],
        'userdetails' => ['users'],
    ];

    /**
     * A column every real copy of the table has, so a stub without it is recognised. Tables
     * with no entry are taken as they are found.
     */
    private const SENTINELS = [
        'applications' => 'apikey',
        'usertokens'   => 'token',
        'users'        => 'username',
        'userdetails'  => 'fieldname',
        'settings'     => 'setting',
        // A column the stubs left out and every write sets.
        'authserver.permissions' => 'granted_by',
    ];

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
            $migration = new $class($application);

            /*
             * The PostgreSQL schema a table lives in, first. A migration declares it in
             * `$dependencies`, and the runner honours that; this did not, so a table in
             * `authserver.*` was created only while something else had left the schema
             * standing. The migration tests drop it, so a test built on this failed with
             * "schema authserver does not exist" whenever one of them had run before it.
             */
            foreach ($migration->dependencies ?? [] as $dependency) {
                if (isset(self::SCHEMA_MIGRATIONS[$dependency])) {
                    $schemaClass = self::SCHEMA_MIGRATIONS[$dependency];
                    (new $schemaClass($application))->up();
                }
            }

            $migration->up();
        }
    }

    /**
     * The migrations that create a schema rather than a table, by the name a table's
     * migration lists them under in `$dependencies`. Each `up()` is idempotent and does
     * nothing on MySQL, where a schema is a table prefix.
     */
    private const SCHEMA_MIGRATIONS = [
        'create_authserver_schema'   => \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverSchema::class,
        'create_pramnos_schema'      => \Pramnos\Framework\Migrations\Core\CreatePramnosSchema::class,
        'create_applications_schema' => \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsSchema::class,
    ];
}
