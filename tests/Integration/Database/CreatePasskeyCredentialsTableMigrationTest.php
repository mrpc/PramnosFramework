<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\AuthServer\CreatePasskeyCredentialsTable;

/**
 * Integration test for the passkey_credentials table migration (WebAuthn).
 *
 * WHAT: the table is really created with its columns and unique index, and a
 *       credential row round-trips. WHY: it is the store for every registered
 *       passkey; must be additive/idempotent and portable (§8, DB-safety §3).
 *
 * Runs against whichever engine DB_TYPE selects; the CI matrix covers all three.
 */
class CreatePasskeyCredentialsTableMigrationTest extends TestCase
{
    private Database $db;
    private Application $app;
    private bool $isPg;

    private const TBL = 'authserver.passkey_credentials';

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
        $this->db->password  = $_ENV['DB_PASS'] ?? (getenv('DB_PASS') ?: 'secret');
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

        $this->app = new Application();
        $this->app->database = $this->db;

        // Fresh table per test.
        $m = new CreatePasskeyCredentialsTable($this->app);
        $m->down();
        $m->up();
    }

    protected function tearDown(): void
    {
        try {
            (new CreatePasskeyCredentialsTable($this->app))->down();
        } catch (\Throwable) {
            // Non-fatal cleanup.
        }
    }

    public function testTableHasExpectedColumns(): void
    {
        $schema = $this->db->schema();
        foreach (['credentialid', 'userid', 'credential_id', 'public_key', 'sign_count',
                  'aaguid', 'transports', 'name', 'backup_eligible', 'backup_state',
                  'is_active', 'created_at', 'last_used_at'] as $col) {
            $this->assertTrue($schema->hasColumn(self::TBL, $col), "Column {$col} must exist");
        }
    }

    public function testCredentialRowRoundTrips(): void
    {
        // Arrange + Act — insert a passkey row.
        $this->db->queryBuilder()->table(self::TBL)->insert([
            'userid'          => 4242,
            'credential_id'   => 'Y3JlZC1hYmM',
            'public_key'      => 'cG90LWtleQ==',
            'sign_count'      => 7,
            'aaguid'          => '00000000-0000-0000-0000-000000000000',
            'transports'      => json_encode(['internal', 'hybrid']),
            'name'            => 'MacBook Touch ID',
            'backup_eligible' => true,
            'backup_state'    => true,
            'is_active'       => true,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        // Assert — read it back.
        $row = $this->db->queryBuilder()->table(self::TBL)->where('credential_id', 'Y3JlZC1hYmM')->first();
        $this->assertSame(1, (int) $row->numRows);
        $this->assertSame(4242, (int) $row->fields['userid']);
        $this->assertSame(7, (int) $row->fields['sign_count']);
        $this->assertSame(['internal', 'hybrid'], json_decode((string) $row->fields['transports'], true));
    }

    public function testDownDropsTable(): void
    {
        (new CreatePasskeyCredentialsTable($this->app))->down();

        $this->assertFalse($this->db->schema()->hasTable(self::TBL), 'down() must drop the table');
    }
}
