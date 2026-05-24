<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\OrganizationsController;

/**
 * Unit tests for OrganizationsController structural contracts.
 *
 * These tests verify class hierarchy, action registration, required-usertype
 * protection, and method existence without requiring a database connection.
 * Database behaviour (membership management, soft-delete) is covered by the
 * Integration test suite.
 */
#[CoversClass(OrganizationsController::class)]
class OrganizationsControllerTest extends TestCase
{
    /**
     * OrganizationsController must extend the framework base Controller so that
     * exec(), addAuthAction(), redirect(), and getView() are available.
     */
    public function testExtendsFrameworkController(): void
    {
        // Arrange
        $ctrl = new OrganizationsController(null);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Application\Controller::class,
            $ctrl,
            'OrganizationsController must extend Pramnos\Application\Controller'
        );
    }

    /**
     * All seven CRUD + membership actions must be registered via addAuthAction()
     * so unauthenticated users cannot modify organization structure.
     */
    public function testAllActionsAreAuthProtected(): void
    {
        // Arrange
        $ctrl = new OrganizationsController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('actions_auth');
        $authActions = $prop->getValue($ctrl);

        // Assert
        $expected = ['display', 'edit', 'save', 'delete', 'members', 'addmember', 'removemember'];
        foreach ($expected as $action) {
            $this->assertContains(
                $action, $authActions,
                "OrganizationsController::$action() must be registered via addAuthAction()"
            );
        }
    }

    /**
     * The default requiredUserType must be >= 80 (manager level).
     *
     * Allowing regular users (usertype=50) to manage organizations would be a
     * privilege-escalation vulnerability.
     */
    public function testRequiredUserTypeIsAtLeastManager(): void
    {
        // Arrange
        $ctrl = new OrganizationsController(null);
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
     * All expected action methods must exist on the class.
     *
     * A missing method causes a fatal error when exec() dispatches to it.
     */
    public function testAllActionMethodsExist(): void
    {
        // Arrange
        $ctrl = new OrganizationsController(null);

        // Assert
        foreach (['display', 'edit', 'save', 'delete', 'members', 'addmember', 'removemember'] as $action) {
            $this->assertTrue(
                method_exists($ctrl, $action),
                "OrganizationsController::$action() method must exist"
            );
        }
    }

    // -------------------------------------------------------------------------
    // save() — CSRF protection
    // -------------------------------------------------------------------------

    /**
     * save() must redirect when the submitted CSRF token does not match the
     * session token.
     *
     * Organizations contain sensitive membership and ownership data. A CSRF
     * attack that bypasses this check could rename, delete, or restructure
     * organizations on behalf of a logged-in admin.
     */
    public function testSaveRedirectsWhenCsrfTokenIsInvalid(): void
    {
        // Arrange
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['csrf_token'] = 'correct-org-token';

        $_POST = [
            '_csrf_token'     => 'tampered-token',
            'organization_id' => '0',
            'name'            => 'Evil Corp',
        ];

        $ctrl = $this->getMockBuilder(OrganizationsController::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['redirect', 'requireMinUserType'])
            ->getMock();

        // requireMinUserType() returns false when auth passes (true = redirect was issued)
        $ctrl->method('requireMinUserType')->willReturn(false);

        // Assert — redirect must be called when CSRF is invalid
        $ctrl->expects($this->once())
            ->method('redirect');

        // Act
        $ctrl->save();
    }

    // -------------------------------------------------------------------------
    // Teardown: clean up superglobals
    // -------------------------------------------------------------------------

    protected function tearDown(): void
    {
        $_POST    = [];
        $_SESSION = [];
        parent::tearDown();
    }
}
