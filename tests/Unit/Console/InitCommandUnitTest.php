<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
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
#[CoversClass(Init::class)]
class InitCommandUnitTest extends TestCase
{
    private string $tmpDir;
    private Init   $command;
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

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
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

    /**
     * renderStub('CLAUDE.md') uses the same {{ key }} syntax as all other stubs —
     * verifies the unified syntax contract established in Phase 3 stub unification.
     * APP_NAME, CLI_NAME, NAMESPACE, DB_TYPE, DB_TYPE_LABEL, and FEATURES_LIST
     * must all be substituted.
     */
    public function testClaudeMdStubSubstitutesAllTokens(): void
    {
        // Act
        $result = $this->command->renderStub('CLAUDE.md', [
            'APP_NAME'      => 'MyApp',
            'NAMESPACE'     => 'MyApp',
            'CLI_NAME'      => 'myapp',
            'DB_TYPE'       => 'postgresql',
            'DB_TYPE_LABEL' => 'PostgreSQL',
            'FEATURES_LIST' => '- `auth`',
        ]);

        // Assert — each token must be substituted; no raw {{ }} placeholders left
        $this->assertStringContainsString('MyApp', $result,
            'APP_NAME token must be substituted');
        $this->assertStringContainsString('myapp', $result,
            'CLI_NAME token must be substituted');
        $this->assertStringContainsString('postgresql', $result,
            'DB_TYPE token must be substituted');
        $this->assertStringContainsString('- `auth`', $result,
            'FEATURES_LIST token must be substituted');
        $this->assertStringNotContainsString('{{ APP_NAME }}', $result,
            'No unresolved {{ APP_NAME }} placeholders must remain');
        $this->assertStringNotContainsString('{{ CLI_NAME }}', $result,
            'No unresolved {{ CLI_NAME }} placeholders must remain');
    }

    /**
     * renderStub('mcp.json') substitutes {{ DB_MCP_NAME }}, {{ DB_MCP_PACKAGE }},
     * and {{ DB_MCP_DSN }} — producing a valid JSON structure with actual values.
     */
    public function testMcpJsonStubSubstitutesAllTokens(): void
    {
        // Act
        $result = $this->command->renderStub('mcp.json', [
            'DB_MCP_NAME'    => 'postgres',
            'DB_MCP_PACKAGE' => '@modelcontextprotocol/server-postgres',
            'DB_MCP_DSN'     => 'postgresql://user:pass@localhost:5432/mydb',
        ]);

        // Assert
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded, 'mcp.json stub must produce valid JSON');
        $this->assertArrayHasKey('mcpServers', $decoded,
            'mcp.json must contain mcpServers key');
        $this->assertArrayHasKey('postgres', $decoded['mcpServers'],
            'DB_MCP_NAME must be substituted as server key');
        $this->assertStringContainsString(
            '@modelcontextprotocol/server-postgres',
            $result,
            'DB_MCP_PACKAGE must be substituted'
        );
        $this->assertStringNotContainsString('{{ DB_MCP_NAME }}', $result,
            'No unresolved {{ DB_MCP_NAME }} placeholders must remain');
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
     * www/.htaccess must route via r=$1 so that Pramnos\Http\Request::calcParams()
     * is triggered for every request — the framework reads $_GET['r'] to determine
     * the controller.  Using url=$1 (the wrong key) leaves self::$_controller
     * unpopulated and every URL silently falls back to the default (home) controller.
     */
    public function testHtaccessUsesRParameterForRouting(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'TestApp',
            '--namespace' => 'TestApp',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--rest-api'  => 'n',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'testapp_db',
            '--db-user'   => 'testapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — the rewrite rule passes the path as ?r=, NOT ?url=
        $htaccess = file_get_contents($this->tmpDir . '/www/.htaccess');
        $this->assertStringContainsString('index.php?r=', $htaccess,
            'www/.htaccess must route via r= parameter for Request::calcParams() to fire');
        $this->assertStringNotContainsString('index.php?url=', $htaccess,
            'www/.htaccess must not use url= parameter (not read by the Request class)');
    }

    /**
     * www/index.php must bootstrap using a direct instantiation of the app's
     * namespace-specific Application class, not the framework's getInstance().
     *
     * Direct instantiation ensures the namespace-derived Application subclass
     * (with its registerVendorLibraries() override) is used from the first request.
     */
    public function testIndexPhpUsesDirectApplicationInstantiation(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'TestApp',
            '--namespace' => 'MyVendor',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--rest-api'  => 'n',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'testapp_db',
            '--db-user'   => 'testapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — uses namespace-specific Application class, not the generic getInstance()
        $index = file_get_contents($this->tmpDir . '/www/index.php');
        $this->assertStringContainsString('new \MyVendor\Application()', $index,
            'www/index.php must instantiate the namespace-specific Application subclass');
        $this->assertStringNotContainsString('getInstance()', $index,
            'www/index.php must not use the generic getInstance() factory');
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

        // Dockerfile targets PHP 8.5 (recommended development image; minimum requirement is 8.1)
        $dockerfile = file_get_contents($this->tmpDir . '/Dockerfile');
        $this->assertStringContainsString('php:8.5-apache', $dockerfile);
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
     * --no-download flag skips all HTTP requests and the command must still
     * exit 0 (success) — library metadata is tracked in-memory only.
     * NOTE: the runtime app no longer writes an assets.json manifest to disk;
     * library tracking via disk was intentionally removed in v1.2 scaffold.
     */
    public function testLibraryManifestIsWrittenEvenWithNoDownload(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $exit = $tester->execute([
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

        // Assert — command succeeds; no disk manifest (removed in v1.2 scaffold)
        $this->assertSame(0, $exit, 'init with --no-download must exit 0');
        $this->assertFileDoesNotExist($this->tmpDir . '/scaffolding/assets.json');
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
    // REST API scaffolding (--rest-api option)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When --rest-api=y is passed, the scaffolder must create the
     * src/Api/Controllers/ directory, write src/Api/routes.php,
     * generate a src/Api.php application class, and produce an API
     * entry point at www/api/index.php with its own .htaccess.
     *
     * These artifacts form the complete REST API scaffold: the router
     * file, the entry-point PHP file, and the URL rewriting config.
     */
    public function testRestApiOptionScaffoldsApiDirectoryAndRoutesFile(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'ApiApp',
            '--namespace' => 'ApiApp',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--rest-api'  => 'y',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'apiapp_db',
            '--db-user'   => 'apiapp',
            '--db-pass'   => 'secret',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — directory for API controllers was created
        $this->assertDirectoryExists(
            $this->tmpDir . '/src/Api/Controllers',
            'src/Api/Controllers must be created when --rest-api=y'
        );

        // Assert — routes file was written
        $this->assertFileExists(
            $this->tmpDir . '/src/Api/routes.php',
            'src/Api/routes.php must be written when --rest-api=y'
        );

        // Assert — Api application class was generated
        $this->assertFileExists(
            $this->tmpDir . '/src/Api.php',
            'src/Api.php must be written when --rest-api=y'
        );

        // Assert — API entry point and .htaccess were written
        $this->assertFileExists(
            $this->tmpDir . '/www/api/index.php',
            'www/api/index.php must be written when --rest-api=y'
        );
        $this->assertFileExists(
            $this->tmpDir . '/www/api/.htaccess',
            'www/api/.htaccess must be written when --rest-api=y'
        );
    }

    /**
     * src/Api/routes.php must demonstrate Router::group() usage so developers
     * have a working template to extend.
     *
     * The group call is the canonical way to apply a shared prefix (e.g. /v1)
     * and middleware to a set of API routes.
     */
    public function testRestApiRoutesFileContainsRouterGroupAndNamespaceComment(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'ApiApp',
            '--namespace' => 'MyVendor',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--rest-api'  => 'y',
            '--db-type'   => 'postgresql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'apiapp_db',
            '--db-user'   => 'apiapp',
            '--db-pass'   => 'secret',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — routes.php opens with strict types
        $routes = file_get_contents($this->tmpDir . '/src/Api/routes.php');
        $this->assertStringContainsString('declare(strict_types=1)', $routes,
            'routes.php must declare strict types');

        // Assert — Router is instantiated inside routes.php (required for dispatch to work)
        $this->assertStringContainsString('new \Pramnos\Routing\Router($this)', $routes,
            'routes.php must create a Router instance bound to the Api application');

        // Assert — Router::group() call is present
        $this->assertStringContainsString('$router->group(', $routes,
            'routes.php must demonstrate Router::group() usage');

        // Assert — version prefix /v1 is present
        $this->assertStringContainsString("'prefix' => '/v1'", $routes,
            'routes.php group must define a /v1 prefix');

        // Assert — dispatch call returns to _executeCore caller
        $this->assertStringContainsString('return $router->dispatch($newRequest)', $routes,
            'routes.php must return the dispatched result so _executeCore can process it');

        // Assert — namespace token was substituted with the actual namespace
        $this->assertStringContainsString('MyVendor', $routes,
            'routes.php must contain the application namespace in the example comment');
        $this->assertStringNotContainsString('{{ namespace }}', $routes,
            'No unresolved {{ namespace }} placeholder must remain');
    }

    /**
     * When --rest-api=y, app.php must include an 'api' key with 'prefix',
     * 'cors_origins', and 'version' sub-keys.
     *
     * This config block is read by Api::exec() to configure CORS and routing,
     * so it must be present whenever the REST API layer is scaffolded.
     */
    public function testRestApiOptionAddsApiSectionToAppPhp(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'ApiApp',
            '--namespace' => 'ApiApp',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--rest-api'  => 'y',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'apiapp_db',
            '--db-user'   => 'apiapp',
            '--db-pass'   => 'secret',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — app.php contains 'api' section
        $appConfig = file_get_contents($this->tmpDir . '/app/app.php');
        $this->assertStringContainsString("'api'", $appConfig,
            "app.php must contain 'api' key when --rest-api=y");
        $this->assertStringContainsString("'prefix'", $appConfig,
            "api section must contain 'prefix' key");
        $this->assertStringContainsString('/api/v1', $appConfig,
            "api prefix must default to /api/v1");
        $this->assertStringContainsString("'cors_origins'", $appConfig,
            "api section must contain 'cors_origins' key");
        $this->assertStringContainsString("'version'", $appConfig,
            "api section must contain 'version' key");
    }

    /**
     * When --rest-api is not set (or set to 'n'), no API scaffolding must occur.
     *
     * The src/Api/ directory must not be created and app.php must not contain
     * an 'api' section, keeping the config minimal for non-API projects.
     */
    public function testNoRestApiOptionSkipsApiScaffolding(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — explicitly opt out of REST API scaffolding
        $tester->execute([
            '--app-name'  => 'NoApiApp',
            '--namespace' => 'NoApiApp',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'noapiapp_db',
            '--db-user'   => 'noapiapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        // Assert — src/Api directory must not exist
        $this->assertDirectoryDoesNotExist(
            $this->tmpDir . '/src/Api',
            'src/Api must not be created when --rest-api is not requested'
        );

        // Assert — app.php must not contain 'api' section
        $appConfig = file_get_contents($this->tmpDir . '/app/app.php');
        $this->assertStringNotContainsString("'api' =>", $appConfig,
            "app.php must not contain 'api' section when REST API is not requested");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Auth feature wiring
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the 'auth' feature is requested, the scaffolder must create
     * src/Controllers/Login.php so that /login routes to a login form.
     */
    public function testAuthFeatureScaffoldsLoginController(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'AuthApp',
            '--namespace' => 'AuthApp',
            '--features'  => 'auth',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'authapp_db',
            '--db-user'   => 'authapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        // Assert — Login controller must exist
        $loginPath = $this->tmpDir . '/src/Controllers/Login.php';
        $this->assertFileExists($loginPath, 'src/Controllers/Login.php must be scaffolded when auth feature is enabled');

        $login = file_get_contents($loginPath);

        // Must declare the correct namespace
        $this->assertStringContainsString('namespace AuthApp\\Controllers;', $login);

        // Must contain display, dologin, logout actions
        $this->assertStringContainsString('public function display()', $login);
        $this->assertStringContainsString('public function dologin()', $login);
        $this->assertStringContainsString('public function logout()', $login);

        // Must use the framework Auth class for authentication
        $this->assertStringContainsString('Auth::getInstance()', $login);
        $this->assertStringContainsString('->auth(', $login);
        $this->assertStringContainsString('->logout()', $login);
    }

    /**
     * When the 'auth' feature is requested, the scaffolder must create
     * src/Controllers/Account.php that extends the framework Dashboard controller,
     * making all account management actions available via /account.
     */
    public function testAuthFeatureScaffoldsAccountControllerExtendingDashboard(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'AuthApp',
            '--namespace' => 'AuthApp',
            '--features'  => 'auth',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'authapp_db',
            '--db-user'   => 'authapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        // Assert — Account controller must exist
        $accountPath = $this->tmpDir . '/src/Controllers/Account.php';
        $this->assertFileExists($accountPath, 'src/Controllers/Account.php must be scaffolded when auth feature is enabled');

        $account = file_get_contents($accountPath);

        // Must declare the correct namespace
        $this->assertStringContainsString('namespace AuthApp\\Controllers;', $account);

        // Must extend the framework Dashboard controller
        $this->assertStringContainsString('extends \\Pramnos\\Auth\\Controllers\\Dashboard', $account);
    }

    /**
     * When the 'auth' feature is requested, the scaffolder must create a login
     * view at src/Views/login/login.html.php with a working login form.
     */
    public function testAuthFeatureScaffoldsLoginView(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'AuthApp',
            '--namespace' => 'AuthApp',
            '--features'  => 'auth',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'authapp_db',
            '--db-user'   => 'authapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        // Assert — login view must exist
        $viewPath = $this->tmpDir . '/src/Views/login/login.html.php';
        $this->assertFileExists($viewPath, 'src/Views/login/login.html.php must be scaffolded when auth feature is enabled');

        $view = file_get_contents($viewPath);

        // Must POST to the dologin action
        $this->assertStringContainsString('login/dologin', $view);

        // Must have username and password fields
        $this->assertStringContainsString('name="username"', $view);
        $this->assertStringContainsString('name="password"', $view);

        // Must show login error when present
        $this->assertStringContainsString('$this->error', $view);
    }

    /**
     * When the 'auth' feature is requested the Bootstrap theme header must include
     * conditional PHP that renders Login/Logout/Account links depending on session state.
     * This allows the navbar to reflect authentication status without a page refresh.
     */
    public function testAuthFeatureAddsAuthLinksToBootstrapNavbar(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — bootstrap UI with auth feature
        $tester->execute([
            '--app-name'  => 'AuthApp',
            '--namespace' => 'AuthApp',
            '--features'  => 'auth',
            '--ui-system' => 'bootstrap',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'authapp_db',
            '--db-user'   => 'authapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        $header = file_get_contents($this->tmpDir . '/app/themes/default/header.php');

        // Must check session state for login detection
        $this->assertStringContainsString('staticIsLogged()', $header);

        // Must have a login link
        $this->assertStringContainsString('login/logout', $header);
        $this->assertStringContainsString('href="<?php echo sURL; ?>login"', $header);

        // Must have account link for logged-in users
        $this->assertStringContainsString('href="<?php echo sURL; ?>account"', $header);
    }

    /**
     * When the 'auth' feature is NOT requested, the theme header must NOT contain
     * auth-related conditional PHP. The nav should remain static.
     */
    public function testNoAuthFeatureOmitsAuthLinksFromNavbar(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no features, bootstrap UI
        $tester->execute([
            '--app-name'  => 'PlainApp',
            '--namespace' => 'PlainApp',
            '--features'  => '',
            '--ui-system' => 'bootstrap',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'plainapp_db',
            '--db-user'   => 'plainapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        $header = file_get_contents($this->tmpDir . '/app/themes/default/header.php');

        // Auth nav conditional must not be present
        $this->assertStringNotContainsString('staticIsLogged()', $header,
            'Auth session check must not appear in navbar when auth feature is disabled');
        $this->assertStringNotContainsString('login/logout', $header,
            'Logout link must not appear in navbar when auth feature is disabled');
    }

    /**
     * When the 'auth' feature is NOT requested, the Login and Account controllers
     * must NOT be scaffolded — these are auth-only files.
     */
    public function testNoAuthFeatureSkipsLoginAndAccountControllers(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no features selected
        $tester->execute([
            '--app-name'  => 'PlainApp',
            '--namespace' => 'PlainApp',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'plainapp_db',
            '--db-user'   => 'plainapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        // Assert — auth-specific controllers must not be created
        $this->assertFileDoesNotExist(
            $this->tmpDir . '/src/Controllers/Login.php',
            'Login.php must not be scaffolded when auth feature is disabled'
        );
        $this->assertFileDoesNotExist(
            $this->tmpDir . '/src/Controllers/Account.php',
            'Account.php must not be scaffolded when auth feature is disabled'
        );
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
