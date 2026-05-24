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

    // -------------------------------------------------------------------------
    // save() — CSRF protection
    // -------------------------------------------------------------------------

    /**
     * save() must redirect with an error when the submitted CSRF token does not
     * match the session token (or is missing entirely).
     *
     * This prevents cross-site request forgery: a malicious page cannot submit
     * a user-edit form on behalf of a logged-in admin, because it cannot know the
     * per-session random token stored server-side.
     */
    public function testSaveRedirectsWhenCsrfTokenIsInvalid(): void
    {
        // Arrange — start a clean session with a known CSRF token
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['csrf_token'] = 'correct-token-abc123';

        // Submit a different (invalid) token; also supply a username so that
        // the CSRF guard is the only thing that fires.
        $_POST = [
            '_csrf_token' => 'wrong-token-xyz',
            'username'    => 'someuser',
        ];

        // Mock redirect() + requireMinUserType() so we can track calls without
        // a database or running application.
        $ctrl = $this->getMockBuilder(UsersController::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['redirect', 'requireMinUserType'])
            ->getMock();

        // requireMinUserType() is void — no willReturn() needed (mock just does nothing)

        // Assert — redirect must be called (CSRF block fires)
        $ctrl->expects($this->once())
            ->method('redirect')
            ->with($this->stringContains('users/edit'));

        // Act
        $ctrl->save();
    }

    /**
     * save() must NOT redirect due to CSRF when the submitted token matches
     * the session token.
     *
     * This positive-path test confirms that a legitimate form submission with
     * the correct token is not rejected by the CSRF guard. The redirect that
     * eventually fires (missing username / DB error) is unrelated to CSRF.
     *
     * We test this by verifying the redirect target does NOT include 'users/edit'
     * for a CSRF-related reason. Since username is blank in this test, the
     * redirect will point to 'users/edit/' — but we set a valid CSRF token first
     * so the guard does NOT trigger; only the blank-username guard triggers.
     */
    public function testSaveDoesNotRedirectForCsrfWhenTokenIsValid(): void
    {
        // Arrange
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = 'valid-session-token-999';
        $_SESSION['csrf_token'] = $token;

        // Correct CSRF token but empty username → save will redirect for username reason
        $_POST = [
            '_csrf_token' => $token,
            'username'    => '',
        ];

        $ctrl = $this->getMockBuilder(UsersController::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['redirect', 'requireMinUserType'])
            ->getMock();

        // requireMinUserType() is void — no willReturn() needed

        // The CSRF guard must NOT store 'Invalid security token' in the session
        $ctrl->method('redirect')->willReturn(null);
        $ctrl->save();

        // Assert — CSRF error must NOT have been set in the session
        $this->assertNotSame(
            'Invalid security token. Please try again.',
            $_SESSION['users_error'] ?? '',
            'session must not contain a CSRF error when the submitted token is valid'
        );
    }

    // -------------------------------------------------------------------------
    // Teardown: clean up superglobals shared across tests
    // -------------------------------------------------------------------------

    protected function tearDown(): void
    {
        $_POST    = [];
        $_SESSION = [];
        parent::tearDown();
    }
}
