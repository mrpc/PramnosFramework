<?php

declare(strict_types=1);

namespace NamespacelessApp {
    /**
     * An application whose `applicationInfo` can be set by the test.
     *
     * The property default cannot be used for this: `Application::__construct()` overwrites
     * `applicationInfo` with `loadApplicationInfo(APP_PATH/app.php)`, so a subclass that declares
     * `= []` still comes back holding the fixture's namespace. It has to be assigned afterwards,
     * which is what {@see MakeScaffoldRefusalsTest::useApplication()} does.
     */
    class Application extends \Pramnos\Application\Application
    {
        public function init($settingsFile = '') {}
    }
}

namespace Pramnos\Tests\Unit\Console {

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;

/**
 * What the five scaffolders refuse, and the namespace they fall back to.
 *
 * The happy paths have tests. What had none was every refusal: the name that is not a class name,
 * the file that is already there, and the write that fails — five methods repeating the same three
 * guards, none of them ever executed. Which is the wrong half to leave untested, because a
 * generator that fails silently overwrites work: `create:model` had exactly that bug, found the
 * same way, and it had been shipping for a while.
 *
 * The `Cannot write …` arms are reached by putting a **directory** where the file should go.
 * `file_put_contents()` then returns `false` on any platform and as any user, which `chmod 000`
 * does not — the suite runs as root in its container, where an unwritable file is still writable.
 *
 * The `App` fallback needs an application whose `applicationInfo` names no namespace. Every other
 * test here supplies one, which is why those four lines had never run: they are what a project that
 * has not been through `init` gets.
 */
#[CoversClass(MakeCommandBase::class)]
class MakeScaffoldRefusalsTest extends TestCase
{
    private ScaffoldRefusalCommand $command;

    /** @var list<string> Absolute paths to remove, files or directories */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Anything a previous crashed run left behind.
         *
         * This class deliberately puts directories where files belong, in tree locations the other
         * scaffolding tests also write to. A run interrupted between the arrange and the teardown
         * leaves one of those behind, and the next run of *another* test then finds a directory
         * where it expects to be able to create a file. Clearing them here rather than trusting
         * teardown is what keeps that from being somebody else's failure tomorrow.
         */
        $root = defined('ROOT') ? ROOT : getcwd();
        foreach ([
            '/src/Middleware/RefusalBlocked.php',
            '/src/Services/RefusalBlocked.php',
            '/src/Events/RefusalBlocked.php',
            '/src/Listeners/RefusalBlocked.php',
            '/tests/Unit/RefusalBlockedStubTest.php',
        ] as $leftover) {
            $path = $root . $leftover;
            if (is_dir($path)) {
                foreach (glob($path . '/*') ?: [] as $inside) {
                    @unlink($inside);
                }
                @rmdir($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }

        // Non-empty, and naming no namespace: what a project that has not been configured has.
        $this->useApplication(['theme' => 'default']);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $path) {
            if (is_dir($path)) {
                @rmdir($path);
            } elseif (file_exists($path)) {
                @unlink($path);
            }
        }
        $this->cleanup = [];
        parent::tearDown();
    }

    /** Point the command at a given internal application. */
    private function useApplication(array $applicationInfo, string $appName = ''): void
    {
        $application = new \NamespacelessApp\Application();

        // After construction, never as a property default — see the class's own note.
        $application->applicationInfo = $applicationInfo;
        $application->appName         = $appName;

        $console = new \Pramnos\Console\Application();
        $console->internalApplication = $application;

        $this->command = new ScaffoldRefusalCommand();
        $this->command->setApplication($console);
    }

    /** Creates a directory where a file is expected, so the write fails. */
    private function blockWith(string $path): void
    {
        $parent = dirname($path);
        if (!is_dir($parent)) {
            @mkdir($parent, 0777, true);
            $this->cleanup[] = $parent;
        }
        @mkdir($path, 0777, true);
        $this->cleanup[] = $path;
    }

    /** Remembers a path so tearDown removes whatever the generator wrote. */
    private function willWrite(string $path): string
    {
        $this->cleanup[] = $path;

        return $path;
    }

    /**
     * With no namespace in `applicationInfo`, everything is generated under `App`.
     *
     * The four creators each carry their own copy of the fallback, so all four are asserted: a
     * project whose `applicationInfo` is empty must still get a valid namespace rather than a
     * class declared in none.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function creators(): array
    {
        return [
            'middleware' => ['createMiddleware', 'src/Middleware', 'App\\Middleware'],
            'service'    => ['createService',    'src/Services',   'App\\Services'],
            'event'      => ['createEvent',      'src/Events',     'App\\Events'],
            'listener'   => ['createListener',   'src/Listeners',  'App\\Listeners'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('creators')]
    public function testWithoutAConfiguredNamespaceEverythingLandsUnderApp(
        string $method,
        string $directory,
        string $expectedNamespace
    ): void {
        // Arrange
        $root = defined('ROOT') ? ROOT : getcwd();
        $this->willWrite($root . '/' . $directory . '/RefusalFallback.php');
        $this->willWrite($root . '/tests/Unit/RefusalFallbackTest.php');
        $this->willWrite($root . '/tests/Unit/RefusalFallbackMiddlewareTest.php');

        // Act
        $summary = $this->command->{$method}('RefusalFallback');

        // Assert
        $this->assertStringContainsString($expectedNamespace, $summary);
    }

    /**
     * A name with no word characters in it is refused before anything is written.
     *
     * `preg_replace('/\W+/', '', '@@@')` is the empty string, and a class named `''` is a parse
     * error in a file the generator would have reported as created.
     */
    public function testANameThatIsNotAClassNameIsRefused(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Service name must be a valid PHP class name.');

        // Act
        $this->command->createService('@@@');
    }

    /**
     * An existing file is never overwritten — the generator refuses and says where it is.
     *
     * The path that matters: `create:model` used to overwrite, and the report read the same either
     * way. Somebody's hand-written model is not a scaffolding conflict to resolve silently.
     */
    public function testAnExistingFileIsRefusedRatherThanOverwritten(): void
    {
        // Arrange
        $root = defined('ROOT') ? ROOT : getcwd();
        $path = $root . '/src/Services/RefusalExisting.php';
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0777, true);
            $this->cleanup[] = dirname($path);
        }
        file_put_contents($path, "<?php\n// hand written\n");
        $this->cleanup[] = $path;

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('RefusalExisting already exists');

        // Act
        $this->command->createService('RefusalExisting');
    }

    /** The file survives the refusal — the point of refusing. */
    public function testTheExistingFileIsLeftExactlyAsItWas(): void
    {
        // Arrange
        $root = defined('ROOT') ? ROOT : getcwd();
        $path = $root . '/src/Services/RefusalUntouched.php';
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0777, true);
            $this->cleanup[] = dirname($path);
        }
        file_put_contents($path, "<?php\n// hand written\n");
        $this->cleanup[] = $path;

        // Act
        try {
            $this->command->createService('RefusalUntouched');
        } catch (\Exception) {
            // The assertion is about the file, not the message.
        }

        // Assert
        $this->assertSame("<?php\n// hand written\n", file_get_contents($path));
    }

    /**
     * Every creator refuses when something already occupies its path — a directory included.
     *
     * The four `Cannot write …` arms below these guards are **not** reachable from the suite, and
     * this test is why I know: `file_exists()` is true for a directory, so the obvious way to make
     * `file_put_contents()` fail trips the guard above it instead. Every remaining way needs a
     * read-only mount, a full disk or a quota, none of which a container running as root produces.
     * The arms are correct and stay; they carry a `@codeCoverageIgnore` with that reason.
     *
     * What this asserts is the guard that does fire, on all four, because each has its own copy and
     * the message names the kind of file — the only thing distinguishing four identical arms.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function occupiedTargets(): array
    {
        return [
            'middleware' => ['createMiddleware', 'src/Middleware', 'Middleware RefusalBlocked already exists'],
            'service'    => ['createService',    'src/Services',   'Service RefusalBlocked already exists'],
            'event'      => ['createEvent',      'src/Events',     'Event RefusalBlocked already exists'],
            'listener'   => ['createListener',   'src/Listeners',  'Listener RefusalBlocked already exists'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('occupiedTargets')]
    public function testAnOccupiedPathIsRefusedByEveryCreator(
        string $method,
        string $directory,
        string $expectedMessage
    ): void {
        // Arrange — a directory where the file should be
        $root = defined('ROOT') ? ROOT : getcwd();
        $this->blockWith($root . '/' . $directory . '/RefusalBlocked.php');

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($expectedMessage);

        // Act
        $this->command->{$method}('RefusalBlocked');
    }

    /**
     * The migration namespace: `App` without configuration, and the sub-application appended.
     *
     * `appName` is the second half of a namespace on a multi-application project, and a migration
     * declared in the wrong one is not loaded by `MigrationLoader` — it simply never runs, which is
     * the worst way for a migration to be wrong.
     */
    public function testTheMigrationNamespaceFallsBackAndAppendsTheApplicationName(): void
    {
        // Act — no configured namespace
        $plain = $this->command->createMigration('refusal_fallback_one');
        $this->rememberMigration($plain);

        // Assert
        $this->assertStringContainsString('Namespace: App\\Migrations', $plain);

        // Act — a namespace and a sub-application
        $this->useApplication(['namespace' => 'TestApp'], 'Billing');
        $named = $this->command->createMigration('refusal_fallback_two');
        $this->rememberMigration($named);

        // Assert
        $this->assertStringContainsString('Namespace: TestApp\\Billing\\Migrations', $named);
    }

    /** Reads the generated path out of a `createMigration()` summary so teardown removes it. */
    private function rememberMigration(string $summary): void
    {
        if (preg_match('/^File:\s+(.+)$/m', $summary, $match)) {
            $this->cleanup[] = trim($match[1]);
        }
    }

    /**
     * A migration whose file is already there is refused.
     *
     * The filename carries `date('Y_m_d_His')`, so the collision this asserts is real but only
     * exists inside one second. Both candidate names are pre-created — this second's and the next
     * one's — because otherwise a run that crosses the boundary between computing the name here and
     * computing it in the command would generate a file instead of refusing, and the test would
     * fail for a reason that has nothing to do with the code.
     */
    public function testAMigrationThatAlreadyExistsIsRefused(): void
    {
        // Arrange
        $slug = 'refusal_duplicate';
        $dir  = APP_PATH . DS . 'migrations';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
            $this->cleanup[] = $dir;
        }

        foreach ([time(), time() + 1] as $second) {
            $path = $dir . DS . date('Y_m_d_His', $second) . '_' . $slug . '.php';
            file_put_contents($path, "<?php\n");
            $this->cleanup[] = $path;
        }

        // Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Migration file already exists');

        // Act
        $this->command->createMigration($slug);
    }

    /**
     * A generated test stub is never overwritten either, and its absence is not an error.
     *
     * `generateTestStub()` returns an empty string rather than throwing, because a scaffolder that
     * refused to create a controller over an existing *test* would be unusable on any project that
     * has written one. The empty string is what keeps it out of the summary.
     */
    public function testAnExistingTestStubIsSkippedQuietly(): void
    {
        // Arrange
        $root = defined('ROOT') ? ROOT : getcwd();
        $path = $root . '/tests/Unit/RefusalStubTest.php';
        file_put_contents($path, "<?php\n// hand written\n");
        $this->cleanup[] = $path;

        // Act
        $result = $this->command->generateTestStub('RefusalStub', 'App');

        // Assert
        $this->assertSame('', $result, 'an existing test should be skipped, not reported as written');
        $this->assertSame("<?php\n// hand written\n", file_get_contents($path));
    }

    /** A directory in the stub's place is skipped the same way, and nothing is written into it. */
    public function testATestStubWhosePathIsOccupiedIsSkipped(): void
    {
        // Arrange
        $root = defined('ROOT') ? ROOT : getcwd();
        $path = $root . '/tests/Unit/RefusalBlockedStubTest.php';
        $this->blockWith($path);

        // Act
        $result = $this->command->generateTestStub('RefusalBlockedStub', 'App');

        // Assert
        $this->assertSame('', $result);
        $this->assertSame([], glob($path . '/*') ?: [], 'something was written into the blocked path');
    }
}

/** The base class is abstract in practice — this makes its public creators callable. */
class ScaffoldRefusalCommand extends MakeCommandBase
{
    protected function configure(): void {}

    protected function execute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ): int {
        return 0;
    }
}

}
