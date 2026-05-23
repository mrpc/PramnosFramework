<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\TokensController;

/**
 * Unit tests for TokensController structural contracts.
 *
 * These tests verify class hierarchy, action registration, and required-usertype
 * protection without requiring an authserver database. Full controller behaviour
 * (bulk revocation guard, status update) is covered by the Integration test suite.
 */
#[CoversClass(TokensController::class)]
class TokensControllerTest extends TestCase
{
    /**
     * TokensController must extend the framework base Controller so that
     * exec(), addAuthAction(), redirect(), and getView() are available.
     */
    public function testExtendsFrameworkController(): void
    {
        // Arrange
        $ctrl = new TokensController(null);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Application\Controller::class,
            $ctrl,
            'TokensController must extend Pramnos\Application\Controller'
        );
    }

    /**
     * All three actions must be registered via addAuthAction().
     * Unprotected revoke/revokeall would allow unauthenticated token invalidation,
     * a denial-of-service against all authenticated users.
     */
    public function testAllActionsAreAuthProtected(): void
    {
        // Arrange
        $ctrl = new TokensController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('actions_auth');
        $authActions = $prop->getValue($ctrl);

        // Assert
        $expected = ['display', 'revoke', 'revokeall'];
        foreach ($expected as $action) {
            $this->assertContains(
                $action, $authActions,
                "TokensController::$action() must be registered via addAuthAction()"
            );
        }
    }

    /**
     * The default requiredUserType must be >= 90 (admin level).
     *
     * Token revocation is an irreversible, high-impact action — allowing
     * manager-level users (80) to revoke all tokens would be a misuse vector.
     */
    public function testRequiredUserTypeIsAtLeastAdmin(): void
    {
        // Arrange
        $ctrl = new TokensController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('requiredUserType');
        $required = $prop->getValue($ctrl);

        // Assert
        $this->assertGreaterThanOrEqual(
            90, $required,
            'requiredUserType must be at least 90 (admin) for token revocation'
        );
    }

    /**
     * All expected action methods must exist on the class.
     */
    public function testAllActionMethodsExist(): void
    {
        // Arrange
        $ctrl = new TokensController(null);

        // Assert
        foreach (['display', 'revoke', 'revokeall'] as $action) {
            $this->assertTrue(
                method_exists($ctrl, $action),
                "TokensController::$action() method must exist"
            );
        }
    }
}
