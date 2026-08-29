<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MailPrune;

/**
 * The command that deletes an audit trail, and the two numbers that decide how much of it.
 *
 * Everything asserted here is a guard rather than a feature: the difference between `90d` and
 * `90` is three months of somebody's mail log, and a policy that is quietly assumed is worse
 * than none at all.
 */
#[CoversClass(MailPrune::class)]
class MailPruneTest extends TestCase
{
    /**
     * A duration reads as the duration it says.
     */
    public function testEveryUnitIsRead(): void
    {
        // Arrange
        $command = $this->command();

        // Assert
        $this->assertSame(90 * 86400, $command->probeSeconds('90d'));
        $this->assertSame(6 * 2592000, $command->probeSeconds('6m'));
        $this->assertSame(6 * 2592000, $command->probeSeconds('6mo'));
        $this->assertSame(2 * 31536000, $command->probeSeconds('2y'));
        $this->assertSame(3 * 604800, $command->probeSeconds('3w'));
        $this->assertSame(12 * 3600, $command->probeSeconds('12h'));
    }

    /**
     * A bare number is minutes, and it is the one unit nobody types by accident.
     *
     * Seconds would make `90` mean a minute and a half — a value that deletes nothing and looks
     * like it worked. Days would make it mean three months.
     */
    public function testABareNumberIsMinutes(): void
    {
        // Assert
        $this->assertSame(90 * 60, $this->command()->probeSeconds('90'));
    }

    /**
     * Something that is not a duration is **zero**, which means "no policy", not "everything".
     *
     * The failure that matters: a typo that parsed as a very small number would delete an
     * entire mail log on a scheduled run, and nothing would ever say why.
     */
    public function testATypoIsNoPolicyRatherThanEverything(): void
    {
        // Arrange
        $command = $this->command();

        // Assert
        foreach (['ninety days', '90 days', 'd90', '-30d', '', '   ', 'yesterday'] as $value) {
            $this->assertSame(0, $command->probeSeconds($value), $value);
        }
    }

    /**
     * With no policy configured and none passed, it explains rather than choosing one.
     *
     * Picking a default here would apply somebody's guess to an audit trail on the first run of
     * a command they were only exploring.
     */
    public function testWithNoPolicyItExplainsInsteadOfChoosingOne(): void
    {
        // Act
        $display = $this->display([]);

        // Assert
        $this->assertStringContainsString('No retention policy is configured', $display);
        $this->assertStringContainsString('strip_after', $display, 'and says where to put one');
        $this->assertStringNotContainsString('deleted', $display);
    }

    /**
     * A dry run is the default, and it says what it would do.
     */
    public function testItIsADryRunUnlessTold(): void
    {
        // Act
        $display = $this->display(['--strip-after' => '90d', '--delete-after' => '2y']);

        // Assert
        $this->assertStringContainsString('Dry run', $display);
        $this->assertStringContainsString('strip', $display);
        $this->assertStringContainsString('delete', $display);
        $this->assertSame(0, $this->tester(['--strip-after' => '90d'])->getStatusCode());
    }

    /**
     * `--apply` deletes before it strips.
     *
     * The other order strips a body and then deletes the row it belonged to — the same outcome,
     * having written every one of those rows twice.
     */
    public function testApplyDeletesBeforeItStrips(): void
    {
        // Arrange
        $command = $this->command();

        // Act
        $display = $this->tester(
            ['--strip-after' => '90d', '--delete-after' => '2y', '--apply' => true],
            $command
        )->getDisplay();

        // Assert
        $this->assertSame(['prune', 'strip'], $command->ran,
            'stripping a body and then deleting its row writes every one of them twice');
        $this->assertMatchesRegularExpression('~deleted.*stripped~s', $display);
        $this->assertStringNotContainsString('Dry run', $display);
    }

    /**
     * The report says what the bodies cost, because that is the whole argument.
     *
     * Rows against body bytes are two orders of magnitude apart, and it is the only number that
     * makes stripping look different from deleting.
     */
    public function testTheReportSaysWhatTheBodiesCost(): void
    {
        // Act
        $display = $this->display([]);

        // Assert
        $this->assertStringContainsString('Still with body', $display);
        $this->assertMatchesRegularExpression('~\d+(\.\d+)? (B|KB|MB|GB)~', $display);
    }

    /**
     * A database that will not answer is reported, not thrown.
     *
     * The command a scheduled job runs at four in the morning. A fatal there is a cron mail
     * nobody reads; a reported failure is an exit code the supervisor can act on.
     */
    public function testADatabaseFailureIsReported(): void
    {
        // Arrange
        $command = new class extends MailPrune {
            protected function stats(int $stripAfter, int $deleteAfter): array
            {
                return ['error' => 'Database is not connected'];
            }
        };

        $application = new \Symfony\Component\Console\Application();
        $application->add($command);

        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);

        // Act
        $tester->execute([], ['interactive' => false]);

        // Assert
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Database is not connected', $tester->getDisplay());
    }

    /**
     * A recipients policy is reported and applied like the other two.
     */
    public function testTheRecipientsPolicyIsReportedAndApplied(): void
    {
        // Arrange
        $command = $this->command();

        // Act
        $dry     = $this->display(['--recipients-after' => '6m']);
        $display = $this->tester(['--recipients-after' => '6m', '--apply' => true], $command)->getDisplay();

        // Assert
        $this->assertStringContainsString('recipient rows', $dry);
        $this->assertSame(['recipients'], $command->ran);
        $this->assertStringContainsString('removed', $display);
    }

    /**
     * A second is a second, and a day reads as days rather than as seconds.
     *
     * The report is what somebody checks a policy against before applying it, and "7776000
     * seconds" is not something anybody verifies.
     */
    public function testDurationsAreReportedInTheUnitsPeopleThinkIn(): void
    {
        // Arrange
        $command = $this->command();

        // Assert
        $this->assertSame(30, $command->probeSeconds('30s'));
        $this->assertSame('14 days', $command->probeHuman(14 * 86400));
        $this->assertSame('3 months', $command->probeHuman(3 * 2592000));
        $this->assertSame('2 years', $command->probeHuman(2 * 31536000));
        $this->assertSame('45 seconds', $command->probeHuman(45));

        // «1 months» reads as a bug, and a reader who thinks the tool is buggy checks the
        // number they are about to delete an audit trail with less carefully, not more.
        $this->assertSame('1 month', $command->probeHuman(2592000));
        $this->assertSame('1 day', $command->probeHuman(86400));
    }

    /**
     * Sizes are reported in the unit that makes them legible.
     *
     * The whole argument for stripping is that the bodies are gigabytes and the rows are
     * megabytes, and a number in bytes hides exactly that.
     */
    public function testSizesAreReportedLegibly(): void
    {
        // Arrange
        $command = $this->command();

        // Assert
        $this->assertSame('512 B', $command->probeSize(512));
        $this->assertSame('2 KB', $command->probeSize(2048));
        $this->assertSame('1.5 MB', $command->probeSize(1_572_864));
        $this->assertSame('2.5 GB', $command->probeSize(2_684_354_560));
    }

    /**
     * The seams reach the real retention layer when nothing overrides them.
     *
     * Every other test here substitutes a double, so without this the command could be wired to
     * nothing at all and the suite would stay green.
     */
    public function testTheSeamsReachTheRealRetention(): void
    {
        // Arrange — no policy, so nothing is touched whatever the database says
        $command = new class extends MailPrune {
            /** @return array<string, mixed> */
            public function probeStats(): array { return $this->stats(0, 0); }

            public function probeStrip(): int { return $this->strip(0); }

            public function probePrune(): int { return $this->prune(0); }

            public function probeRecipients(): int { return $this->pruneRecipients(0); }
        };

        // Assert
        $this->assertIsArray($command->probeStats());
        $this->assertSame(0, $command->probeStrip());
        $this->assertSame(0, $command->probePrune());
        $this->assertSame(0, $command->probeRecipients());
    }

    /** @param array<string, mixed> $input */
    private function display(array $input): string
    {
        return $this->tester($input)->getDisplay();
    }

    /** @param array<string, mixed> $input */
    private function tester(array $input, ?object $command = null): \Symfony\Component\Console\Tester\CommandTester
    {
        $command ??= $this->command();

        $application = new \Symfony\Component\Console\Application();
        $application->add($command);

        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    /**
     * A command with a mail log that is not a database.
     *
     * Fixed numbers, so what is asserted is the reporting rather than whatever happens to be in
     * a test table — and the two counters record the order the operations ran in, which is the
     * one thing about them that is not obvious.
     */
    private function command(): object
    {
        return new class extends MailPrune {
            /** @var list<string> */
            public array $ran = [];

            public function probeSeconds(string $value): int
            {
                return $this->seconds($value, 'nothing_configured_by_this_name');
            }

            public function probeHuman(int $seconds): string { return $this->human($seconds); }

            public function probeSize(int $bytes): string { return $this->size($bytes); }

            protected function stats(int $stripAfter, int $deleteAfter): array
            {
                return [
                    'rows'         => 3627,
                    'with_body'    => 3627,
                    'body_bytes'   => 7_235_000,
                    'oldest'       => 1_756_000_000,
                    'newest'       => 1_756_400_000,
                    'would_strip'  => $stripAfter > 0 ? 1360 : 0,
                    'would_delete' => $deleteAfter > 0 ? 12 : 0,
                ];
            }

            protected function strip(int $olderThan): int
            {
                $this->ran[] = 'strip';

                return 1360;
            }

            protected function prune(int $olderThan): int
            {
                $this->ran[] = 'prune';

                return 12;
            }

            protected function pruneRecipients(int $olderThan): int
            {
                $this->ran[] = 'recipients';

                return 0;
            }
        };
    }
}
