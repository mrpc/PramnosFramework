<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;
use Pramnos\Framework\Factory;

/**
 * The framework's own queries over `organizations`, `applications` and `queueitems`, on an
 * installation with a table prefix, on MySQL and PostgreSQL.
 *
 * The models already named these tables with the prefix — `Model::resolveTableName()` prefixes
 * even a bare name — while about forty queries beside them named them bare, so on a prefixed
 * installation the queue screen, the organisation names and a user's tokens read tables that do
 * not exist, while the models wrote to the ones that did.
 */
#[CoversClass(\Pramnos\Auth\OrganizationAdmin::class)]
#[CoversClass(\Pramnos\Auth\Controllers\Session::class)]
#[CoversClass(\Pramnos\Mcp\Tools\StatusTool::class)]
class PrefixedRuntimeTablesTest extends TestCase
{
    private const PROBE = 'pramnos_prefixed_runtime_probe';

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

    /** An organisation's name, a user's tokens with their application, and the queue's counts. */
    #[DataProvider('databases')]
    public function testTheFrameworksQueriesFindThePrefixedTables(string $type, string $host, int $port, string $user): void
    {
        // Arrange — every framework migration, under the prefix
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

        try {
            $migrations = MigrationLoader::loadFromDirectories(glob(MigrationLoader::resolveFrameworkMigrationsBase() . '/*', GLOB_ONLYDIR) ?: [], $application);
            (new MigrationRunner($db))->run($migrations);
            $qb = static fn (string $t) => $db->queryBuilder()->table($t);

            $qb('#PREFIX#organizations')->insert(['name' => 'Daily News', 'org_type' => 'newsroom', 'is_active' => true]);
            $orgId = (int) $qb('#PREFIX#organizations')->first()->fields['organization_id'];
            $qb('#PREFIX#users')->insert(['username' => 'maria', 'password' => 'x', 'email' => 'm@example.com', 'active' => 1]);
            $userId = (int) $qb('#PREFIX#users')->where('username', 'maria')->first()->fields['userid'];
            $qb('#PREFIX#applications')->insert(['name' => 'Connector', 'apikey' => 'k1', 'accesstype' => 1]);
            $appId = (int) $qb('#PREFIX#applications')->first()->fields['appid'];
            $qb('#PREFIX#usertokens')->insert(['userid' => $userId, 'tokentype' => 'access_token', 'token' => 't1', 'status' => 1, 'created' => time(), 'expires' => 0, 'applicationid' => $appId, 'deviceinfo' => '', 'scope' => '']);
            foreach (['pending', 'pending', 'failed'] as $status) {
                $qb('#PREFIX#queueitems')->insert(['type' => 'email', 'payload' => '{}', 'status' => $status]);
            }

            // Act
            $name   = \Pramnos\Auth\OrganizationAdmin::nameOf($orgId);
            $tokens = (new \ReflectionMethod(\Pramnos\Auth\Controllers\Session::class, 'getActiveTokens'))
                ->invoke((new \ReflectionClass(\Pramnos\Auth\Controllers\Session::class))->newInstanceWithoutConstructor(), $userId);
            $queue  = (new \ReflectionMethod(\Pramnos\Mcp\Tools\StatusTool::class, 'queue'))
                ->invoke(new \Pramnos\Mcp\Tools\StatusTool($application));

            // Assert
            $this->assertSame('Daily News', $name);
            $this->assertSame(['Connector'], array_column($tokens, 'app_name'), 'the token, joined with its application');
            $counts = array_intersect_key($queue['by_status'], ['failed' => 0, 'pending' => 0]);
            ksort($counts);
            $this->assertSame(['failed' => 1, 'pending' => 2], $counts, 'the queue counted where QueueItem writes');
        } finally {
            $db->close();
            $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
            $admin->close();
        }
    }
}
