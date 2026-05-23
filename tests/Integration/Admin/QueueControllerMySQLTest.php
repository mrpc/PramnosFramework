<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Admin;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Framework\Factory;
use Pramnos\Queue\Controllers\QueueController;

/**
 * Testable QueueController subclass.
 *
 * Bypasses the auth check in requireMinUserType() so integration tests
 * can call action methods without setting up a full session.
 */
class TestableQueueController extends QueueController
{
    protected function requireMinUserType(int $minType): bool
    {
        // Always grant access in test context — auth is not the focus here.
        return false;
    }

    public function redirect($url = null, $quit = true, $code = '302'): void
    {
        // Suppress redirect output — tests verify DB state instead.
    }
}

/**
 * Integration tests for QueueController against a live MySQL 8.0 database.
 *
 * These tests verify that the controller's database operations — soft-delete,
 * retry, bulk-retry, and bulk-clear — actually take effect in the real queueitems
 * table.  Unit tests verify the controller's structural contracts (auth, methods);
 * these integration tests prove that the SQL mutations are correct.
 *
 * Isolation: setUp creates a fresh queueitems table; tearDown drops it.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class QueueControllerMySQLTest extends TestCase
{
    protected Database $db;
    protected Application $app;
    protected TestableQueueController $ctrl;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        if (!defined('sURL')) {
            define('sURL', 'http://localhost/');
        }
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        $this->dropQueueTable();
        $this->runQueueMigration();

        $this->app  = $this->makeApp();
        $this->ctrl = new TestableQueueController($this->app);
    }

    protected function tearDown(): void
    {
        $this->dropQueueTable();
    }

    // -------------------------------------------------------------------------
    // retry()
    // -------------------------------------------------------------------------

    /**
     * retry($id) must change status from 'failed' to 'pending' in the DB.
     *
     * This is the recovery path: a failed job can be rescheduled without
     * touching the queue from the CLI. If the UPDATE does not run, the job
     * stays failed indefinitely.
     */
    public function testRetryChangesFailedJobToPending(): void
    {
        // Arrange — insert a failed job directly
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES ('test_job', '{}', 'failed', NOW())"
        );
        $id = (int) $this->db->getInsertId();

        // Act
        $this->ctrl->retry($id);

        // Assert — status must now be 'pending'
        $row = $this->db->query("SELECT status FROM queueitems WHERE taskid = {$id}");
        $this->assertSame('pending', $row->fields['status'],
            "retry() must reset a failed job's status to 'pending'");
    }

    /**
     * retry() on a non-failed job must NOT change its status.
     *
     * A pending job that is accidentally retried must remain pending;
     * otherwise a processing job could be reset while a worker holds it.
     */
    public function testRetryDoesNotAffectNonFailedJob(): void
    {
        // Arrange — insert a pending job
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES ('pending_job', '{}', 'pending', NOW())"
        );
        $id = (int) $this->db->getInsertId();

        // Act
        $this->ctrl->retry($id);

        // Assert — status unchanged (the WHERE status='failed' guard must hold)
        $row = $this->db->query("SELECT status FROM queueitems WHERE taskid = {$id}");
        $this->assertSame('pending', $row->fields['status'],
            "retry() must not change non-failed jobs — the WHERE status='failed' guard must hold");
    }

    // -------------------------------------------------------------------------
    // retryall()
    // -------------------------------------------------------------------------

    /**
     * retryall() must reset ALL failed jobs to 'pending' in one operation.
     *
     * Without this, an operator must retry each failed job individually,
     * which is unusable when a batch of 100 jobs fails due to a transient error.
     */
    public function testRetryAllResetsAllFailedJobs(): void
    {
        // Arrange — insert three failed and one pending job
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES
             ('job_a', '{}', 'failed', NOW()),
             ('job_b', '{}', 'failed', NOW()),
             ('job_c', '{}', 'failed', NOW()),
             ('job_d', '{}', 'pending', NOW())"
        );

        // Act
        $this->ctrl->retryall();

        // Assert — all three failed jobs now pending
        $result = $this->db->query(
            "SELECT COUNT(*) as cnt FROM queueitems WHERE type IN ('job_a','job_b','job_c') AND status = 'pending'"
        );
        $this->assertSame(3, (int)$result->fields['cnt'],
            'retryall() must reset all three failed jobs to pending');

        // Assert — the originally-pending job is still pending (not touched)
        $result2 = $this->db->query(
            "SELECT status FROM queueitems WHERE type = 'job_d'"
        );
        $this->assertSame('pending', $result2->fields['status'],
            'retryall() must not touch already-pending jobs');
    }

    // -------------------------------------------------------------------------
    // delete()
    // -------------------------------------------------------------------------

    /**
     * delete($id) must soft-delete the job by setting status='deleted'.
     *
     * Hard DELETE is deliberately avoided so the row remains available for
     * audit and post-mortem analysis. Workers must skip 'deleted' items.
     */
    public function testDeleteSoftDeletesJobByStatus(): void
    {
        // Arrange
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES ('delete_me', '{}', 'completed', NOW())"
        );
        $id = (int) $this->db->getInsertId();

        // Act
        $this->ctrl->delete($id);

        // Assert — row still exists, status changed to 'deleted'
        $row = $this->db->query("SELECT status FROM queueitems WHERE taskid = {$id}");
        $this->assertSame('deleted', $row->fields['status'],
            "delete() must soft-delete (status='deleted'), not hard-delete");
    }

    /**
     * delete() with invalid id (0) must NOT modify any row.
     *
     * An invalid ID guard prevents accidentally deleting unintended rows
     * when the id parameter is missing from a crafted URL.
     */
    public function testDeleteWithInvalidIdIsNoop(): void
    {
        // Arrange — insert a job
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES ('safe_job', '{}', 'pending', NOW())"
        );

        // Act — call with id=0 (invalid)
        $this->ctrl->delete(0);

        // Assert — the pending job is untouched
        $result = $this->db->query("SELECT status FROM queueitems WHERE type = 'safe_job'");
        $this->assertSame('pending', $result->fields['status'],
            "delete(0) must be a noop — no rows should be modified");
    }

    // -------------------------------------------------------------------------
    // clear()
    // -------------------------------------------------------------------------

    /**
     * clear() with status='failed' must soft-delete all failed jobs.
     *
     * This is the bulk cleanup path: operators can purge completed/failed
     * jobs to keep the table lean without removing pending work.
     */
    public function testClearSoftDeletesAllFailedJobs(): void
    {
        // Arrange — two failed, one pending
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES
             ('fail_1', '{}', 'failed', NOW()),
             ('fail_2', '{}', 'failed', NOW()),
             ('keep_me', '{}', 'pending', NOW())"
        );
        $_POST['status'] = 'failed';

        // Act
        $this->ctrl->clear();

        // Assert — both failed rows are now 'deleted'
        $result = $this->db->query(
            "SELECT COUNT(*) as cnt FROM queueitems WHERE status = 'deleted'"
        );
        $this->assertSame(2, (int)$result->fields['cnt'],
            'clear(failed) must soft-delete all failed jobs');

        // Assert — pending job unaffected
        $pending = $this->db->query("SELECT status FROM queueitems WHERE type = 'keep_me'");
        $this->assertSame('pending', $pending->fields['status'],
            'clear() must not touch pending jobs');

        unset($_POST['status']);
    }

    /**
     * clear() with status='pending' must be rejected — pending jobs cannot be
     * bulk-deleted because they represent work that has not yet been attempted.
     * Bulk-deleting pending jobs would silently discard unprocessed tasks.
     */
    public function testClearWithPendingStatusIsRejected(): void
    {
        // Arrange — one pending job
        $this->db->query(
            "INSERT INTO queueitems (type, payload, status, createdat) VALUES ('protected_job', '{}', 'pending', NOW())"
        );
        $_POST['status'] = 'pending';

        // Act — clear() with invalid status
        $this->ctrl->clear();

        // Assert — the pending job must not be touched
        $row = $this->db->query("SELECT status FROM queueitems WHERE type = 'protected_job'");
        $this->assertSame('pending', $row->fields['status'],
            "clear() must refuse status='pending' — pending jobs are protected");

        unset($_POST['status']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function makeApp(): Application
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        return $app;
    }

    protected function runQueueMigration(): void
    {
        $dir = ROOT . \DS . 'database' . \DS . 'migrations' . \DS . 'framework' . \DS . 'queue';
        $migrations = MigrationLoader::loadFromDirectory($dir, $this->makeAppForMigration());
        foreach ($migrations as $m) {
            $m->up();
        }
    }

    private function makeAppForMigration(): Application
    {
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        return $app;
    }

    protected function dropQueueTable(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query('DROP TABLE IF EXISTS `queueitems`');
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
