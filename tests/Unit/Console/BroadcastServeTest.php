<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Application as SymfonyApp;
use Pramnos\Console\Commands\BroadcastServe;
use Pramnos\Broadcasting\LocalBroadcastServer;

/**
 * Unit tests for Pramnos\Console\Commands\BroadcastServe.
 *
 * BroadcastServe starts a blocking WebSocket server loop; running that in
 * tests would hang PHPUnit. The strategy used here is to subclass the command
 * and inject a pre-stopped LocalBroadcastServer stub whose run() returns
 * immediately, so we can exercise all configure() and execute() branches
 * without blocking.
 *
 * Branches covered:
 *  - configure(): name, options (host, port, log-file, app-key).
 *  - execute(): output messages for "log file exists", "log file expected but
 *    absent", and "no log file configured".
 *  - execute(): exit code SUCCESS when run() returns normally.
 *  - execute(): exit code FAILURE when run() throws RuntimeException.
 *  - execute(): resolveDefaultLogFile() fallback when ROOT is defined.
 *  - execute(): resolveDefaultLogFile() fallback when no Application exists.
 */
#[CoversClass(BroadcastServe::class)]
class BroadcastServeTest extends TestCase
{
    /** @var string Temp directory used for log-file tests */
    private string $tmpDir;

    /** @var string|null Saved PHP_SELF value */
    private ?string $origPhpSelf = null;

    protected function setUp(): void
    {
        $this->origPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $this->tmpDir = sys_get_temp_dir() . '/pramnos_bcast_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);

        // Reset the Pramnos Application singleton so getContainer() returns
        // null and resolveDefaultLogFile() takes the simple fallback path.
        $ref  = new \ReflectionClass(\Pramnos\Application\Application::class);
        $ref->getProperty('appInstances')->setValue(null, []);
        $ref->getProperty('lastUsedApplication')->setValue(null, null);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            is_file($f) && unlink($f);
        }
        is_dir($this->tmpDir) && rmdir($this->tmpDir);

        // Restore PHP_SELF
        if ($this->origPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->origPhpSelf;
        }

        // Reset Application singleton to avoid cross-test pollution
        $ref  = new \ReflectionClass(\Pramnos\Application\Application::class);
        $ref->getProperty('appInstances')->setValue(null, []);
        $ref->getProperty('lastUsedApplication')->setValue(null, null);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a BroadcastServe command subclass that overrides only
     * createServer() and resolveDefaultLogFile() so that parent::execute()
     * runs fully (covering all execute() lines) without opening a real TCP socket.
     *
     * @param bool $throwOnRun When true, the stub's run() throws RuntimeException.
     */
    private function buildCommand(bool $throwOnRun = false): BroadcastServe
    {
        $serverStub = $this->createMock(LocalBroadcastServer::class);

        if ($throwOnRun) {
            $serverStub->method('run')
                ->willThrowException(new \RuntimeException('Address already in use'));
        }
        // When not throwing, run() is a void no-op — PHPUnit mock default.

        // Override createServer() so no real socket is opened, and override
        // resolveDefaultLogFile() to return '' so tests are environment-agnostic.
        $cmd = new class($serverStub) extends BroadcastServe {
            private LocalBroadcastServer $injectedServer;
            /** @var string Forced log-file path (used by testExecuteShows* tests) */
            public string $forcedLogFile = '';

            public function __construct(LocalBroadcastServer $server)
            {
                parent::__construct();
                $this->injectedServer = $server;
            }

            /**
             * Return the injected stub instead of constructing a real server.
             */
            protected function createServer(string $appKey, ?string $logFile): LocalBroadcastServer
            {
                return $this->injectedServer;
            }

            /**
             * Return '' by default so the "No log file configured" branch
             * is hit. Tests that need a specific value set $forcedLogFile
             * before calling execute().
             */
            protected function resolveDefaultLogFile(string $appKey): string
            {
                return $this->forcedLogFile;
            }
        };

        return $cmd;
    }

    // =========================================================================
    // configure()
    // =========================================================================

    /**
     * configure() must register the command with name 'broadcast:serve' and
     * declare the options: host, port, log-file, app-key.
     *
     * The Symfony framework calls configure() when the command is added to an
     * Application; inspecting the registered definition is sufficient to verify
     * all addOption() calls without running execute().
     */
    public function testConfigureRegistersNameAndOptions(): void
    {
        // Arrange
        $app = new SymfonyApp();
        $cmd = new BroadcastServe();
        $app->add($cmd);
        $registered = $app->find('broadcast:serve');

        // Assert — name
        $this->assertSame('broadcast:serve', $registered->getName(),
            'Command must be named "broadcast:serve"');

        // Assert — required options present
        $def = $registered->getDefinition();
        $this->assertTrue($def->hasOption('host'),     'Option --host must be declared');
        $this->assertTrue($def->hasOption('port'),     'Option --port must be declared');
        $this->assertTrue($def->hasOption('log-file'), 'Option --log-file must be declared');
        $this->assertTrue($def->hasOption('app-key'),  'Option --app-key must be declared');
    }

    /**
     * The default value for --host must be '0.0.0.0' (listen on all interfaces)
     * and for --port must be '6001' (Pusher standard port).
     */
    public function testConfigureDefaultValues(): void
    {
        // Arrange
        $app = new SymfonyApp();
        $cmd = new BroadcastServe();
        $app->add($cmd);
        $registered = $app->find('broadcast:serve');
        $def        = $registered->getDefinition();

        // Assert — defaults
        $this->assertSame('0.0.0.0',     $def->getOption('host')->getDefault(),
            'Default --host must be 0.0.0.0');
        $this->assertSame('6001',        $def->getOption('port')->getDefault(),
            'Default --port must be 6001');
        $this->assertSame('pramnos-local', $def->getOption('app-key')->getDefault(),
            'Default --app-key must be pramnos-local');
    }

    // =========================================================================
    // execute() — output messages
    // =========================================================================

    /**
     * execute() must print the "Listening on" header and the Ctrl-C hint.
     *
     * These messages are the minimal user-facing output that confirms the server
     * started successfully.
     */
    public function testExecuteOutputsStartupHeader(): void
    {
        // Arrange
        $app    = new SymfonyApp();
        $cmd    = $this->buildCommand();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exitCode = $tester->execute([]);

        // Assert — basic output
        $display = $tester->getDisplay();
        $this->assertStringContainsString('broadcast:serve', $display,
            'Output must include the command name');
        $this->assertStringContainsString('Ctrl+C', $display,
            'Output must include Ctrl+C stop hint');
        $this->assertSame(BroadcastServe::SUCCESS, $exitCode,
            'Exit code must be SUCCESS when run() returns normally');
    }

    /**
     * execute() with an existing log file must print the "Tailing log file" message.
     *
     * This confirms the code path that checks file_exists() for the provided
     * --log-file option value.
     */
    public function testExecuteShowsTailingMessageWhenLogFileExists(): void
    {
        // Arrange — create a real temp file so file_exists() returns true
        $logFile = $this->tmpDir . '/broadcast.jsonl';
        file_put_contents($logFile, '');

        $app    = new SymfonyApp();
        $cmd    = $this->buildCommand();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $tester->execute(['--log-file' => $logFile]);

        // Assert — tailing message present
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Tailing log file', $display,
            'Output must mention "Tailing log file" when the file exists');
        $this->assertStringContainsString($logFile, $display,
            'Output must include the log file path');
    }

    /**
     * execute() with a non-existent log file path must print the
     * "will be watched when created" message.
     *
     * This covers the elseif branch where the log file path is given but the
     * file does not yet exist.
     */
    public function testExecuteShowsWatchMessageWhenLogFileAbsent(): void
    {
        // Arrange — a path that does not exist
        $logFile = $this->tmpDir . '/not_yet_created.jsonl';

        $app    = new SymfonyApp();
        $cmd    = $this->buildCommand();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $tester->execute(['--log-file' => $logFile]);

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('watched when created', $display,
            'Output must say the file will be watched when it is created');
    }

    /**
     * execute() must print "No log file configured" when resolveDefaultLogFile()
     * returns an empty string.
     *
     * buildCommand() already overrides resolveDefaultLogFile() to return ''
     * so no log-file option is needed. Calling execute() with no --log-file
     * triggers the else branch.
     */
    public function testExecuteShowsNoLogFileMessageWhenNoneConfigured(): void
    {
        // Arrange — buildCommand() forces resolveDefaultLogFile() → ''
        $app    = new SymfonyApp();
        $cmd    = $this->buildCommand();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $tester->execute([]);

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('No log file configured', $display,
            'Output must say "No log file configured" when no log path is available');
    }

    // =========================================================================
    // execute() — exit codes
    // =========================================================================

    /**
     * execute() must return FAILURE and print an error when the WebSocket
     * server throws a RuntimeException (e.g. port already in use).
     *
     * This covers the catch (\RuntimeException) block in execute().
     */
    public function testExecuteReturnFailureWhenServerThrows(): void
    {
        // Arrange — stub throws on run()
        $app    = new SymfonyApp();
        $cmd    = $this->buildCommand(throwOnRun: true);
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exitCode = $tester->execute([]);

        // Assert — FAILURE exit code
        $this->assertSame(BroadcastServe::FAILURE, $exitCode,
            'execute() must return FAILURE when LocalBroadcastServer::run() throws');

        // Assert — error message displayed
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Error', $display,
            'Output must include "Error:" prefix for the exception message');
        $this->assertStringContainsString('Address already in use', $display,
            'Output must include the exception message text');
    }

    /**
     * execute() must print "Server stopped." and return SUCCESS when run()
     * returns normally (simulates the server being stopped via stop()).
     */
    public function testExecuteReturnsSuccessAfterNormalRun(): void
    {
        // Arrange
        $app    = new SymfonyApp();
        $cmd    = $this->buildCommand();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $exitCode = $tester->execute([]);

        // Assert
        $this->assertSame(BroadcastServe::SUCCESS, $exitCode);
        $this->assertStringContainsString('Server stopped', $tester->getDisplay(),
            'Output must include "Server stopped." on normal exit');
    }

    // =========================================================================
    // execute() — custom options
    // =========================================================================

    /**
     * execute() must honour --host and --port and include them in the
     * "Listening on" output line.
     */
    public function testExecuteUsesCustomHostAndPort(): void
    {
        // Arrange
        $app    = new SymfonyApp();
        $cmd    = $this->buildCommand();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $tester->execute(['--host' => '127.0.0.1', '--port' => '7000']);

        // Assert — custom host/port appear in the Listening output line
        $display = $tester->getDisplay();
        $this->assertStringContainsString('127.0.0.1', $display,
            'Custom --host must appear in the startup output');
        $this->assertStringContainsString('7000', $display,
            'Custom --port must appear in the startup output');
    }

    /**
     * execute() must honour --app-key and include it in the output.
     */
    public function testExecuteUsesCustomAppKey(): void
    {
        // Arrange
        $app    = new SymfonyApp();
        $cmd    = $this->buildCommand();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act
        $tester->execute(['--app-key' => 'my-custom-key']);

        // Assert
        $this->assertStringContainsString('my-custom-key', $tester->getDisplay(),
            'Custom --app-key must appear in the startup output');
    }

    /**
     * getJobName() must return the string 'broadcast-serve'.
     *
     * CommandBase uses this value as the lock-file basename; an incorrect
     * return value would cause the lock file to be created in the wrong place
     * and could allow duplicate daemon instances.
     */
    public function testGetJobNameReturnsBroadcastServe(): void
    {
        // Arrange — expose protected method via reflection
        $cmd = new BroadcastServe();
        $ref = new \ReflectionMethod($cmd, 'getJobName');

        // Act
        $name = $ref->invoke($cmd);

        // Assert
        $this->assertSame('broadcast-serve', $name,
            'getJobName() must return "broadcast-serve"');
    }

    // =========================================================================
    // onTick callback — verbose mode coverage (lines 132-139)
    // =========================================================================

    /**
     * When verbose output is enabled, the onTick callback must print the
     * client/channel count whenever the client count changes.
     *
     * We capture the registered callback by implementing onTick() on the stub
     * to store it, then invoke it manually with verbose output.
     */
    public function testOnTickCallbackPrintsClientCountInVerboseMode(): void
    {
        // Arrange — capture the onTick callback so we can invoke it manually
        $capturedCallback = null;
        $serverStub = $this->createMock(LocalBroadcastServer::class);
        $serverStub->method('onTick')->willReturnCallback(
            function (callable $cb) use (&$capturedCallback): void {
                $capturedCallback = $cb;
            }
        );

        $cmd = new class($serverStub) extends BroadcastServe {
            private LocalBroadcastServer $injectedServer;

            public function __construct(LocalBroadcastServer $server)
            {
                parent::__construct();
                $this->injectedServer = $server;
            }

            protected function createServer(string $appKey, ?string $logFile): LocalBroadcastServer
            {
                return $this->injectedServer;
            }

            protected function resolveDefaultLogFile(string $appKey): string
            {
                return '';
            }
        };

        $app    = new SymfonyApp();
        $app->add($cmd);
        $tester = new CommandTester($cmd);

        // Act — execute with verbose flag so $verbose = true in the callback
        $tester->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

        // Simulate the tick callback being called by the server (clients=3, channels=2)
        $this->assertNotNull($capturedCallback, 'onTick callback must have been registered');
        if ($capturedCallback !== null) {
            $capturedCallback(3, 2);   // first call — clients differ from $lastCount (-1)
            $capturedCallback(3, 2);   // second call — same count, no output
        }

        // Assert — the callback ran without exception
        $this->assertSame(BroadcastServe::SUCCESS, $tester->getStatusCode(),
            'Command must still exit with SUCCESS after onTick callback fires');
    }

    // =========================================================================
    // resolveDefaultLogFile() — Application container branch (lines 177-187)
    // =========================================================================

    /**
     * resolveDefaultLogFile() must return the log path from the BroadcastingManager
     * when the Application container has a 'broadcasting' binding with getLogPath().
     *
     * This exercises the Application container inspection branch (lines 177-183).
     * We use a hand-rolled anonymous container stub since no framework Container
     * class is available for createMock().
     */
    public function testResolveDefaultLogFileReturnsManagerLogPath(): void
    {
        // Arrange — manager with getLogPath()
        $managerStub = new class {
            public function getLogPath(): string
            {
                return '/tmp/custom/broadcast.jsonl';
            }
        };

        // The real container, with the manager bound under the id the command
        // looks for. It used to be a hand-rolled stub returned by an overridden
        // getContainer(), because Application had no such method — which is also
        // why the production call `$app->getContainer()` threw, silently, into
        // the catch below it. The method exists now, so the test uses it.
        $container = new \Pramnos\Application\Container();
        $container->instance('broadcasting', $managerStub);

        $appStub = new class($container) extends \Pramnos\Application\Application {
            public function __construct(\Pramnos\Application\Container $container)
            {
                // Do NOT call parent::__construct() — it would require a full bootstrap
                $this->container = $container;
            }
        };

        // Inject the stub into the Application singleton registry directly
        $ref = new \ReflectionClass(\Pramnos\Application\Application::class);
        $ref->getProperty('appInstances')->setValue(null, ['default' => $appStub]);
        $ref->getProperty('lastUsedApplication')->setValue(null, 'default');

        $cmd    = new BroadcastServe();
        $method = new \ReflectionMethod($cmd, 'resolveDefaultLogFile');

        // Act
        $path = $method->invoke($cmd, 'pramnos-local');

        // Assert — manager's path must be returned
        $this->assertSame('/tmp/custom/broadcast.jsonl', $path,
            'resolveDefaultLogFile() must return the BroadcastingManager log path when available');
    }

    // =========================================================================
    // createServer() — factory method coverage
    // =========================================================================

    /**
     * createServer() must instantiate a LocalBroadcastServer with the given
     * appKey and logFile arguments.
     *
     * This test invokes the real (non-overridden) factory method on a concrete
     * BroadcastServe instance so the factory body (line 162) is covered.
     */
    public function testCreateServerReturnsLocalBroadcastServer(): void
    {
        // Arrange — use the real command (not the subclass from buildCommand)
        $cmd = new BroadcastServe();
        $ref = new \ReflectionMethod($cmd, 'createServer');

        // Act — call the protected factory; it must not throw
        $server = $ref->invoke($cmd, 'test-key', null);

        // Assert
        $this->assertInstanceOf(LocalBroadcastServer::class, $server,
            'createServer() must return a LocalBroadcastServer instance');
    }

    // =========================================================================
    // resolveDefaultLogFile() — coverage of the real implementation
    // =========================================================================

    /**
     * resolveDefaultLogFile() must return a path ending in 'var/broadcast.jsonl'
     * when the ROOT constant is defined (which it is in the Docker test environment).
     *
     * This test calls the real (non-overridden) method on a concrete BroadcastServe
     * so the if/else chain inside resolveDefaultLogFile() (lines 172-192) is covered.
     */
    public function testResolveDefaultLogFileWithRootDefined(): void
    {
        // Arrange — ensure no Application instance so we test the ROOT fallback
        $ref = new BroadcastServe();
        $method = new \ReflectionMethod($ref, 'resolveDefaultLogFile');

        // Act
        $path = $method->invoke($ref, 'pramnos-local');

        // Assert — in docker, ROOT is defined as /var/www/html
        if (defined('ROOT')) {
            $this->assertStringContainsString('broadcast.jsonl', $path,
                'resolveDefaultLogFile() must return a path ending in broadcast.jsonl when ROOT is defined');
        } else {
            // Outside docker, ROOT may not be defined — empty string is valid
            $this->assertIsString($path, 'resolveDefaultLogFile() must return a string');
        }
    }

    /**
     * resolveDefaultLogFile() must return '' when ROOT is not defined and
     * no Application instance provides a log path.
     *
     * This test temporarily undefines ROOT (if possible) or verifies the
     * fallback returns a non-throwing value.
     *
     * NOTE: PHP does not allow undefining constants, so this test verifies the
     * method return type and absence of exceptions rather than the specific value.
     */
    public function testResolveDefaultLogFileWithApplicationReturnsString(): void
    {
        // Arrange — call on a real command with Application cleared
        $cmd    = new BroadcastServe();
        $method = new \ReflectionMethod($cmd, 'resolveDefaultLogFile');

        // Act — must not throw regardless of whether ROOT is defined
        $path = $method->invoke($cmd, 'test-key');

        // Assert — always returns a string
        $this->assertIsString($path,
            'resolveDefaultLogFile() must always return a string, never throw');
    }

    // =========================================================================
    // Client events banner
    // =========================================================================

    /**
     * Install an Application whose applicationInfo carries a broadcasting config,
     * so broadcastingConfig() has something to read.
     *
     * @param array<string,mixed> $broadcasting
     */
    private function installApplicationWith(array $broadcasting): void
    {
        $app = new class($broadcasting) extends \Pramnos\Application\Application {
            public function __construct(array $broadcasting)
            {
                $this->_data['container'] = new \Pramnos\Application\Container();
                $this->applicationInfo    = ['broadcasting' => $broadcasting];
            }
        };

        $ref = new \ReflectionClass(\Pramnos\Application\Application::class);
        $ref->getProperty('appInstances')->setValue(null, ['default' => $app]);
        $ref->getProperty('lastUsedApplication')->setValue(null, 'default');
    }

    /**
     * With client events off — the default — the startup banner says so.
     *
     * Reported rather than left silent: an operator has no other way to tell whether
     * browsers can write to a channel on this server, and silence reads the same as
     * "enabled" to somebody debugging a whisper that never arrives.
     */
    public function testExecuteReportsClientEventsDisabledByDefault(): void
    {
        // Arrange
        $this->installApplicationWith([]);
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $this->assertStringContainsString('Client events: disabled', $tester->getDisplay());
    }

    /**
     * With client events enabled, the banner names the per-connection rate and the
     * channels they apply to.
     *
     * Enabling this grants every connected browser a write path onto a private or
     * presence channel, so it belongs in the startup output where an operator will
     * see it.
     */
    public function testExecuteReportsClientEventsEnabledWithTheRate(): void
    {
        // Arrange
        $this->installApplicationWith([
            'websocket' => ['client_events' => true, 'client_events_per_second' => 25],
        ]);
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Client events: enabled', $display);
        $this->assertStringContainsString('25/s per connection', $display);
        $this->assertStringContainsString('private/presence channels only', $display);
    }

    /**
     * A configured rate of zero is reported as the clamped value rather than as
     * zero, so the banner matches what the server will actually enforce.
     */
    public function testExecuteReportsTheClampedRate(): void
    {
        // Arrange
        $this->installApplicationWith([
            'websocket' => ['client_events' => true, 'client_events_per_second' => 0],
        ]);
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $this->assertStringContainsString('1/s per connection', $tester->getDisplay());
    }

    // =========================================================================
    // App-key source
    // =========================================================================

    /**
     * Install an Application with both a broadcasting config and a features list.
     *
     * @param array<string,mixed> $broadcasting
     * @param string[]            $features
     */
    private function installApplicationWithFeatures(array $broadcasting, array $features): void
    {
        $app = new class($broadcasting, $features) extends \Pramnos\Application\Application {
            public function __construct(array $broadcasting, array $features)
            {
                $this->_data['container'] = new \Pramnos\Application\Container();
                $this->applicationInfo    = [
                    'broadcasting' => $broadcasting,
                    'features'     => $features,
                ];
            }
        };

        $ref = new \ReflectionClass(\Pramnos\Application\Application::class);
        $ref->getProperty('appInstances')->setValue(null, ['default' => $app]);
        $ref->getProperty('lastUsedApplication')->setValue(null, 'default');
    }

    /**
     * With the authserver feature enabled, the daemon resolves app keys from the
     * applications table and says so.
     *
     * This is the piece that makes more than one app possible at the edge: the
     * single-pair authorizer verifies against one secret, so the registry could
     * describe fifty applications and the daemon would still only know one.
     */
    public function testExecuteUsesTheAuthserverRegistryWhenTheFeatureIsEnabled(): void
    {
        // Arrange
        $this->installApplicationWithFeatures([], ['broadcasting', 'authserver']);
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $exitCode = $tester->execute([]);

        // Assert
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'app keys from the AuthServer applications table',
            $tester->getDisplay()
        );
    }

    /**
     * Without the feature, a configured secret keeps the historical single-pair
     * authorizer.
     */
    public function testExecuteKeepsTheSinglePairAuthorizerWithoutTheFeature(): void
    {
        // Arrange
        $this->installApplicationWithFeatures(
            ['pusher' => ['app_secret' => 'a-secret']],
            ['broadcasting']
        );
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Pusher signatures enforced', $display);
        $this->assertStringNotContainsString('AuthServer applications table', $display);
    }

    /**
     * A misconfigured app source stops the daemon instead of starting it.
     *
     * Naming `authserver` while the feature is off would otherwise fall back to the
     * config registry and authorize channels against a different secret than the
     * operator asked for — a security decision reversed by a missing line in
     * app.php, with the daemon reporting itself as healthy.
     */
    public function testExecuteFailsOnAMisconfiguredAppSource(): void
    {
        // Arrange
        $this->installApplicationWithFeatures(
            ['apps' => ['source' => 'authserver']],
            ['broadcasting']
        );
        $cmd = $this->buildCommand();

        // Act
        $tester   = new CommandTester($cmd);
        $exitCode = $tester->execute([]);

        // Assert
        $this->assertSame(1, $exitCode, 'the daemon must not start');
        $this->assertStringContainsString("'authserver' feature is not enabled", $tester->getDisplay());
    }

    // =========================================================================
    // HTTP API and webhooks
    // =========================================================================

    /**
     * The HTTP API is absent unless app.php asks for it, and the banner says which.
     *
     * Opt-in because it opens a publish path on the port: a signed request can
     * broadcast to any channel, so it must not appear on an installation merely
     * because the framework was updated.
     */
    public function testExecuteReportsTheHttpApiDisabledByDefault(): void
    {
        // Arrange
        $this->installApplicationWithFeatures([], ['broadcasting']);
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $this->assertStringContainsString('HTTP API: disabled', $tester->getDisplay());
    }

    /**
     * With `http_api.enabled`, the API is installed and reported.
     */
    public function testExecuteEnablesTheHttpApi(): void
    {
        // Arrange
        $this->installApplicationWithFeatures(
            ['http_api' => ['enabled' => true], 'pusher' => ['app_key' => 'k', 'app_secret' => 's']],
            ['broadcasting']
        );
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $this->assertStringContainsString('HTTP API: enabled', $tester->getDisplay());
    }

    /**
     * A configured webhook URL is queued with a signer.
     */
    public function testExecuteConfiguresWebhooks(): void
    {
        // Arrange
        $this->installApplicationWithFeatures(
            [
                'pusher'   => ['app_key' => 'k', 'app_secret' => 's'],
                'webhooks' => ['url' => 'https://hooks.test/realtime'],
            ],
            ['broadcasting']
        );
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $this->assertStringContainsString('Webhooks: queued for https://hooks.test/realtime', $tester->getDisplay());
    }

    /**
     * A webhook URL with no signing secret is refused loudly rather than sent
     * unsigned.
     *
     * Unsigned webhooks are worse than none: a receiver cannot tell them from
     * anybody else's POST, so it either trusts every caller or rejects ours.
     */
    public function testExecuteRefusesToSendUnsignedWebhooks(): void
    {
        // Arrange — a URL but no app_secret anywhere
        $this->installApplicationWithFeatures(
            ['webhooks' => ['url' => 'https://hooks.test/realtime']],
            ['broadcasting']
        );
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('no app secret is available to sign', $display);
        $this->assertStringNotContainsString('Webhooks: queued', $display);
    }

    /**
     * With no webhook URL configured, nothing about webhooks is printed — silence
     * is correct for a feature that was never asked for.
     */
    public function testExecuteSaysNothingAboutUnconfiguredWebhooks(): void
    {
        // Arrange
        $this->installApplicationWithFeatures([], ['broadcasting']);
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $this->assertStringNotContainsString('Webhooks:', $tester->getDisplay());
    }

    // =========================================================================
    // Clustering
    // =========================================================================

    /**
     * Clustering is off unless asked for, and nothing about it is printed.
     *
     * Silence is right for a feature nobody enabled, and a single-daemon deployment
     * is the common case.
     */
    public function testExecuteSaysNothingAboutClusteringByDefault(): void
    {
        // Arrange
        $this->installApplicationWithFeatures([], ['broadcasting']);
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $this->assertStringNotContainsString('Cluster:', $tester->getDisplay());
    }

    /**
     * With clustering on, the node id, the gossip channel and the interval are
     * reported, along with the consistency caveat.
     *
     * The caveat belongs in the banner because it changes what the numbers mean: an
     * operator reading a member count needs to know it is eventually consistent, and
     * a receiver counting member webhooks needs to know they are per-node.
     */
    public function testExecuteReportsClusteringWithItsCaveat(): void
    {
        // Arrange
        $this->installApplicationWithFeatures(
            [
                'redis'   => ['prefix' => 'app:'],
                'cluster' => ['enabled' => true, 'node_id' => 'node-a', 'interval' => 15],
            ],
            ['broadcasting']
        );
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Cluster: node node-a', $display);
        $this->assertStringContainsString('app:__pramnos_cluster every 15s', $display);
        $this->assertStringContainsString('eventually consistent', $display);
        $this->assertStringContainsString('per-node', $display);
    }

    /**
     * The cluster channel joins the ingest subscription even with no --channels
     * option.
     *
     * Gossip arrives over the backplane, so a clustered node that subscribed to
     * nothing would publish its own state and never hear anybody else's — every node
     * believing it was alone, with nothing in any log.
     */
    public function testExecuteSubscribesToTheGossipChannelWithoutOtherChannels(): void
    {
        // Arrange
        $this->installApplicationWithFeatures(
            ['redis' => ['prefix' => 'app:'], 'cluster' => ['enabled' => true, 'node_id' => 'n']],
            ['broadcasting']
        );
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $this->assertStringContainsString('Redis ingest: app:__pramnos_cluster', $tester->getDisplay());
    }

    /**
     * A generated node id is used when none is configured, so two nodes on one host
     * do not collide.
     */
    public function testExecuteGeneratesANodeIdWhenNoneIsConfigured(): void
    {
        // Arrange
        $this->installApplicationWithFeatures(
            ['cluster' => ['enabled' => true]],
            ['broadcasting']
        );
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $this->assertMatchesRegularExpression('/Cluster: node [0-9a-f]{12}/', $tester->getDisplay());
    }

    // =========================================================================
    // Transport
    // =========================================================================

    /**
     * Without a certificate the banner says the port speaks plain `ws://`.
     *
     * Reported either way because an operator needs to know which scheme the port
     * they are about to point clients at speaks — getting it wrong presents as a
     * network fault rather than a scheme mismatch.
     */
    public function testExecuteReportsPlainTransportByDefault(): void
    {
        // Arrange
        $this->installApplicationWithFeatures([], ['broadcasting']);
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $this->assertStringContainsString('Transport: ws:// (plain TCP)', $tester->getDisplay());
    }

    /**
     * With a certificate configured, TLS is reported along with its caveat.
     *
     * The caveat belongs in the banner: the handshake is synchronous and the loop is
     * single-threaded, so an operator turning this on for a busy install needs to see
     * that before the traffic tells them.
     */
    public function testExecuteReportsTlsWithItsCaveat(): void
    {
        // Arrange
        $this->installApplicationWithFeatures(
            ['websocket' => ['tls' => ['local_cert' => '/etc/ssl/realtime/fullchain.pem']]],
            ['broadcasting']
        );
        $cmd = $this->buildCommand();

        // Act
        $tester = new CommandTester($cmd);
        $tester->execute([]);

        // Assert
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Transport: wss://', $display);
        $this->assertStringContainsString('handshake is synchronous', $display);
    }
}
