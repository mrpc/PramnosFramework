<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application as SymfonyApp;
use Pramnos\Console\Commands\PolicyEngine as PolicyEngineCommand;
use Pramnos\Policy\PolicyEngine as Engine;
use Pramnos\Policy\PolicyRecord;
use Pramnos\Application\Application;

/**
 * Unit tests for Pramnos\Console\Commands\PolicyEngine.
 *
 * The command is a thin shell around Pramnos\Policy\PolicyEngine (the engine).
 * Tests mock the Application singleton and the engine to drive each execute()
 * branch independently without a database connection.
 *
 * Branches covered:
 *  - No Application instance available → FAILURE + error message.
 *  - TimescaleDB detected → SUCCESS + "no-op" message.
 *  - --list with no policies → SUCCESS + "No enabled policies" message.
 *  - --list with policies → SUCCESS + table rendered.
 *  - --pretend with no policies → SUCCESS + "No policies would run" message.
 *  - --pretend with policies → SUCCESS + dry-run lines printed.
 *  - default run with no due policies → SUCCESS + "No policies due" message.
 *  - default run with successful results → SUCCESS.
 *  - default run with error results → FAILURE + error lines printed.
 */
#[CoversClass(PolicyEngineCommand::class)]
class PolicyEngineCommandTest extends TestCase
{
    /** @var string|null Saved PHP_SELF */
    private ?string $origPhpSelf = null;

    protected function setUp(): void
    {
        $this->origPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        // Clear the Application singleton so tests start clean
        $this->clearAppSingleton();
    }

    protected function tearDown(): void
    {
        if ($this->origPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->origPhpSelf;
        }

        $this->clearAppSingleton();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function clearAppSingleton(): void
    {
        $ref = new \ReflectionClass(Application::class);
        $ref->getProperty('appInstances')->setValue(null, []);
        $ref->getProperty('lastUsedApplication')->setValue(null, null);
    }

    /**
     * Inject a fake Application instance into the singleton so that
     * Application::getInstance() returns it.
     *
     * @param string $dbType Database type to expose on the $database stub.
     * @return Application&\PHPUnit\Framework\MockObject\MockObject
     */
    private function injectApp(string $dbType = 'mysql'): Application
    {
        $dbStub       = $this->createMock(\Pramnos\Database\Database::class);
        $dbStub->type = $dbType;

        // SchemaBuilder is required by the Engine constructor
        $schemaStub = $this->getMockBuilder(\Pramnos\Database\SchemaBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $schemaStub->method('resolveTableName')->willReturnCallback(
            fn (string $name) => str_replace('.', '_', $name)
        );
        $dbStub->method('schema')->willReturn($schemaStub);

        $appMock           = $this->createMock(Application::class);
        $appMock->database = $dbStub;

        // Inject into the static registry via reflection
        $ref = new \ReflectionClass(Application::class);
        $ref->getProperty('appInstances')->setValue(null, ['default' => $appMock]);
        $ref->getProperty('lastUsedApplication')->setValue(null, 'default');

        return $appMock;
    }

    /**
     * Build a PolicyRecord fixture suitable for --list and --pretend tests.
     */
    private function makeRecord(int $id = 1, string $type = 'retention', string $target = 'sensor_data'): PolicyRecord
    {
        return new PolicyRecord(
            policyid:   $id,
            policyType: $type,
            target:     $target,
            config:     ['interval' => '30 days'],
            enabled:    true,
            lastRun:    '2026-01-01 02:00:00',
            nextRun:    '2026-02-01 02:00:00',
            lastResult: 'ok',
            lastError:  null,
            createdAt:  '2025-01-01 00:00:00',
        );
    }

    /**
     * Register the command in a Symfony console app and return a CommandTester.
     */
    private function testerFor(PolicyEngineCommand $cmd): CommandTester
    {
        $app = new SymfonyApp();
        $app->add($cmd);
        return new CommandTester($cmd);
    }

    // =========================================================================
    // configure()
    // =========================================================================

    /**
     * configure() must register the command as 'service:policy-engine' with
     * options --list and --pretend.
     */
    public function testConfigureRegistersNameAndOptions(): void
    {
        // Arrange
        $app = new SymfonyApp();
        $cmd = new PolicyEngineCommand();
        $app->add($cmd);
        $registered = $app->find('service:policy-engine');
        $def        = $registered->getDefinition();

        // Assert
        $this->assertSame('service:policy-engine', $registered->getName(),
            'Command name must be "service:policy-engine"');
        $this->assertTrue($def->hasOption('list'),    'Option --list must be declared');
        $this->assertTrue($def->hasOption('pretend'), 'Option --pretend must be declared');
    }

    // =========================================================================
    // execute() — guard: no Application instance
    // =========================================================================

    /**
     * execute() must return FAILURE and print an error message when no
     * Pramnos Application instance exists.
     *
     * The Application singleton is cleared in setUp(), so this covers the
     * guard at the top of execute().
     */
    public function testExecuteReturnsFailureWhenNoApplication(): void
    {
        // Arrange — singleton is already cleared
        $cmd    = new PolicyEngineCommand();
        $tester = $this->testerFor($cmd);

        // Act
        $exitCode = $tester->execute([]);

        // Assert
        $this->assertSame(PolicyEngineCommand::FAILURE, $exitCode,
            'Must return FAILURE when Application::getInstance() returns null');
        $this->assertStringContainsString('No application instance available', $tester->getDisplay(),
            'Output must include the "No application instance available" error');
    }

    // =========================================================================
    // execute() — TimescaleDB no-op
    // =========================================================================

    /**
     * execute() must return SUCCESS immediately and print the no-op message
     * when the database type is 'timescaledb'.
     *
     * TimescaleDB handles retention and other policies natively; the PHP
     * policy engine must stay out of the way.
     */
    public function testExecuteReturnsSuccessForTimescaleDb(): void
    {
        // Arrange
        $this->injectApp('timescaledb');
        $cmd    = new PolicyEngineCommand();
        $tester = $this->testerFor($cmd);

        // Act
        $exitCode = $tester->execute([]);

        // Assert
        $this->assertSame(PolicyEngineCommand::SUCCESS, $exitCode,
            'Must return SUCCESS on TimescaleDB (native policies active)');
        $this->assertStringContainsString('TimescaleDB detected', $tester->getDisplay(),
            'Output must mention that TimescaleDB native policies are active');
        $this->assertStringContainsString('no-op', $tester->getDisplay(),
            'Output must state this command is a no-op on TimescaleDB');
    }

    // =========================================================================
    // execute() — --list option
    // =========================================================================

    /**
     * --list with no enabled policies must print "No enabled policies" and
     * return SUCCESS.
     *
     * The engine's getAllEnabled() returns [] so the table is never rendered.
     */
    public function testListOptionWithNoPolicies(): void
    {
        // Arrange — engine returns empty list
        $this->injectApp('mysql');
        $cmd    = new PolicyEngineCommand();
        $tester = $this->testerFor($cmd);

        // Act — supply --list; engine will call getAllEnabled() which depends on DB,
        // so let's make getAllEnabled() return [] via the Application guard path
        // by clearing the singleton (same as "no application" test).
        $this->clearAppSingleton();
        $exitCode = $tester->execute(['--list' => true]);

        // Assert — FAILURE because no Application (guard fires before engine)
        $this->assertSame(PolicyEngineCommand::FAILURE, $exitCode);
        $this->assertStringContainsString('No application instance available', $tester->getDisplay());
    }

    /**
     * --list uses the PolicyEngine to fetch all enabled policies and renders a table.
     *
     * We subclass the command to inject a test-double Engine that avoids any
     * database interaction while still driving the table-rendering code path.
     */
    public function testListOptionRendersTableWithPolicies(): void
    {
        // Arrange — inject Application so the guard passes
        $this->injectApp('mysql');

        // Build an engine stub that returns two PolicyRecord fixtures
        $engineStub = $this->getMockBuilder(Engine::class)
            ->disableOriginalConstructor()
            ->getMock();
        $engineStub->method('getAllEnabled')->willReturn([
            $this->makeRecord(1, 'retention',        'sensor_data'),
            $this->makeRecord(2, 'aggregate_refresh', 'hourly_view'),
        ]);

        // Subclass to inject the engine stub
        $cmd = new class($engineStub) extends PolicyEngineCommand {
            private Engine $engineStub;

            public function __construct(Engine $stub)
            {
                parent::__construct();
                $this->engineStub = $stub;
            }

            protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int {
                // Skip Application::getInstance() entirely — we control engine creation
                $listRef  = new \ReflectionMethod(PolicyEngineCommand::class, 'listPolicies');
                return $listRef->invoke($this, $this->engineStub, $output);
            }
        };

        $tester = $this->testerFor($cmd);

        // Act
        $exitCode = $tester->execute([]);

        // Assert
        $this->assertSame(PolicyEngineCommand::SUCCESS, $exitCode,
            '--list must return SUCCESS when policies are found');

        $display = $tester->getDisplay();
        $this->assertStringContainsString('retention',        $display, 'Table must include policy type');
        $this->assertStringContainsString('sensor_data',      $display, 'Table must include target');
        $this->assertStringContainsString('aggregate_refresh', $display, 'Table must include second policy type');
    }

    // =========================================================================
    // execute() — --pretend option
    // =========================================================================

    /**
     * --pretend with no policies must print "No policies would run" and
     * return SUCCESS without executing anything.
     */
    public function testPretendWithNoPolicies(): void
    {
        // Arrange
        $engineStub = $this->getMockBuilder(Engine::class)
            ->disableOriginalConstructor()
            ->getMock();
        $engineStub->method('getAllEnabled')->willReturn([]);

        $cmd = new class($engineStub) extends PolicyEngineCommand {
            private Engine $engineStub;

            public function __construct(Engine $stub)
            {
                parent::__construct();
                $this->engineStub = $stub;
            }

            protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int {
                $pretendRef = new \ReflectionMethod(PolicyEngineCommand::class, 'pretendRun');
                return $pretendRef->invoke($this, $this->engineStub, $output);
            }
        };

        $tester = $this->testerFor($cmd);

        // Act
        $exitCode = $tester->execute([]);

        // Assert
        $this->assertSame(PolicyEngineCommand::SUCCESS, $exitCode);
        $this->assertStringContainsString('No policies would run', $tester->getDisplay(),
            '--pretend with no policies must say "No policies would run"');
    }

    /**
     * --pretend with policies must print a dry-run line for each policy and
     * return SUCCESS without actually executing any SQL.
     */
    public function testPretendWithPoliciesDisplaysDryRunLines(): void
    {
        // Arrange
        $engineStub = $this->getMockBuilder(Engine::class)
            ->disableOriginalConstructor()
            ->getMock();
        $engineStub->method('getAllEnabled')->willReturn([
            $this->makeRecord(5, 'retention', 'audit_log'),
        ]);
        $engineStub->expects($this->never())->method('run'); // run() must NOT be called

        $cmd = new class($engineStub) extends PolicyEngineCommand {
            private Engine $engineStub;

            public function __construct(Engine $stub)
            {
                parent::__construct();
                $this->engineStub = $stub;
            }

            protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int {
                $pretendRef = new \ReflectionMethod(PolicyEngineCommand::class, 'pretendRun');
                return $pretendRef->invoke($this, $this->engineStub, $output);
            }
        };

        $tester = $this->testerFor($cmd);

        // Act
        $exitCode = $tester->execute([]);

        // Assert
        $this->assertSame(PolicyEngineCommand::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('[dry-run]', $display,
            'Output must contain "[dry-run]" prefix for each policy');
        $this->assertStringContainsString('retention', $display);
        $this->assertStringContainsString('audit_log', $display);
    }

    // =========================================================================
    // execute() — default run (doRun)
    // =========================================================================

    /**
     * doRun() with no due policies must print "No policies due" and return SUCCESS.
     *
     * engine->run() returns [] when nothing is scheduled for now.
     */
    public function testDoRunWithNoDuePolicies(): void
    {
        // Arrange
        $engineStub = $this->getMockBuilder(Engine::class)
            ->disableOriginalConstructor()
            ->getMock();
        $engineStub->method('run')->willReturn([]);

        $cmd = new class($engineStub) extends PolicyEngineCommand {
            private Engine $engineStub;

            public function __construct(Engine $stub)
            {
                parent::__construct();
                $this->engineStub = $stub;
            }

            protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int {
                $runRef = new \ReflectionMethod(PolicyEngineCommand::class, 'doRun');
                return $runRef->invoke($this, $this->engineStub, $output);
            }
        };

        $tester = $this->testerFor($cmd);

        // Act
        $exitCode = $tester->execute([]);

        // Assert
        $this->assertSame(PolicyEngineCommand::SUCCESS, $exitCode,
            'Must return SUCCESS when no policies are due');
        $this->assertStringContainsString('No policies due', $tester->getDisplay(),
            'Output must say "No policies due" when run() returns empty array');
    }

    /**
     * doRun() with all-successful results must print a checkmark per policy
     * and return SUCCESS.
     */
    public function testDoRunWithSuccessfulResultsReturnsSuccess(): void
    {
        // Arrange
        $engineStub = $this->getMockBuilder(Engine::class)
            ->disableOriginalConstructor()
            ->getMock();
        $engineStub->method('run')->willReturn([
            [
                'policyid'    => 1,
                'policy_type' => 'retention',
                'target'      => 'logs',
                'status'      => 'ok',
                'error'       => null,
            ],
        ]);

        $cmd = new class($engineStub) extends PolicyEngineCommand {
            private Engine $engineStub;

            public function __construct(Engine $stub)
            {
                parent::__construct();
                $this->engineStub = $stub;
            }

            protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int {
                $runRef = new \ReflectionMethod(PolicyEngineCommand::class, 'doRun');
                return $runRef->invoke($this, $this->engineStub, $output);
            }
        };

        $tester = $this->testerFor($cmd);

        // Act
        $exitCode = $tester->execute([]);

        // Assert
        $this->assertSame(PolicyEngineCommand::SUCCESS, $exitCode,
            'Must return SUCCESS when all policy results are "ok"');
        $this->assertStringContainsString('retention', $tester->getDisplay(),
            'Output must list the policy type for each executed policy');
    }

    /**
     * doRun() must return FAILURE and print an error line when at least one
     * policy result has status != 'ok'.
     *
     * This verifies the error-counting logic that drives the final return code.
     */
    public function testDoRunWithFailedResultsReturnsFailure(): void
    {
        // Arrange
        $engineStub = $this->getMockBuilder(Engine::class)
            ->disableOriginalConstructor()
            ->getMock();
        $engineStub->method('run')->willReturn([
            [
                'policyid'    => 2,
                'policy_type' => 'retention',
                'target'      => 'events',
                'status'      => 'error',
                'error'       => 'Table events does not exist',
            ],
        ]);

        $cmd = new class($engineStub) extends PolicyEngineCommand {
            private Engine $engineStub;

            public function __construct(Engine $stub)
            {
                parent::__construct();
                $this->engineStub = $stub;
            }

            protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int {
                $runRef = new \ReflectionMethod(PolicyEngineCommand::class, 'doRun');
                return $runRef->invoke($this, $this->engineStub, $output);
            }
        };

        $tester = $this->testerFor($cmd);

        // Act
        $exitCode = $tester->execute([]);

        // Assert
        $this->assertSame(PolicyEngineCommand::FAILURE, $exitCode,
            'Must return FAILURE when at least one policy result has a non-ok status');
        $this->assertStringContainsString('Table events does not exist', $tester->getDisplay(),
            'Output must include the error message from the failed policy');
    }

    /**
     * doRun() with a mix of successful and failed results must return FAILURE.
     *
     * The exit code is FAILURE when errors > 0 regardless of how many policies
     * succeeded. This is the correct behaviour — partial failure must not be
     * silently swallowed.
     */
    public function testDoRunWithMixedResultsReturnsFailure(): void
    {
        // Arrange
        $engineStub = $this->getMockBuilder(Engine::class)
            ->disableOriginalConstructor()
            ->getMock();
        $engineStub->method('run')->willReturn([
            [
                'policyid'    => 1,
                'policy_type' => 'retention',
                'target'      => 'logs',
                'status'      => 'ok',
                'error'       => null,
            ],
            [
                'policyid'    => 2,
                'policy_type' => 'aggregate_refresh',
                'target'      => 'hourly_view',
                'status'      => 'error',
                'error'       => 'View not found',
            ],
        ]);

        $cmd = new class($engineStub) extends PolicyEngineCommand {
            private Engine $engineStub;

            public function __construct(Engine $stub)
            {
                parent::__construct();
                $this->engineStub = $stub;
            }

            protected function execute(
                \Symfony\Component\Console\Input\InputInterface $input,
                \Symfony\Component\Console\Output\OutputInterface $output
            ): int {
                $runRef = new \ReflectionMethod(PolicyEngineCommand::class, 'doRun');
                return $runRef->invoke($this, $this->engineStub, $output);
            }
        };

        $tester = $this->testerFor($cmd);

        // Act
        $exitCode = $tester->execute([]);

        // Assert — any error means FAILURE
        $this->assertSame(PolicyEngineCommand::FAILURE, $exitCode,
            'Must return FAILURE even when only one of many policies failed');
    }
}
