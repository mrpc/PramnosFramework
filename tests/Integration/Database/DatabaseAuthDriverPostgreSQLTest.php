<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/**
 * Integration tests for DatabaseAuthDriver against PostgreSQL 14 / TimescaleDB.
 *
 * Extends DatabaseAuthDriverMySQLTest — the same behavioural contracts apply on
 * PostgreSQL. Only setUp/tearDown and the SQL helper methods differ because
 * PostgreSQL uses double-quoted identifiers and SERIAL for auto-increment.
 *
 * Each test runs in a separate process to avoid the MySQL Database singleton
 * being reused for the PostgreSQL connection.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
#[RunTestsInSeparateProcesses]
class DatabaseAuthDriverPostgreSQLTest extends DatabaseAuthDriverMySQLTest
{
    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        $pgSettingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'pg_settings.php';
        Settings::loadSettings($pgSettingsFile);

        $this->db = Database::getInstance();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('PostgreSQL container not reachable (timescaledb:5432)');
        }

        // Snapshot Application singleton state
        $ref = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $this->originalAppInstances = $ref->getValue() ?? [];

        // Isolated prefix so tests never conflict with real tables
        $this->originalPrefix = $this->db->prefix;
        $this->db->prefix     = 'testdad_';

        $this->dropTable();
        $this->createTable();
    }

    protected function dropTable(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS "testdad_users"');
    }

    protected function createTable(): void
    {
        $this->db->execute(
            'CREATE TABLE "testdad_users" (
                "userid"    SERIAL PRIMARY KEY,
                "username"  VARCHAR(100) NOT NULL,
                "password"  VARCHAR(255) NOT NULL,
                "email"     VARCHAR(255) NOT NULL DEFAULT \'\',
                "active"    SMALLINT NOT NULL DEFAULT 1,
                "validated" SMALLINT NOT NULL DEFAULT 1
            )'
        );
    }

    protected function insertBcryptUser(
        string $username,
        string $plainPassword,
        int    $active = 1
    ): int {
        $salt = \Pramnos\Application\Settings::getSetting('securitySalt');

        $sql = $this->db->prepareQuery(
            "INSERT INTO \"testdad_users\" (\"username\", \"password\", \"email\", \"active\", \"validated\")
             VALUES (%s, %s, %s, %d, 1)",
            $username,
            'placeholder',
            $username . '@example.com',
            $active
        );
        $this->db->query($sql);
        $uid = (int) $this->db->getInsertId();

        // Recompute hash now that we know the uid
        $hash = password_hash($plainPassword . md5($salt . $uid), PASSWORD_DEFAULT);
        $this->db->query(
            $this->db->prepareQuery(
                'UPDATE "testdad_users" SET "password" = %s WHERE "userid" = %d',
                $hash,
                $uid
            )
        );

        return $uid;
    }

    protected function insertMd5User(string $username, string $plainPassword): int
    {
        $sql = $this->db->prepareQuery(
            "INSERT INTO \"testdad_users\" (\"username\", \"password\", \"email\", \"active\", \"validated\")
             VALUES (%s, %s, %s, 1, 1)",
            $username,
            md5($plainPassword),
            $username . '@example.com'
        );
        $this->db->query($sql);
        return (int) $this->db->getInsertId();
    }

    protected function readStoredPassword(int $userid): string
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                'SELECT "password" FROM "testdad_users" WHERE "userid" = %d',
                $userid
            )
        );
        return (string) ($result->fields['password'] ?? '');
    }
}
