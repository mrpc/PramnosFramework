<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Permissions;
use Pramnos\Framework\Factory;

/**
 * Characterization tests for Permissions on PostgreSQL 14 / TimescaleDB.
 *
 * Mirrors PermissionsCharacterizationTest (MySQL) exactly — same contracts,
 * same assertions. The goal is to prove that Permissions works identically on
 * PostgreSQL before any internal QB migration work is started.
 *
 * Key mechanism: Database::query() translates backtick-quoted identifiers to
 * double-quote quoting on PostgreSQL (line 949 of Database.php). This means
 * the raw SQL inside Permissions is already dialect-portable at the DB layer.
 * These tests lock that assumption.
 *
 * #[RunTestsInSeparateProcesses] is required because Factory::getDatabase()
 * returns a static singleton. Separate processes give each test a clean PHP
 * state so that the PostgreSQL settings take effect before any MySQL singleton
 * is created by a sibling test class in the same suite.
 */
#[CoversClass(Permissions::class)]
#[RunTestsInSeparateProcesses]
class PermissionsPostgreSQLCharacterizationTest extends PermissionsCharacterizationBase
{
    /**
     * Connects to the PostgreSQL/TimescaleDB test database defined in
     * pg_settings.php. Skips if the container is unreachable.
     */
    protected function connectDatabase(): void
    {
        // Arrange — bootstrap application constants and load PostgreSQL settings
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        if (!$this->db->connected) {
            $this->markTestSkipped('PostgreSQL/TimescaleDB container not reachable.');
        }

        if ($this->db->type !== 'postgresql') {
            $this->markTestSkipped(
                'PermissionsPostgreSQLCharacterizationTest requires a PostgreSQL connection.'
            );
        }
    }
}
