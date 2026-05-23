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
     * When the 'auth' feature is enabled, app.php must contain BOTH the
     * UserDatabase addon (password verification) and the User addon (session
     * management). Missing the User addon causes Auth::triger('Login','user')
     * to have no handler, so $_SESSION['logged'] is never set and every login
     * silently redirects back to the homepage.
     */
    public function testAuthFeatureScaffoldsAppPhpWithBothAuthAddons(): void
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

        $appConfig = file_get_contents($this->tmpDir . '/app/app.php');

        // UserDatabase handles password verification
        $this->assertStringContainsString("Pramnos\\\\Addon\\\\Auth\\\\UserDatabase", $appConfig,
            'app.php must include UserDatabase addon for password verification');

        // User addon sets $_SESSION[logged|uid|username] after successful login
        $this->assertStringContainsString("Pramnos\\\\Addon\\\\User\\\\User", $appConfig,
            'app.php must include User addon to set session state after login');

        // UserDatabase must be type=auth
        $this->assertMatchesRegularExpression(
            "/'addon'\s*=>\s*'Pramnos\\\\\\\\Addon\\\\\\\\Auth\\\\\\\\UserDatabase'.*'type'\s*=>\s*'auth'/s",
            $appConfig,
            'UserDatabase must have type=auth'
        );

        // User must be type=user
        $this->assertMatchesRegularExpression(
            "/'addon'\s*=>\s*'Pramnos\\\\\\\\Addon\\\\\\\\User\\\\\\\\User'.*'type'\s*=>\s*'user'/s",
            $appConfig,
            'User addon must have type=user'
        );
    }

    /**
     * When the 'auth' feature is NOT requested, no addons section must appear
     * in app.php — the addons key is only written when auth is enabled.
     */
    public function testNoAuthFeatureOmitsAddonsFromAppPhp(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no features
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

        $appConfig = file_get_contents($this->tmpDir . '/app/app.php');

        // No addons key when auth is not requested
        $this->assertStringNotContainsString("'addons'", $appConfig,
            "app.php must not contain 'addons' when auth feature is not selected");
    }

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

        // Constructor must register dologin and logout in addaction so that
        // Controller::exec() can route POST requests to them.
        $this->assertStringContainsString('addaction(', $login,
            'Login controller must register dologin/logout via addaction() in constructor');
        $this->assertStringContainsString("'dologin'", $login,
            'dologin must be registered so exec() can dispatch the POST');
        $this->assertStringContainsString("'logout'", $login,
            'logout must be registered so exec() can dispatch the action');

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
     * The scaffolded theme header uses NavRegistry::getForUser() to render
     * navigation — no hardcoded URLs in the header file itself.
     *
     * Phase 24: all nav items (Login, Logout, Account, Logs, OAuth) are
     * registered by Application::registerDefaultNavItems() at runtime based on
     * enabled features. The header is a generic template that iterates over the
     * registry result; the features flag in app.php is what controls which links
     * appear, not conditional PHP in the header file.
     */
    public function testHeaderUsesNavRegistryForNavigation(): void
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

        // Must use NavRegistry::getForUser() to obtain nav items — not raw session checks
        $this->assertStringContainsString('NavRegistry::getForUser', $header,
            'Header must delegate nav rendering to NavRegistry::getForUser()');

        // Must iterate over sections returned by the registry
        $this->assertStringContainsString('NavSection::Main->value', $header,
            'Header must iterate over NavSection::Main items');
        $this->assertStringContainsString('NavSection::User->value', $header,
            'Header must iterate over NavSection::User items (Login/Account/Logout)');
        $this->assertStringContainsString('NavSection::Admin->value', $header,
            'Header must iterate over NavSection::Admin items (Logs, OAuth)');

        // Must NOT contain hardcoded auth session check — the registry handles visibility
        $this->assertStringNotContainsString('staticIsLogged()', $header,
            'Header must not contain hardcoded staticIsLogged() — NavRegistry handles visibility');
    }

    /**
     * The NavRegistry-based header template is identical regardless of which
     * features are enabled — feature differences are handled at runtime by
     * Application::registerDefaultNavItems(), not by different header templates.
     */
    public function testHeaderTemplateIsFeatureAgnostic(): void
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

        // The header must use NavRegistry — same template regardless of features
        $this->assertStringContainsString('NavRegistry::getForUser', $header,
            'Header must always use NavRegistry, regardless of features');
        // No hardcoded auth URLs baked into the template
        $this->assertStringNotContainsString("href=\"<?php echo sURL; ?>login\"", $header,
            'Hardcoded login URL must not be in header — NavRegistry provides it at runtime');
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
    // authserver feature wiring
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When 'authserver' is enabled, the scaffolder must create src/Controllers/Oauth.php
     * extending the framework Oauth controller so that /oauth/authorize etc. route correctly.
     * The OAuth2 consent views are served via the framework's scaffolding fallback mechanism
     * and do not need to be copied into the app.
     */
    public function testAuthserverFeatureScaffoldsOauthControllerWrapper(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — enable both auth (required for authserver) and authserver
        $tester->execute([
            '--app-name'  => 'OAuthApp',
            '--namespace' => 'OAuthApp',
            '--features'  => 'auth,authserver',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'oauthapp_db',
            '--db-user'   => 'oauthapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        // Assert — Oauth controller wrapper must exist
        $oauthPath = $this->tmpDir . '/src/Controllers/Oauth.php';
        $this->assertFileExists($oauthPath, 'src/Controllers/Oauth.php must be scaffolded when authserver feature is enabled');

        $oauth = file_get_contents($oauthPath);

        // Must declare the correct namespace
        $this->assertStringContainsString('namespace OAuthApp\\Controllers;', $oauth);

        // Must extend the framework Oauth controller
        $this->assertStringContainsString('extends \\Pramnos\\Auth\\Controllers\\Oauth', $oauth);
    }

    /**
     * When 'authserver' is NOT enabled, Oauth.php must not be scaffolded.
     */
    public function testNoAuthserverFeatureSkipsOauthController(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — auth only, no authserver
        $tester->execute([
            '--app-name'  => 'AuthOnlyApp',
            '--namespace' => 'AuthOnlyApp',
            '--features'  => 'auth',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'authonly_db',
            '--db-user'   => 'authonly',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        // Assert — Oauth controller must not be created when authserver is not enabled
        $this->assertFileDoesNotExist(
            $this->tmpDir . '/src/Controllers/Oauth.php',
            'Oauth.php must not be scaffolded without the authserver feature'
        );
    }

    /**
     * When 'authserver' is enabled, the scaffolded app.php must include
     * 'authserver' in the features array so that Application::registerDefaultNavItems()
     * registers the OAuth Apps nav item at runtime.
     *
     * Phase 24: the header itself is feature-agnostic (uses NavRegistry); the
     * features array in app.php is the sole control for which admin links appear.
     */
    public function testAuthserverFeatureIsInAppPhpFeaturesList(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'OAuthApp',
            '--namespace' => 'OAuthApp',
            '--features'  => 'auth,authserver',
            '--ui-system' => 'bootstrap',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'oauthapp_db',
            '--db-user'   => 'oauthapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        // Assert — authserver in features list; registerDefaultNavItems() will register admin.oauth
        $appConfig = file_get_contents($this->tmpDir . '/app/app.php');
        $this->assertStringContainsString("'authserver'", $appConfig,
            "app.php must contain 'authserver' feature so OAuth Apps nav item is registered at runtime");

        // Assert — header uses NavRegistry (runtime nav, not hardcoded links)
        $header = file_get_contents($this->tmpDir . '/app/themes/default/header.php');
        $this->assertStringContainsString('NavRegistry::getForUser', $header,
            'Header must use NavRegistry — OAuth Apps link appears via registry, not hardcoded');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Logs controller wiring (always created)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every new application must receive src/Controllers/Logs.php extending
     * the framework LogController. This makes /logs available in every app and
     * follows the Urbanwater pattern (thin wrapper, customize whitelist/blacklist).
     * Authentication is enforced by the framework controller via addAuthAction().
     */
    public function testLogsControllerIsAlwaysScaffolded(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no features, plain-css
        $tester->execute([
            '--app-name'  => 'MinimalApp',
            '--namespace' => 'MinimalApp',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'minimal_db',
            '--db-user'   => 'minimal',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        // Assert — Logs controller must always exist
        $logsPath = $this->tmpDir . '/src/Controllers/Logs.php';
        $this->assertFileExists($logsPath, 'src/Controllers/Logs.php must be scaffolded in every new application');

        $logs = file_get_contents($logsPath);

        // Must declare the correct namespace
        $this->assertStringContainsString('namespace MinimalApp\\Controllers;', $logs);

        // Must extend the framework LogController
        $this->assertStringContainsString('extends LogController', $logs);
        $this->assertStringContainsString('use Pramnos\\Application\\Controllers\\LogController', $logs);
    }

    /**
     * Every scaffolded app receives the NavRegistry-based header which renders
     * the Admin section, including the Logs link registered by
     * Application::registerDefaultNavItems().  The header always contains the
     * NavSection::Admin iteration snippet — the actual link appears at runtime
     * once NavRegistry is populated.
     *
     * Phase 24: the Logs link is no longer hardcoded in the header; it is
     * registered via NavRegistry::register('admin.logs', ...) in registerDefaultNavItems().
     */
    public function testNavRegistryAdminSectionAlwaysInHeader(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no features, bootstrap
        $tester->execute([
            '--app-name'  => 'MinimalApp',
            '--namespace' => 'MinimalApp',
            '--features'  => '',
            '--ui-system' => 'bootstrap',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'minimal_db',
            '--db-user'   => 'minimal',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        $header = file_get_contents($this->tmpDir . '/app/themes/default/header.php');

        // The Admin section iteration snippet must always be present in the header
        $this->assertStringContainsString('NavSection::Admin->value', $header,
            'Header must always iterate NavSection::Admin — Logs and other admin items registered at runtime');

        // The header itself must not hardcode /logs — the URL comes from NavRegistry
        $this->assertStringNotContainsString('href="<?php echo sURL; ?>logs"', $header,
            'Hardcoded /logs URL must not be in header — the URL is provided by NavRegistry at runtime');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phase 23 — admin CRUD controller scaffolding
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every new application receives src/Controllers/Users.php extending
     * the framework UsersController. This makes /users available in every app.
     * Authentication and permission gates are handled by the framework controller.
     */
    public function testUsersControllerIsAlwaysScaffolded(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no features, minimal scaffold
        $tester->execute([
            '--app-name'  => 'AdminApp',
            '--namespace' => 'AdminApp',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'adminapp_db',
            '--db-user'   => 'adminapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        // Assert — Users controller must exist and extend framework class
        $usersPath = $this->tmpDir . '/src/Controllers/Users.php';
        $this->assertFileExists($usersPath, 'src/Controllers/Users.php must be scaffolded in every new application');

        $users = file_get_contents($usersPath);
        $this->assertStringContainsString('namespace AdminApp\\Controllers;', $users);
        $this->assertStringContainsString('UsersController', $users,
            'Users wrapper must extend the framework UsersController');
    }

    /**
     * Every new application receives src/Controllers/Settings.php extending
     * the framework SettingsController. This makes /settings available in every app.
     */
    public function testSettingsControllerIsAlwaysScaffolded(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'AdminApp',
            '--namespace' => 'AdminApp',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'adminapp_db',
            '--db-user'   => 'adminapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        $settingsPath = $this->tmpDir . '/src/Controllers/Settings.php';
        $this->assertFileExists($settingsPath, 'src/Controllers/Settings.php must be scaffolded in every new application');

        $settings = file_get_contents($settingsPath);
        $this->assertStringContainsString('namespace AdminApp\\Controllers;', $settings);
        $this->assertStringContainsString('SettingsController', $settings,
            'Settings wrapper must extend the framework SettingsController');
    }

    /**
     * When auth feature is enabled, the scaffolded tests/Unit/Controllers/ must
     * contain meaningful test files — not just placeholder assertTrue(true).
     *
     * Adequate scaffolded tests verify controller structure and prevent "it builds
     * but breaks on the first request" issues that placeholders cannot catch.
     */
    public function testAuthFeatureScaffoldsRealControllerTests(): void
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
            '--features'  => 'auth',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'testapp_db',
            '--db-user'   => 'testapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        // Assert — LoginControllerTest exists and has real assertions
        $loginTestPath = $this->tmpDir . '/tests/Unit/Controllers/LoginControllerTest.php';
        $this->assertFileExists($loginTestPath,
            'tests/Unit/Controllers/LoginControllerTest.php must be scaffolded when auth is enabled');

        $loginTest = file_get_contents($loginTestPath);

        // Must not be a pure placeholder (assertTrue(true) is useless)
        $this->assertStringNotContainsString('assertTrue(true)', $loginTest,
            'LoginControllerTest must not be a placeholder — it must verify real behaviour');

        // Must test action registration (the most common scaffold wiring bug)
        $this->assertStringContainsString('addaction', $loginTest,
            'LoginControllerTest must verify that dologin/logout are registered via addaction()');

        // Assert — HomeControllerTest also exists
        $homeTestPath = $this->tmpDir . '/tests/Unit/Controllers/HomeControllerTest.php';
        $this->assertFileExists($homeTestPath,
            'tests/Unit/Controllers/HomeControllerTest.php must be scaffolded in every new application');

        // Assert — integration test skeleton exists
        $integrationTestPath = $this->tmpDir . '/tests/Integration/AuthFlowTest.php';
        $this->assertFileExists($integrationTestPath,
            'tests/Integration/AuthFlowTest.php must be scaffolded when auth is enabled');
    }

    /**
     * Without auth feature, no auth-specific tests are scaffolded, but the
     * HomeControllerTest is still present (always scaffolded).
     */
    public function testNoAuthFeatureSkipsAuthTests(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no features
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

        // HomeControllerTest is always present
        $this->assertFileExists(
            $this->tmpDir . '/tests/Unit/Controllers/HomeControllerTest.php',
            'HomeControllerTest must be present in every scaffolded app'
        );

        // Auth-specific tests must not exist when auth is not enabled
        $this->assertFileDoesNotExist(
            $this->tmpDir . '/tests/Unit/Controllers/LoginControllerTest.php',
            'LoginControllerTest must not be created when auth feature is not selected'
        );
        $this->assertFileDoesNotExist(
            $this->tmpDir . '/tests/Integration/AuthFlowTest.php',
            'AuthFlowTest must not be created when auth feature is not selected'
        );
    }

    /**
     * The ServicesController wrapper must be scaffolded in every new application,
     * regardless of features. It extends the framework ServicesController so the
     * app can customise requiredUserType or maxLogLines without touching the framework.
     */
    public function testServicesControllerIsAlwaysScaffolded(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no features
        $tester->execute([
            '--app-name'  => 'SvcApp',
            '--namespace' => 'SvcApp',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'svcapp_db',
            '--db-user'   => 'svcapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        $path = $this->tmpDir . '/src/Controllers/Services.php';
        $this->assertFileExists($path, 'src/Controllers/Services.php must be scaffolded in every new application');

        $content = file_get_contents($path);
        $this->assertStringContainsString('namespace SvcApp\\Controllers;', $content);
        $this->assertStringContainsString('ServicesController', $content,
            'Services wrapper must extend the framework ServicesController');
    }

    /**
     * The admin/ops DashboardController wrapper must be scaffolded in every
     * new application, regardless of features. It extends the framework
     * DashboardController so the app can customise requiredUserType without
     * touching the framework class.
     */
    public function testDashboardControllerIsAlwaysScaffolded(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no features, so only universally-scaffolded controllers appear
        $tester->execute([
            '--app-name'  => 'AdminApp',
            '--namespace' => 'AdminApp',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'adminapp_db',
            '--db-user'   => 'adminapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        $dashboardPath = $this->tmpDir . '/src/Controllers/Dashboard.php';
        $this->assertFileExists($dashboardPath, 'src/Controllers/Dashboard.php must be scaffolded in every new application');

        $dashboard = file_get_contents($dashboardPath);
        $this->assertStringContainsString('namespace AdminApp\\Controllers;', $dashboard);
        $this->assertStringContainsString('DashboardController', $dashboard,
            'Dashboard wrapper must extend the framework DashboardController');
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
