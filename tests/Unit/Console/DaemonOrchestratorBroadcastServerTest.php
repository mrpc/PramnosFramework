<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\DaemonOrchestrator;

/**
 * An orchestrator supervises the WebSocket daemon when the application serves over one.
 *
 * `broadcast:serve` is the process that turns a published event into a frame in a
 * browser. Without it every subscription is a perfectly healthy socket that never
 * receives anything — the publish succeeded, the channel exists, the client connected,
 * and there is no error anywhere to say what is missing.
 *
 * Exactly the shape the framework's own schedule worker was in before it was declared
 * here: something the framework needs running, left to each application to remember, and
 * silent in every application that did not.
 */
#[CoversClass(DaemonOrchestrator::class)]
class DaemonOrchestratorBroadcastServerTest extends TestCase
{
    protected function setUp(): void
    {
        // Symfony's completion command reads PHP_SELF while commands are configured.
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
    }

    /**
     * An orchestrator whose transport answer and process list the test controls.
     *
     * `includeBroadcastServer()` is overridden rather than driven through configuration,
     * because reading `broadcasting.transport` means an Application, and what is under
     * test is the supervision decision rather than where the answer comes from.
     *
     * @param array<int, array<string, mixed>> $processes
     */
    private function orchestrator(array $processes, bool $websocket): DaemonOrchestrator
    {
        return new class($processes, $websocket) extends DaemonOrchestrator {
            /** @param array<int, array<string, mixed>> $processes */
            public function __construct(
                private array $processes,
                private bool $websocket
            ) {
                parent::__construct();
            }

            protected function buildDesiredProcesses(): array
            {
                return $this->processes;
            }

            /** The schedule is not what this file is about. */
            protected function includeScheduler(): bool
            {
                return false;
            }

            protected function includeBroadcastServer(): bool
            {
                return $this->websocket;
            }

            protected function getDashboardTitle(): string
            {
                return ' TEST ';
            }

            protected function getEntryPoint(): string
            {
                return '/app/console.php';
            }

            protected function getJobName(): string
            {
                return 'test-orchestrator.lock';
            }

            /** @return array<int, array<string, mixed>> */
            public function desired(): array
            {
                return $this->collectDesiredProcesses();
            }
        };
    }

    /** @param array<int, array<string, mixed>> $processes */
    private function ids(array $processes): array
    {
        return array_map(static fn(array $p): string => (string) ($p['id'] ?? ''), $processes);
    }

    /**
     * On the WebSocket transport, the daemon is supervised without being asked for.
     *
     * The regression test for the whole finding: this is the shape a scaffolded project
     * has after turning realtime on, and it supervised nothing that could deliver a frame.
     */
    public function testTheWebSocketDaemonIsAddedWhenTheTransportIsWebSocket(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator([
            ['id' => 'importer', 'tokens' => ['import:run']],
        ], websocket: true);

        // Act
        $desired = $orchestrator->desired();

        // Assert
        $this->assertSame(['importer', 'broadcast'], $this->ids($desired));
    }

    /**
     * On any other transport, nothing is added.
     *
     * An application on SSE needs no daemon, and one it never asked for would sit failing
     * to bind a port and reporting itself unhealthy for ever — a permanent error in a
     * dashboard, about a process nobody wanted.
     */
    public function testNothingIsAddedOnAnotherTransport(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator([
            ['id' => 'importer', 'tokens' => ['import:run']],
        ], websocket: false);

        // Act & Assert
        $this->assertSame(['importer'], $this->ids($orchestrator->desired()));
    }

    /**
     * An application already supervising it keeps its own entry.
     *
     * Recognised by token, so an application passing `--channels`, a certificate or a
     * different port keeps exactly the entry it wrote. Two entries would not corrupt
     * anything — the second cannot bind the port — but it would report itself unhealthy
     * for ever, which is worse than useless in a dashboard.
     */
    public function testAnApplicationsOwnEntryIsKept(): void
    {
        // Arrange
        $own = [
            'id'     => 'ws',
            'tokens' => ['broadcast:serve', '--port=7001', '--channels=chat'],
        ];
        $orchestrator = $this->orchestrator([$own], websocket: true);

        // Act
        $desired = $orchestrator->desired();

        // Assert — one entry, and it is theirs
        $this->assertSame(['ws'], $this->ids($desired));
        $this->assertSame($own['tokens'], $desired[0]['tokens']);
    }

    /**
     * An entry written as a shell command is recognised too.
     *
     * Not every supervised process is declared with tokens; some are a command line. A
     * duplicate would be just as unhealthy either way.
     */
    public function testAShellCommandEntryIsRecognised(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator([
            ['id' => 'ws', 'shellCommand' => 'php console.php broadcast:serve --port=7001'],
        ], websocket: true);

        // Act & Assert
        $this->assertSame(['ws'], $this->ids($orchestrator->desired()));
    }

    /**
     * The framework's entry carries what the supervisor needs.
     *
     * A lock file of its own, so the orchestrator's health checks and its cooperative
     * stop apply — `broadcast:serve` wires the stop sentinel itself, and an entry without
     * a lock file would be reported `[stop-timeout]` on every deploy.
     */
    public function testTheEntryIsSupervisable(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator([], websocket: true);

        // Act
        $entry = $orchestrator->desired()[0];

        // Assert
        $this->assertSame(['broadcast:serve'], $entry['tokens']);
        $this->assertNotEmpty($entry['lockFile']);
        $this->assertNotEmpty($entry['workerId']);
        $this->assertStringContainsString('broadcast', $entry['lockFile']);
    }

    /**
     * Its lock file is not the scheduler's.
     *
     * Two daemons sharing a single-instance lock means the second never starts, and
     * reports itself as a failed start for ever rather than as a conflict.
     */
    public function testItDoesNotShareTheSchedulersLock(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator([], websocket: true);

        // Act
        $entry = $orchestrator->desired()[0];

        // Assert
        $this->assertStringNotContainsString('pramnos-work.lock', (string) $entry['lockFile']);
    }
}
