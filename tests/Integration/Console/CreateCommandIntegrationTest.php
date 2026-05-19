<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Pramnos\Application\Settings;
use Pramnos\Application\Application;
use Pramnos\Console\Application as ConsoleApplication;
use Pramnos\Console\Commands\Create;
use Pramnos\Database\Database;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration tests for Create command methods that require a live database.
 *
 * These tests exercise createModel() and createController() ($full=true) which
 * call Database::getInstance() — the static singleton cannot be mocked, so a
 * real MySQL connection is needed.
 *
 * The "stub skeleton" path of createModel() (table does not exist) is covered
 * here: it writes a minimal Model PHP file without inspecting DB columns.
 *
 * Each test runs in a separate process so the Database singleton used here
 * (MySQL) does not leak into sibling integration tests that expect PostgreSQL.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
#[CoversClass(Create::class)]
#[RunTestsInSeparateProcesses]
class CreateCommandIntegrationTest extends TestCase
{
    private ConsoleApplication $consoleApp;
    private Create $create;
    private string $testId;

    /** Files created by the command under test — deleted in tearDown. */
    private array $filesToCleanup = [];

    /** Directories created (only removed when empty). */
    private array $dirsToCleanup = [];
    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] in configure();
        // ensure it is set to prevent "Undefined array key" warnings in PHP 8.4.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }
        if (!defined('INCLUDES')) {
            define('INCLUDES', 'src');
        }

        // Bootstrap MySQL singleton so createModel()/createController() can call
        // Database::getInstance() and tableExists().
        $settingsFile = ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();

        $db = Database::getInstance();
        if (!$db->connected) {
            $db->connect();
        }
        if (!$db->connected) {
            $this->markTestSkipped('MySQL container not reachable (db:3306)');
        }

        $this->testId = bin2hex(random_bytes(6));

        // Build a minimal mock console application — same pattern as CreateCommandFileTest
        $this->consoleApp = new class extends ConsoleApplication {
            protected function registerCommands(): void {}
        };
        $this->consoleApp->internalApplication = new class {
            public string $appName         = '';
            public array  $applicationInfo = ['namespace' => 'TestApp'];
            public $database               = null;
            public function init(): void {}
        };

        $this->create = new Create();
        $this->consoleApp->add($this->create);
    }

    protected function tearDown(): void
    {
        // Remove individual files registered for cleanup
        foreach (array_reverse($this->filesToCleanup) as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        // Glob-based fallback for any file containing this test's ID
        $root    = defined('ROOT') ? ROOT : getcwd();
        $modDir  = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Models';
        $unitDir = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit';
        foreach ([$modDir, $unitDir] as $dir) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*' . $this->testId . '*.php') ?: [] as $f) {
                @unlink($f);
            }
        }

        // Remove empty directories created during tests
        foreach (array_reverse($this->dirsToCleanup) as $dir) {
            if (is_dir($dir)) {
                $entries = array_diff(scandir($dir) ?: [], ['.', '..']);
                foreach ($entries as $entry) {
                    @unlink($dir . DIRECTORY_SEPARATOR . $entry);
                }
                @rmdir($dir);
            }
        }

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    // =========================================================================
    // createModel() — stub-skeleton path (table does not exist)
    // =========================================================================

    /**
     * execute() with entity='model' must dispatch to createModel().  When the
     * table derived from the model name does not exist in the database,
     * createModel() must write a stub skeleton file and return a message
     * containing "Model skeleton created".
     *
     * This covers:
     *   - execute() switch arm for 'model' (lines 83-86 of Create.php)
     *   - createModel() lines 2539-2594: the no-table stub path including
     *     tableExists() check, mkdir, renderStub('model'), file_put_contents,
     *     generateTestStub, and the return message construction.
     */
    public function testExecuteCreatesModelSkeletonWhenTableDoesNotExist(): void
    {
        // Arrange — ensure Models directory exists
        $root    = defined('ROOT') ? ROOT : getcwd();
        $modDir  = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Models';
        if (!is_dir($modDir)) {
            mkdir($modDir, 0755, true);
            $this->dirsToCleanup[] = $modDir;
        }

        // Name chosen so the derived table name ('#PREFIX#zzzmtest<id>s') is
        // guaranteed not to exist in the test database.
        $modelName = 'Zzzmtest' . $this->testId;

        $tester = new CommandTester($this->create);

        // Act
        $exit = $tester->execute(['entity' => 'model', 'name' => $modelName]);

        // Assert — exit code 0
        $this->assertSame(0, $exit, 'execute(model) must return 0');

        // Assert — output announces stub skeleton creation
        $display = $tester->getDisplay();
        $this->assertStringContainsString(
            'Model skeleton created',
            $display,
            'createModel() must report stub creation when table is absent'
        );

        // Assert — the model file actually exists somewhere under src/Models/
        $createdFiles = glob($modDir . DIRECTORY_SEPARATOR . '*' . $this->testId . '*.php') ?: [];
        $this->assertNotEmpty(
            $createdFiles,
            'createModel() must write a .php file under src/Models/'
        );
    }

    /**
     * createModel() must throw when the model file already exists.
     *
     * This covers the "Model already exists" guard at line 2577 of Create.php,
     * which protects against accidental overwrites of existing models.
     */
    public function testCreateModelThrowsWhenFileAlreadyExists(): void
    {
        // Arrange — create the Models directory and a pre-existing model file
        $root   = defined('ROOT') ? ROOT : getcwd();
        $modDir = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Models';
        if (!is_dir($modDir)) {
            mkdir($modDir, 0755, true);
            $this->dirsToCleanup[] = $modDir;
        }

        $modelName  = 'Zzzmexist' . $this->testId;
        // getProperClassName() with $forceSingular=true: singularize if plural, else ucfirst
        $className  = \Pramnos\Console\Commands\Create::getProperClassName($modelName, true);
        $preExisting = $modDir . DIRECTORY_SEPARATOR . $className . '.php';
        file_put_contents($preExisting, "<?php // pre-existing\n");
        $this->filesToCleanup[] = $preExisting;

        $tester = new CommandTester($this->create);

        // Act & Assert — second call throws "Model already exists"
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Model already exists/');

        $tester->execute(['entity' => 'model', 'name' => $modelName]);
    }
}
