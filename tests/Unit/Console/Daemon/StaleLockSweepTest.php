<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Daemon;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\DaemonOrchestrator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/** An orchestrator that names its own lock files, which is how the sweep is meant to be used. */
class SweepProbeOrchestrator extends DaemonOrchestrator
{
    public static string $prefix = '';

    protected function getManagedLockFileGlobPattern(): string
    {
        return self::$prefix . '*';
    }

    protected function buildDesiredProcesses(): array
    {
        return [];
    }

    protected function getDashboardTitle(): string
    {
        return ' SWEEP PROBE ';
    }

    protected function getEntryPoint(): string
    {
        return '/dev/null';
    }

    protected function getJobName(): string
    {
        return 'sweep_probe';
    }

    public function callCleanup(OutputInterface $output): void
    {
        $this->cleanupStaleLockFiles($output);
    }
}

/** The same orchestrator with the shipped default, to show what it now does. */
class DefaultSweepOrchestrator extends DaemonOrchestrator
{
    protected function buildDesiredProcesses(): array
    {
        return [];
    }

    protected function getDashboardTitle(): string
    {
        return ' DEFAULT SWEEP ';
    }

    protected function getEntryPoint(): string
    {
        return '/dev/null';
    }

    protected function getJobName(): string
    {
        return 'default_sweep';
    }

    public function callCleanup(OutputInterface $output): void
    {
        $this->cleanupStaleLockFiles($output);
    }
}

/**
 * The startup sweep that clears lock files a crashed daemon left behind — 21 statements.
 *
 * A daemon claims its slot with a lock file and touches it as a heartbeat. Crash it — `kill -9`, an
 * OOM, a container replaced — and the file stays, so the orchestrator believes the daemon is running
 * and never restarts it. The sweep is what makes a crash recoverable without somebody logging in to
 * delete a file.
 *
 * Which makes it code that *deletes things at startup*, and the interesting assertions are all about
 * what it must not touch:
 *
 * - **a fresh lock survives.** It belongs to a daemon that is running right now, and deleting it
 *   makes the orchestrator start a second copy of a process already doing the work — the failure the
 *   lock exists to prevent, caused by the thing meant to repair it.
 * - **a `.stop` file is never removed.** It is an instruction, not a claim: somebody asked a daemon
 *   to stop, and sweeping it away restarts what they were shutting down.
 * - **nothing is said when nothing was stale**, which is every ordinary start. A line on each one
 *   trains the reader to skip the startup output, and that is where the interesting lines are.
 *
 * The sweep's scope is asserted too, and that changed today: the default pattern was `'*'` — every
 * file directly in `var/` — and `var/` is not a lock directory. See the test at the bottom.
 */
#[CoversClass(DaemonOrchestrator::class)]
class StaleLockSweepTest extends TestCase
{
    /** Comfortably past `HEARTBEAT_STALE_SECONDS + 60`, which is the threshold. */
    private const STALE = 500;

    private string $varDir = '';

    private array $made = [];

    protected function setUp(): void
    {
        $this->varDir = (defined('ROOT') ? ROOT : sys_get_temp_dir()) . '/var';
        if (!is_dir($this->varDir)) {
            mkdir($this->varDir, 0777, true);
        }

        // A prefix of this test's own, so the sweep can never reach anything else in var/.
        SweepProbeOrchestrator::$prefix = 'sweepprobe_' . bin2hex(random_bytes(4)) . '_';
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $file) {
            @unlink($file);
        }
        $this->made = [];
    }

    /** A lock file with a given age in seconds. */
    private function lock(string $name, int $ageSeconds): string
    {
        $path = $this->varDir . '/' . SweepProbeOrchestrator::$prefix . $name;
        file_put_contents($path, (string) getmypid());
        touch($path, time() - $ageSeconds);
        $this->made[] = $path;

        return $path;
    }

    private function sweep(): string
    {
        $output = new BufferedOutput();
        (new SweepProbeOrchestrator())->callCleanup($output);

        return $output->fetch();
    }

    // ── What it clears ────────────────────────────────────────────────────────

    /**
     * A lock whose heartbeat stopped long ago is removed, and the removal is reported.
     *
     * The whole purpose: without it a crashed daemon's slot stays claimed for ever and the
     * orchestrator never restarts it. The count is reported because a start that silently deleted
     * state leaves nothing to correlate with the crash that caused it.
     */
    public function testAStaleLockIsRemovedAndReported(): void
    {
        // Arrange
        $stale = $this->lock('QUEUE.lock', self::STALE);

        // Act
        $said = $this->sweep();

        // Assert
        $this->assertFileDoesNotExist($stale, 'the crashed daemon keeps its slot for ever');
        $this->assertStringContainsString('1 stale', $said, 'the sweep deleted state silently');
    }

    /**
     * Several stale locks are counted together, in one line.
     *
     * A container that was replaced leaves one per daemon, so this is the ordinary shape of a real
     * sweep — and one line per file would bury the rest of the startup output.
     */
    public function testSeveralStaleLocksAreCountedInOneLine(): void
    {
        // Arrange
        foreach (['QUEUE.lock', 'KAFKA.lock', 'MAILER.lock'] as $name) {
            $this->lock($name, self::STALE);
        }

        // Act
        $said = $this->sweep();

        // Assert
        $this->assertStringContainsString('3 stale', $said);
        $this->assertSame(1, substr_count($said, 'stale daemon lock'), 'one line per file');
    }

    // ── What it must not touch ────────────────────────────────────────────────

    /**
     * A lock whose heartbeat is current survives.
     *
     * It belongs to a daemon running right now. Deleting it makes the orchestrator start a second
     * copy of a process already doing the work — which is the failure the lock exists to prevent,
     * caused by the mechanism meant to repair it.
     */
    public function testAFreshLockSurvives(): void
    {
        // Arrange
        $fresh = $this->lock('RUNNING.lock', 5);

        // Act
        $said = $this->sweep();

        // Assert
        $this->assertFileExists($fresh, "a running daemon's claim was swept away");
        $this->assertSame('', $said, 'it reported cleaning a file it did not clean');
    }

    /**
     * A lock exactly at the threshold is not yet stale.
     *
     * The comparison is strictly greater than, and the boundary matters because the heartbeat
     * interval and this threshold are set independently: a daemon that touches its lock every five
     * minutes against a threshold of six is one slow cycle away from being declared dead.
     */
    public function testALockAtTheThresholdIsNotYetStale(): void
    {
        // Arrange — HEARTBEAT_STALE_SECONDS + 60 exactly
        $atThreshold = $this->lock('BORDERLINE.lock', 360);

        // Act
        $this->sweep();

        // Assert
        $this->assertFileExists($atThreshold, 'a daemon one cycle slow was declared dead');
    }

    /**
     * A `.stop` file is never removed, however old.
     *
     * It is an instruction rather than a claim: somebody asked a daemon to stop, and the file is how
     * that request survives until the daemon next looks. Sweeping it restarts what they were
     * shutting down — and an old one is the *normal* case, because a daemon that has stopped is not
     * touching anything.
     */
    public function testAStopFileIsNeverRemoved(): void
    {
        // Arrange
        $stop = $this->lock('QUEUE.stop', self::STALE * 10);

        // Act
        $said = $this->sweep();

        // Assert
        $this->assertFileExists($stop, 'a stop request was swept away, restarting what it stopped');
        $this->assertSame('', $said);
    }

    /**
     * Nothing is said when nothing was stale.
     *
     * Every ordinary start is this. A line on each one trains the reader to skip the startup output,
     * which is where the lines that matter appear.
     */
    public function testNothingIsSaidWhenNothingWasStale(): void
    {
        // Arrange
        $this->lock('RUNNING.lock', 10);

        // Act & Assert
        $this->assertSame('', $this->sweep(), 'a quiet start produced a line anyway');
    }

    /**
     * Only files are considered; a directory matching the pattern is left alone.
     *
     * `var/` holds `cache/`, `logs/`, `mails/` and `migrations/`, and an `is_file()` check is the
     * only thing between this sweep and `@unlink()` on a directory.
     */
    public function testADirectoryMatchingThePatternIsLeftAlone(): void
    {
        // Arrange
        $dir = $this->varDir . '/' . SweepProbeOrchestrator::$prefix . 'adirectory';
        mkdir($dir, 0777, true);
        touch($dir, time() - self::STALE);

        try {
            // Act
            $this->sweep();

            // Assert
            $this->assertDirectoryExists($dir, 'the sweep tried to unlink a directory');
        } finally {
            @rmdir($dir);
        }
    }

    // ── How far it reaches ────────────────────────────────────────────────────

    /**
     * With the shipped default, nothing is swept at all.
     *
     * Changed today, and this is the test that says so. The default was `'*'` — every file directly
     * in `var/` — while the method's own docblock describes a *narrow* pattern and offers `''` as the
     * way to skip the scan. `var/` is not a lock directory: on this checkout it holds `junit.xml` and
     * the `migrations-*.lock` advisory locks that stop two migration runs overlapping, and deleting
     * one of those because six minutes passed is precisely how two concurrent migrations begin.
     *
     * A base class does not know which files are its locks. An orchestrator that wants the sweep
     * names them.
     */
    public function testTheShippedDefaultSweepsNothing(): void
    {
        // Arrange — a stale file directly in var/, which the old default would have deleted
        $bystander = $this->varDir . '/sweepprobe_bystander_' . bin2hex(random_bytes(4)) . '.xml';
        file_put_contents($bystander, '<testsuite/>');
        touch($bystander, time() - self::STALE);
        $this->made[] = $bystander;

        // Act
        $output = new BufferedOutput();
        (new DefaultSweepOrchestrator())->callCleanup($output);

        // Assert
        $this->assertFileExists(
            $bystander,
            'the default swept a file that is not a daemon lock — junit.xml and the migration '
            . 'advisory locks live here too'
        );
        $this->assertSame('', $output->fetch());
    }
}
