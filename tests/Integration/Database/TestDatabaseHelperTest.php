<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Framework\Testing\TestDatabase;

/**
 * Integration test for the standalone, init-less test-DB helper
 * (Pramnos\Framework\Testing\TestDatabase), against the real PostgreSQL
 * container. Proves it builds a connection from the `database` settings without
 * the MVC init lifecycle, honours the setConnection/reset seams, and that its
 * row-existence assertions work.
 */
class TestDatabaseHelperTest extends TestCase
{
    protected function setUp(): void
    {
        Settings::setSetting('database', [
            'hostname' => 'timescaledb',
            'port'     => 5432,
            'database' => 'pramnos_test',
            'user'     => 'postgres',
            'password' => 'secret',
            'type'     => 'postgresql',
        ], false);
        TestDatabase::reset();
        TestDatabase::connection()->exec('DROP TABLE IF EXISTS "tdh_probe"');
        TestDatabase::connection()->exec('CREATE TABLE "tdh_probe" (id INT, name VARCHAR(50))');
    }

    protected function tearDown(): void
    {
        try {
            TestDatabase::connection()->exec('DROP TABLE IF EXISTS "tdh_probe"');
        } catch (\Throwable) {
            // best effort
        }
        TestDatabase::reset();
    }

    /**
     * connection() builds a live PDO from the `database` settings (no init), and
     * is a per-process singleton.
     */
    public function testConnectionIsBuiltFromSettingsAndMemoised(): void
    {
        $a = TestDatabase::connection();
        $this->assertInstanceOf(\PDO::class, $a);
        $this->assertSame($a, TestDatabase::connection(), 'connection() must memoise');
        $this->assertSame('pgsql', $a->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    /**
     * assertDatabaseHas / assertDatabaseMissing reflect the actual rows.
     */
    public function testRowAssertionsReflectDatabaseState(): void
    {
        TestDatabase::assertDatabaseMissing('tdh_probe', ['id' => 1]);

        $stmt = TestDatabase::connection()->prepare('INSERT INTO "tdh_probe" (id, name) VALUES (?, ?)');
        $stmt->execute([1, 'alice']);

        TestDatabase::assertDatabaseHas('tdh_probe', ['id' => 1, 'name' => 'alice']);
        TestDatabase::assertDatabaseMissing('tdh_probe', ['name' => 'bob']);
    }

    /**
     * setConnection() injects an alternate handle and reset() drops the cache.
     */
    public function testSetConnectionAndResetSeams(): void
    {
        // A distinct real connection (avoids depending on pdo_sqlite).
        $injected = new \PDO('pgsql:host=timescaledb;dbname=pramnos_test', 'postgres', 'secret');
        TestDatabase::setConnection($injected);
        $this->assertSame($injected, TestDatabase::connection());

        TestDatabase::reset();
        $this->assertNotSame($injected, TestDatabase::connection(), 'reset() must rebuild from settings');
    }
}
