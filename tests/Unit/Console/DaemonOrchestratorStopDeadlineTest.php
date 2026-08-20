<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\DaemonOrchestrator;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A stop request has a deadline, wherever it came from.
 *
 * The teardown path — a process that has left the desired set — records `stoppingAt` and
 * escalates to SIGTERM once the grace period expires. The two paths that stop *desired*
 * processes recorded nothing: a redeploy (`requestStopAll()` on a git HEAD change, followed
 * immediately by a re-exec that remembers only the state file) and the orchestrator being
 * disabled. So the grace period ten lines away never started, and a daemon that does not
 * poll its sentinel was never stopped, never signalled, and reported as healthy throughout.
 *
 * Reported from a project where `realtime:serve` ran for **1h32m across three deploys** with
 * its `.stop` sentinel on disk the whole time — the one worker bridging Redis to every
 * WebSocket client, running the old code, while the log said `[ok]`.
 *
 * Two things were missing and both are here: the deadline is recorded when the stop is
 * asked for **and** started by reconcile if it finds a sentinel without one, and a `.stop`
 * counts as "not healthy" whatever `requireLockFile` says — because the sentinel is the
 * orchestrator's instruction to that process, not a fact about its lock.
 */
#[CoversClass(DaemonOrchestrator::class)]
class DaemonOrchestratorStopDeadlineTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $this->tmpDir = sys_get_temp_dir() . '/pramnos_stopdl_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/var/logs', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/var/*') ?: [] as $file) {
            is_dir($file) ? array_map('unlink', glob($file . '/*') ?: []) : @unlink($file);
            @is_dir($file) && @rmdir($file);
        }
        @rmdir($this->tmpDir . '/var');
        @rmdir($this->tmpDir);
    }

    /**
     * An orchestrator supervising one daemon, with the process table scripted.
     *
     * @param  bool $requireLockFile What the application declared
     * @return DaemonOrchestrator&object
     */
    private function orchestrator(bool $requireLockFile = true): DaemonOrchestrator
    {
        $lockFile = $this->tmpDir . '/var/worker.lock';

        return new class($this->tmpDir, $lockFile, $requireLockFile) extends DaemonOrchestrator {
            /** @var array<int, bool> pid => alive */
            public array $processRunning = [];

            /** @var list<int> pids that were signalled */
            public array $signalled = [];

            public function __construct(
                private string $baseDir,
                public string $lockFile,
                private bool $requireLock
            ) {
                parent::__construct();
            }

            protected function buildDesiredProcesses(): array
            {
                return [[
                    'id'              => 'realtime',
                    'daemon'          => 'realtime',
                    'workerId'        => 'realtime-1',
                    'lockFile'        => $this->lockFile,
                    'tokens'          => ['realtime:serve'],
                    'requireLockFile' => $this->requireLock,
                ]];
            }

            protected function includeScheduler(): bool
            {
                return false;
            }

            protected function getDashboardTitle(): string
            {
                return ' TEST ';
            }

            protected function getEntryPoint(): string
            {
                return $this->baseDir . '/console';
            }

            protected function getJobName(): string
            {
                return 'test-orchestrator.lock';
            }

            protected function getStateFile(): string
            {
                return $this->baseDir . '/var/state.json';
            }

            protected function getProcessLogFile(array $desiredProcess): string
            {
                return $this->baseDir . '/var/logs/test.log';
            }

            protected function isProcessRunning(int $pid): bool
            {
                return $this->processRunning[$pid] ?? false;
            }

            protected function startDesiredProcess(string $phpBinary, array $desiredProcess): int
            {
                return 0;   // nothing is spawned in these tests
            }

            public function publicReconcile(BufferedOutput $output): void
            {
                $this->reconcile('php', false, $output);
            }

            public function publicRequestStopAll(BufferedOutput $output): void
            {
                $this->requestStopAll($output);
            }

            public function publicSaveState(array $state): void
            {
                $this->saveState($state);
            }

            public function publicLoadState(): array
            {
                return $this->loadState();
            }
        };
    }

    /**
     * A state entry for the supervised worker.
     *
     * @param  string|null $stoppingAt When a stop was asked for, if it was
     * @return array<int, array<string, mixed>>
     */
    private function state(?string $stoppingAt = null): array
    {
        $entry = [
            'id'        => 'realtime',
            'daemon'    => 'realtime',
            'workerId'  => 'realtime-1',
            'pid'       => 4242,
            'lockFile'  => $this->tmpDir . '/var/worker.lock',
            'updatedAt' => date('c'),
        ];

        if ($stoppingAt !== null) {
            $entry['stoppingAt'] = $stoppingAt;
        }

        return [$entry];
    }

    /**
     * `requestStopAll()` records when it asked, so the deadline survives the re-exec.
     *
     * A redeploy calls this and then replaces the process. The new image knows only what is
     * in the state file — which is why the grace period could never start.
     *
     * @return void
     */
    public function testRequestStopAllRecordsWhenItAsked(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator();
        $orchestrator->publicSaveState($this->state());
        touch($orchestrator->lockFile);

        // Act
        $orchestrator->publicRequestStopAll(new BufferedOutput());

        // Assert — the sentinel is on disk, and so is the deadline
        $this->assertFileExists($orchestrator->lockFile . '.stop');
        $this->assertArrayHasKey('stoppingAt', $orchestrator->publicLoadState()[0]);
    }

    /**
     * A worker still running after the grace period is signalled.
     *
     * The regression test. Before this the branch did not exist: a desired process with a
     * stop sentinel was `[waiting]` for ever.
     *
     * @return void
     */
    public function testAWorkerThatIgnoresTheSentinelIsSignalled(): void
    {
        // Arrange — asked to stop well over the grace period ago, still alive
        $orchestrator = $this->orchestrator();
        $orchestrator->processRunning[4242] = true;
        $orchestrator->publicSaveState($this->state(date('c', time() - 600)));
        touch($orchestrator->lockFile);
        touch($orchestrator->lockFile . '.stop');

        // Act
        $output = new BufferedOutput();
        $orchestrator->publicReconcile($output);

        // Assert — reported as its own thing, and the sentinel cleared so the next
        // cycle can start a fresh worker
        $out = $output->fetch();
        $this->assertStringContainsString('[stop-timeout]', $out);
        $this->assertStringContainsString('realtime', $out);
        $this->assertFileDoesNotExist($orchestrator->lockFile . '.stop');
    }

    /**
     * Inside the grace period it waits, and says how long is left.
     *
     * A daemon that honours the sentinel exits long before the deadline and never sees it;
     * escalating early would kill work that was about to finish cleanly.
     *
     * @return void
     */
    public function testInsideTheGracePeriodItWaits(): void
    {
        // Arrange — asked to stop a second ago
        $orchestrator = $this->orchestrator();
        $orchestrator->processRunning[4242] = true;
        $orchestrator->publicSaveState($this->state(date('c', time() - 1)));
        touch($orchestrator->lockFile);
        touch($orchestrator->lockFile . '.stop');

        // Act
        $output = new BufferedOutput();
        $orchestrator->publicReconcile($output);

        // Assert
        $out = $output->fetch();
        $this->assertStringContainsString('[waiting]', $out);
        $this->assertStringContainsString('before SIGTERM', $out);
        $this->assertStringNotContainsString('[stop-timeout]', $out);
        $this->assertFileExists($orchestrator->lockFile . '.stop');
    }

    /**
     * A sentinel that arrived without a deadline gets one.
     *
     * Belt and braces for the case the report is actually about: a state file written by an
     * older release, or a sentinel an operator touched by hand. Nothing is signalled on this
     * pass — the clock starts here.
     *
     * @return void
     */
    public function testASentinelWithNoRecordedDeadlineStartsOne(): void
    {
        // Arrange — a stop on disk, and a state entry that never heard about it
        $orchestrator = $this->orchestrator();
        $orchestrator->processRunning[4242] = true;
        $orchestrator->publicSaveState($this->state());
        touch($orchestrator->lockFile);
        touch($orchestrator->lockFile . '.stop');

        // Act
        $output = new BufferedOutput();
        $orchestrator->publicReconcile($output);

        // Assert
        $this->assertStringContainsString('[waiting]', $output->fetch());
        $this->assertArrayHasKey(
            'stoppingAt',
            $orchestrator->publicLoadState()[0],
            'the deadline has to start somewhere, or the sentinel is only advisory'
        );
    }

    /**
     * A daemon that keeps no lock is still subject to its stop sentinel.
     *
     * `requireLockFile => false` used to force the health check true, so the sentinel was
     * never even read: the reported worker was `[ok]` for 1h32m with a `.stop` beside it.
     * The sentinel is the orchestrator's instruction to that process, not a claim about
     * its lock.
     *
     * @return void
     */
    public function testALockLessDaemonStillHonoursItsStopSentinel(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator(false);
        $orchestrator->processRunning[4242] = true;
        $orchestrator->publicSaveState($this->state(date('c', time() - 600)));
        touch($orchestrator->lockFile . '.stop');

        // Act
        $output = new BufferedOutput();
        $orchestrator->publicReconcile($output);

        // Assert — not "[ok]", and past the deadline
        $out = $output->fetch();
        $this->assertStringNotContainsString('[ok]', $out);
        $this->assertStringContainsString('[stop-timeout]', $out);
    }

    /**
     * With no stop pending, a lock-less daemon is healthy as before.
     *
     * The other half: the sentinel check must not turn every `requireLockFile => false`
     * daemon into a permanent restart loop.
     *
     * @return void
     */
    public function testALockLessDaemonWithNoSentinelIsStillHealthy(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator(false);
        $orchestrator->processRunning[4242] = true;
        $orchestrator->publicSaveState($this->state());

        // Act
        $output = new BufferedOutput();
        $orchestrator->publicReconcile($output);

        // Assert
        $this->assertStringContainsString('[ok]', $output->fetch());
    }
}
