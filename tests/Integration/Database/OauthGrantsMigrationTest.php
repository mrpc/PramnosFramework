<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\AuthServer\CreateOauth2ApplicationGrantsTable;
use Pramnos\Framework\Testing\Schema;

/**
 * `applications.oauth2_application_grants`, its views and its cleanup function, built on MySQL
 * and PostgreSQL, with a table prefix and without.
 *
 * The migration was raw SQL naming `public.applications` and `applications_…`, so on an
 * installation with a prefix it referenced tables that do not exist. Built with the schema
 * builder, every name — the table, the one its foreign key references, the views' sources —
 * resolves as the rest of the installation's do.
 */
#[CoversClass(CreateOauth2ApplicationGrantsTable::class)]
#[CoversClass(\Pramnos\Database\SchemaBuilder::class)]
class OauthGrantsMigrationTest extends TestCase
{
    private const PROBE = 'pramnos_grants_probe';

    private ?Database $previous = null;

    /** @return array<string, array{string, string, int, string, string}> */
    public static function installations(): array
    {
        return [
            'mysql'               => ['mysql', 'db', 3306, 'root', ''],
            'mysql, prefixed'     => ['mysql', 'db', 3306, 'root', 'pf_'],
            'postgresql'          => ['postgresql', 'timescaledb', 5432, 'postgres', ''],
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

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        parent::tearDown();
    }

    /**
     * The grants table under the installation's names: an application's grants are written and
     * read through GrantPolicy, its foreign key cascades from the prefixed applications table,
     * the views read both, the cleanup function runs, and the later jwt_bearer migration finds
     * the CHECK it replaces.
     */
    #[DataProvider('installations')]
    public function testTheGrantsTableUnderTheInstallationsNames(string $type, string $host, int $port, string $user, string $prefix): void
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
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;

        try {
            Schema::ensure([
                \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverSchema::class,
                \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsSchema::class,
                \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
                \Pramnos\Framework\Migrations\Auth\CreateUsertokensTable::class,
                \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class,
                CreateOauth2ApplicationGrantsTable::class,
                \Pramnos\Framework\Migrations\AuthServer\AllowJwtBearerInOauth2ApplicationGrants::class,
            ], $db);
            $qb = static fn (string $t) => $db->queryBuilder()->table($t);
            $qb('#PREFIX#applications')->insert(['name' => 'Connector', 'apikey' => 'k1', 'accesstype' => 1, 'scope' => 'read', 'callback' => 'https://x.test/cb']);
            $appId = (int) $qb('#PREFIX#applications')->first()->fields['appid'];

            // Act
            \Pramnos\Auth\OAuth2\GrantPolicy::setGrants($appId, ['authorization_code', 'jwt_bearer']);
            $grants      = \Pramnos\Auth\OAuth2\GrantPolicy::grants($appId);
            $permissions = $db->query('SELECT allowed_grants FROM ' . $db->schema()->quoteTable('applications.oauth2_application_permissions') . ' WHERE appid = ' . $appId)->fields;
            $active      = $db->query('SELECT COUNT(*) AS n FROM ' . $db->schema()->quoteTable('applications.oauth2_active_tokens'))->fields;
            $qb('#PREFIX#applications')->where('appid', $appId)->delete();
            $left        = $qb('applications.oauth2_application_grants')->count();

            // Assert
            $this->assertTrue($db->schema()->hasTable('applications.oauth2_application_grants'));
            $this->assertEqualsCanonicalizing(['authorization_code', 'jwt_bearer'], $grants, 'written and read where the migration put it');
            $this->assertStringContainsString('jwt_bearer', (string) $permissions['allowed_grants'], 'the view reads the grants');
            $this->assertSame(0, (int) $active['n']);
            $this->assertSame(0, $left, 'the foreign key references the applications table this installation has, and cascades');
            if ($type === 'postgresql') {
                $this->assertSame(0, (int) $db->query('SELECT authserver.cleanup_expired_oauth2_tokens() AS n')->fields['n']);
            }

            // And down() takes it all away again.
            $application           = (new \ReflectionClass(\Pramnos\Application\Application::class))->newInstanceWithoutConstructor();
            $application->database = $db;
            (new CreateOauth2ApplicationGrantsTable($application))->down();
            $this->assertFalse($db->schema()->hasTable('applications.oauth2_application_grants'));
        } finally {
            $db->close();
            $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
            $admin->close();
        }
    }

    /**
     * An installation whose grants table carries the CHECK on the column — what MariaDB keeps
     * from the earlier version of the create migration — takes `jwt_bearer`: on MariaDB the
     * column is redefined without its CHECK, which `DROP CONSTRAINT` cannot reach, and the new
     * CHECK still refuses a grant type nobody declared.
     */
    #[DataProvider('installations')]
    public function testAnOlderColumnCheckIsReplaced(string $type, string $host, int $port, string $user, string $prefix): void
    {
        // Arrange — the table as an earlier version made it, CHECK on the column
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
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;

        try {
            Schema::ensure([\Pramnos\Framework\Migrations\AuthServer\CreateApplicationsSchema::class], $db);
            $schema = $db->schema();
            $schema->createTable('applications.oauth2_application_grants', function ($table) {
                $table->increments('grant_id');
                $table->integer('appid')->unsigned();
                $table->string('grant_type', 50)->check("grant_type IN ('authorization_code', 'client_credentials')")->comment('The grant');
            });
            $application           = (new \ReflectionClass(\Pramnos\Application\Application::class))->newInstanceWithoutConstructor();
            $application->database = $db;

            // Act
            (new \Pramnos\Framework\Migrations\AuthServer\AllowJwtBearerInOauth2ApplicationGrants($application))->up();
            $db->queryBuilder()->table('applications.oauth2_application_grants')->insert(['appid' => 1, 'grant_type' => 'jwt_bearer']);
            $refused = false;
            try {
                $db->queryBuilder()->table('applications.oauth2_application_grants')->insert(['appid' => 1, 'grant_type' => 'made_up']);
            } catch (\Throwable) {
                $refused = true;
            }

            // Assert
            $this->assertSame(1, $db->queryBuilder()->table('applications.oauth2_application_grants')->count(), 'jwt_bearer is taken');
            $this->assertTrue($refused, 'and an undeclared grant type is still refused');
        } finally {
            $db->close();
            $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
            $admin->close();
        }
    }
}
