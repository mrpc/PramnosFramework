<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\TOTPHelper;
use Pramnos\Auth\TwoFactorAuthService;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\AuthServer\CreateTwofactorTables;

/**
 * Integration tests for the 2FA tables migration.
 *
 * WHAT: the migration that creates user_twofactor / twofactor_setup /
 *       twofactor_attempts, verified two ways — the tables exist / drop / are
 *       idempotent, AND a full TwoFactorAuthService setup→verify→disable
 *       round-trip runs against the created schema.
 * WHY:  the framework shipped the 2FA service but not its tables, so a scaffolded
 *       authserver's 2FA was broken. §8 wants the DML proven against the real DB;
 *       driving the actual service is the strongest proof the schema matches what
 *       the code expects (a missing/renamed column would fail the round-trip).
 *
 * Runs against whichever engine DB_TYPE selects; the CI matrix covers all three.
 */
class TwofactorTablesMigrationTest extends TestCase
{
    private const USER_ID = 880001;

    private Database $db;
    private Application $app;
    private bool $isPg;

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

        $this->app = new Application();
        $this->app->database = $this->db;

        $migration = new CreateTwofactorTables($this->app);
        $migration->down();
        $migration->up();
    }

    protected function tearDown(): void
    {
        try {
            (new CreateTwofactorTables($this->app))->down();
        } catch (\Throwable) {
        }
    }

    /** up() creates all three tables. */
    public function testCreatesAllThreeTables(): void
    {
        $schema = $this->db->schema();
        $this->assertTrue($schema->hasTable('authserver.user_twofactor'));
        $this->assertTrue($schema->hasTable('authserver.twofactor_setup'));
        $this->assertTrue($schema->hasTable('authserver.twofactor_attempts'));
    }

    /** down() drops all three tables. */
    public function testDownDropsTables(): void
    {
        (new CreateTwofactorTables($this->app))->down();
        $schema = $this->db->schema();
        $this->assertFalse($schema->hasTable('authserver.user_twofactor'));
        $this->assertFalse($schema->hasTable('authserver.twofactor_setup'));
        $this->assertFalse($schema->hasTable('authserver.twofactor_attempts'));
    }

    /** up() is idempotent: re-running over existing tables is a no-op, no error. */
    public function testUpIsIdempotent(): void
    {
        // Act — run up() again over the already-created tables.
        (new CreateTwofactorTables($this->app))->up();

        // Assert — still there, still usable.
        $this->assertTrue($this->db->schema()->hasTable('authserver.user_twofactor'));
    }

    /**
     * A full TwoFactorAuthService lifecycle runs against the created schema:
     * start setup → confirm code → enabled → verify a code → disable.
     *
     * If any column the service writes/reads were missing or mistyped, one of
     * these steps would fail — so this is the schema-matches-code proof.
     */
    public function testServiceRoundTripAgainstSchema(): void
    {
        // Arrange
        $service = new TwoFactorAuthService($this->db);

        // Act 1 — begin setup (writes twofactor_setup).
        $setup  = $service->startSetup(self::USER_ID, 'user@example.com', 'Pramnos');
        $secret = $setup['secret'];
        $this->assertNotEmpty($secret);
        $this->assertFalse($service->isEnabled(self::USER_ID), 'Not enabled until confirmed');

        // Act 2 — confirm with a valid TOTP code (writes user_twofactor).
        $confirmed = $service->completeSetup(self::USER_ID, TOTPHelper::generateCode($secret));

        // Assert — enabled and status reflects it.
        $this->assertTrue($confirmed, 'Setup completes with a valid code');
        $this->assertTrue($service->isEnabled(self::USER_ID));
        $status = $service->getStatus(self::USER_ID);
        $this->assertTrue($status['enabled']);
        $this->assertGreaterThan(0, $status['backup_codes_remaining']);

        // Act 3 — verify a fresh code (writes twofactor_attempts + last_used).
        $this->assertTrue($service->verifyCode(self::USER_ID, TOTPHelper::generateCode($secret)));

        // Act 4 — a wrong code is rejected.
        $this->assertFalse($service->verifyCode(self::USER_ID, '000000'));

        // Act 5 — disable clears the state.
        $this->assertTrue($service->disable(self::USER_ID));
        $this->assertFalse($service->isEnabled(self::USER_ID));
    }
}
