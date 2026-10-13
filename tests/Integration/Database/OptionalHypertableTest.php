<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\SchemaBuilder;
use Pramnos\Framework\Testing\Schema;

/**
 * The `auth` tables whose hypertable is an extra, migrated on MySQL (no TimescaleDB) and on
 * PostgreSQL with it.
 *
 * On MariaDB each created its table and was then declined — "the database has no timescaledb,
 * so nothing was applied" — and the next `migrate`, finding the table, recorded it as applied.
 * The runner declines on whatever the migration deferred; here nothing may be deferred, and the
 * table must exist.
 */
#[CoversNothing]
class OptionalHypertableTest extends TestCase
{
    private const MIGRATIONS = [
        \Pramnos\Framework\Migrations\Auth\CreateTwofactorAttemptsTable::class => 'authserver.twofactor_attempts',
        \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class => 'authserver.user_activity_log',
        \Pramnos\Framework\Migrations\Auth\CreateUserConsentsTable::class => 'authserver.user_consents',
        \Pramnos\Framework\Migrations\Auth\CreateDataProcessingRecordsTable::class => 'authserver.data_processing_records',
        \Pramnos\Framework\Migrations\Auth\CreateGdprRequestsTable::class => 'authserver.gdpr_requests',
    ];

    private const PROBE = 'pramnos_optional_hypertable_probe';

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    private static function connection(string $type, string $host, int $port, string $user, string $name): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = $name;
        $db->schema   = $type === 'postgresql' ? 'public' : '';

        return $db;
    }

    /** Each migration, on a database of its own: its table made, and nothing held back. */
    #[DataProvider('databases')]
    public function testTheTableIsMadeAndNothingIsHeldBack(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $admin = self::connection($type, $host, $port, $user, TEST_DATABASE);
        try {
            if (!$admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
        $admin->query('CREATE DATABASE ' . self::PROBE);
        $db = self::connection($type, $host, $port, $user, self::PROBE);
        $db->connect(false);
        Schema::ensure([\Pramnos\Framework\Migrations\AuthServer\CreateAuthserverSchema::class], $db);

        try {
            // Act
            $deferred = [];
            foreach (array_keys(self::MIGRATIONS) as $class) {
                SchemaBuilder::resetDeferredCapabilities();
                Schema::ensure([$class], $db);
                $deferred[$class] = SchemaBuilder::deferredCapabilities();
            }

            // Assert
            $this->assertNotEmpty($deferred, 'the sweep found nothing to check');
            foreach (self::MIGRATIONS as $class => $table) {
                $this->assertSame([], $deferred[$class], $class . ' held back for a capability');
                $this->assertTrue($db->schema()->hasTable($table), $table . ' was not made');
            }
        } finally {
            SchemaBuilder::resetDeferredCapabilities();
            $db->close();
            $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
            $admin->close();
        }
    }
}
