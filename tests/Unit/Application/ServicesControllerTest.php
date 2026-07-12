<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\ServicesController;

/**
 * Unit tests for ServicesController structural contracts.
 *
 * These tests verify class hierarchy, action registration, required-usertype
 * protection, and method existence without requiring a real daemon or database.
 * Lifecycle behaviour (start/stop/restart state-file mutations) is covered
 * by the Integration test suite.
 */
#[CoversClass(ServicesController::class)]
class ServicesControllerTest extends TestCase
{
    /**
     * ServicesController must extend the framework base Controller so that
     * exec(), addAuthAction(), redirect(), and getView() are available.
     */
    public function testExtendsFrameworkController(): void
    {
        // Arrange
        $ctrl = new ServicesController(null);

        // Assert
        $this->assertInstanceOf(
            \Pramnos\Application\Controller::class,
            $ctrl,
            'ServicesController must extend Pramnos\Application\Controller'
        );
    }

    /**
     * All six lifecycle and monitoring actions must be registered via addAuthAction().
     * Any unprotected action would let anonymous users stop or inspect daemon processes,
     * enabling denial-of-service or information disclosure.
     */
    public function testAllActionsAreAuthProtected(): void
    {
        // Arrange
        $ctrl = new ServicesController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('actions_auth');
        $authActions = $prop->getValue($ctrl);

        // Assert — every lifecycle action is auth-gated
        $expected = ['display', 'stop', 'start', 'restart', 'logs', 'status'];
        foreach ($expected as $action) {
            $this->assertContains(
                $action, $authActions,
                "ServicesController::$action() must be registered via addAuthAction()"
            );
        }
    }

    /**
     * The default requiredUserType must be >= 80 (manager level).
     *
     * Allowing regular users (usertype=50) to start or stop daemons would be a
     * privilege-escalation vulnerability and could enable denial-of-service.
     */
    public function testRequiredUserTypeIsAtLeastManager(): void
    {
        // Arrange
        $ctrl = new ServicesController(null);
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
     * A missing method causes a fatal error when exec() dispatches to it,
     * typically manifesting as an unhelpful 500 error in production.
     */
    public function testAllActionMethodsExist(): void
    {
        // Arrange
        $ctrl = new ServicesController(null);

        // Assert
        foreach (['display', 'stop', 'start', 'restart', 'logs', 'status'] as $action) {
            $this->assertTrue(
                method_exists($ctrl, $action),
                "ServicesController::$action() method must exist"
            );
        }
    }

    /**
     * The maxLogLines property must be a positive integer.
     * Returning unlimited log lines would risk exhausting PHP memory on high-traffic
     * services that produce large log files.
     */
    public function testMaxLogLinesIsPositive(): void
    {
        // Arrange
        $ctrl = new ServicesController(null);
        $ref  = new \ReflectionClass($ctrl);
        $prop = $ref->getProperty('maxLogLines');
        $max  = $prop->getValue($ctrl);

        // Assert
        $this->assertIsInt($max, 'maxLogLines must be an integer');
        $this->assertGreaterThan(0, $max, 'maxLogLines must be > 0 to return at least one log line');
    }
}
