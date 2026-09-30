<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Framework;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\Schema;

/**
 * `Schema::ensure()` creates the PostgreSQL schema a table lives in before the table.
 *
 * A migration names its schema in `$dependencies` and the runner honours it; the test
 * helper did not, so an `authserver.*` table was built only while something had left the
 * schema standing. The migration tests drop it, and a test built on this helper then
 * failed with "schema authserver does not exist" — but only in a run where one of them
 * went first, which is the kind of failure nobody can bisect.
 */
#[CoversClass(Schema::class)]
class TestingSchemaCreatesTheSchemaFirstTest extends TestCase
{
    private Database $db;

    private ?Database $previous = null;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $this->db           = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped('PostgreSQL not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }

        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;
    }

    protected function tearDown(): void
    {
        $singleton = &Factory::getDatabase();
        $singleton = $this->previous;
    }

    /**
     * With the `authserver` schema gone, ensuring a table in it creates both.
     */
    public function testAMissingSchemaIsCreatedBeforeItsTable(): void
    {
        // Arrange — the state a migration test leaves behind. Inside a transaction, because
        // PostgreSQL's DDL is transactional: the rollback puts every authserver table back,
        // so this test does not become one more thing that drops the schema under the rest.
        $this->db->startTransaction();

        try {
            $this->db->query('DROP SCHEMA IF EXISTS authserver CASCADE');

            // Act
            Schema::ensure([\Pramnos\Framework\Migrations\Auth\CreateLoginlockoutTable::class], $this->db);

            // Assert
            $exists = $this->db->query(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = 'authserver' AND table_name = 'loginlockouts'"
            )->numRows;
            $this->assertSame(1, (int) $exists);
        } finally {
            $this->db->rollbackTransaction();
        }
    }

    /**
     * A stub of a table — one a migration test built with two columns and left behind —
     * is replaced by the real thing rather than taken as it is.
     *
     * Without this, every migration's `up()` returned early because the table existed and
     * the next test inserting an `apikey` was told the statement could not be prepared.
     */
    public function testAStubTableIsRebuiltInItsProductionShape(): void
    {
        // Arrange — rolled back at the end, so the real table is untouched.
        $this->db->startTransaction();

        try {
            $this->db->query('DROP TABLE IF EXISTS public.applications CASCADE');
            $this->db->query('CREATE TABLE public.applications (appid serial PRIMARY KEY, name varchar(255))');

            // Act
            Schema::table('applications', $this->db);

            // Assert
            $this->assertTrue($this->db->schema()->hasColumn('applications', 'apikey'), 'the stub was kept');
            $this->assertTrue($this->db->schema()->hasColumn('applications', 'apisecret'));
        } finally {
            $this->db->rollbackTransaction();
        }
    }
}
