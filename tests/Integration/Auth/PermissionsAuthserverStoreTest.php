<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Permissions;
use Pramnos\Database\Database;

/**
 * A Permissions instance bound to one specific connection.
 *
 * The class normally resolves its connection through the Factory singleton,
 * which is shared with every other test in the process. Overriding the seam
 * keeps this test on its own connection and leaves that singleton alone.
 */
class BoundPermissions extends Permissions
{
    /** @var Database The connection this instance works through. */
    private Database $connection;

    /**
     * @param Database $connection The test's own connection
     */
    public function __construct(Database $connection)
    {
        $this->connection = $connection;
        parent::__construct('database');
    }

    protected function db()
    {
        return $this->connection;
    }

    /** Expose the resolved store for assertions. */
    public function store(): string
    {
        return $this->activeStore();
    }
}

/**
 * Integration tests for `Pramnos\Auth\Permissions` over `authserver.permissions`.
 *
 * WHAT: the full round trip through the store the framework actually maintains —
 *       allow, deny, remove, and the tri-state read — against a real database.
 * WHY:  the read path moved to this store before the write path did, so for a
 *       while `allow()` wrote to a table no migration creates while `isAllowed()`
 *       read somewhere else entirely. A grant could be neither made nor revoked,
 *       and the class would report "no such permission" about one it had just
 *       refused to store. Only a round trip against a real table proves the two
 *       sides meet; unit tests over a recorded query builder cannot.
 *
 * Uses isolated identifiers and its own stub table so it is self-contained on
 * MySQL, PostgreSQL and TimescaleDB alike.
 */
class PermissionsAuthserverStoreTest extends TestCase
{
    use \Pramnos\Tests\Support\ForgetsPermissionStore;

    /** @var Database The test's own connection */
    private Database $db;

    /** @var bool Whether this driver is PostgreSQL-shaped */
    private bool $isPg;

    /** @var BoundPermissions The object under test */
    private BoundPermissions $permissions;

    /** @var bool Whether this test created the permissions table and must drop it */
    private bool $createdPerms = false;

    /** @var bool Whether this test created the roles table and must drop it */
    private bool $createdRoles = false;

    /** An identifier far outside anything a fixture would use. */
    private const USER = 970101;

    /** The role a "group" subject maps onto. */
    private const ROLE = 960101;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $driver = $_ENV['DB_TYPE'] ?? (getenv('DB_TYPE') ?: 'mysql');
        $this->isPg = in_array($driver, ['postgresql', 'pgsql', 'timescaledb'], true);

        $this->db = new Database();
        $this->db->type     = $driver;
        $this->db->server   = $_ENV['DB_HOST'] ?? (getenv('DB_HOST') ?: 'db');
        $this->db->port     = (int) ($_ENV['DB_PORT'] ?? (getenv('DB_PORT') ?: ($this->isPg ? 5432 : 3306)));
        $this->db->user     = $_ENV['DB_USER'] ?? (getenv('DB_USER') ?: 'root');
        $this->db->password = $_ENV['DB_PASS'] ?? (getenv('DB_PASS') ?: 'secret');
        $this->db->database = $_ENV['DB_NAME'] ?? (getenv('DB_NAME') ?: 'pramnos_test');

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped('Database not reachable');
            }
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Database not reachable: ' . $e->getMessage());
        }

        if ($this->isPg) {
            $this->db->statement('CREATE SCHEMA IF NOT EXISTS authserver');
        }

        $this->ensureTables();
        $this->clearTestRows();

        $this->permissions = new BoundPermissions($this->db);
    }

    protected function tearDown(): void
    {
        try {
            $this->clearTestRows();
            if ($this->createdPerms) {
                $this->db->schema()->dropTableIfExists('authserver.permissions');
            }
            // Leaving this behind would hand the next test a half-provisioned
            // store — permissions gone, roles present — which is exactly the
            // shape that makes resolution ambiguous.
            if ($this->createdRoles) {
                $this->db->schema()->dropTableIfExists('authserver.user_roles');
            }
        } catch (\Throwable) {
            // Non-fatal cleanup.
        }
        $this->forgetPermissionStore();
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * Create the store's tables when the installation under test lacks them.
     */
    private function ensureTables(): void
    {
        $schema = $this->db->schema();
        $pk     = $this->isPg ? 'BIGSERIAL PRIMARY KEY' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';

        if (!$schema->hasTable('authserver.permissions')) {
            $this->createdPerms = true;
            $this->db->statement(
                'CREATE TABLE ' . $this->q('authserver.permissions') . " (permissionid $pk, "
                . 'subject_type VARCHAR(20), subject_id BIGINT, object_type VARCHAR(50), '
                . 'object_id VARCHAR(100), action VARCHAR(20), grant_type VARCHAR(5), '
                . 'priority INT DEFAULT 100, is_active BOOLEAN DEFAULT TRUE, '
                . 'expires_at TIMESTAMP NULL, app_id BIGINT NULL, conditions TEXT NULL)'
            );
        }
        if (!$schema->hasTable('authserver.user_roles')) {
            $this->createdRoles = true;
            $this->db->statement(
                'CREATE TABLE ' . $this->q('authserver.user_roles') . ' (userid BIGINT, '
                . 'roleid INT, is_active BOOLEAN DEFAULT TRUE, expires_at TIMESTAMP NULL)'
            );
        }
    }

    /** authserver.x on PostgreSQL; authserver_x on MySQL. */
    private function q(string $logical): string
    {
        return $this->isPg ? $logical : str_replace('authserver.', 'authserver_', $logical);
    }

    /** Remove only this test's rows, never anybody else's. */
    private function clearTestRows(): void
    {
        $this->db->queryBuilder()
            ->table('authserver.permissions')
            ->whereIn('subject_id', [self::USER, self::ROLE])
            ->delete();
    }

    /** Ask the tri-state question, which distinguishes deny from silence. */
    private function ask(string $privilege, string $element = ''): ?bool
    {
        return $this->permissions->isAllowed(
            self::USER, 'invoice', $privilege, $element, 'module', 'user', false
        );
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /**
     * With the new table present, that is the store — regardless of anything else.
     */
    public function testTheNewStoreIsSelected(): void
    {
        // Act + Assert
        $this->assertSame('authserver', $this->permissions->store());
    }

    /**
     * A grant written by allow() is readable by isAllowed().
     *
     * This is the round trip the class exists for, and the one that was broken:
     * the two halves have to agree on where the rows live.
     */
    public function testAllowThenReadBack(): void
    {
        // Arrange
        $this->permissions->allow(self::USER, 'invoice', 'view');

        // Act + Assert
        $this->assertTrue($this->ask('view'));
    }

    /**
     * A deny is read back as an explicit refusal, not as silence.
     */
    public function testDenyThenReadBack(): void
    {
        // Arrange
        $this->permissions->deny(self::USER, 'invoice', 'delete');

        // Act + Assert
        $this->assertFalse($this->ask('delete'));
    }

    /**
     * Removing a grant leaves no opinion behind — not a deny.
     *
     * The difference is the whole point of the tri-state call: a caller can
     * treat "nobody said" differently from "explicitly refused", and a removal
     * that silently became a deny would erase that distinction.
     */
    public function testRemoveLeavesNoOpinion(): void
    {
        // Arrange
        $this->permissions->allow(self::USER, 'invoice', 'view');
        $this->assertTrue($this->ask('view'), 'precondition: the grant exists');

        // Act
        $this->permissions->removePermission(self::USER, 'invoice', 'view');

        // Assert
        $this->assertNull($this->ask('view'), 'a removal is silence, not a deny');
    }

    /**
     * Writing the same grant twice replaces rather than accumulates.
     *
     * Two rows for one (subject, object, action) would make the answer depend on
     * row order, and flipping an allow to a deny would leave both on record.
     */
    public function testReWritingAGrantReplacesIt(): void
    {
        // Arrange
        $this->permissions->allow(self::USER, 'invoice', 'view');

        // Act — the same grant, now a deny
        $this->permissions->deny(self::USER, 'invoice', 'view');

        // Assert
        $this->assertFalse($this->ask('view'), 'the later verdict wins');

        $rows = $this->db->queryBuilder()
            ->table('authserver.permissions')
            ->where('subject_id', self::USER)
            ->where('object_type', 'invoice')
            ->where('action', 'view')
            ->get();

        $count = 0;
        foreach ($rows as $ignored) {
            $count++;
        }
        $this->assertSame(1, $count, 'exactly one row survives, not two');
    }

    /**
     * The `admin` privilege is stored as `*` and answers any question.
     *
     * The two names mean the same thing in their respective models. Storing
     * `admin` verbatim would create a row nothing could ever match, because the
     * read path looks for the wildcard.
     */
    public function testAdminIsStoredAsTheWildcardAndAnswersAnything(): void
    {
        // Arrange
        $this->permissions->allow(self::USER, 'invoice', 'admin');

        // Assert — the row really says '*'
        $row = $this->db->queryBuilder()
            ->table('authserver.permissions')
            ->where('subject_id', self::USER)
            ->where('object_type', 'invoice')
            ->first();
        $this->assertSame('*', $row->fields['action']);

        // ...and the wildcard answers a privilege nobody granted by name
        $this->assertTrue($this->ask('anything'));
    }

    /**
     * A grant on one element answers only about that element.
     */
    public function testElementScopingSurvivesTheRoundTrip(): void
    {
        // Arrange
        $this->permissions->allow(self::USER, 'invoice', 'view', '42');

        // Act + Assert
        $this->assertTrue($this->ask('view', '42'), 'the element it was granted on');
        $this->assertNull($this->ask('view', '43'), 'a different element');
    }

    /**
     * A group subject is stored as a role, which is what the new model calls it.
     *
     * Storing it as a user would silently grant the permission to whichever user
     * happens to carry that id.
     */
    public function testAGroupIsStoredAsARole(): void
    {
        // Arrange
        $this->permissions->allow(self::ROLE, 'invoice', 'view', '', 'module', 'group');

        // Act
        $row = $this->db->queryBuilder()
            ->table('authserver.permissions')
            ->where('subject_id', self::ROLE)
            ->first();

        // Assert
        $this->assertSame('role', $row->fields['subject_type']);
    }
}
