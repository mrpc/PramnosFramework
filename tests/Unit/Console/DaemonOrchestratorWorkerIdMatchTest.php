<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\DaemonOrchestrator;

/**
 * A worker id that is a prefix of another must not be mistaken for it.
 *
 * `findRunningPidsByWorkerSignature()` asked
 * `strpos($cmdline, '--worker-id ' . $workerId)` — a substring test — and it **capped every
 * pool at nine workers per profile**. `queue-x-1` is found in `queue-x-10`, `queue-x-11`,
 * `queue-x-12`, so `deduplicateRunningProcesses()`, whose whole job is killing genuine
 * double-spawns, killed workers 10 and above as duplicates of worker 1 within a cycle of
 * being spawned.
 *
 * Reported from an installation with ceilings of 15 and 20 configured, where the journal
 * repeated this every 31 seconds for hours:
 *
 * ```
 * [started] queue-getnetworkserverdata-10 pid=293679
 * [started] queue-getnetworkserverdata-11 pid=294639
 * [dedup]   killed duplicate queue-getnetworkserverdata-1 pid=293679
 * [dedup]   killed duplicate queue-getnetworkserverdata-1 pid=294639
 * [exited]  queue-getnetworkserverdata-10 — daemon shutdown cleanly
 * ```
 *
 * **The symptom pointed at the wrong place entirely.** *"Burst is not working"* was
 * investigated three times against the thresholds, the load gate and the stored settings —
 * and each investigation correctly confirmed the policy and therefore concluded the
 * configuration must be wrong. The policy was asking for eleven workers and getting them;
 * something else was killing two of them every cycle.
 *
 * **Nine is not a special number.** It is the boundary only because ids are decimal, and any
 * identifier that is a prefix of another has the same hole — no separator convention closes
 * it while the test is a substring search. `cmdline` is NUL-separated, so the arguments
 * arrive already delimited, and comparing them as values removes the question rather than
 * answering it.
 */
#[CoversClass(DaemonOrchestrator::class)]
class DaemonOrchestratorWorkerIdMatchTest extends TestCase
{
    private function orchestrator(): object
    {
        return new class extends DaemonOrchestrator {
            public function __construct()
            {
                // The real constructor wants an application; the matcher wants nothing.
            }

            // The four the base class declares. None is reached from here — this test is
            // about one comparison — and a concrete subclass has to supply them.
            protected function buildDesiredProcesses(): array
            {
                return [];
            }

            protected function getDashboardTitle(): string
            {
                return 'probe';
            }

            protected function getEntryPoint(): string
            {
                return 'pramnos';
            }

            protected function getJobName(): string
            {
                return 'probe';
            }

            /** @param array<int, string> $arguments */
            public function matchArgs(array $arguments, string $workerId): bool
            {
                return $this->argumentsNameWorker($arguments, $workerId);
            }

            public function matchLine(string $line, string $workerId): bool
            {
                return $this->commandLineNamesWorker($line, $workerId);
            }
        };
    }

    /**
     * The arguments of a real worker, as `/proc/<pid>/cmdline` delivers them.
     *
     * @return array<int, string>
     */
    private function cmdline(string $workerId): array
    {
        return ['php', 'pramnos', 'queue:process', '--daemon', '--worker-id', $workerId];
    }

    /**
     * Worker 1 is worker 1, and worker 10 is not.
     *
     * The reported bug in one assertion. Every id from 10 up was collateral.
     */
    public function testAPrefixIsNotAMatch(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator();

        // Assert — its own id matches
        $this->assertTrue($orchestrator->matchArgs($this->cmdline('queue-x-1'), 'queue-x-1'));

        // and the ones that used to be killed as its duplicates do not
        foreach (['queue-x-10', 'queue-x-11', 'queue-x-12', 'queue-x-100'] as $other) {
            $this->assertFalse(
                $orchestrator->matchArgs($this->cmdline($other), 'queue-x-1'),
                $other . ' was read as a duplicate of queue-x-1'
            );
        }
    }

    /**
     * And the reverse: a longer id is not matched by a shorter process.
     *
     * The other direction of the same mistake, which a fix that merely compared lengths would
     * still get wrong.
     */
    public function testALongerIdIsNotMatchedByAShorterProcess(): void
    {
        // Assert
        $this->assertFalse(
            $this->orchestrator()->matchArgs($this->cmdline('queue-x-1'), 'queue-x-10')
        );
    }

    /**
     * `--worker-id=x` is the same request as `--worker-id x`.
     *
     * Both spellings reach a PHP CLI application, and a matcher that understood one would
     * report a whole pool as absent — so the supervisor would spawn a second set beside the
     * first and then kill nothing, because it can see neither.
     */
    public function testBothSpellingsOfTheOptionAreUnderstood(): void
    {
        // Arrange
        $joined = ['php', 'pramnos', 'queue:process', '--worker-id=queue-x-7'];

        // Assert
        $this->assertTrue($this->orchestrator()->matchArgs($joined, 'queue-x-7'));
        $this->assertFalse($this->orchestrator()->matchArgs($joined, 'queue-x-70'));
    }

    /**
     * A process with no `--worker-id` at all is nobody's worker.
     *
     * The control: a matcher that answered true on absence would report every PHP process on
     * the machine as a duplicate of every slot, and the deduplicator would kill them.
     */
    public function testAProcessWithoutTheOptionNeverMatches(): void
    {
        // Assert
        $this->assertFalse(
            $this->orchestrator()->matchArgs(['php', 'pramnos', 'queue:process', '--daemon'], 'queue-x-1')
        );
        $this->assertFalse($this->orchestrator()->matchArgs([], 'queue-x-1'));
    }

    /**
     * A dangling `--worker-id` with nothing after it does not match either.
     *
     * The shape a truncated `cmdline` read produces, and reading past the end of the array
     * would be a notice inside a supervisor's hot loop.
     */
    public function testADanglingOptionDoesNotMatch(): void
    {
        // Assert
        $this->assertFalse(
            $this->orchestrator()->matchArgs(['php', 'pramnos', '--worker-id'], 'queue-x-1')
        );
    }

    /**
     * An empty id matches nothing, rather than everything.
     *
     * `deduplicateRunningProcesses()` already skips a slot with no id, but the matcher is
     * `protected` and reachable on its own — and "match anything" is the worst possible
     * answer for something whose caller kills what it finds.
     */
    public function testAnEmptyIdMatchesNothing(): void
    {
        // Assert
        $this->assertFalse($this->orchestrator()->matchArgs($this->cmdline('queue-x-1'), ''));
    }

    /**
     * The `ps aux` fallback is bounded too, for a platform with no `/proc`.
     *
     * There the arguments arrive as one space-joined string, so there is nothing to compare
     * as a value and only a bounded match can tell `-1` from `-10`. Easy to fix one path and
     * leave the other, and the other is the one nobody runs — until somebody does.
     */
    public function testThePsFallbackIsBoundedAsWell(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator();
        $line = 'www-data 293679  0.5  0.1 php pramnos queue:process --daemon --worker-id queue-x-10';

        // Assert
        $this->assertTrue($orchestrator->matchLine($line, 'queue-x-10'));
        $this->assertFalse($orchestrator->matchLine($line, 'queue-x-1'), 'the prefix matched');

        // and at the end of the line, which is where the last argument usually is
        $this->assertTrue($orchestrator->matchLine('php x --worker-id queue-x-1', 'queue-x-1'));
    }

    /**
     * A regex metacharacter in an id is a literal, not a pattern.
     *
     * Ids are configured, so a `.` or a `+` in one is a typo rather than an attack — and a
     * `.` that matched any character would make one slot match its neighbours, which is this
     * whole bug again by a different route.
     */
    public function testAMetacharacterInAnIdIsLiteral(): void
    {
        // Arrange
        $orchestrator = $this->orchestrator();

        // Assert
        $this->assertTrue($orchestrator->matchLine('php x --worker-id q.1', 'q.1'));
        $this->assertFalse($orchestrator->matchLine('php x --worker-id qx1', 'q.1'), 'the dot matched');
    }
}
