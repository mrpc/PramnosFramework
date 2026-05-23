<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Queue\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Queue\Controllers\QueueController;

/**
 * Unit tests for QueueController structural contracts.
 *
 * These tests verify class hierarchy, action registration, and required-usertype
 * protection without requiring a database connection. The soft-delete contract
 * (status='deleted' rather than hard DELETE) is covered by the Integration suite.
 */
#[CoversClass(QueueController::class)]
class QueueControllerTest extends TestCase
{
    /**
     * QueueController must extend the framework base Controller.
     */
    public function testExtendsFrameworkController(): void
    {
        // Arrange
        $ctrl = new QueueController(null);

        // Assert
        $this->assertInstanceOf(\Pramnos\Application\Controller::class, $ctrl);
    }

    /**
     * All six actions must be auth-protected.
     * An unprotected clear() would allow anonymous denial-of-service via
     * bulk-deletion of all pending background jobs.
     */
    public function testAllActionsAreAuthProtected(): void
    {
        // Arrange
        $ctrl = new QueueController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('actions_auth');
        $authActions = $prop->getValue($ctrl);

        // Assert
        foreach (['display', 'retry', 'retryall', 'delete', 'clear', 'stats'] as $action) {
            $this->assertContains(
                $action, $authActions,
                "QueueController::$action() must be registered via addAuthAction()"
            );
        }
    }

    /**
     * requiredUserType must be >= 80 (manager level).
     */
    public function testRequiredUserTypeIsAtLeastManager(): void
    {
        // Arrange
        $ctrl = new QueueController(null);
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
        $ctrl = new QueueController(null);

        // Assert
        foreach (['display', 'retry', 'retryall', 'delete', 'clear', 'stats'] as $action) {
            $this->assertTrue(method_exists($ctrl, $action));
        }
    }
}
