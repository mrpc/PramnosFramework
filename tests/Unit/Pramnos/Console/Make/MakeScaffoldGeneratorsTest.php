<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Application;
use Pramnos\Console\Commands\Make\MakeCommand;
use Pramnos\Console\Commands\Make\MakeTask;
use Pramnos\Console\Commands\Make\MakeProvider;
use Pramnos\Console\Commands\Make\MakePolicy;
use Pramnos\Console\Commands\Make\MakeTest;

/**
 * Unit tests for the five create:* scaffold generators added to the Make family:
 * create:command, create:task, create:provider, create:policy, create:test.
 *
 * Each generator resolves the application namespace from the console
 * application's internalApplication, renders a stub, and writes a PHP file
 * under ROOT/src (plus, for four of them, a companion test stub under
 * ROOT/tests/Unit). These tests drive the public create* method of each
 * generator against a stub application whose namespace is "ScaffoldApp",
 * then assert the generated file exists with the expected namespace and
 * class name. No database is required.
 *
 * tearDown removes every artifact so the checkout stays clean.
 */
#[CoversClass(MakeCommand::class)]
#[CoversClass(MakeTask::class)]
#[CoversClass(MakeProvider::class)]
#[CoversClass(MakePolicy::class)]
#[CoversClass(MakeTest::class)]
class MakeScaffoldGeneratorsTest extends TestCase
{
    /** @var string[] Absolute paths removed in tearDown. */
    private array $artifacts = [];

    protected function setUp(): void
    {
        // Every artifact these tests create — deleted before and after each run
        // so a half-finished previous run cannot make a test pass or fail spuriously.
        $this->artifacts = [
            ROOT . '/src/Console/Commands/CmdSample.php',
            ROOT . '/src/Tasks/TaskSample.php',
            ROOT . '/src/Providers/ProvSample.php',
            ROOT . '/src/Policies/PolSample.php',
            ROOT . '/tests/Unit/CmdSampleCommandTest.php',
            ROOT . '/tests/Unit/TaskSampleTaskTest.php',
            ROOT . '/tests/Unit/ProvSampleProviderTest.php',
            ROOT . '/tests/Unit/PolSamplePolicyTest.php',
            ROOT . '/tests/Unit/TestSubjectTest.php',
        ];
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    /**
     * Remove all known artifacts and any now-empty scaffold directories.
     */
    private function cleanup(): void
    {
        foreach ($this->artifacts as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        foreach (['/src/Console/Commands', '/src/Console', '/src/Tasks', '/src/Providers', '/src/Policies'] as $dir) {
            $path = ROOT . $dir;
            if (is_dir($path)) {
                $entries = glob($path . '/*');
                if ($entries === false || empty($entries)) {
                    @rmdir($path);
                }
            }
        }
    }

    /**
     * Build a generator of the given class wired to a stub console application
     * whose namespace is "ScaffoldApp".
     *
     * The stub application is an anonymous subclass of the framework
     * Application: it exposes applicationInfo['namespace'] and a no-op init()
     * so the generator resolves a deterministic namespace without touching a
     * real config file or database.
     *
     * @template T of \Pramnos\Console\Commands\MakeCommandBase
     * @param class-string<T> $commandClass
     * @return T
     */
    private function makeGenerator(string $commandClass)
    {
        $app = new Application();
        // Plain stub (NOT extending Application) so its parent constructor cannot
        // reset applicationInfo — the generators only read ->applicationInfo and
        // ->appName off internalApplication, no type check.
        $app->internalApplication = new class {
            public $applicationInfo = ['namespace' => 'ScaffoldApp'];
            public $appName = '';
            public function init($settingsFile = '') {}
        };

        $command = new $commandClass();
        $command->setApplication($app);
        return $command;
    }

    // =========================================================================
    // create:command
    // =========================================================================

    /**
     * create:command must write a Symfony Console command class under the
     * application's <namespace>\Console\Commands namespace. This proves the
     * generator resolves the app namespace and renders the command stub.
     */
    public function testCreateCommand(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakeCommand::class);

        // Act
        $summary = $command->createConsoleCommand('CmdSample');

        // Assert — summary reports the class, and the file exists with the
        // expected namespace/class and Symfony Command base.
        $file = ROOT . '/src/Console/Commands/CmdSample.php';
        $this->assertFileExists($file);
        $this->assertStringContainsString('CmdSample', $summary);
        $src = (string) file_get_contents($file);
        $this->assertStringContainsString('namespace ScaffoldApp\\Console\\Commands;', $src);
        $this->assertStringContainsString('class CmdSample extends Command', $src);
        // CLI name is derived from the class name (Command suffix stripped).
        $this->assertStringContainsString("setName('app:cmdsample')", $src);
    }

    // =========================================================================
    // create:task
    // =========================================================================

    /**
     * create:task must write a queue task extending AbstractTask, implementing
     * the two required methods (execute/getDescription).
     */
    public function testCreateTask(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakeTask::class);

        // Act
        $summary = $command->createTask('TaskSample');

        // Assert
        $file = ROOT . '/src/Tasks/TaskSample.php';
        $this->assertFileExists($file);
        $this->assertStringContainsString('TaskSample', $summary);
        $src = (string) file_get_contents($file);
        $this->assertStringContainsString('namespace ScaffoldApp\\Tasks;', $src);
        $this->assertStringContainsString('class TaskSample extends AbstractTask', $src);
        // The required TaskInterface methods must be stubbed.
        $this->assertStringContainsString('public function execute(QueueItem $queueItem): mixed', $src);
        $this->assertStringContainsString('public function getDescription(QueueItem $queueItem): string', $src);
    }

    // =========================================================================
    // create:provider
    // =========================================================================

    /**
     * create:provider must write a service provider extending the framework
     * ServiceProvider with register()/boot() stubs.
     */
    public function testCreateProvider(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakeProvider::class);

        // Act
        $summary = $command->createProvider('ProvSample');

        // Assert
        $file = ROOT . '/src/Providers/ProvSample.php';
        $this->assertFileExists($file);
        $this->assertStringContainsString('ProvSample', $summary);
        $src = (string) file_get_contents($file);
        $this->assertStringContainsString('namespace ScaffoldApp\\Providers;', $src);
        $this->assertStringContainsString('class ProvSample extends ServiceProvider', $src);
        $this->assertStringContainsString('public function register(): void', $src);
        $this->assertStringContainsString('public function boot(): void', $src);
    }

    // =========================================================================
    // create:policy
    // =========================================================================

    /**
     * create:policy must write a plain authorization policy class (there is no
     * framework policy base) exposing ability methods, and the summary must
     * note that the methods have to be wired in manually.
     */
    public function testCreatePolicy(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakePolicy::class);

        // Act
        $summary = $command->createPolicy('PolSample');

        // Assert
        $file = ROOT . '/src/Policies/PolSample.php';
        $this->assertFileExists($file);
        $this->assertStringContainsString('PolSample', $summary);
        // The summary flags the absence of a framework policy base.
        $this->assertStringContainsString('no framework policy base', $summary);
        $src = (string) file_get_contents($file);
        $this->assertStringContainsString('namespace ScaffoldApp\\Policies;', $src);
        $this->assertStringContainsString('class PolSample', $src);
        // Ability method stubs must be present.
        $this->assertStringContainsString('public function view(', $src);
        $this->assertStringContainsString('public function update(', $src);
    }

    // =========================================================================
    // create:test
    // =========================================================================

    /**
     * create:test must write a PHPUnit test class to tests/Unit. The trailing
     * "Test" collapsing means both "TestSubject" and "TestSubjectTest" produce
     * a single TestSubjectTest class — this test verifies the no-suffix input.
     */
    public function testCreateTest(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakeTest::class);

        // Act
        $summary = $command->createTest('TestSubject');

        // Assert
        $file = ROOT . '/tests/Unit/TestSubjectTest.php';
        $this->assertFileExists($file);
        $this->assertStringContainsString('TestSubjectTest', $summary);
        $src = (string) file_get_contents($file);
        $this->assertStringContainsString('namespace Tests\\Unit;', $src);
        $this->assertStringContainsString('class TestSubjectTest extends TestCase', $src);
        // Exactly one "Test" suffix — no double "TestTest".
        $this->assertStringNotContainsString('TestSubjectTestTest', $src);
    }
}
