<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\TwoFactorAuthService;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * `auth:twofactor-cleanup` against a real `authserver.twofactor_setup` — the framework's shape and
 * an older one.
 *
 * The framework makes `used` a small integer and `expires_at` a Unix time. An installation whose
 * table predates its migration can have `used` boolean and `expires_at` a timestamp, and
 * PostgreSQL compares neither with an integer: the nightly cleanup failed there and the expired
 * sessions stayed. What holds on both shapes: a used session and an expired one go, a live one
 * stays.
 *
 * {@see TwoFactorSetupCleanupPostgreSQLTest} re-runs it on PostgreSQL, where the older shape is the
 * one that failed.
 */
#[CoversClass(TwoFactorAuthService::class)]
#[\PHPUnit\Framework\Attributes\Group('serial')]
class TwoFactorSetupCleanupTest extends BaseTestCase
{
    private const TABLE = 'authserver.twofactor_setup';

    private $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        $app = Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        try {
            if (!$this->db->connected) {
                $this->db->connect();
            }
        } catch (\Throwable) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }
        $app->database = $this->db;
        $this->frameworkTable();
    }

    protected function tearDown(): void
    {
        if ($this->db && $this->db->connected) {
            $this->frameworkTable();
        }
        parent::tearDown();
    }

    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** The table as the framework's migration makes it, empty. */
    private function frameworkTable(): void
    {
        // The older shape, left by a test, is recognised by its timestamp.
        $expires = $this->db->schema()->columnType(self::TABLE, 'expires_at');
        if ($expires !== null && !in_array($expires, ['int', 'integer'], true)) {
            $this->db->schema()->dropTableIfExists(self::TABLE);
        }
        $this->runMigrations([\Pramnos\Framework\Migrations\Auth\CreateTwofactorSetupTable::class], $this->db);
        $this->db->queryBuilder()->table(self::TABLE)->delete();
    }

    /** The ids left in the table. */
    private function left(): array
    {
        return array_map('intval', $this->db->queryBuilder()->table(self::TABLE)->orderBy('userid')->pluck('userid'));
    }

    /**
     * The framework's shape: a used session and an expired one go, a live one stays.
     */
    public function testTheFrameworksShape(): void
    {
        // Arrange
        $now = time();
        foreach ([[1, 1, $now + 900], [2, 0, $now - 60], [3, 0, $now + 900]] as [$user, $used, $expires]) {
            $this->db->queryBuilder()->table(self::TABLE)->insert([
                'userid' => $user, 'temp_secret' => 'S', 'used' => $used, 'expires_at' => $expires, 'created_at' => $now,
            ]);
        }

        // Act
        (new TwoFactorAuthService($this->db))->cleanupExpiredSessions();

        // Assert
        $this->assertSame([3], $this->left());
    }

    /**
     * An older shape — `used` boolean, `expires_at` a timestamp — is cleaned the same way
     * instead of failing on the comparison.
     */
    public function testAnOlderShape(): void
    {
        // Arrange
        $schema = $this->db->schema();
        $schema->dropTableIfExists(self::TABLE);
        $schema->createTable(self::TABLE, function ($table) {
            $table->increments('id');
            $table->bigInteger('userid');
            $table->string('temp_secret', 64);
            $table->boolean('used')->default(false);
            $table->timestamp('expires_at');
        });
        foreach ([[1, true, '+15 minutes'], [2, false, '-1 minute'], [3, false, '+15 minutes']] as [$user, $used, $expires]) {
            $this->db->queryBuilder()->table(self::TABLE)->insert([
                'userid' => $user, 'temp_secret' => 'S', 'used' => $used, 'expires_at' => gmdate('Y-m-d H:i:s', strtotime($expires)),
            ]);
        }

        // Act
        (new TwoFactorAuthService($this->db))->cleanupExpiredSessions();

        // Assert
        $this->assertSame([3], $this->left(), 'the used and the expired went, on a boolean and a timestamp');
    }
}
