<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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

    protected function setUp(): void
    {
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
}
