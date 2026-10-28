<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Admin;

use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

// Helpers this file uses are declared there, under names the autoloader cannot map to a file;
// required here so the file does not depend on another test having run first.
require_once __DIR__ . '/QueueControllerMySQLTest.php';

/**
 * Integration tests for QueueController against a live PostgreSQL 14 database.
 *
 * Extends QueueControllerMySQLTest — the same behavioural contracts must hold
 * on PostgreSQL because deployed stacks may use either backend.
 *
 * PostgreSQL notes:
 * - queueitems.status is VARCHAR(20) with a CHECK constraint
 * - `getInsertId()` uses `SELECT LASTVAL()` rather than `mysqli_insert_id()`
 *
 * Requires the Docker PostgreSQL / TimescaleDB container.
 */
class QueueControllerPostgreSQLTest extends QueueControllerMySQLTest
{
    protected function setUp(): void
    {
        // This bespoke setUp swaps in a PostgreSQL connection and does NOT chain
        // to BaseTestCase::setUp(), so reset request superglobals here too —
        // otherwise a prior test's $_GET['_option'] leaks into this one.
        $this->resetRequestState();

        if (!defined('sURL')) {
            define('sURL', 'http://localhost/');
        }
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $pgSettings = Settings::getSetting('postgresql');
        if (!$pgSettings) {
            $this->markTestSkipped('PostgreSQL settings not found');
        }

        $pgDb           = new Database();
        $pgDb->type     = 'postgresql';
        $pgDb->server   = $pgSettings->hostname;
        $pgDb->user     = $pgSettings->user;
        $pgDb->password = $pgSettings->password;
        $pgDb->database = $pgSettings->database;
        $pgDb->port     = $pgSettings->port ?? 5432;
        $pgDb->schema   = $pgSettings->schema ?? 'public';

        try {
            $pgDb->connect(true);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('PostgreSQL container not reachable: ' . $e->getMessage());
        }

        // Swap the Factory singleton to PostgreSQL so QueryBuilder uses it.
        $singleton = &Factory::getDatabase();
        $singleton = $pgDb;

        $this->db   = $pgDb;
        $this->app  = $this->makeApp();

        $this->dropQueueTable();
        $this->runQueueMigration();

        $this->ctrl = new TestableQueueController($this->app);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Restore singleton to MySQL so subsequent tests use the correct backend.
        $singleton = &Factory::getDatabase();
        $singleton = null;
        Settings::loadSettings(
            ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php'
        );
    }

    /**
     * The migration that teaches PostgreSQL `stopped` runs a second time without harm; down()
     * turns stopped tasks into deleted ones and the constraint refuses `stopped` again; up()
     * brings it back. The type the create migration made learns the value too.
     */
    public function testTheStoppedStatusMigrationRunsTwiceAndGoesBack(): void
    {
        // Arrange
        $migration = new \Pramnos\Framework\Migrations\Queue\AddStoppedToQueueitemsStatus($this->makeApp());
        $insert    = fn (): mixed => $this->db->queryBuilder()->table('queueitems')->insert(['type' => 'job', 'payload' => '{}', 'status' => 'stopped']);

        // Act
        $migration->up();
        $insert();
        $migration->down();
        $afterDown = $this->db->queryBuilder()->table('queueitems')->select(['status'])->first()->fields['status'];
        try {
            $insert();
            $refused = false;
        } catch (\Throwable) {
            $refused = true;
        }
        $migration->up();
        $insert();
        $type = $this->db->query("SELECT COUNT(*) AS n FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid WHERE t.typname = 'queue_status' AND e.enumlabel = 'stopped'");

        // Assert
        $this->assertSame('deleted', $afterDown, 'down() left a status its constraint refuses');
        $this->assertTrue($refused, 'down() left `stopped` allowed');
        $this->assertSame(2, (int) $this->db->queryBuilder()->table('queueitems')->count());
        $this->assertSame(1, (int) $type->fields['n'], 'the queue_status type did not learn `stopped`');
    }

    /**
     * A table moved over from MySQL whose `status` is *of* the `queue_status` enum, with only
     * the labels its application used — no `deleted`, no `stopped`, no CHECK.
     *
     * A CHECK naming a label the enum lacks is a cast error that stopped the whole bootstrap.
     * Here the migration teaches the enum every missing status instead, runs a second time
     * without harm, adds no constraint, and its down() leaves the column alone, because
     * PostgreSQL cannot take a label out of an enum.
     */
    public function testTheStoppedStatusMigrationTeachesAnEnumStatusColumnInsteadOfConstrainingIt(): void
    {
        // Arrange — the shape a converted installation has
        $this->dropQueueTable();
        $this->db->query("CREATE TYPE queue_status AS ENUM ('pending','processing','completed','failed','warning')");
        $this->db->query('CREATE TABLE "queueitems" (id serial PRIMARY KEY, type varchar(50), payload text, status queue_status NOT NULL DEFAULT \'pending\')');
        $migration = new \Pramnos\Framework\Migrations\Queue\AddStoppedToQueueitemsStatus($this->makeApp());

        // Act — twice, as a re-run would; then down()
        $migration->up();
        $migration->up();
        $migration->down();
        foreach (['deleted', 'stopped'] as $status) {
            $this->db->queryBuilder()->table('queueitems')->insert(['type' => 'job', 'payload' => '{}', 'status' => $status]);
        }
        $labels = [];
        $result = $this->db->query("SELECT e.enumlabel FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid WHERE t.typname = 'queue_status' ORDER BY e.enumsortorder");
        while ($result->fetch()) {
            $labels[] = $result->fields['enumlabel'];
        }
        $check = $this->db->query("SELECT COUNT(*) AS n FROM pg_constraint WHERE conname = 'chk_queueitems_status'");

        // Assert
        $this->assertSame(
            ['pending', 'processing', 'completed', 'failed', 'warning', 'deleted', 'stopped'],
            $labels,
            'each missing status added once, in order, and kept by down()'
        );
        // Both statuses the framework writes are now accepted by the column itself
        $this->assertSame(2, (int) $this->db->queryBuilder()->table('queueitems')->count());
        $this->assertSame(0, (int) $check->fields['n'], 'no CHECK beside an enum that already constrains');
    }

    protected function dropQueueTable(): void
    {
        $this->db->query('DROP TABLE IF EXISTS "queueitems" CASCADE');
        $this->db->query('DROP TYPE IF EXISTS queue_status');
    }
}
