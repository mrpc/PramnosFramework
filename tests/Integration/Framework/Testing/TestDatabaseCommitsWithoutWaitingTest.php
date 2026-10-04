<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Framework\Testing;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestEnvironment;

/**
 * The PostgreSQL test database does not wait for the disk on commit, and only it.
 *
 * A suite commits thousands of small transactions; waiting for each WAL flush roughly
 * doubled one project's run. The setting is per database so that a development database
 * on the same server keeps full durability, which is the half of this that must not break.
 */
#[CoversClass(TestEnvironment::class)]
class TestDatabaseCommitsWithoutWaitingTest extends TestCase
{
    /** Runs the protected setup step against the container's server. */
    private function setUpPostgres(string $dbName): void
    {
        $method = new \ReflectionMethod(TestEnvironment::class, 'setupPostgres');
        $method->invoke(null, 'timescaledb', 5432, $dbName, 'postgres', 'secret', null);
    }

    /** What a new session on that database sees. */
    private function synchronousCommit(string $dbName): string
    {
        $pdo = new PDO('pgsql:host=timescaledb;port=5432;dbname=' . $dbName, 'postgres', 'secret');

        return (string) $pdo->query('SHOW synchronous_commit')->fetchColumn();
    }

    /**
     * After setup, a new session on the test database commits without waiting; one on
     * another database on the same server still waits.
     */
    public function testOnlyTheTestDatabaseStopsWaiting(): void
    {
        // Arrange
        try {
            new PDO('pgsql:host=timescaledb;port=5432;dbname=postgres', 'postgres', 'secret');
        } catch (\PDOException $exception) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $exception->getMessage());
        }

        // Act
        $this->setUpPostgres('pramnos_test');

        // Assert
        $this->assertSame('off', $this->synchronousCommit('pramnos_test'));
        // `postgres` stands in for a development database beside it
        $this->assertSame('on', $this->synchronousCommit('postgres'), 'the setting leaked past the test database');
    }
}
