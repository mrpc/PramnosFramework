<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Console\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
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
            // Edge-case artifacts exercised by the coverage tests below.
            ROOT . '/src/ConsoleCommands/Command.php',
            ROOT . '/tests/Unit/CommandCommandTest.php',
            ROOT . '/tests/Unit/TestTest.php',
            // `create:command` writes where `init` put the project's commands, which
            // is a second directory these tests have to know about — and Console.php,
            // which it now appends the registration to.
            ROOT . '/src/ConsoleCommands/CmdSample.php',
            ROOT . '/src/ConsoleCommands/CmdNamed.php',
            ROOT . '/src/ConsoleCommands/CmdRegistered.php',
            ROOT . '/src/ConsoleCommands/CmdSecond.php',
            ROOT . '/src/ConsoleCommands/CmdTested.php',
            ROOT . '/src/Console/Commands/CmdLegacy.php',
            ROOT . '/src/Console.php',
            ROOT . '/tests/Unit/CmdNamedCommandTest.php',
            ROOT . '/tests/Unit/CmdRegisteredCommandTest.php',
            ROOT . '/tests/Unit/CmdSecondCommandTest.php',
            ROOT . '/tests/Unit/CmdTestedCommandTest.php',
            ROOT . '/tests/Unit/CmdLegacyCommandTest.php',
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
        foreach ([
            '/src/ConsoleCommands',
            '/src/Console/Commands',
            '/src/Console',
            '/src/Tasks',
            '/src/Providers',
            '/src/Policies',
        ] as $dir) {
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
     * create:command writes where `init` put the project's commands.
     *
     * It wrote `src/Console/Commands/` while `init` writes `src/ConsoleCommands/`,
     * and `src/Console.php` — the file that has to `add()` the command — invites the
     * next one *there*. Both namespaces autoload, so nothing failed; a project simply
     * ended up with commands in two places, by a convention that depended on which
     * tool wrote the file.
     */
    public function testCreateCommandWritesWhereInitPutTheProjectsCommands(): void
    {
        // Arrange — a project scaffolded by `init`, which is what that directory means.
        @mkdir(ROOT . '/src/ConsoleCommands', 0777, true);
        $command = $this->makeGenerator(MakeCommand::class);

        // Act
        $summary = $command->createConsoleCommand('CmdSample');

        // Assert
        $file = ROOT . '/src/ConsoleCommands/CmdSample.php';
        $this->assertFileExists($file);
        $this->assertFileDoesNotExist(
            ROOT . '/src/Console/Commands/CmdSample.php',
            'a second, parallel tree is the defect'
        );
        $this->assertStringContainsString('CmdSample', $summary);
        $src = (string) file_get_contents($file);
        $this->assertStringContainsString('namespace ScaffoldApp\\ConsoleCommands;', $src);
        $this->assertStringContainsString('class CmdSample extends Command', $src);
    }

    /**
     * A project that has only the old location keeps it.
     *
     * Discovery rather than a new hard-coded path: a project scaffolded before this,
     * with commands already in `src/Console/Commands/`, would otherwise get the split
     * in the other direction.
     */
    public function testCreateCommandKeepsAnExistingLocation(): void
    {
        // Arrange
        @mkdir(ROOT . '/src/Console/Commands', 0777, true);
        $command = $this->makeGenerator(MakeCommand::class);

        // Act
        $command->createConsoleCommand('CmdLegacy');

        // Assert
        $this->assertFileExists(ROOT . '/src/Console/Commands/CmdLegacy.php');
        $src = (string) file_get_contents(ROOT . '/src/Console/Commands/CmdLegacy.php');
        $this->assertStringContainsString('namespace ScaffoldApp\\Console\\Commands;', $src);
    }

    /**
     * The CLI name follows the documented `namespace:verb` taxonomy.
     *
     * `CollectChannels` became `app:collectchannels` — a lower-cased concatenation,
     * against the convention the console guide states at the top of its own command
     * table. The first word of a PascalCase class name is nearly always the verb, and
     * one word has no noun to group under, so it keeps `app:`.
     */
    public function testTheCliNameFollowsTheTaxonomy(): void
    {
        // Act & Assert
        $this->assertSame('channels:collect', MakeCommand::deriveCliName('CollectChannels'));
        $this->assertSame('stock:sync', MakeCommand::deriveCliName('SyncStock'));
        $this->assertSame('users:import', MakeCommand::deriveCliName('ImportUsersCommand'));
        $this->assertSame('app:deploy', MakeCommand::deriveCliName('Deploy'));
    }

    /**
     * An explicit name wins over the derivation.
     *
     * A guess is still a guess; what it replaced was not a guess but a concatenation.
     */
    public function testAnExplicitCliNameIsUsed(): void
    {
        // Arrange
        @mkdir(ROOT . '/src/ConsoleCommands', 0777, true);
        $command = $this->makeGenerator(MakeCommand::class);

        // Act
        $command->createConsoleCommand('CmdNamed', 'feeds:pull');

        // Assert
        $src = (string) file_get_contents(ROOT . '/src/ConsoleCommands/CmdNamed.php');
        $this->assertStringContainsString("setName('feeds:pull')", $src);
    }

    /**
     * The command is registered, so it appears in `<cli> list`.
     *
     * The one thing a generated command must be is runnable, and nothing registered
     * it — so it did not exist until somebody added the line by hand, which the
     * output did not say either. The line lands on the invitation `init` writes.
     */
    public function testTheCommandIsRegisteredInConsolePhp(): void
    {
        // Arrange — Console.php as `init` writes it.
        @mkdir(ROOT . '/src', 0777, true);
        file_put_contents(ROOT . '/src/Console.php', <<<'PHP'
<?php
namespace ScaffoldApp;

class Console extends \Pramnos\Console\Application
{
    protected function registerCommands(): void
    {
        parent::registerCommands();
        // Register your custom commands here:
    }
}
PHP);
        $command = $this->makeGenerator(MakeCommand::class);

        // Act
        $summary = $command->createConsoleCommand('CmdRegistered');

        // Assert
        $console = (string) file_get_contents(ROOT . '/src/Console.php');
        $this->assertStringContainsString(
            '$this->add(new \\ScaffoldApp\\ConsoleCommands\\CmdRegistered());',
            $console
        );
        $this->assertStringContainsString('added to src/Console.php', $summary);

        // …and it is not added twice when the generator runs again for another command.
        $command->createConsoleCommand('CmdSecond');
        $console = (string) file_get_contents(ROOT . '/src/Console.php');
        $this->assertSame(
            1,
            substr_count($console, 'CmdRegistered()'),
            'the registration must be idempotent'
        );
    }

    /**
     * The generated test asserts something.
     *
     * `test.stub` is `assertTrue(true)` — a passing test with no subject, which in a
     * project with a coverage floor is a hole the report cannot see. A command's name
     * is its entire public surface, and its first failure mode is a fatal before it
     * prints anything; both are worth pinning from the first minute.
     */
    public function testTheGeneratedTestHasASubject(): void
    {
        // Arrange
        @mkdir(ROOT . '/src/ConsoleCommands', 0777, true);
        $command = $this->makeGenerator(MakeCommand::class);

        // Act
        $command->createConsoleCommand('CmdTested');

        // Assert
        $test = (string) file_get_contents(ROOT . '/tests/Unit/CmdTestedCommandTest.php');
        $this->assertStringNotContainsString('assertTrue(true)', $test);
        $this->assertStringContainsString('CommandTester', $test);
        $this->assertStringContainsString(
            "find('" . MakeCommand::deriveCliName('CmdTested') . "')",
            $test,
            'the test has to look the command up by the name it was generated with'
        );
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

    // =========================================================================
    // execute() wrappers — driven through Symfony CommandTester
    // =========================================================================

    /**
     * create:command's execute() wrapper must parse the `name` argument, invoke
     * createConsoleCommand(), and print its summary. Running through
     * CommandTester covers the option/argument parsing (prepareExecution) and
     * the writeln() output path that the direct-method tests bypass.
     */
    public function testCommandExecuteWritesFileAndOutput(): void
    {
        // Arrange — a generator wired to the ScaffoldApp stub application.
        $command = $this->makeGenerator(MakeCommand::class);
        $tester  = new CommandTester($command);

        // Act — run the command exactly as the CLI would.
        $exit = $tester->execute(['name' => 'CmdSample']);

        // Assert — success exit code, file written, summary echoed to output.
        $this->assertSame(0, $exit); // execute() returns 0 on success
        $this->assertFileExists(ROOT . '/src/ConsoleCommands/CmdSample.php');
        $this->assertStringContainsString('Command created.', $tester->getDisplay());
    }

    /**
     * create:task's execute() wrapper end-to-end via CommandTester.
     */
    public function testTaskExecuteWritesFileAndOutput(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakeTask::class);
        $tester  = new CommandTester($command);

        // Act
        $exit = $tester->execute(['name' => 'TaskSample']);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertFileExists(ROOT . '/src/Tasks/TaskSample.php');
        $this->assertStringContainsString('Task created.', $tester->getDisplay());
    }

    /**
     * create:provider's execute() wrapper end-to-end via CommandTester.
     */
    public function testProviderExecuteWritesFileAndOutput(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakeProvider::class);
        $tester  = new CommandTester($command);

        // Act
        $exit = $tester->execute(['name' => 'ProvSample']);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertFileExists(ROOT . '/src/Providers/ProvSample.php');
        $this->assertStringContainsString('Provider created.', $tester->getDisplay());
    }

    /**
     * create:policy's execute() wrapper end-to-end via CommandTester.
     */
    public function testPolicyExecuteWritesFileAndOutput(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakePolicy::class);
        $tester  = new CommandTester($command);

        // Act
        $exit = $tester->execute(['name' => 'PolSample']);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertFileExists(ROOT . '/src/Policies/PolSample.php');
        $this->assertStringContainsString('Policy created.', $tester->getDisplay());
    }

    /**
     * create:test's execute() wrapper end-to-end via CommandTester.
     */
    public function testTestExecuteWritesFileAndOutput(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakeTest::class);
        $tester  = new CommandTester($command);

        // Act
        $exit = $tester->execute(['name' => 'TestSubject']);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertFileExists(ROOT . '/tests/Unit/TestSubjectTest.php');
        $this->assertStringContainsString('Test created.', $tester->getDisplay());
    }

    // =========================================================================
    // execute() — missing name argument hits the "Name is required" guard
    // =========================================================================

    /**
     * Each execute() throws InvalidArgumentException when no name is supplied.
     * The `name` argument is OPTIONAL at the Symfony level, so the value is
     * null and the generator's own guard must reject it. One assertion per
     * generator proves every wrapper's guard branch is reachable.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missingNameProvider')]
    public function testExecuteRequiresName(string $commandClass, string $expectedMessage): void
    {
        // Arrange
        $command = $this->makeGenerator($commandClass);
        $tester  = new CommandTester($command);

        // Assert (set expectation before Act — the call must throw)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        // Act — run with an empty input set so getArgument('name') is null.
        $tester->execute([]);
    }

    /**
     * @return array<string,array{0:class-string,1:string}>
     */
    public static function missingNameProvider(): array
    {
        return [
            'command'  => [MakeCommand::class,  'Name is required for: command'],
            'task'     => [MakeTask::class,     'Name is required for: task'],
            'provider' => [MakeProvider::class, 'Name is required for: provider'],
            'policy'   => [MakePolicy::class,   'Name is required for: policy'],
            'test'     => [MakeTest::class,     'Name is required for: test'],
        ];
    }

    // =========================================================================
    // create* — file-already-exists guard
    // =========================================================================

    /**
     * Calling a generator twice for the same name must throw on the second
     * call: the target file already exists and must never be silently
     * overwritten. Verifies the file_exists() guard in each create* method.
     */
    public function testCreateCommandThrowsWhenFileExists(): void
    {
        // Arrange — first call creates the file.
        $command = $this->makeGenerator(MakeCommand::class);
        $command->createConsoleCommand('CmdSample');

        // Assert — the second call must refuse to overwrite it.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already exists');

        // Act
        $command->createConsoleCommand('CmdSample');
    }

    /** @see testCreateCommandThrowsWhenFileExists — same guard for create:task. */
    public function testCreateTaskThrowsWhenFileExists(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakeTask::class);
        $command->createTask('TaskSample');

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already exists');

        // Act
        $command->createTask('TaskSample');
    }

    /** @see testCreateCommandThrowsWhenFileExists — same guard for create:provider. */
    public function testCreateProviderThrowsWhenFileExists(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakeProvider::class);
        $command->createProvider('ProvSample');

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already exists');

        // Act
        $command->createProvider('ProvSample');
    }

    /** @see testCreateCommandThrowsWhenFileExists — same guard for create:policy. */
    public function testCreatePolicyThrowsWhenFileExists(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakePolicy::class);
        $command->createPolicy('PolSample');

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already exists');

        // Act
        $command->createPolicy('PolSample');
    }

    /** @see testCreateCommandThrowsWhenFileExists — same guard for create:test. */
    public function testCreateTestThrowsWhenFileExists(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakeTest::class);
        $command->createTest('TestSubject');

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already exists');

        // Act
        $command->createTest('TestSubject');
    }

    // =========================================================================
    // create* — invalid name collapses to an empty class name
    // =========================================================================

    /**
     * A name consisting solely of non-word characters ("###") is stripped to an
     * empty string by the `preg_replace('/\W+/', '', …)` sanitiser, which must
     * trigger the InvalidArgumentException guard before any file is written.
     * One assertion per generator covers each guard branch.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNameProvider')]
    public function testCreateRejectsInvalidName(string $commandClass, string $method): void
    {
        // Arrange
        $command = $this->makeGenerator($commandClass);

        // Assert — the sanitiser yields '' and the guard must reject it.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('valid PHP class name');

        // Act — call the public create* method with a name that has no word chars.
        $command->{$method}('###');
    }

    /**
     * @return array<string,array{0:class-string,1:string}>
     */
    public static function invalidNameProvider(): array
    {
        return [
            'command'  => [MakeCommand::class,  'createConsoleCommand'],
            'task'     => [MakeTask::class,     'createTask'],
            'provider' => [MakeProvider::class, 'createProvider'],
            'policy'   => [MakePolicy::class,   'createPolicy'],
            'test'     => [MakeTest::class,     'createTest'],
        ];
    }

    // =========================================================================
    // Name-derivation edge cases
    // =========================================================================

    /**
     * When the class name is exactly "Command", stripping the trailing
     * "Command" suffix leaves an empty base, so the CLI name must fall back to
     * the full class name ("app:command") rather than producing "app:".
     * Exercises the `$base === '' ? $className : $base` ternary in MakeCommand.
     */
    public function testCreateCommandNamedCommandFallsBackToFullName(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakeCommand::class);

        // Act
        $command->createConsoleCommand('Command');

        // Assert — the empty-base fallback keeps a usable CLI name. One word has no
        // noun to group under, so `app:` is the right prefix rather than a guess.
        $src = (string) file_get_contents(ROOT . '/src/ConsoleCommands/Command.php');
        $this->assertStringContainsString("setName('app:command')", $src);
    }

    /**
     * When the test name is exactly "Test", stripping the trailing "Test" leaves
     * an empty base, so the generator must fall back to the full name and emit a
     * single "TestTest" class (not an empty "Test.php"). Exercises the
     * `if ($base === '')` fallback in MakeTest.
     */
    public function testCreateTestNamedTestFallsBackToFullName(): void
    {
        // Arrange
        $command = $this->makeGenerator(MakeTest::class);

        // Act
        $summary = $command->createTest('Test');

        // Assert — the fallback produces tests/Unit/TestTest.php with class TestTest.
        $this->assertFileExists(ROOT . '/tests/Unit/TestTest.php');
        $this->assertStringContainsString('TestTest', $summary);
    }
}
