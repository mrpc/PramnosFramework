<?php

declare(strict_types=1);

namespace Pramnos\Tests\Support;

use Pramnos\Database\Database;
use Pramnos\Framework\Testing\Schema;

/**
 * A throwaway database, built once per test class and engine, and emptied for each test.
 *
 * For a test that wants real tables from their migrations in a database of its own. Building
 * it for every test cost about half a second on MySQL — `DROP DATABASE`, `CREATE DATABASE`
 * and every migration's DDL — which three classes paid fourteen times each. Emptying the
 * tables gives the same starting point as long as the migrations write no rows of their own,
 * which a class mixing this in must keep true: rows a migration seeds would be emptied too.
 *
 * The database is dropped when the class ends, through the same connection details it was
 * built with.
 */
trait ReusesProbeDatabase
{
    /** @var array<string, \Closure(): void> engine type => drops the database */
    private static array $probeDrops = [];

    /**
     * Open the class's throwaway database: built the first time on this engine, emptied after.
     *
     * @param Database                    $admin      A connection to an existing database on the same server
     * @param string                      $name       The throwaway database
     * @param \Closure(string): Database  $make       Builds an unconnected connection to a database by name
     * @param list<class-string>          $migrations What to build in it
     */
    private function openProbe(Database $admin, string $name, \Closure $make, array $migrations): Database
    {
        $type = $admin->type;

        if (!isset(self::$probeDrops[$type])) {
            $admin->query('DROP DATABASE IF EXISTS ' . $name);
            $admin->query('CREATE DATABASE ' . $name);
            $db = $make($name);
            $db->connect(false);
            Schema::ensure($migrations, $db);

            self::$probeDrops[$type] = static function () use ($make, $name): void {
                $server = $make('pramnos_test');
                if ($server->connect(false)) {
                    $server->query('DROP DATABASE IF EXISTS ' . $name);
                    $server->close();
                }
            };

            return $db;
        }

        $db = $make($name);
        $db->connect(false);
        self::emptyProbe($db, $name);

        return $db;
    }

    /**
     * This test changed the throwaway database's schema: the next one rebuilds it.
     */
    private function probeChanged(string $type): void
    {
        unset(self::$probeDrops[$type]);
    }

    /**
     * Delete every row of every table in the throwaway database.
     *
     * Raw: catalogue introspection, and one TRUNCATE over a list of tables, neither of which
     * the builder expresses.
     */
    private static function emptyProbe(Database $db, string $name): void
    {
        if ($db->type === 'mysql') {
            $tables = $db->query(
                "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = '"
                . $name . "' AND table_type = 'BASE TABLE'"
            );
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            while ($tables->fetch()) {
                $db->query('DELETE FROM `' . $tables->fields['t'] . '`');
            }
            $db->query('SET FOREIGN_KEY_CHECKS = 1');

            return;
        }

        $tables = $db->query(
            "SELECT quote_ident(schemaname) || '.' || quote_ident(tablename) AS t FROM pg_tables"
            . " WHERE schemaname NOT IN ('pg_catalog', 'information_schema') AND schemaname NOT LIKE '\\_timescaledb%'"
        );
        $list = [];
        while ($tables->fetch()) {
            $list[] = $tables->fields['t'];
        }
        if ($list !== []) {
            // Without RESTART IDENTITY, as MySQL's DELETE leaves AUTO_INCREMENT: ids keep
            // rising across tests. Restarting them handed id 1 — the system account, which
            // the code under test refuses on purpose — to the first user a test created.
            $db->query('TRUNCATE ' . implode(', ', $list) . ' CASCADE');
        }
    }

    /** Drop the throwaway databases this class built. */
    public static function tearDownAfterClass(): void
    {
        foreach (self::$probeDrops as $drop) {
            try {
                $drop();
            } catch (\Throwable) {
                // The server went first: nothing to drop.
            }
        }
        self::$probeDrops = [];
        parent::tearDownAfterClass();
    }
}
