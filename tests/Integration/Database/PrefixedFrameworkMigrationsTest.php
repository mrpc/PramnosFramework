<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;
use Pramnos\Framework\Factory;

/**
 * Every framework migration, on a fresh installation with a table prefix, on MySQL and
 * PostgreSQL — the run an application with a prefix makes on its first `migrate`.
 *
 * The suite's fixtures have no prefix, so a migration that named a table in raw SQL ran
 * correctly here and failed on the first prefixed installation: `public.usertokens`,
 * `applications_…`, `ALTER TABLE users`. Nothing may fail, and nothing may be declined for any
 * reason but a capability the backend lacks.
 */
#[CoversNothing]
class PrefixedFrameworkMigrationsTest extends TestCase
{
    private const PROBE = 'pramnos_prefixed_migrations_probe';

    private ?Database $previous = null;

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
        $db->prefix   = 'pf_';
        $db->schema   = $type === 'postgresql' ? 'public' : '';

        return $db;
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        parent::tearDown();
    }

    /** Every framework migration applies on a prefixed installation. */
    #[DataProvider('databases')]
    public function testEveryFrameworkMigrationAppliesWithAPrefix(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
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
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;

        $application           = (new \ReflectionClass(\Pramnos\Application\Application::class))->newInstanceWithoutConstructor();
        $application->database = $db;
        $base                  = MigrationLoader::resolveFrameworkMigrationsBase();
        $migrations            = MigrationLoader::loadFromDirectories(glob($base . '/*', GLOB_ONLYDIR) ?: [], $application);

        try {
            // Act
            $result = (new MigrationRunner($db))->run($migrations);

            // Assert
            $this->assertNotEmpty($result['ran'], 'the sweep found nothing to check');
            $this->assertSame([], $result['failed'], 'failed on a prefixed installation');
            $notCapability = array_filter($result['declined'], static fn (string $why): bool => !str_contains($why, 'the database has no '));
            $this->assertSame([], $notCapability, 'declined for something other than a missing capability');
            $this->assertSame([], $result['warned'], 'statements rejected on a prefixed installation');
        } finally {
            $db->close();
            $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
            $admin->close();
        }
    }
}
