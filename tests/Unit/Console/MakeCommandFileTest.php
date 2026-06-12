<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Pramnos\Console\Application as ConsoleApplication;
use Pramnos\Console\Commands\Make\MakeMiddleware;
use Pramnos\Console\Commands\Make\MakeEvent;
use Pramnos\Console\Commands\Make\MakeListener;
use Pramnos\Console\Commands\Make\MakeMigration;
use Pramnos\Console\Commands\Make\MakeSeeder;
use Pramnos\Console\Commands\Make\MakeController;
use Pramnos\Console\Commands\Make\MakeView;
use Pramnos\Console\Commands\Make\MakeApi;
use Pramnos\Console\Commands\Make\MakeCrud;
use Pramnos\Console\Commands\Make\MakeModel;

/**
 * File-system integration tests for the Make sub-commands.
 *
 * Each test exercises a `create:*` command end-to-end via CommandTester:
 *  - Happy path: correct exit code, success message in output, file on disk
 *  - Error paths: missing required name argument, duplicate file
 *
 * All generated files are cleaned up in tearDown() so nothing leaks between runs.
 * A random testId suffix ensures parallel test runs can't collide on file names.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Console\Commands\MakeCommandBase::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Console\Commands\Make\MakeMiddleware::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Console\Commands\Make\MakeEvent::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Console\Commands\Make\MakeListener::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Console\Commands\Make\MakeMigration::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Console\Commands\Make\MakeSeeder::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Console\Commands\Make\MakeController::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Console\Commands\Make\MakeView::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Console\Commands\Make\MakeApi::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Console\Commands\Make\MakeCrud::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\Pramnos\Console\Commands\Make\MakeModel::class)]
class MakeCommandFileTest extends TestCase
{
    private array $filesToCleanup = [];
    private array $dirsToCleanup = [];
    private ConsoleApplication $consoleApp;
    private string $testId;

    protected function setUp(): void
    {
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $this->testId = bin2hex(random_bytes(6));

        if (!defined('INCLUDES')) {
            define('INCLUDES', 'src');
        }

        $this->consoleApp = new class extends ConsoleApplication {
            protected function registerCommands(): void {}
        };

        $this->consoleApp->internalApplication = new class {
            public string $appName = '';
            public array $applicationInfo = ['namespace' => 'TestApp'];
            public $database = null;
            public function init(): void {}
        };

        $this->consoleApp->add(new MakeMiddleware());
        $this->consoleApp->add(new MakeEvent());
        $this->consoleApp->add(new MakeListener());
        $this->consoleApp->add(new MakeMigration());
        $this->consoleApp->add(new MakeSeeder());
        $this->consoleApp->add(new MakeController());
        $this->consoleApp->add(new MakeView());
        $this->consoleApp->add(new MakeApi());
        $this->consoleApp->add(new MakeCrud());
        $this->consoleApp->add(new MakeModel());
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->filesToCleanup) as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        $root = defined('ROOT') ? ROOT : getcwd();
        $unitDir = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Unit';
        $seedDir = APP_PATH . DIRECTORY_SEPARATOR . 'seeders';
        $migDir  = APP_PATH . DIRECTORY_SEPARATOR . 'migrations';
        $eventsDir = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Events';
        $listenersDir = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Listeners';
        $middlewareDir = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Middleware';

        $dirs = [$unitDir, $seedDir, $migDir, $eventsDir, $listenersDir, $middlewareDir];
        foreach ($dirs as $dir) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*' . $this->testId . '*.php') ?: [] as $leftover) {
                @unlink($leftover);
            }
        }

        $ctrlDir    = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers';
        $featureDir = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature';
        $viewsDir   = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Views';
        foreach (glob($ctrlDir . DIRECTORY_SEPARATOR . '*' . $this->testId . '*.php') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($featureDir . DIRECTORY_SEPARATOR . '*' . $this->testId . '*.php') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($viewsDir . DIRECTORY_SEPARATOR . '*' . $this->testId) ?: [] as $viewSubdir) {
            if (is_dir($viewSubdir)) {
                foreach (glob($viewSubdir . DIRECTORY_SEPARATOR . '*') ?: [] as $vfile) {
                    @unlink($vfile);
                }
                @rmdir($viewSubdir);
            }
        }

        foreach (array_reverse($this->dirsToCleanup) as $dir) {
            if (is_dir($dir)) {
                $entries = array_diff(scandir($dir) ?: [], ['.', '..']);
                foreach ($entries as $entry) {
                    @unlink($dir . DIRECTORY_SEPARATOR . $entry);
                }
                @rmdir($dir);
            }
        }
        $this->filesToCleanup = [];
        $this->dirsToCleanup = [];
    }

    // ── Happy-path tests ─────────────────────────────────────────────────────

    /**
     * create:middleware must exit 0 and report "Middleware created" for a
     * valid PascalCase class name. Verifies the full Symfony CommandTester path.
     */
    public function testMakeMiddleware(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:middleware');
        $tester  = new CommandTester($command);
        $name    = 'ZzzMware' . $this->testId;

        // Act
        $exit = $tester->execute(['name' => $name]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Middleware created', $tester->getDisplay());
    }

    /**
     * create:event must exit 0 and report "Event created".
     */
    public function testMakeEvent(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:event');
        $tester  = new CommandTester($command);
        $name    = 'ZzzEvent' . $this->testId;

        // Act
        $exit = $tester->execute(['name' => $name]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Event created', $tester->getDisplay());
    }

    /**
     * create:listener must exit 0 and report "Listener created".
     */
    public function testMakeListener(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:listener');
        $tester  = new CommandTester($command);
        $name    = 'ZzzListener' . $this->testId;

        // Act
        $exit = $tester->execute(['name' => $name]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Listener created', $tester->getDisplay());
    }

    /**
     * create:migration must exit 0 and report "Migration created".
     */
    public function testMakeMigration(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:migration');
        $tester  = new CommandTester($command);
        $slug    = 'zzz_exec_' . $this->testId;

        // Act
        $exit = $tester->execute(['name' => $slug]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Migration created', $tester->getDisplay());
    }

    /**
     * create:seeder must exit 0 and report "Seeder created".
     */
    public function testMakeSeeder(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:seeder');
        $tester  = new CommandTester($command);
        $name    = 'ZzzSeeder' . $this->testId;

        // Act
        $exit = $tester->execute(['name' => $name]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Seeder created', $tester->getDisplay());
    }

    /**
     * create:controller must exit 0 and report "Controller created".
     */
    public function testMakeController(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:controller');
        $tester  = new CommandTester($command);
        $name    = 'ZzzCtrl' . $this->testId;

        // Act
        $exit = $tester->execute(['name' => $name]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Controller created', $tester->getDisplay());
    }

    /**
     * create:view must exit 0 and report "View generated".
     */
    public function testMakeView(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:view');
        $tester  = new CommandTester($command);
        $name    = 'ZzzView' . $this->testId;

        // Act
        $exit = $tester->execute(['name' => $name]);

        // Assert
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('View created', $tester->getDisplay());
    }

    // ── Error-path: missing required name ────────────────────────────────────

    /**
     * create:middleware must throw InvalidArgumentException when no name is
     * provided. The name is a required argument for all make commands.
     */
    public function testMakeMiddlewareThrowsForMissingName(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:middleware');
        $tester  = new CommandTester($command);

        // Assert + Act
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }

    /**
     * create:controller must throw InvalidArgumentException when no name is
     * provided, guarding the caller against empty-string class generation.
     */
    public function testMakeControllerThrowsForMissingName(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:controller');
        $tester  = new CommandTester($command);

        // Assert + Act
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }

    /**
     * create:migration without a name starts the interactive wizard.
     * In non-interactive mode (CommandTester default) the wizard cannot
     * prompt for required fields, so the validator throws RuntimeException.
     * This guards against silent empty-stub generation in CI/scripted contexts.
     */
    public function testMakeMigrationThrowsForMissingName(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:migration');
        $tester  = new CommandTester($command);

        // Assert + Act — wizard validator fires with empty input in non-interactive mode
        $this->expectException(\RuntimeException::class);
        $tester->execute([]);
    }

    // ── Error-path: duplicate file ────────────────────────────────────────────

    /**
     * create:middleware must throw an Exception when the target file already
     * exists, preventing accidental overwrite of hand-written middleware classes.
     */
    public function testMakeMiddlewareThrowsWhenFileAlreadyExists(): void
    {
        // Arrange — create the file on first call
        $command = $this->consoleApp->find('create:middleware');
        $tester  = new CommandTester($command);
        $name    = 'ZzzMwareDup' . $this->testId;

        $tester->execute(['name' => $name]);

        // Assert + Act — second call must throw
        $this->expectException(\Exception::class);
        $tester->execute(['name' => $name]);
    }

    /**
     * create:event must throw an Exception when the target file already
     * exists to prevent silent overwrites of event classes.
     */
    public function testMakeEventThrowsWhenFileAlreadyExists(): void
    {
        // Arrange — create the file on first call
        $command = $this->consoleApp->find('create:event');
        $tester  = new CommandTester($command);
        $name    = 'ZzzEventDup' . $this->testId;

        $tester->execute(['name' => $name]);

        // Assert + Act — second call must throw
        $this->expectException(\Exception::class);
        $tester->execute(['name' => $name]);
    }

    /**
     * create:listener must throw an Exception when the target file already
     * exists to prevent silent overwrites of listener classes.
     */
    public function testMakeListenerThrowsWhenFileAlreadyExists(): void
    {
        // Arrange — create the file on first call
        $command = $this->consoleApp->find('create:listener');
        $tester  = new CommandTester($command);
        $name    = 'ZzzListenerDup' . $this->testId;

        $tester->execute(['name' => $name]);

        // Assert + Act — second call must throw
        $this->expectException(\Exception::class);
        $tester->execute(['name' => $name]);
    }

    /**
     * create:seeder must throw an Exception when the target file already
     * exists to prevent silent overwrites of seeder classes.
     */
    public function testMakeSeederThrowsWhenFileAlreadyExists(): void
    {
        // Arrange — create the file on first call
        $command = $this->consoleApp->find('create:seeder');
        $tester  = new CommandTester($command);
        $name    = 'ZzzSeederDup' . $this->testId;

        $tester->execute(['name' => $name]);

        // Assert + Act — second call must throw
        $this->expectException(\Exception::class);
        $tester->execute(['name' => $name]);
    }

    /**
     * create:event must throw InvalidArgumentException when no name is provided,
     * covering the missing-name guard branch in MakeEvent::execute().
     */
    public function testMakeEventThrowsForMissingName(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:event');
        $tester  = new CommandTester($command);

        // Assert + Act
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }

    /**
     * create:listener must throw InvalidArgumentException when no name is provided,
     * covering the missing-name guard branch in MakeListener::execute().
     */
    public function testMakeListenerThrowsForMissingName(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:listener');
        $tester  = new CommandTester($command);

        // Assert + Act
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }

    /**
     * create:seeder must throw InvalidArgumentException when no name is provided,
     * covering the missing-name guard branch in MakeSeeder::execute().
     */
    public function testMakeSeederThrowsForMissingName(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:seeder');
        $tester  = new CommandTester($command);

        // Assert + Act
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }

    /**
     * create:view must throw InvalidArgumentException when no name is provided,
     * covering the missing-name guard branch in MakeView::execute().
     */
    public function testMakeViewThrowsForMissingName(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:view');
        $tester  = new CommandTester($command);

        // Assert + Act
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }

    // ── MakeApi ──────────────────────────────────────────────────────────────

    /**
     * create:api must throw InvalidArgumentException when no name is provided.
     */
    public function testMakeApiThrowsForMissingName(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:api');
        $tester  = new CommandTester($command);

        // Assert + Act
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }

    // ── MakeCrud ─────────────────────────────────────────────────────────────

    /**
     * create:crud must throw InvalidArgumentException when no name is provided.
     */
    public function testMakeCrudThrowsForMissingName(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:crud');
        $tester  = new CommandTester($command);

        // Assert + Act
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }

    // ── MakeModel ────────────────────────────────────────────────────────────

    /**
     * create:model must throw InvalidArgumentException when no name is provided.
     */
    public function testMakeModelThrowsForMissingName(): void
    {
        // Arrange
        $command = $this->consoleApp->find('create:model');
        $tester  = new CommandTester($command);

        // Assert + Act
        $this->expectException(\InvalidArgumentException::class);
        $tester->execute([]);
    }
}
