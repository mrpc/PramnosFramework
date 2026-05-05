<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the Init command scaffolding logic.
 *
 * All tests run with skipDockerRun=true and point targetBaseDir at a
 * temporary directory so no real files outside /tmp are written. The
 * docker-compose / composer / migrate:framework shell commands are never
 * executed in this test context.
 */
class InitCommandUnitTest extends TestCase
{
    private string $tmpDir;
    private Init   $command;

    protected function setUp(): void
    {
        // Arrange — isolated temp workspace
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_init_test_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);

        $this->command = new Init();
        $this->command->targetBaseDir  = $this->tmpDir;
        $this->command->skipDockerRun  = true;
        $this->command->scaffoldingDir = dirname(__DIR__, 3) . '/scaffolding';
    }

    protected function tearDown(): void
    {
        // Cleanup — remove the temp directory tree
        $this->rmdir($this->tmpDir);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // renderStub()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * renderStub() loads the file from scaffolding/templates/ and substitutes
     * all {{ key }} tokens. Unmatched tokens are left untouched.
     */
    public function testRenderStubLoadsFileAndSubstitutesTokens(): void
    {
        // Act — namespace is the full qualified namespace passed verbatim by the stub
        $result = $this->command->renderStub('middleware', [
            'namespace' => 'App\\Middleware',
            'class'     => 'RateLimit',
        ]);

        // Assert — namespace token substituted (stub uses {{ namespace }} without suffix)
        $this->assertStringContainsString('namespace App\\Middleware;', $result);
        // class token substituted
        $this->assertStringContainsString('class RateLimit', $result);
        // implements the correct interface
        $this->assertStringContainsString('MiddlewareInterface', $result);
    }

    /**
     * renderStub() falls back to an embedded minimal skeleton when the stub
     * file is absent (scaffolding directory not present or wrong stub name).
     */
    public function testRenderStubFallsBackToEmbeddedSkeletonWhenFileAbsent(): void
    {
        // Arrange — point to a non-existent scaffolding dir
        $this->command->scaffoldingDir = '/does-not-exist';

        // Act
        $result = $this->command->renderStub('middleware', [
            'namespace' => 'App\\Middleware',
            'class'     => 'Auth',
        ]);

        // Assert — fallback still produces a valid PHP skeleton
        $this->assertStringContainsString('<?php', $result);
        $this->assertStringContainsString('class Auth', $result);
    }

    /**
     * renderStub() for 'migration' produces the transactional flag with
     * default false — enforced by the Phase 4 contract.
     */
    public function testMigrationStubContainsTransactionalFalse(): void
    {
        // Act
        $result = $this->command->renderStub('migration', [
            'namespace'   => 'App\\Migrations',
            'class'       => 'CreateUsersTable',
            'description' => 'Create users table',
            'date'        => date('Y-m-d'),
        ]);

        // Assert
        $this->assertStringContainsString('transactional = false', $result);
        $this->assertStringContainsString('class CreateUsersTable', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scaffolded file structure (non-interactive mode via options)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Running init with all options supplied creates the expected directory
     * and file structure without any interactive prompts.
     *
     * Tests the golden-path scaffold: plain-css UI, auth feature enabled,
     * no Docker, no libraries.
     */
    public function testInitScaffoldsProjectStructureInNonInteractiveMode(): void
    {
        // Arrange — create a minimal composer.json so updateComposerJson() can work
        file_put_contents($this->tmpDir . '/composer.json', json_encode([
            'name'    => 'pramnos/app-template',
            'require' => ['mrpc/pramnosframework' => '*'],
        ]));

        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'TestApp',
            '--namespace' => 'TestApp',
            '--features'  => 'auth',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'testapp_db',
            '--db-user'   => 'testapp',
            '--db-pass'   => 'secret',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — key directories exist
        $this->assertDirectoryExists($this->tmpDir . '/www');
        $this->assertDirectoryExists($this->tmpDir . '/src/Controllers');
        $this->assertDirectoryExists($this->tmpDir . '/app/config');
        $this->assertDirectoryExists($this->tmpDir . '/var/logs');
        $this->assertDirectoryExists($this->tmpDir . '/tests/Unit');

        // Assert — key files written
        $this->assertFileExists($this->tmpDir . '/app/config/settings.php');
        $this->assertFileExists($this->tmpDir . '/app/app.php');
        $this->assertFileExists($this->tmpDir . '/www/index.php');
        $this->assertFileExists($this->tmpDir . '/src/Controllers/Home.php');
        $this->assertFileExists($this->tmpDir . '/phpunit.xml');
    }

    /**
     * app.php includes the requested features list so FeatureRegistry can
     * parse it at boot time.
     */
    public function testAppPhpContainsSelectedFeatures(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'MyApp',
            '--namespace' => 'MyApp',
            '--features'  => 'auth,queue',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'myapp_db',
            '--db-user'   => 'myapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — features key is present in app.php
        $appConfig = file_get_contents($this->tmpDir . '/app/app.php');
        $this->assertStringContainsString("'auth'", $appConfig);
        $this->assertStringContainsString("'queue'", $appConfig);
    }

    /**
     * app.php writes an empty features array when no features are selected
     * (only core is always enabled, no extra opt-in).
     */
    public function testAppPhpWritesEmptyFeaturesArrayWhenNoneSelected(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'MyApp',
            '--namespace' => 'MyApp',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'myapp_db',
            '--db-user'   => 'myapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert
        $appConfig = file_get_contents($this->tmpDir . '/app/app.php');
        $this->assertStringContainsString("'features' => []", $appConfig);
    }

    /**
     * When --ui-system=bootstrap, the theme header.php references bootstrap
     * assets from the vendor directory, not a CDN.
     */
    public function testBootstrapThemeHeaderReferencesLocalVendorPath(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'MyApp',
            '--namespace' => 'MyApp',
            '--features'  => '',
            '--ui-system' => 'bootstrap',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'myapp_db',
            '--db-user'   => 'myapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — header references local vendor path, not CDN
        $header = file_get_contents($this->tmpDir . '/app/themes/default/header.php');
        $this->assertStringContainsString('assets/vendor/bootstrap', $header);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $header);
    }

    /**
     * settings.php maps timescaledb → type=postgresql with timescale=true.
     */
    public function testSettingsPhpMapsTimescaledbToPostgresql(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'MyApp',
            '--namespace' => 'MyApp',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'timescaledb',
            '--db-host'   => 'db',
            '--db-name'   => 'myapp_db',
            '--db-user'   => 'myapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert
        $settings = file_get_contents($this->tmpDir . '/app/config/settings.php');
        $this->assertStringContainsString("'type' => 'postgresql'", $settings);
        $this->assertStringContainsString("'timescale' => true", $settings);
    }

    /**
     * With --docker=y, docker-compose.yml, Dockerfile, dockerbash, and
     * dockertest are all written to the project root.
     */
    public function testDockerScaffoldingCreatesExpectedFiles(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'   => 'DockerApp',
            '--namespace'  => 'DockerApp',
            '--features'   => '',
            '--ui-system'  => 'plain-css',
            '--docker'     => 'y',
            '--docker-port'=> '8080',
            '--cache-system' => 'none',
            '--libraries'  => '',
            '--db-type'    => 'postgresql',
            '--db-host'    => 'db',
            '--db-name'    => 'dockerapp_db',
            '--db-user'    => 'dockerapp',
            '--db-pass'    => 'secret',
            '--db-prefix'  => '',
        ], ['interactive' => false]);

        // Assert
        $this->assertFileExists($this->tmpDir . '/docker-compose.yml');
        $this->assertFileExists($this->tmpDir . '/Dockerfile');
        $this->assertFileExists($this->tmpDir . '/dockerbash');
        $this->assertFileExists($this->tmpDir . '/dockertest');

        // docker-compose.yml must map correct port
        $compose = file_get_contents($this->tmpDir . '/docker-compose.yml');
        $this->assertStringContainsString('"8080:80"', $compose);

        // Dockerfile uses PHP 8.4
        $dockerfile = file_get_contents($this->tmpDir . '/Dockerfile');
        $this->assertStringContainsString('php:8.4-apache', $dockerfile);
    }

    /**
     * The dockertest script contains the migrate:framework hint in the next-steps
     * output and the step-6 command reference.
     */
    public function testDockerTestScriptContainsPhpunit(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'    => 'TestApp',
            '--namespace'   => 'TestApp',
            '--features'    => '',
            '--ui-system'   => 'plain-css',
            '--docker'      => 'y',
            '--docker-port' => '8080',
            '--cache-system'=> 'none',
            '--libraries'   => '',
            '--db-type'     => 'mysql',
            '--db-host'     => 'db',
            '--db-name'     => 'testapp_db',
            '--db-user'     => 'testapp',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);

        // Assert — dockertest invokes phpunit inside the container
        $dockertest = file_get_contents($this->tmpDir . '/dockertest');
        $this->assertStringContainsString('vendor/bin/phpunit', $dockertest);
        $this->assertStringContainsString('docker-compose exec', $dockertest);
    }

    /**
     * --no-download flag skips the actual HTTP download but still records
     * the library in the generated assets.json manifest.
     */
    public function testLibraryManifestIsWrittenEvenWithNoDownload(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'   => 'TestApp',
            '--namespace'  => 'TestApp',
            '--features'   => '',
            '--ui-system'  => 'plain-css',
            '--docker'     => 'n',
            '--libraries'  => 'jquery',
            '--no-download'=> true,
            '--db-type'    => 'mysql',
            '--db-host'    => 'localhost',
            '--db-name'    => 'testapp_db',
            '--db-user'    => 'testapp',
            '--db-pass'    => 'pass',
            '--db-prefix'  => '',
        ], ['interactive' => false]);

        // Assert — manifest written with jquery entry
        $manifestPath = $this->tmpDir . '/scaffolding/assets.json';
        $this->assertFileExists($manifestPath);
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $this->assertArrayHasKey('jquery', $manifest['libraries']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
