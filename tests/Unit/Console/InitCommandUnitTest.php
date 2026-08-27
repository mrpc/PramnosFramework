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
     * The scaffolded CLAUDE.md points at the framework's own guides, in vendor.
     *
     * The docs ship inside the composer package, so every project already holds
     * documentation that matches its installed framework version — but an
     * assistant that is not told where it is will not go looking, and will
     * reimplement what the framework already provides. That has happened: the
     * SPA debug panel was rebuilt beside the working one. The pointer, the
     * `use_cases:` selection mechanism, the guides-versus-history distinction,
     * and the "do not reimplement" instruction are therefore all asserted.
     */
    public function testClaudeMdPointsAtTheFrameworkGuidesInVendor(): void
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

        // Assert
        $this->assertStringContainsString('vendor/mrpc/pramnosframework/docs/', $result,
            'the assistant must be told where the framework guides are');
        $this->assertStringContainsString('use_cases:', $result,
            'and how to pick a guide without reading all of them');
        // Without this, "how does X work" gets answered from a dated post
        // describing a delta rather than from the guide describing the feature.
        $this->assertStringContainsString('version-history/posts/', $result,
            'the guides/history distinction must be stated');
        $this->assertStringContainsString('not to be reimplemented', $result,
            'the conclusion the pointer exists to produce');
    }

    /**
     * renderStub('mcp.json') substitutes every token into valid JSON.
     *
     * The command and its arguments are tokens now rather than literals, because the
     * right ones depend on the project: see the two tests below for what each shape
     * has to be. This one only checks that nothing is left unresolved and the result
     * still parses — a broken `.mcp.json` is not a syntax error anywhere, it is an
     * MCP server that silently never appears.
     */
    public function testMcpJsonStubSubstitutesAllTokens(): void
    {
        // Act
        $result = $this->command->renderStub('mcp.json', [
            'APP_SLUG'    => 'myapp',
            'MCP_COMMAND' => 'php',
            'MCP_ARGS'    => json_encode(['myapp.php', 'mcp:serve']),
        ]);

        // Assert
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded, 'mcp.json stub must produce valid JSON');
        $this->assertArrayHasKey('myapp', $decoded['mcpServers'],
            'APP_SLUG must be substituted as the server name key');
        $this->assertSame('php', $decoded['mcpServers']['myapp']['command']);
        $this->assertSame(['myapp.php', 'mcp:serve'], $decoded['mcpServers']['myapp']['args']);
        $this->assertStringNotContainsString('{{', $result,
            'No unresolved placeholders must remain');
    }

    /**
     * A non-Docker project's `.mcp.json` names a file that is actually there.
     *
     * The assertion that matters is `assertFileExists`, and it is the one that was
     * missing. The stub hardcoded `php ./bin/pramnos mcp:serve` — a path that exists
     * in the framework's own repository and nowhere in a scaffolded project, where the
     * CLI is `<cliName>.php` at the root and `bin/pramnos` lives under `vendor/`. So
     * every scaffolded project shipped an MCP server that could not start, and the
     * old test passed the whole time because it checked the stub against the literal
     * it was written from, never against a project.
     */
    public function testMcpJsonPointsAtTheProjectsOwnCli(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no Docker, so the CLI runs on the host.
        $tester->execute([
            '--app-name'    => 'McpApp',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'McpApp',
            '--features'    => '',
            '--ui-system'   => 'plain-css',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'postgresql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'mcp_db',
            '--db-user'     => 'mcp',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);

        // Assert
        $config = json_decode((string) file_get_contents($this->tmpDir . '/.mcp.json'), true);
        $server = reset($config['mcpServers']);
        $this->assertSame('php', $server['command']);
        $this->assertSame('mcp:serve', end($server['args']));

        // The whole point: the script it names is in the project it was written for.
        $this->assertFileExists($this->tmpDir . '/' . $server['args'][0],
            '.mcp.json must name a CLI entry point that the scaffolded project has');
    }

    /**
     * A Docker project's `.mcp.json` runs the CLI inside the container, with `-T`.
     *
     * Two things, both load-bearing. The database is only reachable from inside the
     * container, and `mcp:serve` is a database tool above all — one running on the
     * host would answer every query with a connection error. And MCP speaks stdio
     * over the pipe, so `docker-compose exec` without `-T` allocates a TTY and the
     * protocol never gets a clean stream; that is also why the scaffolded `./<cli>`
     * wrapper is not reused, since it deliberately keeps its TTY for interactive
     * prompts.
     */
    public function testMcpJsonRunsInsideTheContainerForADockerProject(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $this->command->skipDockerRun = true;   // scaffold the files, do not start Docker
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'    => 'McpDockerApp',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'McpDockerApp',
            '--features'    => '',
            '--ui-system'   => 'plain-css',
            '--docker'      => 'y',
            '--libraries'   => '',
            '--db-type'     => 'postgresql',
            '--db-host'     => 'db',
            '--db-name'     => 'mcp_db',
            '--db-user'     => 'mcp',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);

        // Assert
        $config = json_decode((string) file_get_contents($this->tmpDir . '/.mcp.json'), true);
        $server = reset($config['mcpServers']);
        $this->assertSame('docker-compose', $server['command']);
        $this->assertSame('exec', $server['args'][0]);
        $this->assertContains('-T', $server['args'],
            'stdio over docker-compose exec needs -T or the TTY breaks the protocol');
        $this->assertSame('mcp:serve', end($server['args']));

        // The script named after `php` is still one the project has.
        $index = array_search('php', $server['args'], true);
        $this->assertFileExists($this->tmpDir . '/' . $server['args'][$index + 1]);
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
     * With auth + authserver features and the REST API enabled, the scaffold
     * emits thin API-controller wrappers over the framework Auth controllers and
     * wires their routes. Verifies the files exist, are valid PHP, extend the
     * right base classes, and that routes.php references them.
     */
    public function testScaffoldsDefaultApiControllersForAuthFeatures(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'TestApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace' => 'TestApp',
            '--features'  => 'auth,authserver',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--rest-api'  => 'y',
            '--api-docs'  => 'y',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'testapp_db',
            '--db-user'   => 'testapp',
            '--db-pass'   => 'secret',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — wrapper files exist and are valid PHP
        $ctrlDir = $this->tmpDir . '/src/Api/Controllers';
        foreach (['Session', 'Me', 'Account', 'Capabilities'] as $class) {
            $this->assertFileExists("$ctrlDir/$class.php");
            $lint = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg("$ctrlDir/$class.php") . ' 2>&1');
            $this->assertMatchesRegularExpression('/No syntax errors/', (string) $lint,
                "$class.php must be valid PHP");
        }

        // Assert — each extends the correct framework Auth controller
        $this->assertStringContainsString('class Session extends \Pramnos\Auth\Controllers\Session',
            file_get_contents("$ctrlDir/Session.php"));
        $this->assertStringContainsString('class Me extends \Pramnos\Auth\Controllers\Me',
            file_get_contents("$ctrlDir/Me.php"),
            'Me delegates to the framework Me controller (profile + personal tokens)');
        $this->assertStringContainsString('class Account extends \Pramnos\Auth\Controllers\ApiAccount',
            file_get_contents("$ctrlDir/Account.php"),
            'the API Account wrapper delegates to the JSON ApiAccount controller, not the web Account');
        $this->assertStringContainsString('class Capabilities extends \Pramnos\Auth\Controllers\Capabilities',
            file_get_contents("$ctrlDir/Capabilities.php"));

        // Assert — routes wired and the whole routes.php is valid PHP
        $routes = file_get_contents($this->tmpDir . '/src/Api/routes.php');
        $this->assertStringContainsString("\$r->get('/me'", $routes);
        $this->assertStringContainsString("\$r->get('/me/tokens'", $routes);
        $this->assertStringContainsString("\$r->delete('/me/tokens/{tokenid}'", $routes);
        $this->assertStringContainsString("\$r->get('/session/info'", $routes);
        $this->assertStringContainsString("\$r->post('/capabilities/sync'", $routes);
        $this->assertStringContainsString('TestApp\Api\Controllers\Me', $routes,
            'routes target the app Api\\Controllers namespace explicitly');
        $lint = shell_exec(PHP_BINARY . ' -l ' . escapeshellarg($this->tmpDir . '/src/Api/routes.php') . ' 2>&1');
        $this->assertMatchesRegularExpression('/No syntax errors/', (string) $lint, 'routes.php must be valid PHP');

        // Assert — the inherited endpoints are documented via openapi-overrides.json
        // (apidoc can't see them, so their OpenAPI paths are injected here).
        $overrides = json_decode(file_get_contents($this->tmpDir . '/src/Api/openapi-overrides.json'), true);
        $this->assertArrayHasKey('/me', $overrides['paths']);
        $this->assertArrayHasKey('/session/info', $overrides['paths']);
        $this->assertArrayHasKey('/account/login', $overrides['paths']);
        $this->assertArrayHasKey('/capabilities/sync', $overrides['paths']);
        // OAuth endpoints ARE documented, but with a path-level server override to
        // the site ROOT (they live on the web front controller, not the API base).
        $this->assertArrayHasKey('/oauth/token', $overrides['paths']);
        $this->assertArrayHasKey('servers', $overrides['paths']['/oauth/token'],
            'OAuth paths carry a server override to the site root');
        $this->assertStringNotContainsString('/1.0', $overrides['paths']['/oauth/token']['servers'][0]['url'],
            'the OAuth server is the site root, not the versioned API base');
        $this->assertArrayHasKey('OAuth2', $overrides['components']['securitySchemes'],
            'authserver adds the oauth2 security scheme');

        // The docs support contact must be the author email captured at init, not
        // the generic support@example.com placeholder. Non-interactive mode falls
        // back to the Author Email question's default (developer@pramnos.net).
        $this->assertSame('developer@pramnos.net', $overrides['info']['contact']['email'],
            'the support contact email is the author email, not a generic placeholder');

        // Endpoints use per-resource groups + operationIds (apidoc house style),
        // not descriptions.
        $this->assertSame(['Me'], $overrides['paths']['/me']['get']['tags']);
        $this->assertSame('getMe', $overrides['paths']['/me']['get']['operationId']);
        $this->assertSame(['Account'], $overrides['paths']['/account/login']['post']['tags']);
        $this->assertSame('login', $overrides['paths']['/account/login']['post']['operationId']);
        $this->assertSame(['Session'], $overrides['paths']['/session/info']['get']['tags']);

        // Without Docker there is no local server override, but the OAuth server
        // still yields a pre-filled dev API key for the docs.
        $apidoc = json_decode(file_get_contents($this->tmpDir . '/src/Api/apidoc.json'), true);
        $this->assertSame('', $apidoc['localServer']);
        $this->assertSame('localtestkey', $apidoc['defaultApiKey'],
            'authserver pre-fills the stable dev API key into the docs');
    }

    /**
     * With Docker enabled, apidoc.json records the local Docker environment as the
     * localServer (which the generator makes the default server in the docs).
     */
    public function testApiDocsRecordsDockerLocalServer(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — Docker on (the Step-6 lifecycle is skipped via skipDockerRun).
        $tester->execute([
            '--app-name'    => 'TestApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'TestApp',
            '--features'    => 'auth,authserver',
            '--ui-system'   => 'plain-css',
            '--docker'      => 'y',
            '--docker-port' => '8080',
            '--cache-system' => 'none',
            '--libraries'   => '',
            '--rest-api'    => 'y',
            '--api-docs'    => 'y',
            '--db-type'     => 'mysql',
            '--db-host'     => 'db',
            '--db-name'     => 'testapp_db',
            '--db-user'     => 'testapp',
            '--db-pass'     => 'secret',
            '--db-prefix'   => '',
        ], ['interactive' => false]);

        // Assert — Docker env is the (default) local server, and a dev API key is
        // pre-filled for instant "Authorize" in the docs.
        $apidoc = json_decode(file_get_contents($this->tmpDir . '/src/Api/apidoc.json'), true);
        $this->assertSame('http://localhost:8080/api', $apidoc['localServer'],
            'the Docker environment is recorded as the local server');
        $this->assertSame('localtestkey', $apidoc['defaultApiKey']);
    }

    /**
     * With the auth feature but NOT authserver, the overrides document the
     * auth/session endpoints but include no OAuth2 scheme or /oauth paths.
     */
    public function testApiOverridesForAuthWithoutServer(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'TestApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace' => 'TestApp',
            '--features'  => 'auth',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--rest-api'  => 'y',
            '--api-docs'  => 'y',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'testapp_db',
            '--db-user'   => 'testapp',
            '--db-pass'   => 'secret',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert
        $overrides = json_decode(file_get_contents($this->tmpDir . '/src/Api/openapi-overrides.json'), true);
        $this->assertArrayHasKey('/me', $overrides['paths']);
        $this->assertArrayHasKey('/session/info', $overrides['paths']);
        $this->assertArrayNotHasKey('/oauth/token', $overrides['paths'],
            'no OAuth paths without the authserver feature');
        $this->assertArrayNotHasKey('securitySchemes', $overrides['components'],
            'no OAuth2 scheme without the authserver feature');
    }

    /**
     * With the REST API enabled but no auth-related features, no API controllers
     * are scaffolded and routes.php keeps only the commented example.
     */
    public function testNoApiControllersWithoutAuthFeatures(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — REST API + API docs on, but no features
        $tester->execute([
            '--app-name'  => 'TestApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace' => 'TestApp',
            '--features'  => '',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--rest-api'  => 'y',
            '--api-docs'  => 'y',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'testapp_db',
            '--db-user'   => 'testapp',
            '--db-pass'   => 'secret',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — no wrapper controllers, and routes.php has only the example
        $this->assertFileDoesNotExist($this->tmpDir . '/src/Api/Controllers/Session.php');
        $this->assertFileDoesNotExist($this->tmpDir . '/src/Api/Controllers/Capabilities.php');
        $routes = file_get_contents($this->tmpDir . '/src/Api/routes.php');
        $this->assertStringContainsString('// Example:', $routes);
        $this->assertStringNotContainsString("\$r->get('/me'", $routes);

        // Assert — with no features the overrides are the empty stub (paths: {})
        $overrides = json_decode(file_get_contents($this->tmpDir . '/src/Api/openapi-overrides.json'), true);
        $this->assertSame([], $overrides['paths'], 'no endpoints injected without features');
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
        $this->assertStringNotContainsString('Application::getInstance()', $index,
            'www/index.php must not use the generic Application::getInstance() factory');
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            '--app-name'     => 'MyApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'    => 'MyApp',
            '--features'     => '',
            '--ui-system'    => 'plain-css',
            '--docker'       => 'n',
            '--libraries'    => '',
            '--cache-system' => 'none',
            '--db-type'      => 'mysql',
            '--db-host'      => 'localhost',
            '--db-name'      => 'myapp_db',
            '--db-user'      => 'myapp',
            '--db-pass'      => 'pass',
            '--db-prefix'    => '',
        ], ['interactive' => false]);

        // Assert — no features selected and no cache backend ⇒ empty features array
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        // Assert — the head assets reference the local vendor path, not a CDN.
        // head.php, not header.php: the stylesheet links moved there when it turned out
        // that header.php's output lands after <body>, where a manifest link is ignored.
        $head = file_get_contents($this->tmpDir . '/app/themes/default/head.php');
        $this->assertStringContainsString('assets/vendor/bootstrap', $head);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $head);

        // Assert — the CSP style-src relaxation is scoped to tailwind only;
        // bootstrap keeps the strict nonce-based policy (no 'unsafe-inline').
        $appConfig = file_get_contents($this->tmpDir . '/app/app.php');
        $this->assertStringNotContainsString("'unsafe-inline'", $appConfig);
    }

    /**
     * When --ui-system=tailwind, the theme header.php must load the Tailwind
     * browser build from the local vendor directory. Regression test: the
     * tailwind theme previously emitted no Tailwind runtime at all, so scaffolded
     * pages rendered completely unstyled (Tailwind classes present, no CSS).
     */
    public function testTailwindThemeHeaderReferencesLocalVendorPath(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'MyApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace' => 'MyApp',
            '--features'  => '',
            '--ui-system' => 'tailwind',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'myapp_db',
            '--db-user'   => 'myapp',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
        ], ['interactive' => false]);

        // Assert — header loads the Tailwind runtime from local vendor, not a CDN.
        // This is the exact reference whose absence left the page unstyled.
        $head = file_get_contents($this->tmpDir . '/app/themes/default/head.php');
        $this->assertStringContainsString('assets/vendor/tailwind', $head);
        $this->assertStringContainsString('<script', $head); // runtime is a script, loaded in <head>
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $head);

        // Assert — CSP relaxes style-src for tailwind, otherwise the browser
        // build's runtime-injected <style> is blocked (this was the actual
        // symptom: "Applying inline style violates ... style-src"). The framework
        // drops the style nonce when 'unsafe-inline' is present.
        $appConfig = file_get_contents($this->tmpDir . '/app/app.php');
        $this->assertStringContainsString("'unsafe-inline'", $appConfig);

        // Assert — daisyUI's stylesheet is there too, from local vendor. The
        // Tailwind runtime generates the utilities; daisyUI carries the
        // components and the theme tokens. With only the first, every `btn`,
        // `card` and `alert` in the scaffolded views is an unknown class, so the
        // page renders as unstyled text — which reads as a broken install rather
        // than a missing stylesheet.
        $this->assertStringContainsString('assets/vendor/daisyui', $head);
        $this->assertStringContainsString('daisyui.css', $head);

        // …and the theme choice is applied before the first paint. daisyUI reads
        // `data-theme` off the root element, and `<html>` is written by Document
        // rather than by the theme, so it cannot be set in the markup. Deferring
        // it paints light and then flips.
        $this->assertStringContainsString("localStorage.getItem('pf-theme')", $head);
        $this->assertStringContainsString('data-theme', $head);
    }

    /**
     * The tailwind theme's chrome is built from daisyUI components.
     *
     * The header used to be a hand-built bar with a hardcoded palette —
     * `bg-white`, `text-gray-700`, `hover:text-blue-600` — which is invisible or
     * unreadable under `data-theme="dark"`, and that is how such a class is
     * usually found. daisyUI's components carry the theme; the utilities carry
     * one palette.
     */
    public function testTheTailwindThemeChromeUsesDaisyuiComponents(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'    => 'MyApp',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'MyApp',
            '--features'    => '',
            '--ui-system'   => 'tailwind',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'mysql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'myapp_db',
            '--db-user'     => 'myapp',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);

        // Assert — the header is a daisyUI navbar with a real menu
        $header = file_get_contents($this->tmpDir . '/app/themes/default/header.php');
        $this->assertStringContainsString('navbar bg-base-100', $header);
        $this->assertStringContainsString('menu menu-horizontal', $header);
        $this->assertStringContainsString('pf-theme-toggle', $header,
            'the theme has two themes, so it needs a way to choose');

        // …and nothing in the chrome is a hardcoded colour
        foreach (['header.php', 'footer.php'] as $file) {
            $content = (string) file_get_contents($this->tmpDir . '/app/themes/default/' . $file);
            $this->assertDoesNotMatchRegularExpression(
                '/class="[^"]*\b(?:bg-white|(?:bg|text|border)-(?:gray|blue|red|green|yellow|amber|slate|zinc)-[0-9])/',
                $content,
                $file . ' must not carry a hardcoded palette: it is invisible in the dark theme'
            );
        }

        // The stylesheet reads daisyUI tokens rather than literals
        $css = (string) file_get_contents($this->tmpDir . '/www/assets/css/style.css');
        $this->assertStringContainsString('--color-base-content', $css);
        $this->assertStringContainsString('--color-primary', $css);
    }

    /**
     * The favicon set from the framework's brand/ directory must be scaffolded
     * into every new project, regardless of UI system.
     *
     * Why it matters: a scaffolded app should ship with complete favicon /
     * PWA-icon coverage out of the box, pulled from the single brand source of
     * truth. This verifies the agreed layout — favicon.ico + config files at the
     * web root, sized icons under www/assets/favicons/ — and that the header
     * wires them in.
     */
    public function testFaviconSetIsScaffoldedIntoProject(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'MyApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        // Assert — the classic icon and config files live at the web root
        $this->assertFileExists($this->tmpDir . '/www/favicon.ico',
            'favicon.ico must be copied to the web root where browsers auto-request it');
        $this->assertFileExists($this->tmpDir . '/www/manifest.json');
        $this->assertFileExists($this->tmpDir . '/www/browserconfig.xml');

        // Assert — the sized app icons live in the advanced-icons subdir
        $this->assertFileExists($this->tmpDir . '/www/assets/favicons/apple-icon-180x180.png',
            'sized app icons belong under www/assets/favicons/, not the web root');
        $this->assertFileExists($this->tmpDir . '/www/assets/favicons/favicon-32x32.png');

        // Assert — the manifest was stamped with the app name and its icon paths
        // were rewritten from the generator's root-relative "/icon.png" form to
        // the assets/favicons/ subdir (relative, so any base path works).
        $manifest = json_decode(file_get_contents($this->tmpDir . '/www/manifest.json'), true);
        $this->assertSame('MyApp', $manifest['name'], 'manifest name must be the app name, not the generator default "App"');
        $this->assertStringStartsWith('assets/favicons/', $manifest['icons'][0]['src'],
            'manifest icon paths must point at the subdir, not "/"');

        // Assert — browserconfig tile paths were rewritten to the same subdir
        $browserconfig = file_get_contents($this->tmpDir . '/www/browserconfig.xml');
        $this->assertStringContainsString('src="assets/favicons/ms-icon-70x70.png"', $browserconfig);
        $this->assertStringNotContainsString('src="/ms-icon', $browserconfig);

        // Assert — head.php wires the icons, manifest and tile config, and
        // theme.html.php includes it inside a real <head>.
        //
        // Both halves matter, and the second is the whole point: these lines used to be
        // in header.php, whose output the document emits *after* `<body>`. A browser
        // hoists a stylesheet link from there and **ignores** `<link rel="manifest">`,
        // so every scaffolded project shipped a manifest no browser ever read — visible
        // only as "No manifest detected" in devtools.
        $head = file_get_contents($this->tmpDir . '/app/themes/default/head.php');
        $this->assertStringContainsString('rel="manifest"', $head);
        $this->assertStringContainsString('assets/favicons/apple-icon-180x180.png', $head);
        $this->assertStringContainsString('msapplication-config', $head);

        // The manifest is a web app manifest, not a list of icons. Without start_url
        // and display, devtools detects it and then rejects it — "'start_url' is not
        // valid", "'display' property must be one of 'standalone', 'fullscreen', or
        // 'minimal-ui'" — and the application cannot be installed, which is the whole
        // reason the file is there.
        $manifest = json_decode((string) file_get_contents($this->tmpDir . '/www/manifest.json'), true);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#ffffff', $manifest['theme_color']);

        // Relative, so a subdirectory install resolves to its own root rather than the
        // origin's. A literal '/' is correct exactly once and wrong silently elsewhere.
        $this->assertSame('./', $manifest['start_url']);
        $this->assertSame('./', $manifest['scope']);

        $layout = file_get_contents($this->tmpDir . '/app/themes/default/theme.html.php');
        $this->assertMatchesRegularExpression(
            '/<head>.*getElement\(.head.\).*<\/head>/s',
            $layout,
            'the head assets have to be inside a <head> or Theme::getheader() cannot lift them'
        );
    }

    /**
     * The scaffolded theme header must show the placeholder logo image (not just
     * the app name as text), and both ink variants must be copied into the project.
     *
     * Why it matters: a scaffolded app should present a real logo in its header
     * out of the box, pulled from the brand source of truth, and the developer
     * replaces the image files to rebrand. The light-header themes must reference
     * the dark-ink variant so the mark is legible.
     */
    public function testHeaderShowsPlaceholderLogoOnLightThemes(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — plain-css has a white navbar
        $tester->execute([
            '--app-name'  => 'MyApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        // Assert — both ink variants are copied as replaceable placeholders
        $this->assertFileExists($this->tmpDir . '/www/assets/img/logo.png');
        $this->assertFileExists($this->tmpDir . '/www/assets/img/logo-inverse.png');

        // Assert — the header renders the logo image, dark-ink variant on the
        // white plain-css navbar, with the app name preserved as alt text
        $header = file_get_contents($this->tmpDir . '/app/themes/default/header.php');
        $this->assertStringContainsString('<img src="<?php echo sURL; ?>assets/img/logo.png"', $header);
        $this->assertStringContainsString('alt="<?php echo htmlspecialchars(', $header,
            'app name must survive as the logo alt text for accessibility');
        $this->assertStringNotContainsString('assets/img/logo-inverse.png', $header,
            'a light navbar must not use the light-ink (inverse) variant');
    }

    /**
     * The bootstrap theme uses a dark (bg-primary) navbar, so its header must
     * reference the light-ink (inverse) logo variant to stay legible.
     */
    public function testHeaderUsesInverseLogoOnDarkBootstrapNavbar(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'MyApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        // Assert — dark navbar → light-ink (inverse) logo
        $header = file_get_contents($this->tmpDir . '/app/themes/default/header.php');
        $this->assertStringContainsString('assets/img/logo-inverse.png', $header);
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
        // timescaledb is a PostgreSQL driver plus a flag; the driver is the env
        // default, the flag is a literal because it is a property of the schema and
        // not of the machine.
        $this->assertStringContainsString("envvar('APP_DB_TYPE', 'postgresql')", $settings);
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        // The database CLI client matching the selected engine must be installed:
        // TestEnvironment's schema import shells out to it with output redirected to
        // /dev/null, so without it a dump import silently does nothing.
        $this->assertStringContainsString('postgresql-client', $dockerfile);
        $this->assertStringNotContainsString('default-mysql-client', $dockerfile);
    }

    /**
     * A MySQL project gets the MySQL client instead of the PostgreSQL one — the
     * two are mutually exclusive so the image stays as small as it can be.
     */
    public function testDockerfileInstallsMysqlClientForMysqlProjects(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'   => 'DockerMysqlApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'  => 'DockerMysqlApp',
            '--features'   => '',
            '--ui-system'  => 'plain-css',
            '--docker'     => 'y',
            '--docker-port'=> '8080',
            '--cache-system' => 'none',
            '--libraries'  => '',
            '--db-type'    => 'mysql',
            '--db-host'    => 'db',
            '--db-name'    => 'dockerapp_db',
            '--db-user'    => 'dockerapp',
            '--db-pass'    => 'secret',
            '--db-prefix'  => '',
        ], ['interactive' => false]);

        // Assert
        $dockerfile = file_get_contents($this->tmpDir . '/Dockerfile');
        $this->assertStringContainsString('default-mysql-client', $dockerfile);
        $this->assertStringNotContainsString('postgresql-client', $dockerfile);
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'  => 'TestApp',
            '--features'   => '',
            '--ui-system'  => 'plain-css',
            '--docker'     => 'n',
            '--libraries'  => 'jquery',
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
     * The scaffolded theme ships a standalone layout for the login pages.
     *
     * The complaint was `/login` carrying the site header and navigation on a
     * Tailwind project. Every built-in auth view is a full-page centred card
     * (`min-h-screen`, or `min-height: 100vh` in the other two themes), so the chrome
     * was never meant to be above it — and `Theme::$elements` has pointed `'login'`
     * at `login.php` from the start, so the layout simply had to exist.
     *
     * Both halves are asserted: the assets are there (an unstyled login form is the
     * failure a reader blames on the CSS) and the header markup is not.
     */
    public function testTheScaffoldedThemeShipsAStandaloneLoginLayout(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'    => 'LoginLayoutApp',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'LoginLayoutApp',
            '--features'    => 'auth',
            '--ui-system'   => 'tailwind',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'postgresql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'login_db',
            '--db-user'     => 'login',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);

        // Assert
        $layout = $this->tmpDir . '/app/themes/default/login.php';
        $this->assertFileExists($layout, 'the theme must ship a standalone login layout');
        $contents = (string) file_get_contents($layout);

        // The head assets, so the form is styled…
        $this->assertStringContainsString('[MODULE]', $contents);
        $this->assertStringContainsString('assets/css/style.css', $contents);
        $this->assertStringContainsString('renderCss()', $contents);
        // …and the scripts, so the passkey flow still works on this page.
        $this->assertStringContainsString('renderJs()', $contents);

        // But none of the chrome. `<nav` and `<header` are what the report was about.
        $this->assertStringNotContainsString('<nav', $contents);
        $this->assertStringNotContainsString('<header', $contents);
        $this->assertStringNotContainsString('NavRegistry', $contents,
            'the standalone layout must not build the navigation it is not showing');
    }

    /**
     * The head assets are the same list in both layouts.
     *
     * They are built once and used twice on purpose. A copy would drift exactly when
     * the UI system changes — and a login page still loading the previous theme's
     * stylesheet does not look like a bug, it looks like a design decision.
     */
    public function testTheChromeAndStandaloneLayoutsShareTheirAssets(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — bootstrap, whose header carries a framework stylesheet of its own.
        $tester->execute([
            '--app-name'    => 'SharedAssetsApp',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'SharedAssetsApp',
            '--features'    => 'auth',
            '--ui-system'   => 'bootstrap',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'postgresql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'shared_db',
            '--db-user'     => 'shared',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);

        // Assert — bootstrap's own CSS and JS reach both layouts.
        $head   = (string) file_get_contents($this->tmpDir . '/app/themes/default/head.php');
        $footer = (string) file_get_contents($this->tmpDir . '/app/themes/default/footer.php');
        $login  = (string) file_get_contents($this->tmpDir . '/app/themes/default/login.php');

        $this->assertStringContainsString('bootstrap.min.css', $head);
        $this->assertStringContainsString('bootstrap.min.css', $login,
            'the login layout must load the same UI framework as the rest of the site');
        $this->assertStringContainsString('bootstrap.bundle.min.js', $footer);
        $this->assertStringContainsString('bootstrap.bundle.min.js', $login);
    }

    /**
     * `node_modules/` is ignored in a plain MVC project, not only in a SPA one.
     *
     * It used to be written by the SPA scaffolder, so a project with no build stack
     * had no rule for it — and one does not need a build stack to acquire the
     * directory: `npm install` runs at the project root for the OpenAPI/RapiDoc
     * generator, and `./dockernpm` is scaffolded for every project to use. The
     * result was a few thousand untracked files and nothing to say they were
     * expected.
     *
     * Asserted on the plainest possible scaffold — MVC, plain CSS, no API docs —
     * because that is the configuration the rule was missing from.
     */
    public function testGitignoreExcludesNodeModulesInAPlainProject(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no SPA, no API docs, nothing that would have added the line before.
        $tester->execute([
            '--app-name'    => 'NodeModulesApp',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'NodeModulesApp',
            '--features'    => '',
            '--ui-system'   => 'plain-css',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'postgresql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'nodemodules_db',
            '--db-user'     => 'nodemodules',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);

        // Assert
        $contents = (string) file_get_contents($this->tmpDir . '/.gitignore');
        $this->assertStringContainsString('node_modules/', $contents,
            'node_modules/ must be ignored in every scaffolded project, build stack or not');
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
     * The group call is the canonical way to apply a shared prefix (e.g. /1.0)
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        // Assert — the version prefix derives from the APIVERSION constant
        // (single source of truth with app.php 'api_version').
        $this->assertStringContainsString("'prefix' => '/' . (defined('APIVERSION') ? APIVERSION : '1.0')", $routes,
            'routes.php group prefix must derive from APIVERSION');

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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
        $this->assertStringContainsString('/api/1.0', $appConfig,
            "api prefix must default to /api/1.0");
        $this->assertStringContainsString("'cors_origins'", $appConfig,
            "api section must contain 'cors_origins' key");
        // Top-level api_version drives the APIVERSION constant (version checks +
        // routes prefix).
        $this->assertStringContainsString("'api_version' => '1.0'", $appConfig,
            "app.php must set top-level 'api_version' so APIVERSION is defined");
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        // UserDatabase handles password verification (+ onAuthCheck remember-me).
        $this->assertStringContainsString("Pramnos\\\\Addon\\\\Auth\\\\UserDatabase", $appConfig,
            'app.php must include UserDatabase addon for password verification');

        // UserDatabase must be type=auth
        $this->assertMatchesRegularExpression(
            "/'addon'\s*=>\s*'Pramnos\\\\\\\\Addon\\\\\\\\Auth\\\\\\\\UserDatabase'.*'type'\s*=>\s*'auth'/s",
            $appConfig,
            'UserDatabase must have type=auth'
        );

        // The deprecated User addon is intentionally NOT registered any more —
        // Auth's built-in login lifecycle (executeDefaultLogin) sets the session
        // state instead. Registering it would skip the built-in activity logging.
        $this->assertStringNotContainsString("Pramnos\\\\Addon\\\\User\\\\User", $appConfig,
            'app.php must NOT register the deprecated User addon (built-in lifecycle handles session state)');

        // Session tracking is wired explicitly via the middleware pipeline.
        $this->assertStringContainsString("Pramnos\\\\Http\\\\Middleware\\\\SessionTrackingMiddleware", $appConfig,
            'app.php must declare the SessionTrackingMiddleware');
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
     * src/Controllers/Login.php so that /login routes to a login form. It is a
     * thin alias to the framework Account controller (LoginFlow-driven), NOT a
     * hand-rolled login implementation.
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        // Must be a thin alias extending the framework Account controller — the
        // login/verify/logout flow lives there, driven by LoginFlow.
        $this->assertStringContainsString('extends Account', $login,
            'Login must delegate to the framework Account controller');
        $this->assertStringContainsString('use Pramnos\\Auth\\Controllers\\Account;', $login);

        // /login form actions post under the "login" route base.
        $this->assertStringContainsString("routeBase = 'login'", $login);

        // The bare /login URL shows the sign-in form (display delegates to login()).
        $this->assertStringContainsString('public function display()', $login);
        $this->assertStringContainsString('$this->login()', $login);

        // It must NOT re-implement login: no hand-rolled credential handling.
        $this->assertStringNotContainsString('function dologin', $login,
            'Login must not re-implement credential handling — the framework does it');
    }

    /**
     * When the 'auth' feature is requested, the scaffolder must create
     * src/Controllers/Account.php that extends the framework Account controller,
     * making all account management actions available via /account.
     */
    public function testAuthFeatureScaffoldsAccountControllerExtendingAccount(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'AuthApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        // Must extend the framework Account controller
        $this->assertStringContainsString('extends \\Pramnos\\Auth\\Controllers\\Account', $account);
    }

    /**
     * The login view is NOT copied into the app: the framework ships themed
     * login/2FA views as fallbacks under each scaffolding theme's login view
     * group, driven by the Account/LoginFlow flow. Apps customise them via
     * `project:publish-views`. This mirrors how OAuth2 views are handled.
     */
    public function testAuthFeatureDoesNotScaffoldLoginView(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'AuthApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        // Assert — no app-level login view is written; the framework fallback serves it.
        $this->assertFileDoesNotExist(
            $this->tmpDir . '/src/Views/login/login.html.php',
            'login view must come from the framework fallback, not be copied into the app'
        );

        // The bundled fallback exists in the framework and carries the new-flow form.
        $fallback = dirname(__DIR__, 3)
            . '/scaffolding/themes/plain-css/views/login/login.html.php';
        $this->assertFileExists($fallback, 'framework must ship a fallback login view');
        $view = file_get_contents($fallback);
        $this->assertStringContainsString('/login', $view, 'form posts under the login route base');
        $this->assertStringContainsString('name="username"', $view);
        $this->assertStringContainsString('name="password"', $view);
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        // getCurrentUser() returns false for guests; must convert to null before
        // passing to getForUser(?User) to avoid a TypeError at runtime.
        $this->assertStringContainsString('getCurrentUser() ?: null', $header,
            'Header must convert false→null: getCurrentUser() returns false for guests, getForUser() expects ?User');

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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
     * Every new application must receive src/Admin/Controllers/Logs.php extending
     * the framework LogController. This makes /logs available in every app and
     * follows the reference application pattern (thin wrapper, customize whitelist/blacklist).
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
        $logsPath = $this->tmpDir . '/src/Admin/Controllers/Logs.php';
        $this->assertFileExists($logsPath, 'src/Admin/Controllers/Logs.php must be scaffolded in every new application');

        $logs = file_get_contents($logsPath);

        // Must declare the correct namespace
        $this->assertStringContainsString('namespace MinimalApp\\Admin\\Controllers;', $logs);

        // Must extend the framework LogController
        $this->assertStringContainsString('extends LogController', $logs);
        $this->assertStringContainsString('use Pramnos\\Application\\Controllers\\LogController', $logs);
    }

    /**
     * Every new application must receive src/Controllers/Health.php extending
     * the framework Health controller.  This makes /health and the monitoring
     * endpoint GET /health/check available in every scaffolded app.
     * Authentication is enforced by the framework controller — the thin wrapper
     * carries no logic of its own.
     */
    public function testHealthControllerIsAlwaysScaffolded(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no features (health controller is unconditional)
        $tester->execute([
            '--app-name'  => 'MinimalApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        // Assert — Health controller must always be scaffolded
        $healthPath = $this->tmpDir . '/src/Controllers/Health.php';
        $this->assertFileExists($healthPath, 'src/Controllers/Health.php must be scaffolded in every new application');

        $health = file_get_contents($healthPath);

        // Must declare the correct namespace
        $this->assertStringContainsString('namespace MinimalApp\\Controllers;', $health);

        // Must extend the framework Health controller
        $this->assertStringContainsString('extends FrameworkHealth', $health);
        $this->assertStringContainsString('use Pramnos\\Application\\Controllers\\Health as FrameworkHealth', $health);
    }

    /**
     * When --webhook=y is passed, pramnos init must generate www/webhook.php.
     *
     * The file must contain a WebhookHandler instantiation with the HMAC secret
     * from $_ENV and a default onBranch('main', [...]) mapping.  This is the
     * git-deploy entry point that ship-day teams configure once and forget.
     */
    public function testWebhookScaffoldedWhenRequested(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — request webhook generation
        $tester->execute([
            '--app-name'  => 'MinimalApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            '--webhook'   => 'y',
        ], ['interactive' => false]);

        // Assert — www/webhook.php must exist
        $webhookPath = $this->tmpDir . '/www/webhook.php';
        $this->assertFileExists($webhookPath, 'www/webhook.php must be generated when --webhook=y');

        $webhook = file_get_contents($webhookPath);

        // Must reference WebhookHandler
        $this->assertStringContainsString('Pramnos\\Webhook\\WebhookHandler', $webhook,
            'webhook.php must instantiate WebhookHandler');

        // Must read secret from environment
        $this->assertStringContainsString('WEBHOOK_SECRET', $webhook,
            'webhook.php must read WEBHOOK_SECRET from environment');

        // Must configure main branch
        $this->assertStringContainsString("onBranch('main'", $webhook,
            'webhook.php must configure at least a main branch');
    }

    /**
     * When --webhook is not set (or set to 'n'), www/webhook.php must NOT be generated.
     */
    public function testWebhookNotScaffoldedByDefault(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act — no webhook option
        $tester->execute([
            '--app-name'  => 'MinimalApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            '--webhook'   => 'n',
        ], ['interactive' => false]);

        // Assert — webhook.php must NOT exist (opt-in only)
        $webhookPath = $this->tmpDir . '/www/webhook.php';
        $this->assertFileDoesNotExist($webhookPath, 'www/webhook.php must not be generated when --webhook=n');
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
     * Every new application receives src/Admin/Controllers/Users.php extending
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
        $usersPath = $this->tmpDir . '/src/Admin/Controllers/Users.php';
        $this->assertFileExists($usersPath, 'src/Admin/Controllers/Users.php must be scaffolded in every new application');

        $users = file_get_contents($usersPath);
        $this->assertStringContainsString('namespace AdminApp\\Admin\\Controllers;', $users);
        $this->assertStringContainsString('UsersController', $users,
            'Users wrapper must extend the framework UsersController');
    }

    /**
     * Every new application receives src/Admin/Controllers/Settings.php extending
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        $settingsPath = $this->tmpDir . '/src/Admin/Controllers/Settings.php';
        $this->assertFileExists($settingsPath, 'src/Admin/Controllers/Settings.php must be scaffolded in every new application');

        $settings = file_get_contents($settingsPath);
        $this->assertStringContainsString('namespace AdminApp\\Admin\\Controllers;', $settings);
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        // Must pin the alias contract: Login delegates to the framework Account.
        $this->assertStringContainsString('is_subclass_of', $loginTest,
            'LoginControllerTest must verify Login extends the framework Account controller');

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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        $path = $this->tmpDir . '/src/Admin/Controllers/Services.php';
        $this->assertFileExists($path, 'src/Admin/Controllers/Services.php must be scaffolded in every new application');

        $content = file_get_contents($path);
        $this->assertStringContainsString('namespace SvcApp\\Admin\\Controllers;', $content);
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
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
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

        $dashboardPath = $this->tmpDir . '/src/Admin/Controllers/Dashboard.php';
        $this->assertFileExists($dashboardPath, 'src/Admin/Controllers/Dashboard.php must be scaffolded in every new application');

        $dashboard = file_get_contents($dashboardPath);
        $this->assertStringContainsString('namespace AdminApp\\Admin\\Controllers;', $dashboard);
        $this->assertStringContainsString('DashboardController', $dashboard,
            'Dashboard wrapper must extend the framework DashboardController');
    }

    /**
     * Every application scaffolded with the 'auth' feature must receive a
     * TwoFactorAuth controller that extends the framework's TwoFactorAuth.
     *
     * Bug regression: without this controller, navigating to /TwoFactorAuth
     * produces "There is no controller to run..." even though 2FA views and
     * security-page links reference it.
     */
    public function testTwoFactorAuthControllerIsScaffolded(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'AuthApp3',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace' => 'AuthApp3',
            '--features'  => 'auth',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'authapp3_db',
            '--db-user'   => 'authapp3',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        $path = $this->tmpDir . '/src/Controllers/TwoFactorAuth.php';
        $this->assertFileExists($path, 'src/Controllers/TwoFactorAuth.php must be scaffolded with auth feature');

        $content = file_get_contents($path);
        $this->assertStringContainsString('namespace AuthApp3\\Controllers;', $content);
        $this->assertStringContainsString('TwoFactorAuth extends FrameworkTwoFactorAuth', $content,
            'TwoFactorAuth controller must extend the framework class');
        $this->assertStringContainsString(
            'use Pramnos\\Auth\\Controllers\\TwoFactorAuth as FrameworkTwoFactorAuth',
            $content
        );
    }

    /**
     * The Account controller must declare routeBase = 'account' so that all
     * parent Dashboard redirects target /account/... instead of the hardcoded
     * /Dashboard/... fallback in the framework controller.
     *
     * Bug regression: without this property, every redirect inside Dashboard
     * (e.g. after export-data) would send the user to /Dashboard instead of /account.
     */
    public function testAccountControllerHasRouteBase(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'  => 'AuthApp2',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace' => 'AuthApp2',
            '--features'  => 'auth',
            '--ui-system' => 'plain-css',
            '--docker'    => 'n',
            '--libraries' => '',
            '--db-type'   => 'mysql',
            '--db-host'   => 'localhost',
            '--db-name'   => 'authapp2_db',
            '--db-user'   => 'authapp2',
            '--db-pass'   => 'pass',
            '--db-prefix' => '',
            '--rest-api'  => 'n',
        ], ['interactive' => false]);

        $accountPath = $this->tmpDir . '/src/Controllers/Account.php';
        $account     = file_get_contents($accountPath);

        // Assert — routeBase must be set to 'account' so parent redirects are correct
        $this->assertStringContainsString(
            "\$routeBase = 'account'",
            $account,
            "Account controller must declare \$routeBase = 'account' to redirect back to /account/..."
        );
    }

    /**
     * The admin user created by the scaffold must have usertype 90, not 10.
     *
     * Bug regression: init previously set usertype=10 which is below the
     * minUserType=80 threshold for admin nav items (Logs, Users, Settings).
     * A fresh-init admin would see no admin links at all in the navbar.
     */
    public function testCreateAdminUserScriptHasUsertype90(): void
    {
        // Arrange — read the generated admin-user creation snippet directly from Init.php
        $initSrc = file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Console/Commands/Init.php'
        );

        // Act — find the usertype assignment inside createAdminUser()
        // file_get_contents returns raw PHP source; inside a heredoc the dollar is escaped as \$
        $this->assertStringContainsString(
            '\$user->usertype  = 90;',
            $initSrc,
            "createAdminUser() must set usertype=90 so the admin user sees Logs/Users/Settings in navbar (minUserType=80)"
        );
        $this->assertStringNotContainsString(
            '\$user->usertype  = 10;',
            $initSrc,
            "usertype=10 is below minUserType=80 — admin would be locked out of admin nav items"
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * sanitizePassword() must reconstruct the line the way a terminal would when
     * a literal backspace/DEL byte or invalid UTF-8 leaks into the prompt input.
     *
     * This is the bug where typing in a non-Latin keyboard layout, pressing
     * backspace and retyping left the stored admin password containing stray
     * bytes (e.g. "ικ�ikaria1!") that the user could never reproduce at login —
     * the echoed prompt showed one value while another was saved.
     */
    public function testSanitizePasswordHandlesBackspaceAndInvalidBytes(): void
    {
        // Arrange — reach the private helper via reflection.
        $method = new \ReflectionMethod(Init::class, 'sanitizePassword');

        // Act + Assert — a clean password is returned unchanged.
        $this->assertSame(
            'ikaria1!',
            $method->invoke($this->command, 'ikaria1!'),
            'A password with no stray bytes must pass through untouched'
        );

        // A DEL byte (0x7F) deletes the character before it, like a terminal.
        $this->assertSame(
            'abd',
            $method->invoke($this->command, "abc\x7fd"),
            'DEL (0x7F) must delete the preceding character'
        );

        // A backspace byte (0x08) does the same.
        $this->assertSame(
            'ab',
            $method->invoke($this->command, "abc\x08"),
            'Backspace (0x08) must delete the preceding character'
        );

        // Backspace deletes a whole multibyte character, not a single byte, so
        // no broken UTF-8 is left behind.
        $this->assertSame(
            'ι',
            $method->invoke($this->command, "ικ\x7f"),
            'Backspace after a multibyte char must remove the whole character'
        );

        // A stray/invalid continuation byte (the "�" source) is dropped, and the
        // valid characters around it are preserved.
        $this->assertSame(
            'ικikaria1!',
            $method->invoke($this->command, "ικ\xCEikaria1!"),
            'Invalid UTF-8 bytes must be dropped without eating valid neighbours'
        );

        // Other control characters are stripped entirely.
        $this->assertSame(
            'abc',
            $method->invoke($this->command, "a\x01b\x1fc"),
            'C0 control characters must be removed'
        );

        // A well-formed multibyte password survives intact.
        $this->assertSame(
            'ικαρια1!',
            $method->invoke($this->command, 'ικαρια1!'),
            'Valid multibyte input must be preserved byte-for-byte'
        );
    }

    /**
     * The OpenAPI `info.contact.email` must reflect the developer email captured
     * at init when one is supplied, and fall back to the generic placeholder only
     * when it is empty. This guards the docs "Support" contact against shipping a
     * meaningless address, while preserving backward-compatible behaviour for
     * callers that pass no email.
     */
    public function testBuildApiOverridesUsesSupportEmailWithPlaceholderFallback(): void
    {
        // Arrange / Act — a real developer email is threaded through.
        $withEmail = Init::buildApiOverrides(
            'TestApp', 'https://api.example.com', true, true, '', 'dev@acme.test'
        );

        // Assert — the supplied email becomes the docs support contact.
        $this->assertSame('dev@acme.test', $withEmail['info']['contact']['email'],
            'a supplied support email must be used verbatim');
        $this->assertSame('TestApp Support', $withEmail['info']['contact']['name']);

        // Arrange / Act — no email supplied (the pre-existing signature / default).
        $noEmail = Init::buildApiOverrides('TestApp', 'https://api.example.com', true, true);

        // Assert — falls back to the generic placeholder (backward compatible).
        $this->assertSame('support@example.com', $noEmail['info']['contact']['email'],
            'an empty support email must fall back to the generic placeholder');
    }

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

    /**
     * The image can talk to the cache container that was just added beside it.
     *
     * `init` asks "Cache System [redis]" — redis is the **default** — then writes
     * a `cache:` service into docker-compose and `'method' => 'redis'` into the
     * settings. It installed no extension, so PHP could not reach the container
     * and `Cache` fell back to files: settings say redis, service is running,
     * every test passes, and the cache is on disk.
     *
     * The framework does report it — the DevPanel reads "file fell back from
     * redis" — and that line was the only evidence anywhere. Found in a project
     * whose Redis-only bugs could not appear because Redis was never reached.
     */
    public function testTheGeneratedImageInstallsTheChosenCacheExtension(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'     => 'MyApp',
            '--no-install'   => true,
            '--no-download'  => true,
            '--namespace'    => 'MyApp',
            '--features'     => '',
            '--ui-system'    => 'plain-css',
            '--docker'       => 'y',
            '--libraries'    => '',
            '--cache-system' => 'redis',
            '--db-type'      => 'mysql',
            '--db-host'      => 'localhost',
            '--db-name'      => 'myapp_db',
            '--db-user'      => 'myapp',
            '--db-pass'      => 'pass',
            '--db-prefix'    => '',
        ], ['interactive' => false]);

        // Assert — the service, the setting and the extension all agree
        $compose = (string) file_get_contents($this->tmpDir . '/docker-compose.yml');
        $this->assertStringContainsString('image: redis:latest', $compose,
            'a redis project must get the container');

        $settings = (string) file_get_contents($this->tmpDir . '/app/config/settings.php');
        $this->assertStringContainsString("'redis'", $settings,
            'and be configured to use it');

        $dockerfile = (string) file_get_contents($this->tmpDir . '/Dockerfile');
        $this->assertStringContainsString('pecl install redis', $dockerfile,
            'and be able to reach it — without the extension the cache runs on files');
        $this->assertStringContainsString('docker-php-ext-enable redis', $dockerfile);
    }

    /**
     * A project with no cache backend gets no extension.
     *
     * The other half: installing a client for a container that is not there costs
     * build time and adds a moving part for nothing.
     */
    public function testAProjectWithoutACacheGetsNoCacheExtension(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'     => 'MyApp',
            '--no-install'   => true,
            '--no-download'  => true,
            '--namespace'    => 'MyApp',
            '--features'     => '',
            '--ui-system'    => 'plain-css',
            '--docker'       => 'y',
            '--libraries'    => '',
            '--cache-system' => 'none',
            '--db-type'      => 'mysql',
            '--db-host'      => 'localhost',
            '--db-name'      => 'myapp_db',
            '--db-user'      => 'myapp',
            '--db-pass'      => 'pass',
            '--db-prefix'    => '',
        ], ['interactive' => false]);

        // Assert
        $dockerfile = (string) file_get_contents($this->tmpDir . '/Dockerfile');
        $this->assertStringNotContainsString('pecl install redis', $dockerfile);
        $this->assertStringNotContainsString('pecl install memcached', $dockerfile);
    }

    /**
     * The plain-css theme's typeface is self-hosted, not fetched from Google.
     *
     * The header used to carry three `fonts.googleapis.com` tags while the same
     * command generated a CSP restricting `style-src` to `'self'`. The browser
     * refuses that stylesheet outright, so every scaffolded plain-css project
     * rendered in the fallback font stack, with two console errors and no other
     * sign. The two halves were written by one command and disagreed.
     *
     * Asserted on the theme rather than on the download: with `--no-download` the
     * files are not fetched, and what has to hold either way is that the page does
     * not reach out to a third party for them.
     */
    public function testPlainCssThemeSelfHostsItsTypeface(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'    => 'MyApp',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'MyApp',
            '--features'    => '',
            '--ui-system'   => 'plain-css',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'mysql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'myapp_db',
            '--db-user'     => 'myapp',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);

        // Assert — the head links the vendored copy…
        $head = (string) file_get_contents($this->tmpDir . '/app/themes/default/head.php');
        $this->assertStringContainsString('assets/vendor/inter', $head);
        $this->assertStringContainsString('inter.css', $head);

        // …and nothing in the theme reaches out to Google for it
        foreach (['head.php', 'header.php', 'style.css'] as $file) {
            $path = $this->tmpDir . '/app/themes/default/' . $file;
            $contents = is_file($path) ? (string) file_get_contents($path) : '';
            $this->assertStringNotContainsString('fonts.googleapis.com', $contents, $file);
            $this->assertStringNotContainsString('fonts.gstatic.com', $contents, $file);
        }
    }

    /**
     * The typeface is in the asset catalog, fetched as a browser.
     *
     * Google serves woff2 to a browser and ttf to anything else, so a download with
     * the default agent vendors the wrong format — three times the bytes, and no
     * variable-font support. The catalog carries the agent because the downloader
     * cannot guess which hosts care.
     */
    public function testTheTypefaceCatalogEntryAsksForBrowserFormats(): void
    {
        // Arrange
        $catalog = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/scaffolding/assets.json'),
            true
        );

        // Act
        $inter = $catalog['libraries']['inter'] ?? null;

        // Assert
        $this->assertIsArray($inter, 'the catalog must carry the plain-css theme\'s typeface');
        $this->assertStringContainsString('Mozilla/5.0', (string) ($inter['user_agent'] ?? ''));
        $this->assertSame('assets/vendor/inter/latest', $inter['local_path'] ?? null);
    }

    /**
     * A scaffolded project has one palette, and every UI system reads it.
     *
     * Before this, colours lived wherever the chosen UI system kept them: a daisyUI
     * `@plugin` block for Tailwind-with-npm, hand-written custom properties for a
     * buildless one, and a third copy inside a SPA's own theme file. Same palette,
     * three places, and the first thing to go wrong is that they stop agreeing — in
     * whichever theme nobody develops in.
     *
     * The generated forms are written at scaffold time rather than left for a first
     * `theme:build`: a stylesheet referencing tokens no file declares renders in black
     * and white, and "run theme:build" is not discoverable from that symptom.
     */
    public function testScaffoldingWritesOnePaletteAndLinksIt(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'    => 'Acme Portal',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'MyApp',
            '--features'    => '',
            '--ui-system'   => 'tailwind',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'mysql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'myapp_db',
            '--db-user'     => 'myapp',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);

        // Assert — the palette, in daisyUI's own format, named after the application
        $palette = (string) file_get_contents($this->tmpDir . '/app/theme.css');
        $this->assertStringContainsString('@plugin "daisyui/theme"', $palette);
        $this->assertStringContainsString('name: "acme-portal";', $palette);
        $this->assertStringContainsString('name: "acme-portal-dark";', $palette);

        // …its generated forms
        $tokens = (string) file_get_contents($this->tmpDir . '/www/assets/css/theme-tokens.css');
        $this->assertStringContainsString('[data-theme="acme-portal"]', $tokens);
        $this->assertStringContainsString('--color-primary', $tokens);
        $this->assertFileExists($this->tmpDir . '/www/assets/theme-tokens.json');

        // …linked from the head, before the project's own stylesheet
        $head = (string) file_get_contents($this->tmpDir . '/app/themes/default/head.php');
        $this->assertStringContainsString('assets/css/theme-tokens.css', $head);
        $this->assertLessThan(
            strpos($head, 'assets/css/style.css'),
            strpos($head, 'assets/css/theme-tokens.css'),
            'the tokens have to be declared before the stylesheet that uses them'
        );
    }

    /**
     * The theme toggle switches between *this project's* themes.
     *
     * It wrote `light` and `dark` — daisyUI's stock themes — so a project with a
     * palette of its own lost it the first time a visitor pressed the button, and got
     * it back by reloading. Which reads as a rendering glitch rather than as a theme
     * name.
     */
    public function testTheThemeToggleUsesTheProjectsOwnThemeNames(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'    => 'Acme Portal',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'MyApp',
            '--features'    => '',
            '--ui-system'   => 'tailwind',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'mysql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'myapp_db',
            '--db-user'     => 'myapp',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);

        // Assert
        $header = (string) file_get_contents($this->tmpDir . '/app/themes/default/header.php');
        $this->assertStringContainsString("'acme-portal-dark'", $header);
        $this->assertStringContainsString("'acme-portal'", $header);
        $this->assertStringNotContainsString('{{THEME_', $header, 'every placeholder must be substituted');
    }

    /**
     * The administration area is scaffolded as its own directory.
     *
     * `src/Admin/Controllers/`, the counterpart of `src/Api/`, and the framework looks
     * there first for a request inside the area. What it buys is not tidiness: while
     * every admin screen lived in `src/Controllers/`, each one answered on two
     * addresses — `/admin/Users` inside the area and `/Users` outside it, the same page
     * in the public theme, with no sidebar and outside the area's usertype floor.
     *
     * `Health` stays with the public controllers on purpose: `/health/check` is the
     * JSON endpoint an uptime monitor calls, and moving it into the area would put a
     * usertype floor in front of a monitoring URL.
     */
    public function testAdminControllersAreScaffoldedIntoTheirOwnDirectory(): void
    {
        // Arrange
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        $app = new Application();
        $app->add($this->command);
        $tester = new CommandTester($this->command);

        // Act
        $tester->execute([
            '--app-name'    => 'MyApp',
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'   => 'MyApp',
            '--features'    => 'auth,authserver,queue',
            '--ui-system'   => 'tailwind',
            '--docker'      => 'n',
            '--libraries'   => '',
            '--db-type'     => 'mysql',
            '--db-host'     => 'localhost',
            '--db-name'     => 'myapp_db',
            '--db-user'     => 'myapp',
            '--db-pass'     => 'pass',
            '--db-prefix'   => '',
        ], ['interactive' => false]);

        // Assert — the admin screens, in the area's namespace
        foreach (['Users', 'Settings', 'Logs', 'Dashboard', 'Applications', 'Tokens'] as $name) {
            $path = $this->tmpDir . '/src/Admin/Controllers/' . $name . '.php';
            $this->assertFileExists($path, $name . ' belongs to the administration area');
            $this->assertStringContainsString(
                'namespace MyApp\\Admin\\Controllers;',
                (string) file_get_contents($path)
            );
            $this->assertFileDoesNotExist(
                $this->tmpDir . '/src/Controllers/' . $name . '.php',
                $name . ' must not also be reachable at its bare path'
            );
        }

        // …and the public ones where they were
        foreach (['Home', 'Login', 'Account', 'Health'] as $name) {
            $this->assertFileExists(
                $this->tmpDir . '/src/Controllers/' . $name . '.php',
                $name . ' is not an administration screen'
            );
        }
    }
}
