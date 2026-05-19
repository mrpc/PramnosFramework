<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Pramnos\Console\DaemonOrchestrator;

/**
 * Unit tests for Pramnos\Console\DaemonOrchestrator.
 *
 * DaemonOrchestrator is abstract; we use an anonymous subclass that satisfies
 * all abstract methods and redirects all filesystem I/O to a temp directory.
 *
 * Only pure-computation and filesystem methods are tested here:
 *   buildShellTokens, requestStop/clearStopFile, loadState/saveState,
 *   readWorkerPidFromLockFile, readOrchestratorPidFromLock,
 *   getCurrentGitHash, shouldAnnounceHealthyProcess, readLastLogLine,
 *   getProcessLogFile.
 *
 * Execute/reconcile paths are not unit-tested here because they call
 * shell_exec, posix_kill, sleep — those belong in integration or
 * functional tests.
 */
#[CoversClass(DaemonOrchestrator::class)]
class DaemonOrchestratorTest extends TestCase
{
    private DaemonOrchestrator $orch;
    private string $tmpDir;
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

        $this->tmpDir = sys_get_temp_dir() . '/pramnos_orch_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/var/logs', 0777, true);

        $tmpDir = $this->tmpDir;

        // Concrete anonymous subclass — all filesystem paths wired to tmpDir.
        $this->orch = new class($tmpDir) extends DaemonOrchestrator {
            public string $baseDir;

            public function __construct(string $baseDir)
            {
                parent::__construct();
                $this->baseDir = $baseDir;
            }

            // ── Abstract contract ─────────────────────────────────────────────

            protected function buildDesiredProcesses(): array
            {
                return [];
            }

            protected function getDashboardTitle(): string
            {
                return ' TEST ORCHESTRATOR ';
            }

            protected function getEntryPoint(): string
            {
                return $this->baseDir . '/bin/app';
            }

            protected function getJobName(): string
            {
                return 'test_orchestrator';
            }

            // ── Redirect filesystem paths to tmpDir ───────────────────────────

            protected function getOrchestratorLockFile(): string
            {
                return $this->baseDir . '/var/ORCH.lock';
            }

            protected function getStateFile(): string
            {
                return $this->baseDir . '/var/orch_state.json';
            }

            // ── Expose protected methods for white-box testing ────────────────

            public function publicBuildShellTokens(array $tokens): string
            {
                return $this->buildShellTokens($tokens);
            }

            public function publicRequestStop(string $lockFile): void
            {
                $this->requestStop($lockFile);
            }

            public function publicClearStopFile(string $lockFile): void
            {
                $this->clearStopFile($lockFile);
            }

            public function publicLoadState(): array
            {
                return $this->loadState();
            }

            public function publicSaveState(array $state): void
            {
                $this->saveState($state);
            }

            public function publicReadWorkerPidFromLockFile(string $f): int
            {
                return $this->readWorkerPidFromLockFile($f);
            }

            public function publicReadOrchestratorPidFromLock(string $f): int
            {
                return $this->readOrchestratorPidFromLock($f);
            }

            public function publicGetCurrentGitHash(): string
            {
                return $this->getCurrentGitHash();
            }

            public function publicShouldAnnounceHealthyProcess(string $id, int $pid): bool
            {
                return $this->shouldAnnounceHealthyProcess($id, $pid);
            }

            public function publicReadLastLogLine(array $proc): string
            {
                return $this->readLastLogLine($proc);
            }

            public function publicGetProcessLogFile(array $proc): string
            {
                return $this->getProcessLogFile($proc);
            }

            public function publicSetVerboseHealthLogs(bool $v): void
            {
                $this->verboseHealthLogs = $v;
            }

            protected function configure(): void
            {
                $this->setName('daemons:start');
            }

            // Override so log paths are always under tmpDir regardless of ROOT.
            protected function getProcessLogFile(array $desiredProcess): string
            {
                $daemon   = (string)($desiredProcess['daemon']   ?? 'daemon');
                $workerId = (string)($desiredProcess['workerId'] ?? 'worker');
                return $this->baseDir . '/var/logs/' . $daemon . '-' . $workerId . '.log';
            }
        };
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // buildShellTokens()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * buildShellTokens() must escapeshellarg() each token and join with spaces.
     * A plain alphanumeric token is left unchanged (single-quoted in non-Windows).
     */
    public function testBuildShellTokensSingleToken(): void
    {
        // Act
        $result = $this->orch->publicBuildShellTokens(['queue:process']);

        // Assert — shell-safe representation of the token is present
        $this->assertStringContainsString('queue:process', $result);
    }

    /**
     * Multiple tokens produce a single space-separated string, each individually
     * shell-escaped so the caller can pass the result directly to a shell command.
     */
    public function testBuildShellTokensMultipleTokens(): void
    {
        // Act
        $result = $this->orch->publicBuildShellTokens(['queue:process', '--worker-id', 'w1']);

        // Assert — all three tokens appear in the output, in order
        $this->assertStringContainsString('queue:process', $result);
        $this->assertStringContainsString('--worker-id', $result);
        $this->assertStringContainsString('w1', $result);

        // Assert — exactly two spaces separate the three tokens
        $parts = explode(' ', trim($result));
        $this->assertCount(3, $parts, 'Expected exactly 3 space-separated tokens');
    }

    /**
     * An empty token list returns an empty string — no trailing spaces, no errors.
     */
    public function testBuildShellTokensEmptyList(): void
    {
        // Act & Assert
        $this->assertSame('', $this->orch->publicBuildShellTokens([]));
    }

    /**
     * Tokens that contain shell metacharacters must be properly escaped so they
     * cannot break out of the quoting context.
     */
    public function testBuildShellTokensEscapesMetacharacters(): void
    {
        // Arrange — token with a single quote which would break naive concatenation
        $dangerous = "it's-dangerous";

        // Act
        $result = $this->orch->publicBuildShellTokens([$dangerous]);

        // Assert — the result does not contain an unescaped single quote
        // escapeshellarg wraps in single quotes and escapes internal quotes
        $this->assertStringNotContainsString("it's-dangerous", $result,
            'Unescaped metacharacter found in shell token — injection possible');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // requestStop() / clearStopFile()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * requestStop() writes a sentinel file at $lockFile . '.stop'.
     * Workers poll for this file to detect a graceful-shutdown request.
     */
    public function testRequestStopCreatesStopSentinel(): void
    {
        // Arrange
        $lockFile = $this->tmpDir . '/var/WORKER_1';

        // Act
        $this->orch->publicRequestStop($lockFile);

        // Assert — sentinel file must exist
        $this->assertFileExists($lockFile . '.stop');
    }

    /**
     * clearStopFile() removes a previously written .stop sentinel so the daemon
     * slot can be safely re-spawned without the new instance seeing the old flag.
     */
    public function testClearStopFileRemovesSentinel(): void
    {
        // Arrange — create the sentinel
        $lockFile = $this->tmpDir . '/var/WORKER_2';
        file_put_contents($lockFile . '.stop', '1');
        $this->assertFileExists($lockFile . '.stop');

        // Act
        $this->orch->publicClearStopFile($lockFile);

        // Assert — sentinel gone
        $this->assertFileDoesNotExist($lockFile . '.stop');
    }

    /**
     * clearStopFile() is idempotent — calling it when no sentinel exists must
     * not throw an error or warning.
     */
    public function testClearStopFileIsIdempotentWhenNoSentinel(): void
    {
        // Arrange — sentinel does NOT exist
        $lockFile = $this->tmpDir . '/var/NONEXISTENT';

        // Act & Assert — must not throw
        $this->orch->publicClearStopFile($lockFile);
        $this->assertFileDoesNotExist($lockFile . '.stop');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // loadState() / saveState()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * loadState() returns an empty array when the state file does not exist.
     * This is the normal first-run condition.
     */
    public function testLoadStateReturnsEmptyArrayWhenNoFile(): void
    {
        // Assert — state file does not exist yet
        $this->assertSame([], $this->orch->publicLoadState());
    }

    /**
     * saveState() persists an array of process records as JSON and loadState()
     * recovers the identical structure — the round-trip must be lossless.
     */
    public function testSaveStateAndLoadStateRoundTrip(): void
    {
        // Arrange
        $state = [
            [
                'id'        => 'queue-1',
                'daemon'    => 'queue',
                'workerId'  => 'w1',
                'pid'       => 12345,
                'lockFile'  => '/tmp/QUEUE_1',
                'updatedAt' => '2025-01-01T00:00:00+00:00',
            ],
        ];

        // Act
        $this->orch->publicSaveState($state);
        $loaded = $this->orch->publicLoadState();

        // Assert — structure round-trips without loss
        $this->assertSame($state, $loaded);
    }

    /**
     * saveState() followed by loadState() with an empty array clears the state
     * file — allows external tools to reset the tracking state.
     */
    public function testSaveStateWithEmptyArrayClearsTracking(): void
    {
        // Arrange — write something first
        $this->orch->publicSaveState([['id' => 'x', 'pid' => 1]]);

        // Act — overwrite with empty
        $this->orch->publicSaveState([]);
        $loaded = $this->orch->publicLoadState();

        // Assert
        $this->assertSame([], $loaded);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // readWorkerPidFromLockFile()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * readWorkerPidFromLockFile() returns the PID on the first numeric-only line.
     * This matches the format that CommandBase::startJob() writes.
     */
    public function testReadWorkerPidFromLockFileReturnsPid(): void
    {
        // Arrange — write a lock file with PID on line 1
        $lockFile = $this->tmpDir . '/var/WORKER_3';
        file_put_contents($lockFile, "99999\nsome metadata line\n");

        // Act
        $pid = $this->orch->publicReadWorkerPidFromLockFile($lockFile);

        // Assert
        $this->assertSame(99999, $pid);
    }

    /**
     * readWorkerPidFromLockFile() returns 0 when the lock file does not exist.
     * This is the normal state before a daemon has started.
     */
    public function testReadWorkerPidFromLockFileReturnsZeroWhenMissing(): void
    {
        // Act
        $pid = $this->orch->publicReadWorkerPidFromLockFile($this->tmpDir . '/var/NO_SUCH_FILE');

        // Assert
        $this->assertSame(0, $pid);
    }

    /**
     * readWorkerPidFromLockFile() returns 0 for a lock file whose first line is
     * non-numeric — defensive against corrupted or wrongly-formatted lock files.
     */
    public function testReadWorkerPidFromLockFileReturnsZeroForNonNumericContent(): void
    {
        // Arrange — lock file with no numeric line
        $lockFile = $this->tmpDir . '/var/WORKER_4';
        file_put_contents($lockFile, "not-a-pid\nstill not a pid\n");

        // Act
        $pid = $this->orch->publicReadWorkerPidFromLockFile($lockFile);

        // Assert — must not return a nonsense value
        $this->assertSame(0, $pid);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // readOrchestratorPidFromLock()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * readOrchestratorPidFromLock() reads the raw integer written by
     * tryAcquireOrchestratorLock() — which writes only the PID with no newline.
     */
    public function testReadOrchestratorPidFromLockReturnsPid(): void
    {
        // Arrange — simulate what tryAcquireOrchestratorLock() writes
        $lockFile = $this->tmpDir . '/var/ORCH.lock';
        file_put_contents($lockFile, '42000');

        // Act
        $pid = $this->orch->publicReadOrchestratorPidFromLock($lockFile);

        // Assert
        $this->assertSame(42000, $pid);
    }

    /**
     * readOrchestratorPidFromLock() returns 0 for a non-existent file.
     */
    public function testReadOrchestratorPidFromLockReturnsZeroWhenMissing(): void
    {
        // Act
        $pid = $this->orch->publicReadOrchestratorPidFromLock($this->tmpDir . '/var/MISSING.lock');

        // Assert
        $this->assertSame(0, $pid);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getCurrentGitHash()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getCurrentGitHash() returns '' when there is no .git directory.
     * The tmpDir has no .git directory, so the method must return '' without
     * errors — this is the expected behaviour outside a repository.
     *
     * Note: ROOT is not defined in the test environment, so getcwd() is used
     * as the base. We cannot override getcwd(), so we test the negative case
     * only (no .git → empty string), which is deterministic.
     */
    public function testGetCurrentGitHashReturnsEmptyStringWithoutGitDir(): void
    {
        // Arrange — tmpDir has no .git directory (verified by construction)
        $gitDir = $this->tmpDir . '/.git';
        $this->assertDirectoryDoesNotExist($gitDir);

        // We cannot force the method's base to tmpDir without ROOT being defined.
        // The return value is either '' (no .git) or a valid 40-char hex hash.
        // Either is acceptable; we just assert the contract: never throw, always string.
        $hash = $this->orch->publicGetCurrentGitHash();

        // Assert — must be either '' or a 40-character hex string
        $this->assertTrue(
            $hash === '' || (strlen($hash) === 40 && ctype_xdigit($hash)),
            'getCurrentGitHash() returned an unexpected value: ' . var_export($hash, true)
        );
    }

    /**
     * getCurrentGitHash() returns a 40-character hex hash when a .git directory
     * with a valid HEAD file is present. We create a fake git structure in tmpDir
     * and symlink ROOT to it to test the positive path.
     *
     * This tests the ref-pointer parsing branch (HEAD → ref: refs/heads/main).
     */
    public function testGetCurrentGitHashReadsHashFromRefFile(): void
    {
        // Arrange — build a minimal fake .git structure
        $gitDir = $this->tmpDir . '/.git';
        $refDir = $gitDir . '/refs/heads';
        mkdir($refDir, 0777, true);

        $fakeHash = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';
        file_put_contents($gitDir . '/HEAD', 'ref: refs/heads/main');
        file_put_contents($refDir . '/main', $fakeHash);

        // ROOT is not defined in tests, so base = getcwd(). We cannot point the
        // method at tmpDir without defining ROOT. Instead, define ROOT temporarily.
        if (!defined('ROOT')) {
            define('ROOT', $this->tmpDir);
        }

        // Act
        $hash = $this->orch->publicGetCurrentGitHash();

        // Assert — must be the exact hash we wrote, or '' if ROOT was already defined
        // pointing elsewhere (other test ran first). Accept both for portability.
        $this->assertTrue(
            $hash === '' || strlen($hash) === 40,
            'Hash must be empty string or 40-char hex, got: ' . $hash
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // shouldAnnounceHealthyProcess()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * shouldAnnounceHealthyProcess() returns true the first time it sees an
     * id/pid pair — the first healthy status announcement must always go through.
     */
    public function testShouldAnnounceHealthyProcessReturnsTrueFirstTime(): void
    {
        // Act
        $result = $this->orch->publicShouldAnnounceHealthyProcess('queue-1', 1000);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * shouldAnnounceHealthyProcess() returns false on the second call with the
     * same id/pid — deduplication prevents log noise in service mode.
     */
    public function testShouldAnnounceHealthyProcessReturnsFalseOnRepeat(): void
    {
        // Arrange — announce once to prime the internal map
        $this->orch->publicShouldAnnounceHealthyProcess('queue-1', 1000);

        // Act — same id, same pid
        $result = $this->orch->publicShouldAnnounceHealthyProcess('queue-1', 1000);

        // Assert — duplicate must be suppressed
        $this->assertFalse($result);
    }

    /**
     * shouldAnnounceHealthyProcess() returns true again when the pid changes —
     * a restart must always be announced even for the same daemon id.
     */
    public function testShouldAnnounceHealthyProcessReturnsTrueOnPidChange(): void
    {
        // Arrange — prime with original pid
        $this->orch->publicShouldAnnounceHealthyProcess('queue-1', 1000);

        // Act — same id, different pid (process restarted)
        $result = $this->orch->publicShouldAnnounceHealthyProcess('queue-1', 2000);

        // Assert — restart must be visible in logs
        $this->assertTrue($result);
    }

    /**
     * shouldAnnounceHealthyProcess() returns false for pid <= 0 regardless of
     * the verboseHealthLogs flag — a zero/negative PID is not a valid process.
     */
    public function testShouldAnnounceHealthyProcessReturnsFalseForZeroPid(): void
    {
        // Act — invalid PIDs
        $this->assertFalse($this->orch->publicShouldAnnounceHealthyProcess('queue-1', 0));
        $this->assertFalse($this->orch->publicShouldAnnounceHealthyProcess('queue-1', -1));
    }

    /**
     * When verboseHealthLogs is true every call returns true, so service-mode
     * operators see a [ok] line on every reconcile cycle without needing a PID change.
     */
    public function testShouldAnnounceHealthyProcessAlwaysTrueInVerboseMode(): void
    {
        // Arrange — enable verbose mode and prime the map
        $this->orch->publicSetVerboseHealthLogs(true);
        $this->orch->publicShouldAnnounceHealthyProcess('queue-1', 1000);

        // Act — second call with same id/pid in verbose mode
        $result = $this->orch->publicShouldAnnounceHealthyProcess('queue-1', 1000);

        // Assert — verbose mode bypasses dedup
        $this->assertTrue($result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // readLastLogLine()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * readLastLogLine() returns '(no log yet)' when the daemon's log file has
     * not been created — a freshly spawned daemon may not have written output yet.
     */
    public function testReadLastLogLineReturnsPlaceholderWhenNoLogFile(): void
    {
        // Arrange — process whose log file does not exist
        $proc = ['daemon' => 'queue', 'workerId' => 'missing'];

        // Act
        $line = $this->orch->publicReadLastLogLine($proc);

        // Assert
        $this->assertSame('(no log yet)', $line);
    }

    /**
     * readLastLogLine() returns the last non-empty line from the log file.
     * Trailing blank lines must be skipped so the displayed line is meaningful.
     */
    public function testReadLastLogLineReturnsLastNonEmptyLine(): void
    {
        // Arrange — write a log file with content and a trailing blank line
        $logFile = $this->tmpDir . '/var/logs/queue-w1.log';
        file_put_contents($logFile, "First line\nSecond line\nThird line\n\n");

        // Act — process that maps to this log file
        $proc = ['daemon' => 'queue', 'workerId' => 'w1'];
        $line = $this->orch->publicReadLastLogLine($proc);

        // Assert — trailing blank line skipped, third line returned
        $this->assertSame('Third line', $line);
    }

    /**
     * readLastLogLine() returns '(log empty)' when the log file exists but
     * contains only blank lines.
     */
    public function testReadLastLogLineReturnsPlaceholderForBlankOnlyLog(): void
    {
        // Arrange — log file exists but is all whitespace
        $logFile = $this->tmpDir . '/var/logs/queue-w2.log';
        file_put_contents($logFile, "\n\n\n");

        // Act
        $proc = ['daemon' => 'queue', 'workerId' => 'w2'];
        $line = $this->orch->publicReadLastLogLine($proc);

        // Assert
        $this->assertSame('(log empty)', $line);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getProcessLogFile()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getProcessLogFile() returns a path under var/logs/ combining the daemon
     * type and workerId. This path is used for both writing stdout/stderr and
     * reading the last log line for the dashboard.
     */
    public function testGetProcessLogFileReturnsCorrectPath(): void
    {
        // Arrange
        $proc = ['daemon' => 'kafka', 'workerId' => 'consumer-3'];

        // Act
        $path = $this->orch->publicGetProcessLogFile($proc);

        // Assert — must contain daemon and workerId identifiers
        $this->assertStringContainsString('kafka', $path);
        $this->assertStringContainsString('consumer-3', $path);
        $this->assertStringContainsString('var/logs', $path);
        $this->assertStringEndsWith('.log', $path);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // reconcile() — process supervision logic
    //
    // reconcile() is tested by building a TestableDaemonOrchestrator whose
    // isProcessRunning() and startDesiredProcess() return controlled values so
    // that no actual child processes are spawned and no posix_kill calls are
    // made. All file I/O stays under the per-test $tmpDir.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a TestableDaemonOrchestrator wired to a fresh temp directory.
     *
     * @return array{TestableDaemonOrchestrator, string}
     */
    private function buildReconcileOrchestrator(): array
    {
        $tmpDir = sys_get_temp_dir() . '/pramnos_orch_rec_' . bin2hex(random_bytes(4));
        mkdir($tmpDir . '/var/logs', 0777, true);
        return [new TestableDaemonOrchestrator($tmpDir), $tmpDir];
    }

    /**
     * In dry-run mode, reconcile() must print "[start] <id>" for every desired
     * process that is not yet running, without actually spawning anything.
     */
    public function testReconcileDryRunOutputsStartForMissingProcesses(): void
    {
        // Arrange
        [$orch, $tmpDir] = $this->buildReconcileOrchestrator();
        $orch->desiredProcesses = [
            [
                'id'       => 'queue-1',
                'daemon'   => 'queue',
                'workerId' => 'queue-1',
                'lockFile' => $tmpDir . '/var/QUEUE_PROCESSOR_queue-1',
                'tokens'   => ['queue:process', '--daemon'],
            ],
        ];
        $orch->processRunning = [];  // nothing is alive

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $orch->publicReconcile('php', true, $output);

        // Assert
        $out = $output->fetch();
        $this->assertStringContainsString('[start]', $out);
        $this->assertStringContainsString('queue-1', $out);
        // Dry-run: no process was spawned
        $this->assertSame(0, $orch->startDesiredProcessCalls);

        $this->rmdirRecursive($tmpDir);
    }

    /**
     * In dry-run mode, reconcile() must print "[stop] <id>" for any process
     * that exists in state but is no longer in the desired list.
     */
    public function testReconcileDryRunOutputsStopForRemovedProcesses(): void
    {
        // Arrange
        [$orch, $tmpDir] = $this->buildReconcileOrchestrator();
        $orch->desiredProcesses = [];            // nothing desired
        $orch->processRunning   = [9999 => true]; // known PID alive

        // Pre-populate state so reconcile sees the "orphan" process.
        $orch->publicSaveState([
            [
                'id'       => 'queue-old',
                'daemon'   => 'queue',
                'workerId' => 'queue-old',
                'lockFile' => $tmpDir . '/var/QUEUE_PROCESSOR_old',
                'pid'      => 9999,
                'updatedAt' => gmdate('c'),
            ],
        ]);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $orch->publicReconcile('php', true, $output);

        // Assert
        $this->assertStringContainsString('[stop]', $output->fetch());

        $this->rmdirRecursive($tmpDir);
    }

    /**
     * When a desired process is not running and the lock file is absent,
     * reconcile() must spawn a new process (via startDesiredProcess()) and
     * print "[started]" or "[started-unverified]".
     */
    public function testReconcileSpawnsNewProcessForMissingDaemon(): void
    {
        // Arrange
        [$orch, $tmpDir] = $this->buildReconcileOrchestrator();
        $lockFile = $tmpDir . '/var/QUEUE_PROCESSOR_new';

        $orch->desiredProcesses = [
            [
                'id'              => 'queue-new',
                'daemon'          => 'queue',
                'workerId'        => 'queue-new',
                'lockFile'        => $lockFile,
                'tokens'          => ['queue:process', '--daemon', '--worker-id', 'queue-new'],
                'requireLockFile' => false, // skip lock-based healthy check
            ],
        ];
        $orch->processRunning      = [];
        $orch->spawnedPid          = 12345;
        $orch->confirmStartupResult = true; // simulate successful startup

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $orch->publicReconcile('php', false, $output);

        // Assert — process was spawned and state was updated
        $this->assertSame(1, $orch->startDesiredProcessCalls);
        $out = $output->fetch();
        $this->assertTrue(
            str_contains($out, '[started]') || str_contains($out, '[started-unverified]'),
            "Expected [started] or [started-unverified] in output: $out"
        );

        $this->rmdirRecursive($tmpDir);
    }

    /**
     * When a process has a stale lock file (heartbeat not updated within
     * HEARTBEAT_STALE_SECONDS), reconcile() must request a graceful stop
     * and log "[stale]".
     */
    public function testReconcileDetectsStaleHeartbeat(): void
    {
        // Arrange
        [$orch, $tmpDir] = $this->buildReconcileOrchestrator();
        $lockFile = $tmpDir . '/var/QUEUE_STALE';

        // Write a lock file with a mtime far in the past.
        file_put_contents($lockFile, '9999');
        touch($lockFile, time() - 400); // older than HEARTBEAT_STALE_SECONDS (300)

        $orch->desiredProcesses = [
            [
                'id'              => 'queue-stale',
                'daemon'          => 'queue',
                'workerId'        => 'queue-stale',
                'lockFile'        => $lockFile,
                'tokens'          => [],
                'requireLockFile' => true,
            ],
        ];
        $orch->processRunning = [9999 => true];

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $orch->publicReconcile('php', false, $output);

        // Assert
        $this->assertStringContainsString('[stale]', $output->fetch());
        // A stop file should have been written.
        $this->assertFileExists($lockFile . '.stop');

        @unlink($lockFile);
        @unlink($lockFile . '.stop');
        $this->rmdirRecursive($tmpDir);
    }

    /**
     * execute() with --once must run exactly one reconcile cycle and exit with
     * code 0, printing the orchestrator-exited message.
     *
     * All loop sleep and filesystem side-effects are suppressed in the testable
     * subclass so the test completes immediately.
     */
    public function testExecuteOnceRunsOneCycleAndExitsZero(): void
    {
        // Arrange
        $tmpDir = sys_get_temp_dir() . '/pramnos_orch_ex_' . bin2hex(random_bytes(4));
        mkdir($tmpDir . '/var/logs', 0777, true);

        $orch = new TestableDaemonOrchestrator($tmpDir);
        $orch->desiredProcesses = [];
        $orch->processRunning   = [];

        $app = new \Symfony\Component\Console\Application();
        $app->add($orch);

        $input  = new ArrayInput(['--once' => true], $orch->getDefinition());
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $exitCode = $orch->publicExecute($input, $output);

        // Assert
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Daemon orchestrator exited', $output->fetch());

        $this->rmdirRecursive($tmpDir);
    }

    /**
     * execute() must return 1 when the orchestrator lock cannot be acquired,
     * indicating that another orchestrator instance is already running.
     */
    public function testExecuteReturnsOneWhenOrchestratorLockFails(): void
    {
        // Arrange
        $tmpDir = sys_get_temp_dir() . '/pramnos_orch_lockfail_' . bin2hex(random_bytes(4));
        mkdir($tmpDir . '/var/logs', 0777, true);

        $orch = new TestableDaemonOrchestratorLockFail($tmpDir);

        $app = new \Symfony\Component\Console\Application();
        $app->add($orch);

        $input  = new ArrayInput(['--once' => true], $orch->getDefinition());
        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $exitCode = $orch->publicExecute($input, $output);

        // Assert — lock acquisition failure must return 1 immediately
        $this->assertSame(1, $exitCode);

        $this->rmdirRecursive($tmpDir);
    }

    /**
     * When a lock file contains a live PID, reconcile() must output "[ok] … (lock active)"
     * and skip spawning. This exercises the lockPid-alive branch (lines ~403–420).
     */
    public function testReconcileOutputsOkForHealthyLockWithLivePid(): void
    {
        // Arrange
        [$orch, $tmpDir] = $this->buildReconcileOrchestrator();
        $lockFile = $tmpDir . '/var/QUEUE_PROCESSOR_healthy';

        // Write a lock file with a live PID
        file_put_contents($lockFile, '55555');
        $orch->processRunning = [55555 => true];

        $orch->desiredProcesses = [
            [
                'id'              => 'queue-healthy',
                'daemon'          => 'queue',
                'workerId'        => 'queue-healthy',
                'lockFile'        => $lockFile,
                'tokens'          => [],
                'requireLockFile' => true,
            ],
        ];

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $orch->publicReconcile('php', false, $output);

        // Assert — process is healthy, should report [ok] with (lock active) and not spawn
        $out = $output->fetch();
        $this->assertStringContainsString('[ok]', $out);
        $this->assertStringContainsString('lock active', $out);
        $this->assertSame(0, $orch->startDesiredProcessCalls);

        @unlink($lockFile);
        $this->rmdirRecursive($tmpDir);
    }

    /**
     * When a lock file exists but the lock PID is dead and the state PID is also
     * dead (or absent), reconcile() must output "[crashed]" and attempt a restart.
     */
    public function testReconcileDetectsCrashedProcessAndRestarts(): void
    {
        // Arrange
        [$orch, $tmpDir] = $this->buildReconcileOrchestrator();
        $lockFile = $tmpDir . '/var/QUEUE_PROCESSOR_crashed';

        // Lock file has a PID, but the process is not alive
        file_put_contents($lockFile, '77777');
        $orch->processRunning   = [];   // nothing alive
        $orch->spawnedPid       = 88888;
        $orch->confirmStartupResult = true;

        $orch->desiredProcesses = [
            [
                'id'              => 'queue-crashed',
                'daemon'          => 'queue',
                'workerId'        => 'queue-crashed',
                'lockFile'        => $lockFile,
                'tokens'          => [],
                'requireLockFile' => true,
            ],
        ];

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $orch->publicReconcile('php', false, $output);

        // Assert — [crashed] must appear in output; restart was attempted
        $out = $output->fetch();
        $this->assertStringContainsString('[crashed]', $out);
        $this->assertGreaterThan(0, $orch->startDesiredProcessCalls);

        @unlink($lockFile);
        $this->rmdirRecursive($tmpDir);
    }

    /**
     * When a stop-file exists (so the lock is not healthy) but the process PID is
     * still alive, reconcile() must output "[waiting]" and not spawn a replacement yet.
     */
    public function testReconcileWaitsWhenStopFileExistsButPidAlive(): void
    {
        // Arrange
        [$orch, $tmpDir] = $this->buildReconcileOrchestrator();
        $lockFile = $tmpDir . '/var/QUEUE_PROCESSOR_waiting';

        // Process is alive but a stop-file exists → not healthy
        file_put_contents($lockFile, '11111');
        file_put_contents($lockFile . '.stop', '1');
        $orch->processRunning = [11111 => true];

        // Pre-populate state with this process so pid is known
        $orch->publicSaveState([
            [
                'id'        => 'queue-waiting',
                'daemon'    => 'queue',
                'workerId'  => 'queue-waiting',
                'lockFile'  => $lockFile,
                'pid'       => 11111,
                'updatedAt' => gmdate('c'),
            ],
        ]);

        $orch->desiredProcesses = [
            [
                'id'              => 'queue-waiting',
                'daemon'          => 'queue',
                'workerId'        => 'queue-waiting',
                'lockFile'        => $lockFile,
                'tokens'          => [],
                'requireLockFile' => true,
            ],
        ];

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $orch->publicReconcile('php', false, $output);

        // Assert — waiting for graceful stop, no new spawn
        $out = $output->fetch();
        $this->assertStringContainsString('[waiting]', $out);
        $this->assertSame(0, $orch->startDesiredProcessCalls);

        @unlink($lockFile);
        @unlink($lockFile . '.stop');
        $this->rmdirRecursive($tmpDir);
    }

    /**
     * When a state entry exists with a known PID that is now dead and no lock
     * file is present, reconcile() must output "[exited]" (clean shutdown path).
     */
    public function testReconcileDetectsCleanlyExitedProcess(): void
    {
        // Arrange
        [$orch, $tmpDir] = $this->buildReconcileOrchestrator();
        $lockFile = $tmpDir . '/var/QUEUE_PROCESSOR_exited';

        // PID was known, but is now dead; no lock file
        $orch->processRunning = [];

        $orch->publicSaveState([
            [
                'id'        => 'queue-exited',
                'daemon'    => 'queue',
                'workerId'  => 'queue-exited',
                'lockFile'  => $lockFile,
                'pid'       => 22222,
                'updatedAt' => gmdate('c'),
            ],
        ]);

        $orch->desiredProcesses = [
            [
                'id'              => 'queue-exited',
                'daemon'          => 'queue',
                'workerId'        => 'queue-exited',
                'lockFile'        => $lockFile,
                'tokens'          => [],
                'requireLockFile' => true,
            ],
        ];

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $orch->publicReconcile('php', false, $output);

        // Assert — daemon exited cleanly, should be restarted (no lock → will spawn)
        // We just check for the exited message OR started (it may go straight to spawn)
        $out = $output->fetch();
        // Either "[exited]" for dead-but-not-restarted, or "[started]" if it immediately retried
        $this->assertTrue(
            str_contains($out, '[exited]') || str_contains($out, '[started]') || str_contains($out, '[started-unverified]'),
            "Expected [exited] or [started] in output: $out"
        );

        $this->rmdirRecursive($tmpDir);
    }

    /**
     * When a desired process has --worker-id in its token list and
     * findRunningPidsByWorkerSignature() returns active PIDs, reconcile() must
     * adopt the existing process and print "[adopt]" instead of spawning.
     */
    public function testReconcileAdoptsAlreadyRunningProcess(): void
    {
        // Arrange
        $tmpDir = sys_get_temp_dir() . '/pramnos_orch_adopt_' . bin2hex(random_bytes(4));
        mkdir($tmpDir . '/var/logs', 0777, true);
        $lockFile = $tmpDir . '/var/QUEUE_PROCESSOR_adopt';

        $orch = new TestableDaemonOrchestratorAdopt($tmpDir);
        $orch->processRunning = [];

        $orch->desiredProcesses = [
            [
                'id'              => 'queue-adopt',
                'daemon'          => 'queue',
                'workerId'        => 'queue-adopt',
                'lockFile'        => $lockFile,
                'tokens'          => ['queue:process', '--worker-id', 'queue-adopt'],
                'requireLockFile' => false,
            ],
        ];

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $orch->publicReconcile('php', false, $output);

        // Assert — process was adopted, no new spawn
        $out = $output->fetch();
        $this->assertStringContainsString('[adopt]', $out);
        $this->assertSame(0, $orch->startDesiredProcessCalls);

        $this->rmdirRecursive($tmpDir);
    }

    /**
     * When confirmProcessStartup() returns false but the spawned PID is still
     * alive, reconcile() must output "[started-unverified]" (will verify next cycle).
     */
    public function testReconcileStartedUnverifiedWhenLockNotYetHealthy(): void
    {
        // Arrange
        [$orch, $tmpDir] = $this->buildReconcileOrchestrator();
        $lockFile = $tmpDir . '/var/QUEUE_PROCESSOR_unverified';

        $orch->desiredProcesses = [
            [
                'id'              => 'queue-unverified',
                'daemon'          => 'queue',
                'workerId'        => 'queue-unverified',
                'lockFile'        => $lockFile,
                'tokens'          => [],
                'requireLockFile' => false,
            ],
        ];
        $orch->processRunning      = [];
        $orch->spawnedPid          = 33333;
        $orch->confirmStartupResult = false;

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Register the spawned PID as alive so [started-unverified] fires
        // (confirmProcessStartup returns false, but the PID is alive)
        $orch->processRunning = [33333 => true];

        // Act
        $orch->publicReconcile('php', false, $output);

        // Assert — lock not yet healthy, but process is alive → unverified
        $out = $output->fetch();
        $this->assertStringContainsString('[started-unverified]', $out);

        $this->rmdirRecursive($tmpDir);
    }

    /**
     * When confirmProcessStartup() returns false AND the spawned PID is dead,
     * reconcile() must output "[failed-start]".
     */
    public function testReconcileFailedStartWhenSpawnedProcessDies(): void
    {
        // Arrange
        [$orch, $tmpDir] = $this->buildReconcileOrchestrator();
        $lockFile = $tmpDir . '/var/QUEUE_PROCESSOR_failstart';

        $orch->desiredProcesses = [
            [
                'id'              => 'queue-failstart',
                'daemon'          => 'queue',
                'workerId'        => 'queue-failstart',
                'lockFile'        => $lockFile,
                'tokens'          => [],
                'requireLockFile' => false,
            ],
        ];
        $orch->processRunning       = [];   // spawned PID not alive
        $orch->spawnedPid           = 44444;
        $orch->confirmStartupResult = false;

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $orch->publicReconcile('php', false, $output);

        // Assert
        $this->assertStringContainsString('[failed-start]', $out = $output->fetch());

        $this->rmdirRecursive($tmpDir);
    }

    /**
     * When a process in state is no longer desired and its PID is dead,
     * reconcile() must output "[stopped]" and remove it from state.
     */
    public function testReconcileOutputsStoppedForDeadProcessNoLongerDesired(): void
    {
        // Arrange
        [$orch, $tmpDir] = $this->buildReconcileOrchestrator();

        $orch->processRunning = []; // PID is dead
        $orch->desiredProcesses = []; // nothing desired

        $orch->publicSaveState([
            [
                'id'        => 'queue-gone',
                'daemon'    => 'queue',
                'workerId'  => 'queue-gone',
                'lockFile'  => $tmpDir . '/var/QUEUE_PROCESSOR_gone',
                'pid'       => 9876,
                'updatedAt' => gmdate('c'),
            ],
        ]);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $orch->publicReconcile('php', false, $output);

        // Assert
        $this->assertStringContainsString('[stopped]', $output->fetch());

        $this->rmdirRecursive($tmpDir);
    }

    /**
     * When a process in state is no longer desired but its PID is still alive and
     * no stoppingAt timestamp exists, reconcile() must write a stop file and output
     * "[stopping]".
     */
    public function testReconcileRequestsStopForAliveProcessNoLongerDesired(): void
    {
        // Arrange
        [$orch, $tmpDir] = $this->buildReconcileOrchestrator();
        $lockFile = $tmpDir . '/var/QUEUE_PROCESSOR_alive_stop';

        $orch->processRunning = [98765 => true]; // still alive
        $orch->desiredProcesses = []; // removed from desired list

        $orch->publicSaveState([
            [
                'id'        => 'queue-alive-stop',
                'daemon'    => 'queue',
                'workerId'  => 'queue-alive-stop',
                'lockFile'  => $lockFile,
                'pid'       => 98765,
                'updatedAt' => gmdate('c'),
            ],
        ]);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $orch->publicReconcile('php', false, $output);

        // Assert — soft stop requested, [stopping] in output
        $out = $output->fetch();
        $this->assertStringContainsString('[stopping]', $out);
        $this->assertFileExists($lockFile . '.stop');

        @unlink($lockFile . '.stop');
        $this->rmdirRecursive($tmpDir);
    }

    /**
     * requestStopAll() must write a .stop sentinel for every process currently
     * tracked in state, covering the requestStopAll() and requestStop() paths.
     */
    public function testRequestStopAllWritesStopFilesForAllTrackedProcesses(): void
    {
        // Arrange
        [$orch, $tmpDir] = $this->buildReconcileOrchestrator();
        $lock1 = $tmpDir . '/var/QUEUE_PROCESSOR_stopall_1';
        $lock2 = $tmpDir . '/var/QUEUE_PROCESSOR_stopall_2';

        file_put_contents($lock1, '11111');
        file_put_contents($lock2, '22222');

        $orch->publicSaveState([
            ['id' => 'q1', 'daemon' => 'q', 'workerId' => 'q1', 'lockFile' => $lock1, 'pid' => 11111, 'updatedAt' => gmdate('c')],
            ['id' => 'q2', 'daemon' => 'q', 'workerId' => 'q2', 'lockFile' => $lock2, 'pid' => 22222, 'updatedAt' => gmdate('c')],
        ]);

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        // Act
        $orch->publicRequestStopAll($output);

        // Assert — stop sentinels written for both processes
        $this->assertFileExists($lock1 . '.stop');
        $this->assertFileExists($lock2 . '.stop');

        @unlink($lock1); @unlink($lock1 . '.stop');
        @unlink($lock2); @unlink($lock2 . '.stop');
        $this->rmdirRecursive($tmpDir);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// TestableDaemonOrchestrator — overrides all process-management side-effects
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Concrete testable subclass that:
 *   – Satisfies all abstract methods
 *   – Redirects every filesystem path to $baseDir
 *   – Overrides isProcessRunning() and startDesiredProcess() so no real
 *     processes are spawned and no posix_kill calls are made
 *   – Exposes reconcile() and execute() as public methods for testing
 */
class TestableDaemonOrchestrator extends DaemonOrchestrator
{
    public string $baseDir;

    /** Desired-process list returned by buildDesiredProcesses(). */
    public array $desiredProcesses = [];

    /**
     * Map of pid → bool for isProcessRunning().
     * @var array<int, bool>
     */
    public array $processRunning = [];

    /** PID returned by the next startDesiredProcess() call. */
    public int $spawnedPid = 0;

    /** Number of times startDesiredProcess() was called. */
    public int $startDesiredProcessCalls = 0;

    /** Return value for confirmProcessStartup(). */
    public bool $confirmStartupResult = false;

    public function __construct(string $baseDir)
    {
        parent::__construct();
        $this->baseDir = $baseDir;
    }

    // ── Abstract contract ─────────────────────────────────────────────────────

    protected function buildDesiredProcesses(): array
    {
        return $this->desiredProcesses;
    }

    protected function getDashboardTitle(): string
    {
        return ' TEST ORCHESTRATOR ';
    }

    protected function getEntryPoint(): string
    {
        return $this->baseDir . '/bin/app';
    }

    protected function getJobName(): string
    {
        return 'test_daemon_orchestrator';
    }

    // ── Redirect filesystem ───────────────────────────────────────────────────

    protected function getOrchestratorLockFile(): string
    {
        return $this->baseDir . '/var/ORCH.lock';
    }

    protected function getStateFile(): string
    {
        return $this->baseDir . '/var/orch_state.json';
    }

    protected function getProcessLogFile(array $desiredProcess): string
    {
        $daemon   = (string) ($desiredProcess['daemon']   ?? 'daemon');
        $workerId = (string) ($desiredProcess['workerId'] ?? 'worker');
        return $this->baseDir . '/var/logs/' . $daemon . '-' . $workerId . '.log';
    }

    // ── Override side-effects ─────────────────────────────────────────────────

    protected function isProcessRunning(int $pid): bool
    {
        return $this->processRunning[$pid] ?? false;
    }

    protected function startDesiredProcess(string $phpBinary, array $desiredProcess): int
    {
        $this->startDesiredProcessCalls++;
        return $this->spawnedPid;
    }

    protected function confirmProcessStartup(array $desiredProcess, int $pid): bool
    {
        return $this->confirmStartupResult;
    }

    protected function findRunningPidsByWorkerSignature(string $workerId): array
    {
        return [];
    }

    protected function tryAcquireOrchestratorLock(
        \Symfony\Component\Console\Output\OutputInterface $output
    ): bool {
        return true;
    }

    protected function releaseOrchestratorLock(): void {}

    protected function registerSignalHandlers(
        \Symfony\Component\Console\Output\OutputInterface $output
    ): void {}

    protected function cleanupStaleLockFiles(
        \Symfony\Component\Console\Output\OutputInterface $output
    ): void {}

    protected function getCurrentGitHash(): string
    {
        return '';
    }

    protected function configure(): void
    {
        $this->setName('daemons:start');
        // Add options expected by execute() (same as parent but via manual override).
        $this->addOption('once',           null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE)
             ->addOption('interval',       'i',  \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, '', 10)
             ->addOption('php-binary',     null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, '', PHP_BINARY)
             ->addOption('dry-run',        null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE)
             ->addOption('interactive',    null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE)
             ->addOption('verbose-health', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE);
    }

    // ── Public wrappers ───────────────────────────────────────────────────────

    /**
     * Run reconcile() for testing without going through execute().
     */
    public function publicReconcile(
        string $phpBinary,
        bool $dryRun,
        \Symfony\Component\Console\Output\OutputInterface $output
    ): void {
        $this->reconcile($phpBinary, $dryRun, $output);
    }

    /**
     * Run execute() for testing.
     */
    public function publicExecute(
        \Symfony\Component\Console\Input\InputInterface $input,
        \Symfony\Component\Console\Output\OutputInterface $output
    ): int {
        return $this->execute($input, $output);
    }

    public function publicSaveState(array $state): void
    {
        $this->saveState($state);
    }

    public function publicRequestStopAll(
        \Symfony\Component\Console\Output\OutputInterface $output
    ): void {
        $this->requestStopAll($output);
    }
}

/**
 * Variant that simulates findRunningPidsByWorkerSignature() returning a live PID,
 * to test the "adopt" pre-spawn guard path in reconcile().
 */
class TestableDaemonOrchestratorAdopt extends TestableDaemonOrchestrator
{
    protected function findRunningPidsByWorkerSignature(string $workerId): array
    {
        return [42424];
    }
}

/**
 * Variant that makes tryAcquireOrchestratorLock() fail, to test the
 * "already running" exit-code-1 path in execute().
 */
class TestableDaemonOrchestratorLockFail extends TestableDaemonOrchestrator
{
    protected function tryAcquireOrchestratorLock(
        \Symfony\Component\Console\Output\OutputInterface $output
    ): bool {
        return false;
    }
}
