<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Exposes Init's protected port helpers so they can be exercised directly,
 * without driving the whole interactive wizard.
 */
class InitPortProbe extends Init
{
    /** Public shim over Init::isPortAvailable(). */
    public function canBind(int $port): bool
    {
        return $this->isPortAvailable($port);
    }

    /**
     * Public shim over Init::busyPorts().
     *
     * @return list<int>
     */
    public function takenPorts(int $port): array
    {
        return $this->busyPorts($port);
    }

    /** Public shim over Init::findAvailablePortPair(). */
    public function suggestPort(int $start): int
    {
        return $this->findAvailablePortPair($start);
    }
}

/**
 * Covers the host-port selection behind `pramnos init --docker=y`.
 *
 * A generated docker-compose.yml publishes TWO host ports: $port for the
 * application and $port + 1 for the database tool (Adminer/PHPMyAdmin).
 * Checking only the first one is what allowed init to run for minutes and then
 * die inside "docker-compose up" with:
 *
 *     Bind for 0.0.0.0:8081 failed: port is already allocated
 */
class InitPortSelectionTest extends TestCase
{
    /** @var list<resource> Sockets held open for the duration of one test. */
    private array $sockets = [];

    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    /**
     * Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] in configure()
     * and hands it to basename(). The CLI always populates it, but another test
     * in the same process may have removed it — which turns building a console
     * Application here into an "undefined array key" warning plus a basename()
     * deprecation. Guard it the same way InitCommandUnitTest does.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
    }

    /**
     * Release every port a test occupied, so later tests see a clean host, and
     * restore PHP_SELF exactly as it was found.
     */
    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            fclose($socket);
        }
        $this->sockets = [];

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
        parent::tearDown();
    }

    /**
     * Occupy a port for the rest of the test, exactly as another service (or a
     * Docker port proxy) would.
     */
    private function occupy(int $port): void
    {
        $socket = @stream_socket_server(
            "tcp://0.0.0.0:$port",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if ($socket === false) {
            $this->markTestSkipped("could not occupy port $port for the test: $errstr");
        }
        $this->sockets[] = $socket;
    }

    /**
     * A port nothing holds is bindable; the same port becomes unavailable the
     * moment something binds it. This is the primitive every other decision
     * rests on, so it has to reflect a real bind rather than a connect attempt.
     */
    public function testPortAvailabilityFollowsActualBinds(): void
    {
        // Arrange
        $probe = new InitPortProbe();
        $port  = $probe->suggestPort(9300);

        // Act + Assert — free before, taken after
        $this->assertTrue($probe->canBind($port), 'a free port must be reported available');
        $this->occupy($port);
        $this->assertFalse($probe->canBind($port), 'a bound port must be reported unavailable');
    }

    /**
     * The reported bug, reduced: the application port is free but the database
     * tool's port (base + 1) is not. The old check looked only at the base port
     * and happily proposed it.
     */
    public function testToolPortConflictIsDetected(): void
    {
        // Arrange — leave the base free, take the port the tool container needs
        $probe = new InitPortProbe();
        $base  = $probe->suggestPort(9400);
        $this->occupy($base + 1);

        // Act
        $busy = $probe->takenPorts($base);

        // Assert — the conflict is found, and named precisely
        $this->assertSame([$base + 1], $busy);
    }

    /** Both ports taken are both reported, so the message can name them. */
    public function testBothBusyPortsAreReported(): void
    {
        // Arrange
        $probe = new InitPortProbe();
        $base  = $probe->suggestPort(9500);
        $this->occupy($base);
        $this->occupy($base + 1);

        // Act + Assert
        $this->assertSame([$base, $base + 1], $probe->takenPorts($base));
    }

    /** A usable pair reports nothing busy. */
    public function testFreePairReportsNothingBusy(): void
    {
        // Arrange
        $probe = new InitPortProbe();

        // Act + Assert
        $this->assertSame([], $probe->takenPorts($probe->suggestPort(9600)));
    }

    /**
     * An explicit --docker-port is honoured (the caller may know the conflict
     * is about to clear) but must be reported, naming which port is taken and
     * what each one is for — instead of the wizard running for minutes and
     * failing inside "docker-compose up".
     */
    public function testExplicitBusyPortIsHonouredButWarnedAbout(): void
    {
        // Arrange — a project workspace and a base port whose tool port is taken
        $tmpDir = sys_get_temp_dir() . '/pramnos_init_port_' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0777, true);
        file_put_contents($tmpDir . '/composer.json', json_encode(['name' => 'test/app']));

        $probe = new InitPortProbe();
        $base  = $probe->suggestPort(9800);
        $this->occupy($base + 1);

        $command = new Init();
        $command->targetBaseDir  = $tmpDir;
        $command->skipDockerRun  = true;
        $command->scaffoldingDir = dirname(__DIR__, 3) . '/scaffolding';

        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($command);

        try {
            // Act
            $tester->execute([
                '--app-name'    => 'PortApp',
                // Scaffolding is the subject here; installing dependencies and
                // fetching assets over the network are not. See the test suite
                // performance guide: they were 85% of this class's runtime.
                '--no-install'  => true,
                '--no-download' => true,
                '--namespace'   => 'PortApp',
                '--features'    => '',
                '--ui-system'   => 'plain-css',
                '--docker'      => 'y',
                '--docker-port' => (string) $base,
                '--cache-system' => 'none',
                '--libraries'   => '',
                '--db-type'     => 'postgresql',
                '--db-host'     => 'db',
                '--db-name'     => 'portapp_db',
                '--db-user'     => 'portapp',
                '--db-pass'     => 'secret',
                '--db-prefix'   => '',
            ], ['interactive' => false]);

            // Assert — warned about the tool port, and the choice still applied
            $display = $tester->getDisplay();
            $this->assertStringContainsString('already in use', $display);
            $this->assertStringContainsString((string) ($base + 1), $display);
            $this->assertStringContainsString(
                "\"$base:80\"",
                file_get_contents($tmpDir . '/docker-compose.yml'),
                'the explicitly requested port is still used'
            );
        } finally {
            $this->rmdir($tmpDir);
        }
    }

    /**
     * Recursively delete a directory tree created by a test.
     */
    private function rmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->rmdir($full) : unlink($full);
        }
        rmdir($path);
    }

    /**
     * The suggested default skips a base whose tool port is taken — otherwise
     * init would keep proposing a port pair that cannot come up.
     */
    public function testSuggestionSkipsPairsWithABusyToolPort(): void
    {
        // Arrange — make the first candidate unusable via its +1 only
        $probe = new InitPortProbe();
        $start = $probe->suggestPort(9700);
        $this->occupy($start + 1);

        // Act
        $suggested = $probe->suggestPort($start);

        // Assert — moved past the conflict, and what it landed on is usable
        $this->assertGreaterThan($start, $suggested);
        $this->assertSame([], $probe->takenPorts($suggested));
    }
}
