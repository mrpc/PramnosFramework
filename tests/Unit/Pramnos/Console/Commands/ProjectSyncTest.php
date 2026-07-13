<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Application\LibraryManager;
use Pramnos\Console\Commands\ProjectSync;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the `project:reconfigure` console command — the umbrella
 * reconfiguration command for existing projects.
 *
 * Invariants:
 *  - --status lists features and libraries without mutating the project.
 *  - --enable-feature records the feature in app/app.php (idempotently) and
 *    installs the libraries the feature declares (FeatureRegistry::getLibraries).
 *  - --add-library installs + registers a library, always ensuring the
 *    mandatory ones as well.
 *  - Unknown features/libraries are reported and do not corrupt the project.
 *
 * Downloads are mocked by PRAMNOS_TESTING; every test uses a throwaway temp dir.
 */
#[CoversClass(ProjectSync::class)]
#[CoversClass(FeatureRegistry::class)]
class ProjectSyncTest extends TestCase
{
    private string $projectDir;
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        // Arrange: a project with core-only features (no queue) and no libraries.
        $this->projectDir = sys_get_temp_dir() . '/pramnos_projsync_' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/www/assets/vendor', 0777, true);
        mkdir($this->projectDir . '/src', 0777, true);
        mkdir($this->projectDir . '/app', 0777, true);

        file_put_contents(
            $this->projectDir . '/app/app.php',
            "<?php\nreturn [\n    'name' => 'Test',\n    'namespace' => 'FakeApp',\n    'features' => ['auth'],\n];\n"
        );
        file_put_contents(
            $this->projectDir . '/src/Application.php',
            <<<'PHP'
            <?php
            namespace FakeApp;
            class Application extends \Pramnos\Application\Application
            {
                private function registerVendorLibraries(): void
                {
                    $doc = \Pramnos\Framework\Factory::getDocument();
                }
            }
            PHP
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        }
    }

    private function tester(): CommandTester
    {
        $command = new ProjectSync();
        $command->targetBaseDir = $this->projectDir;

        $app = new Application('test', '1.0');
        $app->add($command);
        // project:reconfigure delegates library installation to project:install,
        // so it must be registered on the same application for find() to resolve.
        $app->add(new \Pramnos\Console\Commands\LibrariesSync());
        $app->setAutoExit(false);

        return new CommandTester($app->find('project:reconfigure'));
    }

    private function appConfigSource(): string
    {
        return (string) file_get_contents($this->projectDir . '/app/app.php');
    }

    /**
     * --status reports feature and library state and mutates nothing.
     */
    public function testStatusReportsWithoutMutating(): void
    {
        // Arrange
        $before = $this->appConfigSource();

        // Act
        $tester = $this->tester();
        $tester->execute(['--status' => true], ['interactive' => false]);
        $display = $tester->getDisplay();

        // Assert
        $this->assertStringContainsString('Features:', $display);
        $this->assertStringContainsString('Libraries:', $display);
        $this->assertStringContainsString('queue', $display); // an available feature
        $this->assertSame($before, $this->appConfigSource(), 'status must not edit app.php');
    }

    /**
     * Enabling a feature adds it to app/app.php's features array, preserving the
     * already-enabled ones, and always installs the mandatory library.
     */
    public function testEnableFeatureRecordsInConfig(): void
    {
        // Act
        $exit = $this->tester()->execute(
            ['--enable-feature' => 'queue'],
            ['interactive' => false]
        );

        // Assert
        $this->assertSame(Command::SUCCESS, $exit);
        $src = $this->appConfigSource();
        $this->assertStringContainsString("'queue'", $src, 'queue must be added to features');
        $this->assertStringContainsString("'auth'", $src, 'existing feature must be preserved');
        // Mandatory chart.js is ensured regardless of the requested action.
        $this->assertFileExists($this->projectDir . '/www/assets/vendor/chartjs/4.4.3/chart.umd.min.js');
    }

    /**
     * Enabling an already-enabled feature is a no-op that does not duplicate the
     * entry in app/app.php.
     */
    public function testEnableAlreadyEnabledFeatureIsIdempotent(): void
    {
        // Act — 'auth' is already enabled in setUp()
        $tester = $this->tester();
        $tester->execute(['--enable-feature' => 'auth'], ['interactive' => false]);

        // Assert — still exactly one occurrence of 'auth'
        $this->assertSame(1, substr_count($this->appConfigSource(), "'auth'"));
        $this->assertStringContainsString('already enabled', $tester->getDisplay());
    }

    /**
     * --add-library installs and registers the requested library.
     */
    public function testAddLibraryInstallsAndRegisters(): void
    {
        // Act
        $this->tester()->execute(['--add-library' => 'leaflet'], ['interactive' => false]);

        // Assert
        $this->assertFileExists($this->projectDir . '/www/assets/vendor/leaflet/1.9.4/leaflet.js');
        $this->assertStringContainsString(
            "registerScript('leaflet'",
            (string) file_get_contents($this->projectDir . '/src/Application.php')
        );
    }

    /**
     * An unknown feature is reported as an error and does not get written to
     * app/app.php.
     */
    public function testUnknownFeatureIsRejected(): void
    {
        // Act
        $tester = $this->tester();
        $tester->execute(['--enable-feature' => 'nonexistentfeature'], ['interactive' => false]);

        // Assert
        $this->assertStringContainsString('unknown feature', $tester->getDisplay());
        $this->assertStringNotContainsString('nonexistentfeature', $this->appConfigSource());
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
