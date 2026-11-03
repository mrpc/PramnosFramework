<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * `Database::replicaConnection()` -- a second, read-only instance pointed at a replica host,
 * independent of the global `read`/`write` config split.
 *
 * FW-313: a caller needs to target a known-live replica for ONE batch job without flipping
 * read/write routing for the whole application (which would risk stale reads elsewhere, e.g.
 * save-then-immediate-read patterns, under real replication lag).
 */
#[CoversClass(Database::class)]
class ReplicaConnectionTest extends TestCase
{
    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'       => ['mysql', 'db', 3306],
            'timescaledb' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    /**
     * A connected database, or a skipped test when the container is not there.
     */
    private function connect(string $type, string $host, int $port): Database
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;

        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        return $db;
    }

    /**
     * An explicit hostname is enough -- no credentials re-entered -- to get a working,
     * separately-connected instance that can run reads.
     */
    #[DataProvider('databases')]
    public function testReplicaConnectionReadsWithoutReenteringCredentials(
        string $type,
        string $host,
        int $port
    ): void {
        // Arrange
        $db = $this->connect($type, $host, $port);

        // Act -- same container stands in for "the replica" in this test environment
        $replica = $db->replicaConnection($host, $port);

        // Assert -- a real, independent connection that can run a read
        $this->assertNotSame($db, $replica);
        $this->assertSame(1, (int) $replica->query('SELECT 1 AS one')->fields['one']);
    }

    /**
     * A write statement on the replica instance throws instead of running -- the guard this
     * was explicitly asked for, so a write on a replica fails loudly in PHP, not silently or
     * with an opaque database-level error.
     */
    #[DataProvider('databases')]
    public function testWriteOnReplicaConnectionThrowsInsteadOfRunning(
        string $type,
        string $host,
        int $port
    ): void {
        // Arrange
        $db = $this->connect($type, $host, $port);
        $table = 'replica_guard_test_' . bin2hex(random_bytes(4));
        $db->query("CREATE TABLE {$table} (id INT)");
        $replica = $db->replicaConnection($host, $port);

        try {
            // Act / Assert -- the write never reaches the server
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('read-only replica connection');
            $replica->query("INSERT INTO {$table} (id) VALUES (1)");
        } finally {
            $db->query("DROP TABLE {$table}");
        }
    }

    /**
     * No hostname given, and none configured under the "read" settings: a clear error up
     * front rather than a replica silently pointed at this instance's own primary host.
     */
    #[DataProvider('databases')]
    public function testNoHostnameConfiguredThrows(string $type, string $host, int $port): void
    {
        // Arrange
        $db = $this->connect($type, $host, $port);

        // Act / Assert
        $this->expectException(\InvalidArgumentException::class);
        $db->replicaConnection();
    }

    /**
     * The original instance is left fully untouched -- still able to write -- by taking a
     * replica connection off it.
     */
    #[DataProvider('databases')]
    public function testOriginalInstanceStaysWritable(string $type, string $host, int $port): void
    {
        // Arrange
        $db = $this->connect($type, $host, $port);
        $table = 'replica_guard_original_' . bin2hex(random_bytes(4));

        // Act -- take a replica connection, then write on the original as normal
        $db->replicaConnection($host, $port);
        $created = $db->query("CREATE TABLE {$table} (id INT)");

        // Assert
        $this->assertNotFalse($created);
        $db->query("DROP TABLE {$table}");
    }
}
