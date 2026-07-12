<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\SettingsController;

/**
 * Unit tests for SettingsController structural contracts.
 *
 * These tests verify the class hierarchy, action registration, and
 * readonly-key protection without requiring a database connection.
 * Database interactions are covered by SettingsControllerMySQLTest
 * and SettingsControllerPostgreSQLTest.
 */
#[CoversClass(SettingsController::class)]
class SettingsControllerTest extends TestCase
{
    /**
     * SettingsController must extend the framework base Controller so that
     * exec(), addAuthAction(), redirect(), and getView() are available.
     */
    public function testExtendsFrameworkController(): void
    {
        // Arrange
        $ctrl = new SettingsController(null);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Application\Controller::class,
            $ctrl,
            'SettingsController must extend Pramnos\Application\Controller'
        );
    }

    /**
     * display(), edit(), save(), and delete() must all be registered via
     * addAuthAction() so that unauthenticated users are redirected to /login.
     *
     * Missing entries here would allow unauthenticated access to admin functions.
     */
    public function testAllActionsAreAuthProtected(): void
    {
        // Arrange
        $ctrl = new SettingsController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('actions_auth');
        $authActions = $prop->getValue($ctrl);

        // Assert — each admin action must be auth-protected
        foreach (['display', 'edit', 'save', 'delete'] as $action) {
            $this->assertContains(
                $action, $authActions,
                "SettingsController::$action() must be registered via addAuthAction()"
            );
        }
    }

    /**
     * The default $readonlyKeys list must include database connection keys.
     *
     * These keys contain credentials — exposing them via the UI would be
     * a security vulnerability.
     */
    public function testReadonlyKeysIncludeCredentials(): void
    {
        // Arrange
        $ctrl = new SettingsController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('readonlyKeys');
        $readonly = $prop->getValue($ctrl);

        // Assert — critical credential keys are protected
        foreach (['hostname', 'user', 'password', 'database'] as $key) {
            $this->assertContains(
                $key, $readonly,
                "readonlyKeys must include '$key' to prevent credential exposure via the UI"
            );
        }
    }
}
