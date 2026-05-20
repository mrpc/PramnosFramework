<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Permissions;
use Pramnos\Framework\Factory;

/**
 * Characterization tests for Permissions on MySQL 8.0.
 *
 * Runs all behavioral contracts defined in AbstractPermissionsCharacterizationTest
 * against the MySQL database (default test settings). Documents the current
 * working behavior of Permissions::allow(), deny(), removePermission(),
 * isAllowed(), and the admin-escalation shortcut before any QB migration.
 *
 * Complements PermissionsPostgreSQLCharacterizationTest, which exercises the
 * same contracts on PostgreSQL/TimescaleDB.
 */
#[CoversClass(Permissions::class)]
class PermissionsCharacterizationTest extends PermissionsCharacterizationBase
{
    /**
     * Connects to the default MySQL test database defined in settings.php.
     * Skips the test class if the MySQL container is unreachable.
     */
    protected function connectDatabase(): void
    {
        // Arrange — bootstrap application constants and load settings
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }

        if (!$this->db->connected) {
            $this->markTestSkipped('MySQL container not reachable.');
        }

        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped(
                'PermissionsCharacterizationTest targets MySQL; use PermissionsPostgreSQLCharacterizationTest for PostgreSQL.'
            );
        }
    }
}
