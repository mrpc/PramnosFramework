<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\CommandBase;
use Pramnos\Console\DaemonOrchestrator;

/**
 * The two ends of the stop protocol must resolve one lock path, not two.
 *
 * `DaemonOrchestrator` writes the `.stop` sentinel beside the `lockFile` declared in a
 * worker's desired-process entry. The worker found its own path through
 * `CommandBase::getJobLockFilePath()`, which has its own default and is overridable.
 * Two independent computations of one path — and **nothing could notice when they
 * disagreed**: a sentinel read where nothing writes is indistinguishable from no
 * sentinel, so the worker reported itself healthy while ignoring every stop request.
 *
 * Reported by a project whose loop workers overrode the method to match their
 * supervisor and whose realtime worker did not, so adopting the stop seam landed as a
 * no-op. The fix for a silent failure reproduced it, in the project that had just
 * filed it, with the whole thing fresh in mind. That is the argument for making
 * disagreement impossible rather than detectable.
 */
#[CoversClass(CommandBase::class)]
#[CoversClass(DaemonOrchestrator::class)]
class DaemonLockPathAgreementTest extends TestCase
{
    private ?string $savedEnv = null;

    protected function setUp(): void
    {
        $existing       = getenv(CommandBase::LOCK_FILE_ENV);
        $this->savedEnv = $existing === false ? null : $existing;
        putenv(CommandBase::LOCK_FILE_ENV);
    }

    protected function tearDown(): void
    {
        if ($this->savedEnv === null) {
            putenv(CommandBase::LOCK_FILE_ENV);
        } else {
            putenv(CommandBase::LOCK_FILE_ENV . '=' . $this->savedEnv);
        }
    }

    /**
     * A command that overrides the lock path the way an application would — per
     * installation, so two checkouts on one host never collide.
     */
    private function commandWithOverride(string $override): object
    {
        return new class($override) extends CommandBase {
            public function __construct(private string $override)
            {
                parent::__construct('test:worker');
            }

            protected function getJobName(): string
            {
                return 'test-worker';
            }

            protected function getJobLockFilePath(): string
            {
                return $this->override;
            }

            public function lockPath(): string
            {
                return $this->resolvedJobLockFilePath();
            }
        };
    }

    /**
     * With no supervisor, the command's own answer applies — unchanged behaviour for
     * anything run by hand.
     */
    public function testWithoutASupervisorTheCommandsOwnPathApplies(): void
    {
        // Arrange
        $command = $this->commandWithOverride('/app/logs/realtime-7.lock');

        // Act & Assert
        $this->assertSame('/app/logs/realtime-7.lock', $command->lockPath());
    }

    /**
     * When the supervisor hands a path down, it wins over the command's override.
     *
     * This is the whole fix. Before it, an override that disagreed with the supervisor
     * produced a worker that read `var/realtime.stop` while the orchestrator wrote
     * `logs/realtime-<id>.lock.stop` — and neither side could tell.
     */
    public function testTheSupervisorsPathWinsOverAnOverride(): void
    {
        // Arrange
        putenv(CommandBase::LOCK_FILE_ENV . '=/app/logs/realtime-7.lock');
        $command = $this->commandWithOverride('/app/var/realtime');

        // Act & Assert
        $this->assertSame(
            '/app/logs/realtime-7.lock',
            $command->lockPath(),
            'an override must not be able to disagree with the supervisor'
        );
    }

    /**
     * An empty exported value falls back rather than resolving to nothing.
     *
     * A path of `''` would make every sentinel check read `'.stop'` in the working
     * directory — a single shared file for every worker on the host.
     */
    public function testAnEmptyExportedPathFallsBack(): void
    {
        // Arrange
        putenv(CommandBase::LOCK_FILE_ENV . '=');
        $command = $this->commandWithOverride('/app/var/realtime');

        // Act & Assert
        $this->assertSame('/app/var/realtime', $command->lockPath());
    }

    /**
     * The resolver is final, so a subclass cannot reintroduce the divergence by
     * overriding the resolution itself.
     *
     * `getJobLockFilePath()` stays overridable — that is a legitimate application
     * default. What must not be overridable is the part that prefers the supervisor.
     */
    public function testTheResolverIsFinalAndTheDefaultIsNot(): void
    {
        // Assert
        $this->assertTrue(
            (new \ReflectionMethod(CommandBase::class, 'resolvedJobLockFilePath'))->isFinal()
        );
        $this->assertFalse(
            (new \ReflectionMethod(CommandBase::class, 'getJobLockFilePath'))->isFinal(),
            'an application may still choose its own default'
        );
    }

    // -------------------------------------------------------------------------
    // The supervisor's half
    // -------------------------------------------------------------------------

    /**
     * A concrete orchestrator with nothing but the members the abstract class
     * requires.
     */
    private function orchestrator(): DaemonOrchestrator
    {
        return new class extends DaemonOrchestrator {
            protected function buildDesiredProcesses(): array
            {
                return [];
            }

            protected function getDashboardTitle(): string
            {
                return 'Test daemons';
            }

            protected function getEntryPoint(): string
            {
                return '/app/bin/pramnos';
            }

            protected function getJobName(): string
            {
                return 'test-orchestrator';
            }

            public function buildSpawn(string $php, array $spec): string
            {
                return $this->buildSpawnShellCommand($php, $spec);
            }
        };
    }

    /**
     * The spawn command exports the declared lock path.
     */
    public function testTheSpawnCommandExportsTheDeclaredLockPath(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator();

        // Act
        $shell = $orchestrator->buildSpawn('/usr/bin/php', [
            'id'       => 'realtime',
            'lockFile' => '/app/logs/realtime-7.lock',
            'tokens'   => ['realtime:serve'],
        ]);

        // Assert
        $this->assertStringContainsString(
            CommandBase::LOCK_FILE_ENV . "='/app/logs/realtime-7.lock'",
            $shell
        );
    }

    /**
     * A spec with no lock path exports nothing, rather than an empty assignment.
     *
     * An exported empty value would be read as "the supervisor said so" and defeat the
     * fallback — which is the behaviour every lock-less worker relies on.
     */
    public function testNoLockPathExportsNothing(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator();

        // Act
        $shell = $orchestrator->buildSpawn('/usr/bin/php', [
            'id'     => 'oneoff',
            'tokens' => ['queue:process'],
        ]);

        // Assert
        $this->assertStringNotContainsString(CommandBase::LOCK_FILE_ENV, $shell);
    }

    /**
     * The exported path is shell-escaped, so a directory with a space in it does not
     * split the assignment into two words.
     */
    public function testTheExportedPathIsEscaped(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator();

        // Act
        $shell = $orchestrator->buildSpawn('/usr/bin/php', [
            'id'       => 'realtime',
            'lockFile' => '/app/my logs/realtime.lock',
            'tokens'   => ['realtime:serve'],
        ]);

        // Assert
        $this->assertStringContainsString(escapeshellarg('/app/my logs/realtime.lock'), $shell);
    }

    /**
     * The export precedes the command, so it is an assignment for that process rather
     * than an argument to it.
     */
    public function testTheExportPrecedesTheCommand(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator();

        // Act
        $shell = $orchestrator->buildSpawn('/usr/bin/php', [
            'id'       => 'realtime',
            'lockFile' => '/app/logs/realtime.lock',
            'tokens'   => ['realtime:serve'],
        ]);

        // Assert
        $this->assertLessThan(
            strpos($shell, '/usr/bin/php'),
            strpos($shell, CommandBase::LOCK_FILE_ENV),
            'the assignment must come before the binary'
        );
    }
}
