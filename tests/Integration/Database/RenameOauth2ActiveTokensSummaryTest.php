<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Applications\RenameOauth2ActiveTokensSummary;
use Pramnos\Framework\Testing\Schema;

/**
 * `applications.oauth2_active_tokens` goes back to the per-token view on an installation where
 * the per-application summary took its name.
 *
 * On PostgreSQL `create_applications_views` replaced the per-token view
 * `create_oauth2_application_grants_table` had made with a summary under the same name. Both
 * migrations now agree with MySQL (`…_summary`), but they do not run again where they are
 * recorded — every existing PostgreSQL installation, production included — so this migration
 * moves the one view there.
 */
#[CoversClass(RenameOauth2ActiveTokensSummary::class)]
class RenameOauth2ActiveTokensSummaryTest extends TestCase
{
    private const PROBE = 'pramnos_active_tokens_probe';

    private ?Database $previous = null;

    private ?Database $db = null;

    private ?Database $admin = null;

    /** @return array<string, array{string}> */
    public static function prefixes(): array
    {
        return ['no prefix' => [''], 'prefixed' => ['pf_']];
    }

    private static function connection(string $name, string $prefix): Database
    {
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = $name;
        $db->prefix   = $prefix;
        $db->schema   = 'public';

        return $db;
    }

    protected function tearDown(): void
    {
        $this->db?->close();
        if ($this->admin !== null) {
            $this->admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
            $this->admin->close();
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        parent::tearDown();
    }

    /**
     * A probe database with the per-token view, as the grants migration leaves it.
     */
    private function installation(string $prefix): Database
    {
        $admin = self::connection(TEST_DATABASE, $prefix);
        try {
            if (!$admin->connect(false)) {
                $this->markTestSkipped('PostgreSQL not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
        $admin->query('DROP DATABASE IF EXISTS ' . self::PROBE);
        $admin->query('CREATE DATABASE ' . self::PROBE);
        $this->admin = $admin;

        $db = self::connection(self::PROBE, $prefix);
        $db->connect(false);
        $this->db       = $db;
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;

        Schema::ensure([
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverSchema::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsSchema::class,
            \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUsertokensTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateOauth2ApplicationGrantsTable::class,
        ], $db);

        return $db;
    }

    private function migration(Database $db): RenameOauth2ActiveTokensSummary
    {
        $application           = (new \ReflectionClass(\Pramnos\Application\Application::class))->newInstanceWithoutConstructor();
        $application->database = $db;

        return new RenameOauth2ActiveTokensSummary($application);
    }

    /** Whether a column can be selected from a view, resolved as the installation names it. */
    private function selects(Database $db, string $view, string $column): bool
    {
        try {
            $db->query('SELECT ' . $column . ' FROM ' . $db->schema()->quoteTable($view) . ' LIMIT 0');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Where the summary holds the per-token name — production's state — the two views end up
     * under their own names, each in its own shape.
     */
    #[DataProvider('prefixes')]
    public function testTheSummaryIsMovedOffThePerTokenName(string $prefix): void
    {
        // Arrange — the summary under the per-token name, as create_applications_views left it
        $db     = $this->installation($prefix);
        $schema = $db->schema();
        $db->query('DROP VIEW ' . $schema->quoteTable('applications.oauth2_active_tokens'));
        $db->query('CREATE VIEW ' . $schema->quoteTable('applications.oauth2_active_tokens')
            . ' AS SELECT a.appid, COUNT(ut.tokenid) AS token_count FROM ' . $schema->quoteTable('applications')
            . ' a LEFT JOIN ' . $schema->quoteTable('usertokens') . ' ut ON ut.applicationid = a.appid GROUP BY a.appid');

        // Act
        $this->migration($db)->up();

        // Assert
        $this->assertTrue(
            $this->selects($db, 'applications.oauth2_active_tokens', 'tokenid, client_id, username'),
            'the per-token view must hold its name again'
        );
        $this->assertFalse(
            $this->selects($db, 'applications.oauth2_active_tokens', 'token_count'),
            'and no longer be the summary'
        );
        $this->assertTrue(
            $this->selects($db, 'applications.oauth2_active_tokens_summary', 'appid, token_count, expired_count, revoked_count, avg_expiry_days'),
            'the summary must exist under its own name, in its full shape'
        );
    }

    /**
     * On an installation already in the right shape the migration changes nothing — the common
     * case for every installation built after the fix.
     */
    #[DataProvider('prefixes')]
    public function testThePerTokenViewIsLeftAlone(string $prefix): void
    {
        // Arrange — the per-token view, and no summary
        $db = $this->installation($prefix);

        // Act
        $this->migration($db)->up();

        // Assert
        $this->assertTrue($this->selects($db, 'applications.oauth2_active_tokens', 'tokenid'));
        // Not created here: on a fresh installation create_applications_views makes it.
        $this->assertFalse($this->selects($db, 'applications.oauth2_active_tokens_summary', 'appid'));
    }

    /**
     * A materialized view under the name is an application's own and is not touched, the same
     * rule create_applications_views follows for usage_statistics.
     */
    public function testAMaterializedViewUnderTheNameIsLeftAlone(): void
    {
        // Arrange
        $db = $this->installation('');
        $db->query('DROP VIEW applications.oauth2_active_tokens');
        $db->query('CREATE MATERIALIZED VIEW applications.oauth2_active_tokens AS SELECT 1 AS appid, 0 AS token_count');

        // Act
        $this->migration($db)->up();

        // Assert
        $this->assertSame('m', $db->schema()->getRelationKind('applications.oauth2_active_tokens'));
        $this->assertFalse($this->selects($db, 'applications.oauth2_active_tokens_summary', 'appid'));
    }

    /**
     * MySQL never had the clash — its summary was always `…_summary` — so nothing is touched.
     */
    public function testMySqlIsLeftAlone(): void
    {
        // Arrange
        $db           = new Database();
        $db->type     = 'mysql';
        $db->server   = 'db';
        $db->port     = 3306;
        $db->user     = 'root';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped('MySQL not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not reachable: ' . $e->getMessage());
        }
        $this->db = $db;
        // A view in the summary's shape under MySQL's per-token name: on PostgreSQL this is
        // exactly what gets moved, so leaving it proves the MySQL branch returns first
        $db->query('CREATE OR REPLACE VIEW `applications_oauth2_active_tokens` AS SELECT 1 AS appid, 0 AS token_count');

        try {
            // Act
            $this->migration($db)->up();

            // Assert
            $this->assertTrue($this->selects($db, 'applications.oauth2_active_tokens', 'token_count'));
        } finally {
            $db->query('DROP VIEW IF EXISTS `applications_oauth2_active_tokens`');
        }
    }
}
