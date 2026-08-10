<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;

/**
 * Integration tests for the GDPR repair migration, on a database that has the
 * defects it repairs.
 *
 * WHAT: does `2026_08_10_000001` actually *run*, and does it actually fix a
 *       broken installation?
 * WHY:  a repair migration is worthless if the runner never selects it. Two
 *       things could keep it out, and both are real:
 *
 *       1. **The baseline is already recorded as applied.** The migration that
 *          should have created these foreign keys — `2020_01_01_000050` — is
 *          marked as ran on every existing installation, so correcting it fixes
 *          only fresh installs. That is precisely the gap this migration exists
 *          to close, and the same gap would swallow the repair itself if it
 *          carried a baseline timestamp.
 *       2. **`migration_cutoff`.** A legacy installation sets
 *          `migration_cutoff = 2020_01_02_000000` to skip the whole
 *          `2020_01_01_*` baseline. Anything dated inside that range is silently
 *          dropped — the exact failure mode framework rule §9 exists to prevent.
 *
 * So these tests build a broken installation, run the real runner over the real
 * migration directory with the real cutoff, and assert both that the repair is
 * selected and that the database is correct afterwards.
 */
class GdprRepairMigrationTest extends TestCase
{
    /** @var Database Connection to the PostgreSQL/TimescaleDB service */
    private Database $db;

    /** @var Application Stand-in application carrying the connection */
    private Application $app;

    /** @var string Absolute path to the framework migrations tree */
    private string $migrationsBase;

    /** The cutoff a legacy installation sets to skip the baseline. */
    private const LEGACY_CUTOFF = '2020_01_02_000000';

    /** History table used by this test alone. */
    private const HISTORY = 'schemaversion_repairtest';

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $this->db           = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';

        if (!$this->db->connect(false)) {
            $this->markTestSkipped('PostgreSQL/TimescaleDB container not reachable');
        }

        $this->migrationsBase = dirname(__DIR__, 3) . '/database/migrations/framework';

        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        $this->app     = $app;

        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    /** Remove everything this test creates. */
    private function cleanUp(): void
    {
        foreach ([
            'DROP TABLE IF EXISTS authserver.gdpr_requests CASCADE',
            'DROP TABLE IF EXISTS authserver.user_consents CASCADE',
            'DROP TABLE IF EXISTS repairtest_users CASCADE',
            'DROP TABLE IF EXISTS ' . self::HISTORY . ' CASCADE',
        ] as $sql) {
            try {
                $this->db->query($sql);
            } catch (\Throwable) {
                // Best-effort teardown.
            }
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * Build the installation this migration exists for: the tables as an older
     * framework created them — `notes` instead of `processing_notes`, and no
     * foreign key on `userid`.
     */
    private function createBrokenInstallation(): void
    {
        $this->db->query('CREATE SCHEMA IF NOT EXISTS authserver');

        // A users table for the foreign keys to point at. Named for this test so
        // it cannot collide with, or damage, the shared fixture users table.
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS repairtest_users '
            . '(userid BIGSERIAL PRIMARY KEY, username VARCHAR(80))'
        );

        $this->db->query(
            'CREATE TABLE authserver.gdpr_requests ('
            . 'id BIGSERIAL, '
            . 'userid BIGINT NOT NULL, '
            . 'request_type VARCHAR(50) NOT NULL, '
            . "status VARCHAR(50) NOT NULL DEFAULT 'pending', "
            . 'requested_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), '
            . 'completed_at TIMESTAMPTZ, '
            . 'request_details TEXT, '
            . 'response_data TEXT, '
            . 'notes TEXT, '                       // ← the wrong name
            . 'processed_by BIGINT, '
            . 'ip_address VARCHAR(45), '
            . 'PRIMARY KEY (id, requested_at))'
        );

        // A second table from the same set, to prove the repair is not
        // hard-wired to one name.
        $this->db->query(
            'CREATE TABLE authserver.user_consents ('
            . 'id BIGSERIAL, '
            . 'userid BIGINT NOT NULL, '
            . 'granted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), '
            . 'PRIMARY KEY (id, granted_at))'
        );
    }

    /**
     * Record the whole baseline as applied, the way an existing installation
     * has it — which is what stops `2020_01_01_000050` from ever running again.
     */
    private function recordBaselineAsApplied(): void
    {
        $runner = $this->runner();
        $runner->ensureHistoryTable();

        foreach ($this->frameworkMigrations() as $migration) {
            $timestamp = $migration->getTimestamp();
            if ($timestamp === null || strcmp($timestamp, self::LEGACY_CUTOFF) > 0) {
                continue;   // newer than the baseline — leave it pending
            }

            $this->db->query(
                $this->db->prepareQuery(
                    'INSERT INTO ' . self::HISTORY
                    . ' ("key", "batch", "when", "result") VALUES (%s, 1, NOW(), 1)',
                    $migration->getSlug()
                )
            );
        }
    }

    /** Every migration under the auth feature. */
    private function frameworkMigrations(): array
    {
        return MigrationLoader::loadFromDirectory(
            $this->migrationsBase . '/auth',
            $this->app
        );
    }

    /** A runner writing to this test's own history table. */
    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->db, self::HISTORY);
    }

    /** Does a constraint exist on an authserver table? */
    private function hasConstraint(string $table, string $constraint): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                'SELECT 1 FROM information_schema.table_constraints
                 WHERE table_schema = %s AND table_name = %s AND constraint_name = %s',
                'authserver',
                $table,
                $constraint
            )
        );

        return $result && $result->numRows > 0;
    }

    /** Does a column exist on an authserver table? */
    private function hasColumn(string $table, string $column): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = %s AND table_name = %s AND column_name = %s',
                'authserver',
                $table,
                $column
            )
        );

        return $result && $result->numRows > 0;
    }

    /** The repair migration, loaded from the real directory. */
    private function repairMigration(): \Pramnos\Database\Migration
    {
        foreach ($this->frameworkMigrations() as $m) {
            if ($m->getSlug() === 'repair_gdpr_requests_and_authserver_foreign_keys') {
                return $m;
            }
        }

        $this->fail('The repair migration is not in the auth migrations directory');
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /**
     * The runner selects the repair on an installation whose baseline is
     * already applied.
     *
     * This is the whole point. `2020_01_01_000050` is recorded as ran, so the
     * corrected version of it will never execute again; if the repair were not
     * selected here, the five foreign keys would stay missing for ever.
     */
    public function testTheRepairIsPendingWhenTheBaselineIsAlreadyApplied(): void
    {
        // Arrange
        $this->createBrokenInstallation();
        $this->recordBaselineAsApplied();

        // Act
        $pending = $this->runner()->getPending($this->frameworkMigrations());
        $slugs   = array_map(static fn($m): string => $m->getSlug(), $pending);

        // Assert
        $this->assertContains('repair_gdpr_requests_and_authserver_foreign_keys', $slugs);
        $this->assertNotContains(
            'create_gdpr_requests_table',
            $slugs,
            'the baseline really is recorded as applied — otherwise this test proves nothing'
        );
    }

    /**
     * `migration_cutoff` does not swallow the repair.
     *
     * A legacy installation sets the cutoff to `2020_01_02_000000` to skip the
     * entire baseline. Anything dated inside that range disappears silently —
     * which is exactly what would happen to a repair carelessly given a
     * `2020_01_01_*` timestamp, and is why framework rule §9 forbids it.
     */
    public function testTheRepairSurvivesTheLegacyMigrationCutoff(): void
    {
        // Arrange
        $all = $this->frameworkMigrations();

        // Act
        $kept  = $this->runner()->filterCutoff($all, self::LEGACY_CUTOFF);
        $slugs = array_map(static fn($m): string => $m->getSlug(), $kept);

        // Assert
        $this->assertContains('repair_gdpr_requests_and_authserver_foreign_keys', $slugs);
        $this->assertNotContains(
            'create_gdpr_requests_table',
            $slugs,
            'the cutoff really does drop the baseline — otherwise this test proves nothing'
        );
    }

    /**
     * Running it renames the column on a broken installation.
     */
    public function testRunningItRenamesTheColumn(): void
    {
        // Arrange
        $this->createBrokenInstallation();
        $this->assertTrue($this->hasColumn('gdpr_requests', 'notes'), 'precondition');
        $this->assertFalse($this->hasColumn('gdpr_requests', 'processing_notes'), 'precondition');

        // Act
        $this->repairMigration()->up();

        // Assert
        $this->assertTrue($this->hasColumn('gdpr_requests', 'processing_notes'));
        $this->assertFalse($this->hasColumn('gdpr_requests', 'notes'));
    }

    /**
     * The rename keeps the rows and their values.
     *
     * A "repair" that emptied an audit table of GDPR requests would be a far
     * worse bug than the one being fixed.
     */
    public function testTheRenameKeepsExistingData(): void
    {
        // Arrange
        $this->createBrokenInstallation();
        $this->db->query(
            "INSERT INTO authserver.gdpr_requests (userid, request_type, notes) "
            . "VALUES (7, 'erasure', 'handled by support')"
        );

        // Act
        $this->repairMigration()->up();

        // Assert
        $row = $this->db->query(
            'SELECT userid, processing_notes FROM authserver.gdpr_requests'
        );
        $this->assertSame(1, (int) $row->numRows);
        $this->assertSame(7, (int) $row->fields['userid']);
        $this->assertSame('handled by support', $row->fields['processing_notes']);
    }

    /**
     * Running it adds the foreign keys that were never created.
     *
     * The referenced table is this test's own users table, so the constraint is
     * only added where the guard can see both sides — which is the same guard
     * that (correctly) skips it on an installation without one.
     */
    public function testRunningItAddsTheMissingForeignKey(): void
    {
        // Arrange
        $this->createBrokenInstallation();
        $this->assertFalse(
            $this->hasConstraint('gdpr_requests', 'fk_gdpr_requests_userid'),
            'precondition: this is the constraint that was never created anywhere'
        );

        // Act — with a real `users` table present, the guard is satisfied
        $this->db->query('CREATE TABLE IF NOT EXISTS users (userid BIGSERIAL PRIMARY KEY)');
        try {
            $this->repairMigration()->up();

            // Assert
            $this->assertTrue(
                $this->hasConstraint('gdpr_requests', 'fk_gdpr_requests_userid')
            );
            $this->assertTrue(
                $this->hasConstraint('user_consents', 'fk_user_consents_userid'),
                'the repair is not hard-wired to one table'
            );
        } finally {
            $this->db->query('DROP TABLE IF EXISTS users CASCADE');
        }
    }

    /**
     * A row pointing at a user who no longer exists blocks its foreign key —
     * and is reported, not crashed on.
     *
     * These tables have gone years without the constraint, so nothing has been
     * stopping a request from outliving its user. Adding the key on top of such
     * a row fails, and a failing ALTER aborts the whole migration batch, taking
     * unrelated migrations down with it. Skipping is recoverable: once the
     * orphans are dealt with, the next run adds the key.
     */
    public function testAnOrphanedRowBlocksItsForeignKeyInsteadOfBreakingTheBatch(): void
    {
        // Arrange — a request whose user is not in the users table
        $this->createBrokenInstallation();
        $this->db->query('CREATE TABLE IF NOT EXISTS users (userid BIGSERIAL PRIMARY KEY)');
        $this->db->query(
            "INSERT INTO authserver.gdpr_requests (userid, request_type) VALUES (999999, 'erasure')"
        );

        try {
            // Act — must not throw
            $this->repairMigration()->up();

            // Assert
            $this->assertFalse(
                $this->hasConstraint('gdpr_requests', 'fk_gdpr_requests_userid'),
                'the key cannot be added while an orphan exists'
            );
            $this->assertTrue(
                $this->hasColumn('gdpr_requests', 'processing_notes'),
                'the rest of the repair still runs — one blocked key is not a failed migration'
            );

            // ...and once the orphan is gone, the next run adds it
            $this->db->query('DELETE FROM authserver.gdpr_requests WHERE userid = 999999');
            $this->repairMigration()->up();

            $this->assertTrue(
                $this->hasConstraint('gdpr_requests', 'fk_gdpr_requests_userid'),
                'the skip is recoverable'
            );
        } finally {
            $this->db->query('DROP TABLE IF EXISTS users CASCADE');
        }
    }

    /**
     * Running it twice changes nothing and does not raise.
     *
     * Migrations are not normally re-run, but this one may legitimately be
     * invoked by hand on a database an operator is repairing, and an ALTER that
     * fails the second time would abort the batch around it.
     */
    public function testASecondRunIsANoOp(): void
    {
        // Arrange
        $this->createBrokenInstallation();
        $this->db->query('CREATE TABLE IF NOT EXISTS users (userid BIGSERIAL PRIMARY KEY)');

        try {
            $this->repairMigration()->up();

            // Act
            $this->repairMigration()->up();

            // Assert
            $this->assertTrue($this->hasColumn('gdpr_requests', 'processing_notes'));
            $this->assertTrue(
                $this->hasConstraint('gdpr_requests', 'fk_gdpr_requests_userid')
            );
        } finally {
            $this->db->query('DROP TABLE IF EXISTS users CASCADE');
        }
    }

    /**
     * A correct installation is left alone.
     *
     * A fresh install already has `processing_notes`, and the migration must not
     * mistake it for something to rename.
     */
    public function testACorrectInstallationIsUntouched(): void
    {
        // Arrange
        $this->db->query('CREATE SCHEMA IF NOT EXISTS authserver');
        $this->db->query(
            'CREATE TABLE authserver.gdpr_requests ('
            . 'id BIGSERIAL, userid BIGINT NOT NULL, '
            . 'requested_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), '
            . 'processing_notes TEXT, PRIMARY KEY (id, requested_at))'
        );

        // Act
        $this->repairMigration()->up();

        // Assert
        $this->assertTrue($this->hasColumn('gdpr_requests', 'processing_notes'));
        $this->assertFalse($this->hasColumn('gdpr_requests', 'notes'));
    }

    /**
     * An installation without the table at all is not an error.
     *
     * Not every installation enables the auth feature, and a migration that
     * aborted there would break the batch for everyone else.
     */
    public function testAnInstallationWithoutTheTableIsNotAnError(): void
    {
        // Arrange — nothing created

        // Act
        $this->repairMigration()->up();

        // Assert — reaching this line is the contract
        $this->assertFalse($this->hasColumn('gdpr_requests', 'processing_notes'));
    }
}
