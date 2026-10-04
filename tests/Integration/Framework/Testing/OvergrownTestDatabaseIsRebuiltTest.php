<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Framework\Testing;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\TestEnvironment;

/**
 * A test database that has outgrown a configured limit is dropped, to be rebuilt.
 *
 * Kept between runs, a test database carries whatever tests leave behind, and every later
 * run pays for it. The limit is the floor under cleanup: past it the database starts again
 * from nothing. Below it, and when the database or the table does not exist, nothing happens.
 */
#[CoversClass(TestEnvironment::class)]
class OvergrownTestDatabaseIsRebuiltTest extends TestCase
{
    private const PROBE = 'pramnos_rebuild_probe';

    /** @return array<string, array{string, string, int, string}> */
    public static function servers(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    /** A connection to the server, outside any database. */
    private function server(string $type, string $host, int $port, string $user): PDO
    {
        $dsn = $type === 'mysql' ? "mysql:host=$host;port=$port" : "pgsql:host=$host;port=$port;dbname=postgres";

        try {
            return new PDO($dsn, $user, 'secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\PDOException $exception) {
            $this->markTestSkipped($host . ' not reachable: ' . $exception->getMessage());
        }
    }

    /** The probe database with a table of three rows. */
    private function seed(string $type, string $host, int $port, string $user): void
    {
        $server = $this->server($type, $host, $port, $user);
        $server->exec('DROP DATABASE IF EXISTS ' . self::PROBE);
        $server->exec('CREATE DATABASE ' . self::PROBE);
        $dsn = $type === 'mysql'
            ? "mysql:host=$host;port=$port;dbname=" . self::PROBE
            : "pgsql:host=$host;port=$port;dbname=" . self::PROBE;
        $db = new PDO($dsn, $user, 'secret', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE leaky (id INT)');
        $db->exec('INSERT INTO leaky VALUES (1), (2), (3)');
    }

    /** Whether the probe database exists. */
    private function exists(string $type, string $host, int $port, string $user): bool
    {
        $server = $this->server($type, $host, $port, $user);
        $sql = $type === 'mysql'
            ? "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '" . self::PROBE . "'"
            : "SELECT COUNT(*) FROM pg_database WHERE datname = '" . self::PROBE . "'";

        return (int) $server->query($sql)->fetchColumn() > 0;
    }

    /** Run the check with these limits. */
    private function check(string $type, string $host, int $port, string $user, array $limits): void
    {
        $method = new \ReflectionMethod(TestEnvironment::class, 'rebuildIfOvergrown');
        $method->invoke(null, $type, $host, $port, self::PROBE, $user, 'secret', $limits);
    }

    protected function tearDown(): void
    {
        foreach (self::servers() as [$type, $host, $port, $user]) {
            try {
                $dsn = $type === 'mysql' ? "mysql:host=$host;port=$port" : "pgsql:host=$host;port=$port;dbname=postgres";
                (new PDO($dsn, $user, 'secret'))->exec('DROP DATABASE IF EXISTS ' . self::PROBE);
            } catch (\Throwable) {
                // Not reachable: nothing was made there.
            }
        }
    }

    /**
     * Past the limit, the database is dropped; at or below it, and for a table that does
     * not exist, it is kept.
     */
    #[DataProvider('servers')]
    public function testOnlyATablePastItsLimitDropsTheDatabase(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->seed($type, $host, $port, $user);

        // Act & Assert — at the limit, and an absent table: kept
        $this->check($type, $host, $port, $user, ['leaky' => 3, 'absent' => 0]);
        $this->assertTrue($this->exists($type, $host, $port, $user), 'a database within its limits was dropped');

        // Act & Assert — one past it: dropped
        $this->check($type, $host, $port, $user, ['leaky' => 2]);
        $this->assertFalse($this->exists($type, $host, $port, $user), 'an overgrown database was kept');
    }

    /**
     * A database that does not exist yet has not grown, and is not an error.
     */
    #[DataProvider('servers')]
    public function testAMissingDatabaseIsLeftAlone(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->server($type, $host, $port, $user)->exec('DROP DATABASE IF EXISTS ' . self::PROBE);

        // Act — must not raise
        $this->check($type, $host, $port, $user, ['leaky' => 0]);

        // Assert
        $this->assertFalse($this->exists($type, $host, $port, $user));
    }
}
