<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\UsersController;

/**
 * Unit tests for UsersController structural contracts.
 *
 * These tests verify the class hierarchy, action registration, and
 * default configuration without requiring a database connection.
 * Database interactions are covered by the Integration test suite.
 */
#[CoversClass(UsersController::class)]
class UsersControllerTest extends TestCase
{
    /**
     * UsersController must extend the framework base Controller so that
     * exec(), addAuthAction(), redirect(), and getView() are available.
     */
    public function testExtendsFrameworkController(): void
    {
        // Arrange
        $ctrl = new UsersController(null);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Application\Controller::class,
            $ctrl,
            'UsersController must extend Pramnos\Application\Controller'
        );
    }

    /**
     * All seven CRUD+management actions must be registered via addAuthAction()
     * so that unauthenticated users are redirected to /login rather than
     * receiving a direct response.
     */
    public function testAllActionsAreAuthProtected(): void
    {
        // Arrange
        $ctrl = new UsersController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('actions_auth');
        $authActions = $prop->getValue($ctrl);

        // Assert — every action that touches user data must be auth-gated
        $expected = ['display', 'edit', 'save', 'delete', 'lock', 'unlock', 'sessions'];
        foreach ($expected as $action) {
            $this->assertContains(
                $action, $authActions,
                "UsersController::$action() must be registered via addAuthAction()"
            );
        }
    }

    /**
     * The default requiredUserType must be >= 80 (manager level).
     *
     * Allowing regular users (usertype=50) to manage other users would be a
     * privilege-escalation vulnerability.
     */
    public function testRequiredUserTypeIsAtLeastManager(): void
    {
        // Arrange
        $ctrl = new UsersController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('requiredUserType');
        $required = $prop->getValue($ctrl);

        // Assert
        $this->assertGreaterThanOrEqual(
            80, $required,
            'requiredUserType must be at least 80 (manager) to prevent privilege escalation'
        );
    }

    /**
     * All expected action methods exist on the class.
     *
     * A missing method causes a fatal error when exec() tries to dispatch to it,
     * typically manifesting as an unhelpful 500 error in production.
     */
    public function testAllActionMethodsExist(): void
    {
        // Arrange
        $ctrl = new UsersController(null);

        // Assert
        foreach (['display', 'edit', 'save', 'delete', 'lock', 'unlock', 'sessions'] as $action) {
            $this->assertTrue(
                method_exists($ctrl, $action),
                "UsersController::$action() method must exist"
            );
        }
    }
}
