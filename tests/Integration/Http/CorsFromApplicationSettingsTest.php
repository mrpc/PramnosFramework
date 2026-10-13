<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Framework\Testing\Schema;
use Pramnos\Http\Middleware\CorsMiddleware;

/**
 * An application's CORS origins, read from `applications.application_settings` on MySQL and
 * PostgreSQL, with a table prefix and without.
 *
 * The query was raw SQL naming `public.applications` and `applications_application_settings`,
 * which on an installation with a prefix match nothing — and a miss falls back to `*`, so the
 * failure was every origin allowed, silently.
 */
#[CoversClass(CorsMiddleware::class)]
class CorsFromApplicationSettingsTest extends TestCase
{
    private const PROBE = 'pramnos_cors_probe';

    /** @return array<string, array{string, string, int, string, string}> */
    public static function installations(): array
    {
        return [
            'mysql'                => ['mysql', 'db', 3306, 'root', ''],
            'mysql, prefixed'      => ['mysql', 'db', 3306, 'root', 'pf_'],
            'postgresql'           => ['postgresql', 'timescaledb', 5432, 'postgres', ''],
            'postgresql, prefixed' => ['postgresql', 'timescaledb', 5432, 'postgres', 'pf_'],
        ];
    }

    private static function connection(string $type, string $host, int $port, string $user, string $name, string $prefix): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = $name;
        $db->prefix   = $prefix;
        $db->schema   = $type === 'postgresql' ? 'public' : '';

        return $db;
    }

    /**
     * Enabled: the application's own origins. Disabled, no settings row, or no such
     * application: the wildcard.
     */
    #[DataProvider('installations')]
    public function testTheOriginsAreReadUnderTheInstallationsNames(string $type, string $host, int $port, string $user, string $prefix): void
    {
        // Arrange
        $admin = self::connection($type, $host, $port, $user, TEST_DATABASE, $prefix);
        try {
            if (!$admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
        $admin->query('CREATE DATABASE ' . self::PROBE);
        $db = self::connection($type, $host, $port, $user, self::PROBE, $prefix);
        $db->connect(false);

        try {
            Schema::ensure([
                \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverSchema::class,
                \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsSchema::class,
                \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
                \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class,
                \Pramnos\Framework\Migrations\Applications\CreateApplicationSettingsTable::class,
            ], $db);
            $qb = static fn (string $t) => $db->queryBuilder()->table($t);
            foreach (['Portal', 'Closed', 'Bare'] as $name) {
                $qb('#PREFIX#applications')->insert(['name' => $name, 'apikey' => strtolower($name), 'accesstype' => 1]);
            }
            $ids = [];
            foreach ($qb('#PREFIX#applications')->select(['appid', 'name'])->get()->fetchAll() as $row) {
                $ids[$row['name']] = (int) $row['appid'];
            }
            // TEXT[] on PostgreSQL, JSON on MySQL.
            $origins = static fn (string $o): string => $type === 'postgresql' ? '{' . $o . '}' : '["' . $o . '"]';
            $qb('applications.application_settings')->insert(['appid' => $ids['Portal'], 'cors_enabled' => true, 'cors_origins' => $origins('https://portal.example.com')]);
            $qb('applications.application_settings')->insert(['appid' => $ids['Closed'], 'cors_enabled' => false, 'cors_origins' => $origins('https://closed.example.com')]);

            // Act
            $portal  = CorsMiddleware::fromApplicationSettings('Portal', $db)->getAllowedOrigins();
            $closed  = CorsMiddleware::fromApplicationSettings('Closed', $db)->getAllowedOrigins();
            $bare    = CorsMiddleware::fromApplicationSettings('Bare', $db)->getAllowedOrigins();
            $unknown = CorsMiddleware::fromApplicationSettings('Nobody', $db)->getAllowedOrigins();

            // Assert
            $this->assertSame(['https://portal.example.com'], $portal, 'found under this installation\'s names');
            $this->assertSame([['*'], ['*'], ['*']], [$closed, $bare, $unknown]);
        } finally {
            $db->close();
            $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
            $admin->close();
        }
    }
}
