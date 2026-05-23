<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\ApplicationsController;

/**
 * Unit tests for ApplicationsController structural contracts.
 *
 * These tests verify class hierarchy, action registration, and required-usertype
 * protection without requiring an authserver database. Full controller behaviour
 * (client secret generation, token revocation on delete) is covered by the
 * Integration test suite.
 */
#[CoversClass(ApplicationsController::class)]
class ApplicationsControllerTest extends TestCase
{
    /**
     * ApplicationsController must extend the framework base Controller so that
     * exec(), addAuthAction(), redirect(), and getView() are available.
     */
    public function testExtendsFrameworkController(): void
    {
        // Arrange
        $ctrl = new ApplicationsController(null);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Application\Controller::class,
            $ctrl,
            'ApplicationsController must extend Pramnos\Application\Controller'
        );
    }

    /**
     * All six actions must be registered via addAuthAction().
     * An unprotected rotate() or delete() action would allow unauthenticated
     * revocation of OAuth2 credentials.
     */
    public function testAllActionsAreAuthProtected(): void
    {
        // Arrange
        $ctrl = new ApplicationsController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('actions_auth');
        $authActions = $prop->getValue($ctrl);

        // Assert
        $expected = ['display', 'edit', 'save', 'delete', 'tokens', 'rotate'];
        foreach ($expected as $action) {
            $this->assertContains(
                $action, $authActions,
                "ApplicationsController::$action() must be registered via addAuthAction()"
            );
        }
    }

    /**
     * The default requiredUserType must be >= 90 (admin level).
     *
     * OAuth2 client management is more sensitive than standard admin tasks —
     * it provides credentials that can impersonate application-level access.
     * Requiring admin (90) rather than manager (80) reduces the blast radius
     * of a compromised manager-level account.
     */
    public function testRequiredUserTypeIsAtLeastAdmin(): void
    {
        // Arrange
        $ctrl = new ApplicationsController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('requiredUserType');
        $required = $prop->getValue($ctrl);

        // Assert
        $this->assertGreaterThanOrEqual(
            90, $required,
            'requiredUserType must be at least 90 (admin) for OAuth2 client management'
        );
    }

    /**
     * All expected action methods must exist on the class.
     */
    public function testAllActionMethodsExist(): void
    {
        // Arrange
        $ctrl = new ApplicationsController(null);

        // Assert
        foreach (['display', 'edit', 'save', 'delete', 'tokens', 'rotate'] as $action) {
            $this->assertTrue(
                method_exists($ctrl, $action),
                "ApplicationsController::$action() method must exist"
            );
        }
    }
}
