<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * Integration tests for ORM Relations against PostgreSQL 14 / TimescaleDB.
 *
 * Mirrors OrmRelationsMySQLTest exactly — same relation types, same edge
 * cases — but runs against the timescaledb Docker container.  Uses the same
 * singleton-replacement technique as QueueManagerPostgreSQLTest: setUp()
 * obtains the Database::getInstance() reference via Factory::getDatabase(),
 * replaces it with a live PostgreSQL connection, and tearDown() restores the
 * MySQL singleton so subsequent tests in the full suite are unaffected.
 *
 * The only code differences from the MySQL suite are:
 *  - Table DDL: standard PostgreSQL syntax (no backticks, no ENGINE clause)
 *  - getFullTableName() returns schema-qualified names (public.orm_test_*)
 *    when the DB has schema='public'; our tables live in the default schema.
 *  - INSERT helpers use RETURNING id to get the new primary key.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432).
 */
class OrmRelationsPostgreSQLTest extends OrmRelationsMySQLTest
{
    // -------------------------------------------------------------------------
    // Override connection setup for PostgreSQL
    // -------------------------------------------------------------------------

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

        // Load MySQL settings first so Application::getInstance() doesn't fail.
        // We'll swap the DB singleton to PostgreSQL immediately after.
        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $pgSettings = Settings::getSetting('postgresql');
        if (!$pgSettings) {
            $this->markTestSkipped('PostgreSQL settings not found in settings.php');
        }

        // Build a direct PostgreSQL connection bypassing the MySQL singleton
        $pgDb           = new Database();
        $pgDb->type     = 'postgresql';
        $pgDb->server   = $pgSettings->hostname;
        $pgDb->user     = $pgSettings->user;
        $pgDb->password = $pgSettings->password;
        $pgDb->database = $pgSettings->database;
        $pgDb->port     = $pgSettings->port ?? 5432;
        $pgDb->schema   = $pgSettings->schema ?? 'public';

        try {
            $pgDb->connect(true);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped(
                'TimescaleDB container not reachable (' . $pgSettings->hostname . ':' . ($pgSettings->port ?? 5432) . '): ' . $e->getMessage()
            );
        }

        if (!$pgDb->connected) {
            $this->markTestSkipped('PostgreSQL container not reachable');
        }

        // Replace the global Database::getInstance() singleton so ORM internals
        // (Model::_getList, Relations::getResults) use the PostgreSQL connection.
        $singleton  = &Factory::getDatabase();
        $singleton  = $pgDb;
        $this->db   = $pgDb;

        $this->controller = $this->makeController();

        $this->dropTables();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        // Restore the singleton to MySQL so the rest of the test suite is unaffected.
        $singleton = &Factory::getDatabase();
        $singleton = null;
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');
    }

    // -------------------------------------------------------------------------
    // PostgreSQL DDL
    // -------------------------------------------------------------------------

    protected function createTables(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS orm_test_users (
                id   SERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL
            )"
        );
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS orm_test_profiles (
                id      SERIAL PRIMARY KEY,
                user_id INTEGER NULL,
                bio     TEXT NULL
            )"
        );
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS orm_test_posts (
                id      SERIAL PRIMARY KEY,
                user_id INTEGER NULL,
                title   VARCHAR(255) NOT NULL
            )"
        );
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS orm_test_tags (
                id   SERIAL PRIMARY KEY,
                name VARCHAR(50) NOT NULL
            )"
        );
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS orm_test_post_tag (
                post_id INTEGER NOT NULL,
                tag_id  INTEGER NOT NULL,
                PRIMARY KEY (post_id, tag_id)
            )"
        );
    }

    protected function dropTables(): void
    {
        foreach (['orm_test_post_tag', 'orm_test_tags', 'orm_test_posts', 'orm_test_profiles', 'orm_test_users'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS {$t} CASCADE");
        }
    }

    // ---- Row insertion helpers (PostgreSQL: RETURNING id) -------------------

    protected function insertUser(string $name): int
    {
        $result = $this->db->query(
            "INSERT INTO orm_test_users (name) VALUES ('" . $this->db->prepareInput($name) . "') RETURNING id"
        );
        return (int) $result->fields['id'];
    }

    protected function insertProfile(int $userId, string $bio): int
    {
        $result = $this->db->query(
            "INSERT INTO orm_test_profiles (user_id, bio) VALUES ({$userId}, '" . $this->db->prepareInput($bio) . "') RETURNING id"
        );
        return (int) $result->fields['id'];
    }

    protected function insertPost(?int $userId, string $title): int
    {
        $userVal = $userId === null ? 'NULL' : (string) $userId;
        $result  = $this->db->query(
            "INSERT INTO orm_test_posts (user_id, title) VALUES ({$userVal}, '" . $this->db->prepareInput($title) . "') RETURNING id"
        );
        return (int) $result->fields['id'];
    }

    protected function insertTag(string $name): int
    {
        $result = $this->db->query(
            "INSERT INTO orm_test_tags (name) VALUES ('" . $this->db->prepareInput($name) . "') RETURNING id"
        );
        return (int) $result->fields['id'];
    }

    protected function attachTag(int $postId, int $tagId): void
    {
        $this->db->query(
            "INSERT INTO orm_test_post_tag (post_id, tag_id) VALUES ({$postId}, {$tagId})"
        );
    }
}
