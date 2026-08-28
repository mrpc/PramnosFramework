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

    /**
     * The screen reports whether the supervisor itself is running.
     *
     * Everything else on that page is about the workers, and the buttons are about the
     * supervisor: Stop, Start and Restart write and remove a sentinel file that the
     * orchestrator acts on. With no orchestrator, Start and Restart do nothing whatsoever —
     * no error, no message, the service stays down — so the page has to say which of the two
     * situations an operator is looking at.
     *
     * `DaemonOrchestrator::status()` was written for this and had no caller.
     */
    public function testTheSupervisorsOwnStateIsReported(): void
    {
        // Arrange — a probe that exposes the protected reading, and a lock file with a pid
        // that is certainly not a process
        $probe = new class () extends ServicesController {
            public function __construct()
            {
                // No parent::__construct(): that registers actions against an application
                // this test does not have.
            }

            public function expose(): array
            {
                return $this->orchestratorStatus();
            }
        };

        $lockFile = \Pramnos\Console\DaemonOrchestrator::orchestratorLockPath();
        $existed  = is_file($lockFile);
        $previous = $existed ? (string) file_get_contents($lockFile) : null;

        if (!is_dir(dirname($lockFile))) {
            mkdir(dirname($lockFile), 0777, true);
        }

        // The heartbeat is now part of the answer, so it has to be held still for a test
        // about the pid. A fresh state file means "cycling" and would mask every case below.
        $stateFile  = \Pramnos\Console\DaemonOrchestrator::stateFilePath();
        $stateSaved = is_file($stateFile) ? (string) file_get_contents($stateFile) : null;
        $stateMtime = is_file($stateFile) ? (int) filemtime($stateFile) : null;

        if (is_file($stateFile)) {
            touch($stateFile, time() - 3600);
        }

        try {
            // Act & Assert — a pid nothing is using reads as not running
            file_put_contents($lockFile, '2147483646');
            $status = $probe->expose();
            $this->assertFalse($status['running'], 'a dead pid is not a running supervisor');
            $this->assertNull($status['pid']);

            // …and this process certainly is running
            file_put_contents($lockFile, (string) getmypid());
            $status = $probe->expose();
            $this->assertTrue($status['running']);
            $this->assertSame(getmypid(), $status['pid']);

            // No lock file at all is the common case on an installation with no daemons
            unlink($lockFile);
            $status = $probe->expose();
            $this->assertFalse($status['running']);
            $this->assertNull($status['pid']);
        } finally {
            if ($previous !== null) {
                file_put_contents($lockFile, $previous);
            } elseif (is_file($lockFile)) {
                unlink($lockFile);
            }

            if ($stateSaved !== null) {
                file_put_contents($stateFile, $stateSaved);
                touch($stateFile, (int) $stateMtime);
            }
        }
    }

    /**
     * A moving heartbeat is enough, because a pid from another container is not.
     *
     * The pid alone was the answer, and a pid only means something inside the namespace that
     * issued it. The supervisor's normal home is a container of its own — that is what
     * `pramnos init` writes for an application with background work — so the number in the
     * lock file belongs to *its* namespace and the web request reading it is in another.
     * There it matches something unrelated, or nothing: a working supervisor reported dead, or
     * a dead one reported alive because pid 14 happens to be Apache.
     *
     * The state file crosses the boundary. It is on the shared volume, and the orchestrator
     * rewrites it every reconcile cycle, so a recent mtime means not merely alive but actively
     * cycling — and it cannot lie in the dangerous direction, because a stopped supervisor
     * stops touching it.
     */
    public function testAMovingHeartbeatIsEnoughToCountAsRunning(): void
    {
        // Arrange
        $probe = new class extends \Pramnos\Application\Controllers\ServicesController {
            public function __construct()
            {
            }

            public function expose(): array
            {
                return $this->orchestratorStatus();
            }
        };

        $lockFile   = \Pramnos\Console\DaemonOrchestrator::orchestratorLockPath();
        $stateFile  = \Pramnos\Console\DaemonOrchestrator::stateFilePath();
        $lockSaved  = is_file($lockFile) ? (string) file_get_contents($lockFile) : null;
        $stateSaved = is_file($stateFile) ? (string) file_get_contents($stateFile) : null;
        $stateMtime = is_file($stateFile) ? (int) filemtime($stateFile) : null;

        if (!is_dir(dirname($stateFile))) {
            mkdir(dirname($stateFile), 0777, true);
        }

        try {
            // A pid this host is definitely not running, and a state file written just now
            file_put_contents($lockFile, '2147483646');
            file_put_contents($stateFile, '[]');

            // Act
            $status = $probe->expose();

            // Assert
            $this->assertTrue($status['running'],
                'a supervisor that is cycling is running, whatever its pid means here');
            $this->assertNotNull($status['heartbeat_age_seconds']);

            // …and an old one is not
            touch($stateFile, time() - 3600);
            $this->assertFalse($probe->expose()['running'],
                'a heartbeat that stopped an hour ago is not a running supervisor');
        } finally {
            if ($lockSaved !== null) {
                file_put_contents($lockFile, $lockSaved);
            } elseif (is_file($lockFile)) {
                unlink($lockFile);
            }

            if ($stateSaved !== null) {
                file_put_contents($stateFile, $stateSaved);
                touch($stateFile, (int) $stateMtime);
            } elseif (is_file($stateFile)) {
                unlink($stateFile);
            }
        }
    }

    /**
     * The two paths are readable without constructing an orchestrator.
     *
     * A web request cannot build the application's orchestrator subclass, and the screen
     * had its own copy of the state file's path as a literal — which is how a path drifts
     * from the one the writer uses, and the screen then reports an empty list for ever.
     */
    public function testThePathsAreAvailableStatically(): void
    {
        // Act & Assert
        $this->assertStringEndsWith(
            'var/daemon_orchestrator_state.json',
            \Pramnos\Console\DaemonOrchestrator::stateFilePath()
        );
        $this->assertStringEndsWith(
            'var/DAEMON_ORCHESTRATOR.lock',
            \Pramnos\Console\DaemonOrchestrator::orchestratorLockPath()
        );
    }
}
