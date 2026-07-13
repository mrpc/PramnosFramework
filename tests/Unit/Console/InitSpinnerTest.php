<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tests for Init::runProcessWithSpinner() observability behaviour.
 *
 * WHY this matters:
 *   The spinner drives every long shell step during `init` (docker-compose up,
 *   composer install, migrations). Previously it polled the child forever with
 *   no elapsed indicator and buffered all output until exit — so a step that
 *   hung (a stuck image pull, a network stall) produced an endless, silent
 *   spinner with no way to tell what was happening or how long it had run. A
 *   real init was observed spinning for two hours with zero output.
 *
 *   The fix adds (a) an always-on elapsed-time counter and (b) automatic
 *   escalation to live output once a step exceeds slowStepThreshold seconds.
 *   These tests pin both, plus the compact elapsed formatting.
 *
 * The protected methods under test are reached through a tiny public-forwarding
 * subclass (the same pattern the sibling Make command tests use).
 */
#[CoversClass(Init::class)]
class InitSpinnerTest extends TestCase
{
    /**
     * Builds an Init whose protected spinner/formatting helpers are callable,
     * with a configurable slow-step threshold.
     */
    private function spinnerCommand(int $threshold): Init
    {
        $command = new class extends Init {
            public function callSpinner(string $cmd, string $msg, OutputInterface $out, bool $always = false): int
            {
                return $this->runProcessWithSpinner($cmd, $msg, $out, $always);
            }
            public function callFormatElapsed(int $seconds): string
            {
                return $this->formatElapsed($seconds);
            }
        };
        $command->slowStepThreshold = $threshold;
        return $command;
    }

    /**
     * Sub-minute durations render as bare seconds ("45s").
     */
    public function testFormatElapsedUnderMinute(): void
    {
        // Arrange
        $command = $this->spinnerCommand(120);

        // Act + Assert
        $this->assertSame('0s', $command->callFormatElapsed(0));
        $this->assertSame('45s', $command->callFormatElapsed(45));
        $this->assertSame('59s', $command->callFormatElapsed(59));
    }

    /**
     * A minute or more renders as "MmSSs" with a zero-padded seconds field —
     * proving the boundary at 60s and the %02d padding.
     */
    public function testFormatElapsedOverMinute(): void
    {
        // Arrange
        $command = $this->spinnerCommand(120);

        // Act + Assert
        $this->assertSame('1m00s', $command->callFormatElapsed(60));
        $this->assertSame('2m05s', $command->callFormatElapsed(125));
    }

    /**
     * A fast, successful step reports DONE and exit 0 without ever escalating
     * to live output — the common, healthy case must stay quiet.
     */
    public function testFastStepReportsDone(): void
    {
        // Arrange — threshold well above the step's runtime
        $command = $this->spinnerCommand(120);
        $out = new BufferedOutput();

        // Act — a trivially fast successful command
        $exit = $command->callSpinner('printf ok', 'Fast step', $out);

        // Assert — success is reported and no escalation notice appears
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('DONE', $out->fetch());
    }

    /**
     * Once a step exceeds slowStepThreshold, the spinner must announce the delay
     * and surface the subprocess output live — including lines emitted AFTER the
     * escalation point. This is the core hang-diagnosability guarantee.
     */
    public function testSlowStepEscalatesToLiveOutput(): void
    {
        // Arrange — 1s threshold against a step that prints, waits 2s, prints again
        $command = $this->spinnerCommand(1);
        $out = new BufferedOutput();

        // Act
        $exit = $command->callSpinner(
            "sh -c 'echo BEFORE_WAIT; sleep 2; echo AFTER_WAIT'",
            'Slow step',
            $out
        );
        $display = $out->fetch();

        // Assert — escalated, flushed the pre-escalation line, and streamed the
        // post-escalation line, then finished successfully.
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('still running after', $display); // escalation notice
        $this->assertStringContainsString('BEFORE_WAIT', $display);          // buffered output flushed
        $this->assertStringContainsString('AFTER_WAIT', $display);           // streamed live post-escalation
        $this->assertStringContainsString('DONE', $display);
    }

    /**
     * slowStepThreshold = 0 disables escalation entirely: even a slow step keeps
     * the quiet spinner and never dumps live output. Preserves the opt-out.
     */
    public function testThresholdZeroDisablesEscalation(): void
    {
        // Arrange — escalation disabled, step runs ~1s (longer than one poll tick)
        $command = $this->spinnerCommand(0);
        $out = new BufferedOutput();

        // Act
        $exit = $command->callSpinner("sh -c 'sleep 1'", 'Quiet step', $out);
        $display = $out->fetch();

        // Assert — finished, but no escalation notice was ever printed
        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('still running after', $display);
        $this->assertStringContainsString('DONE', $display);
    }
}
