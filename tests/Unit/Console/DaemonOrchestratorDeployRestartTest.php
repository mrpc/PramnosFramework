<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\DaemonOrchestrator;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A deploy (a new commit, or migrations) restarts every daemon but the WebSocket server, which
 * drops every open realtime connection when it restarts: it is left running while its own code —
 * its fingerprint — is what it started with, and stopped like the rest once that changed. A daemon
 * with no fingerprint recorded (adopted, or recorded before fingerprints) is restarted, to be sure.
 */
#[CoversClass(DaemonOrchestrator::class)]
final class DaemonOrchestratorDeployRestartTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos_deploy_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/var/logs', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/var/*') ?: [] as $file) {
            is_dir($file) ? @rmdir($file) : @unlink($file);
        }
        @rmdir($this->tmpDir . '/var/logs');
        @rmdir($this->tmpDir . '/var');
        @rmdir($this->tmpDir);
    }

    /** An orchestrator supervising a worker and the WebSocket server, its fingerprint `$fingerprint` now. */
    private function orchestrator(string $fingerprint): DaemonOrchestrator
    {
        return new class($this->tmpDir, $fingerprint) extends DaemonOrchestrator {
            public function __construct(private string $baseDir, private string $fingerprint)
            {
                parent::__construct();
            }

            protected function buildDesiredProcesses(): array
            {
                return [
                    ['id' => 'worker', 'daemon' => 'worker', 'workerId' => 'worker-1', 'lockFile' => $this->baseDir . '/var/worker.lock', 'tokens' => ['queue:work']],
                    ['id' => 'broadcast', 'daemon' => 'broadcast', 'workerId' => 'broadcast-1', 'lockFile' => $this->baseDir . '/var/broadcast.lock',
                        'tokens' => ['broadcast:serve'], 'restartOnDeploy' => false, 'fingerprint' => $this->fingerprint],
                ];
            }

            protected function includeScheduler(): bool { return false; }

            protected function includeBroadcastServer(): bool { return false; }

            protected function getDashboardTitle(): string { return 'TEST'; }

            protected function getEntryPoint(): string { return $this->baseDir . '/console'; }

            protected function getStateFile(): string { return $this->baseDir . '/var/state.json'; }

            /** The deploy restart, from outside. */
            public function deploy(BufferedOutput $output): void { $this->requestDeployRestart($output); }

            /** @param array<int, array<string, mixed>> $state */
            public function seed(array $state): void { $this->saveState($state); }
        };
    }

    /** The state of both daemons running, the server started on `$fingerprint`. */
    private function running(string $fingerprint): array
    {
        return [
            ['id' => 'worker', 'daemon' => 'worker', 'workerId' => 'worker-1', 'pid' => 11, 'lockFile' => $this->tmpDir . '/var/worker.lock', 'fingerprint' => '', 'updatedAt' => date('c')],
            ['id' => 'broadcast', 'daemon' => 'broadcast', 'workerId' => 'broadcast-1', 'pid' => 12, 'lockFile' => $this->tmpDir . '/var/broadcast.lock', 'fingerprint' => $fingerprint, 'updatedAt' => date('c')],
        ];
    }

    /**
     * The application deployed, the server's code the same: the worker is stopped, the server
     * left running and said so.
     */
    public function testADeployOfTheApplicationLeavesTheServerRunning(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator('F1');
        $orchestrator->seed($this->running('F1'));
        $output = new BufferedOutput();

        // Act
        $orchestrator->deploy($output);

        // Assert
        $this->assertFileExists($this->tmpDir . '/var/worker.lock.stop');
        $this->assertFileDoesNotExist($this->tmpDir . '/var/broadcast.lock.stop', 'its open connections stay');
        $this->assertStringContainsString('broadcast left running', $output->fetch());
    }

    /** The server's own code changed — or nobody knows what it started with — and it stops too. */
    public function testTheServerStopsWhenItsOwnCodeChanged(): void
    {
        foreach (['F1' => 'F2', '' => 'F2'] as $started => $now) {
            // Arrange
            @unlink($this->tmpDir . '/var/broadcast.lock.stop');
            $orchestrator = $this->orchestrator($now);
            $orchestrator->seed($this->running($started));

            // Act
            $orchestrator->deploy(new BufferedOutput());

            // Assert
            $this->assertFileExists($this->tmpDir . '/var/broadcast.lock.stop', 'started on "' . $started . '"');
        }
    }

    /** The fingerprint is the code on disk now: the same twice, and a hash. */
    public function testTheFingerprintIsTheCodeOnDisk(): void
    {
        // Act
        $first  = DaemonOrchestrator::broadcastFingerprint();
        $second = DaemonOrchestrator::broadcastFingerprint();

        // Assert
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        $this->assertSame($first, $second);
    }
}
