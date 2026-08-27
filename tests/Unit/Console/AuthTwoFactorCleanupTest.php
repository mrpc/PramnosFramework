<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\AuthTwoFactorCleanup;
use Pramnos\Scheduling\FrameworkSchedule;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Something finally runs the second-factor cleanups.
 *
 * `EmailSecondFactor::cleanupExpired()` and `TwoFactorAuthService::cleanupExpiredSessions()`
 * both existed with no caller anywhere — the same way `cleanupAllAuthTokens()` sat unused
 * until `auth:token-cleanup` was written. Nothing goes wrong when a table of expired secrets
 * keeps every row it has written, which is exactly why nobody notices for a year.
 *
 * So the assertions here are about the parts that decide whether it *runs*: that the schedule
 * knows the command, that one missing table does not stop the other sweep, and that a real
 * error is still reported. Whether the DELETEs are correct belongs to the two classes' own
 * tests.
 */
#[CoversClass(AuthTwoFactorCleanup::class)]
class AuthTwoFactorCleanupTest extends TestCase
{
    /**
     * The framework schedules it, so an installation does not have to.
     *
     * The point the rest is worth nothing without.
     */
    public function testTheCleanupIsScheduled(): void
    {
        // Act + Assert
        $this->assertContains('auth:twofactor-cleanup', FrameworkSchedule::commands());
    }

    /**
     * A missing table is not a failure, and does not stop the other sweep.
     *
     * The two tables arrive with different migrations, so an installation mid-upgrade has
     * one and not the other. And the schedule runs this everywhere, including on
     * applications that never enabled `authserver` — a daily red line for a table nobody
     * meant to create is how a log stops being read.
     */
    public function testAMissingTableIsSkippedAndTheOtherSweepStillRuns(): void
    {
        // Arrange — the first sweep reports a missing table, the second must still be tried
        $ran = [];
        $command = new class ($ran) extends AuthTwoFactorCleanup {
            /** @var array<int, string> */
            public array $ran = [];

            public function __construct(array &$ran)
            {
                parent::__construct();
                $this->ran = &$ran;
            }

            protected function sweeps(): array
            {
                return [
                    'first' => function (): void {
                        $this->ran[] = 'first';
                        throw new \RuntimeException("Table 'x.first' doesn't exist");
                    },
                    'second' => function (): void {
                        $this->ran[] = 'second';
                    },
                ];
            }
        };

        // Act
        $output = new BufferedOutput();
        $status = $command->run(new ArrayInput([]), $output);

        // Assert
        $this->assertSame(0, $status, 'a missing table is not a failure');
        $this->assertSame(['first', 'second'], $command->ran,
            'the sweep after the missing one has to be attempted');
        $this->assertStringNotContainsString('<error>', $output->fetch());
    }

    /**
     * A real error is reported, and names which sweep failed.
     */
    public function testARealErrorFailsAndSaysWhichSweep(): void
    {
        // Arrange
        $command = new class () extends AuthTwoFactorCleanup {
            protected function sweeps(): array
            {
                return [
                    'twofactor_email_codes' => static function (): void {
                        throw new \RuntimeException('deadlock detected');
                    },
                ];
            }
        };

        // Act
        $output = new BufferedOutput();
        $status = $command->run(new ArrayInput([]), $output);

        // Assert
        $this->assertSame(1, $status);
        $this->assertStringContainsString('twofactor_email_codes', $output->fetch());
    }

    /**
     * Every driver's way of saying "no such table" is recognised.
     *
     * One phrase each, and they do not resemble each other. A missed phrase turns a
     * skipped sweep into a daily failure on one database engine only, which is the kind of
     * thing that is discovered from a log nobody was reading.
     */
    public function testEachDriversMissingTableWordingIsRecognised(): void
    {
        // Arrange
        $check = new \ReflectionMethod(AuthTwoFactorCleanup::class, 'looksLikeMissingTable');
        $command = new AuthTwoFactorCleanup();

        // Act + Assert
        foreach ([
            "Table 'db.twofactor_email_codes' doesn't exist",
            'relation "authserver.twofactor_setup" does not exist',
            'SQLSTATE[42P01]: Undefined table',
            'no such table: twofactor_setup',
        ] as $message) {
            $this->assertTrue(
                $check->invoke($command, new \RuntimeException($message)),
                $message . ' must be recognised as a missing table'
            );
        }

        // …and something that is not
        $this->assertFalse($check->invoke($command, new \RuntimeException('deadlock detected')));
    }
}
