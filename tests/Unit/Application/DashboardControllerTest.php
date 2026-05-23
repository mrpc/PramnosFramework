<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\DashboardController;

/**
 * Unit tests for DashboardController structural contracts.
 *
 * These tests verify class hierarchy, action registration, and the minimum
 * user-type requirement without requiring a database connection. Full
 * controller behaviour is covered by the Integration test suite.
 */
#[CoversClass(DashboardController::class)]
class DashboardControllerTest extends TestCase
{
    /**
     * DashboardController must extend the framework base Controller so that
     * exec(), addAuthAction(), redirect(), and getView() are available.
     */
    public function testExtendsFrameworkController(): void
    {
        // Arrange
        $ctrl = new DashboardController(null);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Application\Controller::class,
            $ctrl,
            'DashboardController must extend Pramnos\Application\Controller'
        );
    }

    /**
     * All four actions must be registered via addAuthAction() so that
     * unauthenticated users are redirected rather than seeing raw PHP errors.
     */
    public function testAllActionsAreAuthProtected(): void
    {
        // Arrange
        $ctrl = new DashboardController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('actions_auth');
        $authActions = $prop->getValue($ctrl);

        // Assert
        $expected = ['display', 'activeusers', 'apistats', 'dbstats'];
        foreach ($expected as $action) {
            $this->assertContains(
                $action, $authActions,
                "DashboardController::$action() must be registered via addAuthAction()"
            );
        }
    }

    /**
     * The default requiredUserType must be >= 80 (manager level).
     *
     * Allowing regular users (usertype=50) to view server DB metrics and
     * API performance data would be an information-disclosure vulnerability.
     */
    public function testRequiredUserTypeIsAtLeastManager(): void
    {
        // Arrange
        $ctrl = new DashboardController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('requiredUserType');
        $required = $prop->getValue($ctrl);

        // Assert
        $this->assertGreaterThanOrEqual(
            80, $required,
            'requiredUserType must be at least 80 (manager) to prevent info-disclosure'
        );
    }

    /**
     * All expected action methods must exist on the class.
     *
     * A missing method causes a fatal error when exec() tries to dispatch to it,
     * typically manifesting as an unhelpful 500 error in production.
     */
    public function testAllActionMethodsExist(): void
    {
        // Arrange
        $ctrl = new DashboardController(null);

        // Assert
        foreach (['display', 'activeusers', 'apistats', 'dbstats'] as $action) {
            $this->assertTrue(
                method_exists($ctrl, $action),
                "DashboardController::$action() method must exist"
            );
        }
    }
}
