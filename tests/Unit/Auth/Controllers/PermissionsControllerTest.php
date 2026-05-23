<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\PermissionsController;

/**
 * Unit tests for PermissionsController structural contracts.
 *
 * These tests verify class hierarchy, action registration, and required-usertype
 * protection without requiring an authserver database. The grant_type enforcement
 * (allow vs deny semantics) is covered by the Integration test suite.
 */
#[CoversClass(PermissionsController::class)]
class PermissionsControllerTest extends TestCase
{
    /**
     * PermissionsController must extend the framework base Controller.
     */
    public function testExtendsFrameworkController(): void
    {
        // Arrange
        $ctrl = new PermissionsController(null);

        // Assert
        $this->assertInstanceOf(\Pramnos\Application\Controller::class, $ctrl);
    }

    /**
     * All five actions must be auth-protected.
     * Unprotected assign() would allow anonymous RBAC escalation.
     */
    public function testAllActionsAreAuthProtected(): void
    {
        // Arrange
        $ctrl = new PermissionsController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('actions_auth');
        $authActions = $prop->getValue($ctrl);

        // Assert
        foreach (['display', 'edit', 'save', 'delete', 'assign'] as $action) {
            $this->assertContains(
                $action, $authActions,
                "PermissionsController::$action() must be registered via addAuthAction()"
            );
        }
    }

    /**
     * requiredUserType must be >= 90 (admin level).
     * RBAC grant management must be restricted to full admins — a manager
     * could otherwise escalate their own permissions.
     */
    public function testRequiredUserTypeIsAtLeastAdmin(): void
    {
        // Arrange
        $ctrl = new PermissionsController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('requiredUserType');

        // Assert
        $this->assertGreaterThanOrEqual(90, $prop->getValue($ctrl),
            'requiredUserType must be at least 90 (admin) to prevent self-escalation');
    }

    /**
     * All expected action methods must exist.
     */
    public function testAllActionMethodsExist(): void
    {
        // Arrange
        $ctrl = new PermissionsController(null);

        // Assert
        foreach (['display', 'edit', 'save', 'delete', 'assign'] as $action) {
            $this->assertTrue(method_exists($ctrl, $action));
        }
    }
}
