<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\AuthTokenCleanup;
use Pramnos\Console\Commands\MessagesDispatch;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The two commands the framework schedule runs on every installation, whatever it has installed.
 *
 * `auth:token-cleanup` measured 34% covered and `messages:dispatch` 30%: the `looksLikeMissingTable()`
 * helper had tests and `execute()` had none, on either. Which is the wrong half to have covered,
 * because the helper is four `str_contains` calls and `execute()` is the part with a decision in it —
 * three decisions, in fact, and the schedule depends on all three:
 *
 * - **a missing table is not a failure.** Both features are optional and the schedule runs
 *   everywhere; a red line every day on an installation that simply has no messaging is how a
 *   schedule log stops being read.
 * - **any other error is a failure**, and says what it was.
 * - **nothing due is quiet.** `messages:dispatch` runs every few minutes.
 *
 * The tests reach those arms through `retire()` and `dispatcher()`, which exist so that they can.
 * A static call and a `new` written inline in the `try` cannot be made to throw from a test, so
 * before the seams no arm here was reachable at all.
 */
#[CoversClass(AuthTokenCleanup::class)]
#[CoversClass(MessagesDispatch::class)]
class ScheduledCleanupCommandsTest extends TestCase
{
    /** A cleanup command whose one collaborator does whatever the test needs. */
    private function cleanup(?\Throwable $failure): AuthTokenCleanup
    {
        return new class ($failure) extends AuthTokenCleanup {
            /** @var list<int> The day counts it was asked for */
            public array $asked = [];

            public function __construct(private readonly ?\Throwable $failure)
            {
                parent::__construct();
            }

            protected function retire(int $days): void
            {
                $this->asked[] = $days;

                if ($this->failure !== null) {
                    throw $this->failure;
                }
            }
        };
    }

    /**
     * The idle window comes from `--days`, and one day is the floor.
     *
     * `--days=0` would retire every token in the table including the one the caller is holding,
     * and `max(1, …)` is what stops a typo from signing everybody out.
     */
    public function testTheIdleWindowIsReadFromTheOptionAndNeverFallsBelowOneDay(): void
    {
        // Arrange
        $command = $this->cleanup(null);
        $tester  = new CommandTester($command);

        // Act
        $tester->execute(['--days' => '90']);
        $tester->execute(['--days' => '0']);
        $tester->execute([]);

        // Assert
        $this->assertSame([90, 1, 30], $command->asked, 'the default is 30 days and the floor is 1');
    }

    /**
     * Quiet on success, and only verbose output explains itself.
     *
     * A daily scheduled command that prints a line on every run is a line nobody reads.
     */
    public function testItSaysNothingUnlessAskedTo(): void
    {
        // Act
        $quiet = new CommandTester($this->cleanup(null));
        $quiet->execute([]);

        $loud = new CommandTester($this->cleanup(null));
        $loud->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

        // Assert
        $this->assertSame(Command::SUCCESS, $quiet->getStatusCode());
        $this->assertSame('', trim($quiet->getDisplay()));
        $this->assertStringContainsString('retired', $loud->getDisplay());
        $this->assertStringContainsString('30 days', $loud->getDisplay());
    }

    /**
     * A missing table succeeds — the whole reason the helper exists.
     *
     * Each driver's own words, because none of them offers a portable code the query builder passes
     * on. All four are asserted, so a driver whose wording is not matched shows up here rather than
     * as a daily red line on somebody's installation.
     *
     * @return array<string, array{0: string}>
     */
    public static function missingTableMessages(): array
    {
        return [
            'MySQL'      => ["Table 'db.pcms_usertokens' doesn't exist"],
            'MariaDB'    => ["Table 'db.pcms_usertokens' doesn't exist"],
            'PostgreSQL' => ['ERROR:  relation "pcms_usertokens" does not exist'],
            'Timescale'  => ['SQLSTATE[42P01]: Undefined table: 7 ERROR'],
            'SQLite'     => ['no such table: pcms_usertokens'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingTableMessages')]
    public function testAMissingTableIsNotAFailure(string $message): void
    {
        // Act
        $tester = new CommandTester($this->cleanup(new \RuntimeException($message)));
        $tester->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

        // Assert
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $message . ' was treated as a failure');
        $this->assertStringContainsString('nothing to do', $tester->getDisplay());
    }

    /** The same, without `-v`: still success, and still silent. */
    public function testAMissingTableIsSilentWithoutVerbose(): void
    {
        // Act
        $tester = new CommandTester($this->cleanup(new \RuntimeException('no such table: pcms_usertokens')));
        $tester->execute([]);

        // Assert
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertSame('', trim($tester->getDisplay()));
    }

    /**
     * Any other error fails, and says what it was.
     *
     * The counterpart to the test above, and the reason that one is not simply `return SUCCESS`:
     * a connection refused or a permission denied has to be visible.
     */
    public function testARealErrorFailsAndIsPrinted(): void
    {
        // Act
        $tester = new CommandTester($this->cleanup(new \RuntimeException('Connection refused')));
        $tester->execute([]);

        // Assert
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Connection refused', $tester->getDisplay());
    }

    /** A dispatch command whose dispatcher reports whatever the test needs. */
    private function dispatch(array $stats, ?\Throwable $failure = null): MessagesDispatch
    {
        return new class ($stats, $failure) extends MessagesDispatch {
            /** @var list<int> The batch limits it was asked for */
            public array $limits = [];

            public function __construct(
                private readonly array $stats,
                private readonly ?\Throwable $failure
            ) {
                parent::__construct();
            }

            protected function dispatcher(): \Pramnos\Messaging\MassMessageDispatcher
            {
                $outer = $this;

                return new class ($outer, $this->stats, $this->failure)
                    extends \Pramnos\Messaging\MassMessageDispatcher {
                    public function __construct(
                        private readonly object $outer,
                        private readonly array $stats,
                        private readonly ?\Throwable $failure
                    ) {
                    }

                    public function dispatch(int $limit = self::DEFAULT_BATCH): array
                    {
                        $this->outer->limits[] = $limit;

                        if ($this->failure !== null) {
                            throw $this->failure;
                        }

                        return $this->stats;
                    }
                };
            }
        };
    }

    /**
     * Nothing due is quiet, even though this runs every few minutes.
     *
     * `attempted === 0` is its own arm rather than falling through to the counts line, because
     * "0 delivered, 0 failed, of 0 attempted." every five minutes is noise that hides the runs
     * that did something.
     */
    public function testNothingDueIsQuiet(): void
    {
        // Act
        $quiet = new CommandTester($this->dispatch(['attempted' => 0, 'delivered' => 0, 'failed' => 0]));
        $quiet->execute([]);

        $loud = new CommandTester($this->dispatch(['attempted' => 0, 'delivered' => 0, 'failed' => 0]));
        $loud->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

        // Assert
        $this->assertSame(Command::SUCCESS, $quiet->getStatusCode());
        $this->assertSame('', trim($quiet->getDisplay()));
        $this->assertStringContainsString('Nothing due', $loud->getDisplay());
    }

    /**
     * A run that did something reports its counts, unasked.
     *
     * And succeeds with failures in it: a dead address is a delivery failure, the row is marked,
     * and the next run carries on. Exiting non-zero would make the schedule log red for something
     * no operator can act on.
     */
    public function testARunWithWorkInItReportsCountsAndStillSucceeds(): void
    {
        // Arrange
        $command = $this->dispatch(['attempted' => 40, 'delivered' => 37, 'failed' => 3]);
        $tester  = new CommandTester($command);

        // Act
        $tester->execute(['--limit' => '40']);

        // Assert
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), 'delivery failures are not command failures');
        $this->assertStringContainsString('37 delivered, 3 failed, of 40 attempted.', $tester->getDisplay());
        $this->assertSame([40], $command->limits);
    }

    /**
     * The batch limit has the same floor as the idle window, and the same default.
     *
     * `--limit=0` would dispatch nothing forever while reporting success — a schedule that looks
     * healthy and delivers no mail.
     */
    public function testTheBatchLimitNeverFallsBelowOne(): void
    {
        // Arrange
        $command = $this->dispatch(['attempted' => 0, 'delivered' => 0, 'failed' => 0]);
        $tester  = new CommandTester($command);

        // Act
        $tester->execute(['--limit' => '0']);
        $tester->execute([]);

        // Assert
        $this->assertSame(
            [1, \Pramnos\Messaging\MassMessageDispatcher::DEFAULT_BATCH],
            $command->limits,
            'the floor is 1 and the default is the dispatcher\'s own batch size'
        );
    }

    /** A missing messaging schema succeeds: the feature is optional and the schedule is not. */
    public function testDispatchSurvivesAnInstallationWithoutMessaging(): void
    {
        // Act
        $tester = new CommandTester($this->dispatch(
            [],
            new \RuntimeException('ERROR:  relation "pcms_massmessages" does not exist')
        ));
        $tester->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

        // Assert
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No massmessages table', $tester->getDisplay());
    }

    /** And any other dispatch error fails loudly. */
    public function testDispatchReportsARealError(): void
    {
        // Act
        $tester = new CommandTester($this->dispatch([], new \RuntimeException('SMTP timed out')));
        $tester->execute([]);

        // Assert
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('SMTP timed out', $tester->getDisplay());
    }
}
