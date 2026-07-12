<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\EmailsController;

/**
 * Unit tests for EmailsController structural contracts.
 *
 * These tests verify class hierarchy, action registration, required-usertype
 * protection, and method existence without requiring a database connection.
 */
#[CoversClass(EmailsController::class)]
class EmailsControllerTest extends TestCase
{
    /**
     * EmailsController must extend the framework base Controller.
     */
    public function testExtendsFrameworkController(): void
    {
        // Arrange
        $ctrl = new EmailsController(null);

        // Assert
        $this->assertInstanceOf(\Pramnos\Application\Controller::class, $ctrl);
    }

    /**
     * All three actions must be auth-protected.
     * An unprotected display() would expose recipient email addresses and
     * message content to anonymous users.
     */
    public function testAllActionsAreAuthProtected(): void
    {
        // Arrange
        $ctrl = new EmailsController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('actions_auth');
        $authActions = $prop->getValue($ctrl);

        // Assert
        foreach (['display', 'show', 'resend'] as $action) {
            $this->assertContains(
                $action, $authActions,
                "EmailsController::$action() must be registered via addAuthAction()"
            );
        }
    }

    /**
     * requiredUserType must be >= 80 (manager level).
     */
    public function testRequiredUserTypeIsAtLeastManager(): void
    {
        // Arrange
        $ctrl = new EmailsController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('requiredUserType');

        // Assert
        $this->assertGreaterThanOrEqual(80, $prop->getValue($ctrl));
    }

    /**
     * All expected action methods must exist.
     */
    public function testAllActionMethodsExist(): void
    {
        // Arrange
        $ctrl = new EmailsController(null);

        // Assert
        foreach (['display', 'show', 'resend'] as $action) {
            $this->assertTrue(method_exists($ctrl, $action));
        }
    }
}
