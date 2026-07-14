<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\LibraryManager;
use Pramnos\Console\Commands\LibrariesSync;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the `project:install` console command.
 *
 * The command tops up an existing project with front-end vendor libraries that
 * are missing, WITHOUT re-running `pramnos init`. Key invariants verified here:
 *
 *  - Mandatory libraries (Chart.js) are always installed, even for a project
 *    that never selected them.
 *  - A library already registered in src/Application.php is synced too.
 *  - Assets land under www/assets/vendor/<lib>/<version>/ (downloads are mocked
 *    by PRAMNOS_TESTING, so a stub file is written instead of hitting the CDN).
 *  - Missing registrations are inserted idempotently into
 *    Application::registerVendorLibraries(); a second run is a no-op.
 *  - --no-register downloads assets but leaves Application.php untouched.
 *  - --list reports status without mutating anything.
 *
 * Every test uses a throwaway temp project dir so no real files are touched.
 */
#[CoversClass(LibrariesSync::class)]
#[CoversClass(LibraryManager::class)]
class LibrariesSyncTest extends TestCase
{
    private string $projectDir;
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        // Symfony's completion command reads $_SERVER['PHP_SELF'] during configure().
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        // Arrange: a fresh fake project scaffolded WITHOUT chartjs, but WITH leaflet.
        $this->projectDir = sys_get_temp_dir() . '/pramnos_libsync_' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/www/assets/vendor', 0777, true);
        mkdir($this->projectDir . '/src', 0777, true);
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
                    $doc->registerScript('leaflet', sURL . 'assets/vendor/leaflet/1.9.4/leaflet.js', [], '1.9.4', true);
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

    /**
     * Build a CommandTester bound to the temp project dir.
     */
    private function tester(): CommandTester
    {
        $command = new LibrariesSync();
        $command->targetBaseDir = $this->projectDir;

        $app = new Application('test', '1.0');
        $app->add($command);
        $app->setAutoExit(false);

        return new CommandTester($app->find('project:install'));
    }

    private function appSource(): string
    {
        return (string) file_get_contents($this->projectDir . '/src/Application.php');
    }

    /**
     * A default run installs the mandatory Chart.js asset and registers it in
     * the application bootstrap — the exact top-up an old project needs after
     * Chart.js became mandatory.
     */
    public function testDefaultRunInstallsAndRegistersMandatoryChartjs(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $exit = $tester->execute([]);

        // Assert — command succeeded
        $this->assertSame(Command::SUCCESS, $exit);

        // The Chart.js asset was written under the versioned vendor path.
        $chartAsset = $this->projectDir . '/www/assets/vendor/chartjs/4.4.3/chart.umd.min.js';
        $this->assertFileExists($chartAsset, 'Chart.js asset must be installed locally');

        // The registration line was inserted, and the pre-existing leaflet line kept.
        $src = $this->appSource();
        $this->assertStringContainsString("registerScript('chartjs'", $src);
        $this->assertStringContainsString("registerScript('leaflet'", $src);
    }

    /**
     * A library already registered in Application.php is synced as well, so a
     * project whose assets were never downloaded (init --no-download) is healed.
     */
    public function testRegisteredLibraryIsAlsoInstalled(): void
    {
        // Act
        $this->tester()->execute([]);

        // Assert — leaflet (already registered) also got its asset fetched.
        $this->assertFileExists(
            $this->projectDir . '/www/assets/vendor/leaflet/1.9.4/leaflet.js',
            'A registered-but-missing library must be topped up'
        );
    }

    /**
     * Running twice must be a no-op: the second run reports everything present
     * and does not duplicate the registration line.
     */
    public function testSecondRunIsIdempotent(): void
    {
        // Arrange — first run installs + registers
        $this->tester()->execute([]);

        // Act — second run
        $tester = $this->tester();
        $tester->execute([]);
        $display = $tester->getDisplay();

        // Assert — nothing new installed, registration not duplicated
        $this->assertStringContainsString('already present', $display);
        $this->assertSame(
            1,
            substr_count($this->appSource(), "registerScript('chartjs'"),
            'Registration must not be duplicated on re-run'
        );
    }

    /**
     * --no-register downloads the asset but leaves Application.php untouched,
     * printing the snippet for manual insertion instead.
     */
    public function testNoRegisterSkipsApplicationEdit(): void
    {
        // Arrange
        $before = $this->appSource();

        // Act
        $tester = $this->tester();
        $tester->execute(['--no-register' => true]);

        // Assert — asset present, but source unchanged and a hint was printed
        $this->assertFileExists($this->projectDir . '/www/assets/vendor/chartjs/4.4.3/chart.umd.min.js');
        $this->assertSame($before, $this->appSource(), 'Application.php must not be edited with --no-register');
        $this->assertStringContainsString("registerScript('chartjs'", $tester->getDisplay());
    }

    /**
     * --list reports each catalog library's install/registration status and
     * flags the mandatory one, without writing any files.
     */
    public function testListReportsStatusWithoutMutating(): void
    {
        // Arrange
        $before = $this->appSource();

        // Act
        $tester = $this->tester();
        $tester->execute(['--list' => true]);
        $display = $tester->getDisplay();

        // Assert
        $this->assertStringContainsString('chartjs', $display);
        $this->assertStringContainsString('(mandatory)', $display);
        $this->assertStringContainsString('leaflet', $display);
        // No install happened and the bootstrap was not touched.
        $this->assertFileDoesNotExist($this->projectDir . '/www/assets/vendor/chartjs/4.4.3/chart.umd.min.js');
        $this->assertSame($before, $this->appSource());
    }

    /**
     * Explicit library arguments are honoured, and mandatory libraries are
     * always force-included even when omitted from that list.
     */
    public function testExplicitLibrariesListStillForcesMandatory(): void
    {
        // Act — ask only for select2 (positional arg); chartjs must still install
        $this->tester()->execute(['libraries' => ['select2']]);

        // Assert
        $this->assertFileExists($this->projectDir . '/www/assets/vendor/select2/4.1.0-rc.0/select2.min.js');
        $this->assertFileExists($this->projectDir . '/www/assets/vendor/chartjs/4.4.3/chart.umd.min.js');
    }

    /**
     * An unknown library key is reported as "not in catalog" and skipped, while
     * the mandatory set is still installed — a typo must not abort the top-up.
     */
    public function testUnknownLibraryIsReported(): void
    {
        // Act
        $tester = $this->tester();
        $tester->execute(['libraries' => ['doesnotexist']]);
        $display = $tester->getDisplay();

        // Assert — unknown surfaced, mandatory chartjs still installed.
        $this->assertStringContainsString('not in catalog', $display);
        $this->assertFileExists($this->projectDir . '/www/assets/vendor/chartjs/4.4.3/chart.umd.min.js');
    }

    /**
     * Multiple positional library arguments are all installed in one run (in
     * addition to the always-ensured mandatory library).
     */
    public function testMultiplePositionalArgumentsAreAllInstalled(): void
    {
        // Act — two libraries at once.
        $this->tester()->execute(['libraries' => ['select2', 'flatpickr']]);

        // Assert — both requested assets, plus mandatory chartjs, are present.
        $this->assertFileExists($this->projectDir . '/www/assets/vendor/select2/4.1.0-rc.0/select2.min.js');
        $this->assertFileExists($this->projectDir . '/www/assets/vendor/flatpickr/4.6.13/flatpickr.min.js');
        $this->assertFileExists($this->projectDir . '/www/assets/vendor/chartjs/4.4.3/chart.umd.min.js');
    }

    /**
     * When a library fails to install (here: a bundled library whose source
     * file is absent), the command reports it as failed and exits FAILURE. Uses
     * an injected manager backed by a crafted scaffolding dir so the failure is
     * deterministic (real downloads are mocked and never fail).
     */
    public function testFailedInstallReturnsFailure(): void
    {
        // Arrange — a scaffolding whose only library is bundled with NO source.
        $scaf = sys_get_temp_dir() . '/pramnos_libsync_scaf_' . bin2hex(random_bytes(4));
        mkdir($scaf, 0777, true);
        file_put_contents($scaf . '/assets.json', (string) json_encode([
            'libraries' => [
                'brokenlib' => [
                    'version'     => '1.0.0',
                    'bundled'     => true,
                    'source_path' => 'resources/missing',
                    'css'         => [],
                    'js'          => ['broken.js'],
                    'local_path'  => 'assets/vendor/brokenlib/1.0.0',
                ],
            ],
        ]));

        $command = new LibrariesSync();
        $command->targetBaseDir = $this->projectDir;
        $command->manager       = new LibraryManager($scaf);

        $app = new Application('test', '1.0');
        $app->add($command);
        $app->setAutoExit(false);
        $tester = new CommandTester($app->find('project:install'));

        // Act
        $exit = $tester->execute(['libraries' => ['brokenlib']]);

        // Assert — failure surfaced and reflected in the exit code.
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('failed', $tester->getDisplay());

        $this->removeDir($scaf);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
