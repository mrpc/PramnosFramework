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
    // RSA key generation (authserver feature)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the authserver feature is selected, pramnos init must generate an
     * RSA key pair at app/keys/private.key and app/keys/public.key.
     *
     * This verifies the same first-time-setup path as OAuth2ServerFactory::
     * generateKeyPair() but triggered at project scaffold time rather than
     * on first HTTP request.
     */
    public function testAuthserverFeatureGeneratesRsaKeyPair(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension required for RSA key generation');
        }

        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — enable authserver which triggers key generation
        $tester->execute([
            '--app-name'  => 'KeyTestApp',
            '--namespace' => 'KeyTestApp',
            '--features'  => 'auth,authserver',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'postgresql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'keytestapp_db',
            '--db-user'   => 'keytestapp',
            '--db-pass'   => 'secret',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — both key files exist
        $this->assertFileExists($this->tmpDir . '/app/keys/private.key',
            'private.key must be created during authserver init');
        $this->assertFileExists($this->tmpDir . '/app/keys/public.key',
            'public.key must be created during authserver init');

        // Assert — key files contain valid PEM blocks
        $private = file_get_contents($this->tmpDir . '/app/keys/private.key');
        $public  = file_get_contents($this->tmpDir . '/app/keys/public.key');

        $this->assertStringContainsString('-----BEGIN', $private,
            'private.key must be a PEM-encoded key');
        $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', $public,
            'public.key must be a SPKI PEM public key');

        // Assert — private key is loadable by openssl (validates the PEM)
        $parsed = openssl_pkey_get_private($private);
        $this->assertNotFalse($parsed, 'private.key must be parseable by openssl_pkey_get_private()');

        // Assert — the key is RSA 2048-bit
        $details = openssl_pkey_get_details($parsed);
        $this->assertSame(OPENSSL_KEYTYPE_RSA, $details['type'], 'Key type must be RSA');
        $this->assertSame(2048, $details['bits'], 'Key size must be 2048 bits');
    }

    /**
     * Key generation must be idempotent: if app/keys/ already contains valid
     * keys, init must not overwrite them.
     *
     * This matters for re-running init on an existing project — existing OAuth2
     * tokens signed with the original private key must remain valid.
     */
    public function testKeyGenerationIsIdempotentWhenKeysAlreadyExist(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension required for RSA key generation');
        }

        // Arrange — pre-create the keys directory with sentinel content
        $keysDir = $this->tmpDir . '/app/keys';
        mkdir($keysDir, 0700, true);
        file_put_contents($keysDir . '/private.key', 'SENTINEL_PRIVATE');
        file_put_contents($keysDir . '/public.key',  'SENTINEL_PUBLIC');

        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'IdempotentApp',
            '--namespace' => 'IdempotentApp',
            '--features'  => 'authserver',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'idempotentapp_db',
            '--db-user'   => 'idempotentapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — original sentinel content is preserved (keys were NOT regenerated)
        $this->assertSame('SENTINEL_PRIVATE', file_get_contents($keysDir . '/private.key'),
            'Existing private.key must not be overwritten');
        $this->assertSame('SENTINEL_PUBLIC',  file_get_contents($keysDir . '/public.key'),
            'Existing public.key must not be overwritten');
    }

    /**
     * Without the authserver feature, no key pair must be generated.
     *
     * Key generation has a side-effect (files on disk) and must only run when
     * the OAuth2 server is actually enabled; otherwise app/keys/ remains absent.
     */
    public function testNoKeyPairGeneratedWithoutAuthserverFeature(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — only 'auth', no 'authserver'
        $tester->execute([
            '--app-name'  => 'NoAuthserverApp',
            '--namespace' => 'NoAuthserverApp',
            '--features'  => 'auth',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'noauthserver_db',
            '--db-user'   => 'noauthserver',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — app/keys directory must NOT be created
        $this->assertDirectoryDoesNotExist($this->tmpDir . '/app/keys',
            'app/keys must not be created when authserver is not enabled');
    }

    /**
     * .gitignore must include app/keys/private.key when authserver is enabled.
     *
     * RSA private keys must never be committed to version control; the init
     * command is responsible for protecting them at scaffold time.
     */
    public function testGitignoreExcludesPrivateKeyWhenAuthserverEnabled(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension required for key generation path');
        }

        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'GitignoreApp',
            '--namespace' => 'GitignoreApp',
            '--features'  => 'authserver',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'postgresql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'gitignoreapp_db',
            '--db-user'   => 'gitignoreapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — .gitignore exists and contains the private key exclusion
        $gitignorePath = $this->tmpDir . '/.gitignore';
        $this->assertFileExists($gitignorePath, '.gitignore must be created');

        $contents = file_get_contents($gitignorePath);
        $this->assertStringContainsString('/app/keys/private.key', $contents,
            '.gitignore must exclude the RSA private key');
    }

    /**
     * .gitignore must NOT contain the private key exclusion when authserver is
     * not enabled — the extra entry would be misleading noise.
     */
    public function testGitignoreDoesNotExcludeKeyWhenAuthserverDisabled(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no authserver
        $tester->execute([
            '--app-name'  => 'NoKeyApp',
            '--namespace' => 'NoKeyApp',
            '--features'  => 'auth',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'nokeyapp_db',
            '--db-user'   => 'nokeyapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert
        $gitignorePath = $this->tmpDir . '/.gitignore';
        if (!file_exists($gitignorePath)) {
            $this->addToAssertionCount(1); // no .gitignore at all is also acceptable
            return;
        }
        $contents = file_get_contents($gitignorePath);
        $this->assertStringNotContainsString('app/keys/private.key', $contents,
            '.gitignore must not contain private key entry when authserver is not enabled');
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
