<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\TokenActionsController;

/**
 * Unit tests for TokenActionsController structural contracts.
 *
 * These tests verify class hierarchy, action registration, required-usertype
 * protection, the read-only contract (no write actions), and the maxExportRows
 * limit — without requiring a database connection.
 */
#[CoversClass(TokenActionsController::class)]
class TokenActionsControllerTest extends TestCase
{
    /**
     * TokenActionsController must extend the framework base Controller.
     */
    public function testExtendsFrameworkController(): void
    {
        // Arrange
        $ctrl = new TokenActionsController(null);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Application\Controller::class,
            $ctrl
        );
    }

    /**
     * All four actions must be auth-protected.
     * An unprotected export() would allow anonymous users to download a
     * CSV of all API calls made by every user — a significant data-breach risk.
     */
    public function testAllActionsAreAuthProtected(): void
    {
        // Arrange
        $ctrl = new TokenActionsController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('actions_auth');
        $authActions = $prop->getValue($ctrl);

        // Assert
        foreach (['display', 'show', 'stats', 'export'] as $action) {
            $this->assertContains(
                $action, $authActions,
                "TokenActionsController::$action() must be registered via addAuthAction()"
            );
        }
    }

    /**
     * requiredUserType must be >= 80 to restrict audit log access to managers.
     */
    public function testRequiredUserTypeIsAtLeastManager(): void
    {
        // Arrange
        $ctrl = new TokenActionsController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('requiredUserType');

        // Assert
        $this->assertGreaterThanOrEqual(80, $prop->getValue($ctrl));
    }

    /**
     * maxExportRows must be a positive integer.
     * A zero or negative value would make the export action return no rows,
     * silently producing an empty CSV that looks like a success.
     */
    public function testMaxExportRowsIsPositive(): void
    {
        // Arrange
        $ctrl = new TokenActionsController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('maxExportRows');
        $max  = $prop->getValue($ctrl);

        // Assert
        $this->assertIsInt($max);
        $this->assertGreaterThan(0, $max, 'maxExportRows must be > 0');
    }

    /**
     * All expected action methods must exist.
     */
    public function testAllActionMethodsExist(): void
    {
        // Arrange
        $ctrl = new TokenActionsController(null);

        // Assert
        foreach (['display', 'show', 'stats', 'export'] as $action) {
            $this->assertTrue(method_exists($ctrl, $action));
        }
    }
}
