<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Scheduling\CronExpression;

/**
 * When a task runs next, when it was last due, and what its expression says in words.
 *
 * The scheduler screen shows a next run for every task and marks one late when the run its
 * expression last asked for is missing; both come from here. A wrong next run sends an
 * operator to wait at the wrong time, and a wrong previous one marks a healthy task late.
 */
#[CoversClass(CronExpression::class)]
final class CronExpressionNextRunTest extends TestCase
{
    /**
     * The next minute after a moment, for the shapes the schedule uses — including a list of
     * minutes, where the next is not one interval on.
     */
    #[DataProvider('nextRuns')]
    public function testTheNextRun(string $expression, string $after, string $expected): void
    {
        // Arrange
        $cron = new CronExpression($expression);

        // Act
        $next = $cron->nextRunAfter(new \DateTimeImmutable($after));

        // Assert
        $this->assertSame($expected, $next?->format('Y-m-d H:i'));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function nextRuns(): array
    {
        return [
            'a list of minutes'         => ['5,20,35,50 * * * *', '2026-10-21 03:21:09', '2026-10-21 03:35'],
            'past the last in the hour' => ['5,20,35,50 * * * *', '2026-10-21 03:50:00', '2026-10-21 04:05'],
            'strictly after: not now'   => ['* * * * *', '2026-10-21 03:21:00', '2026-10-21 03:22'],
            'daily, tomorrow'           => ['30 2 * * *', '2026-10-21 03:00:00', '2026-10-22 02:30'],
            'weekly, Monday 11:15'      => ['15 11 * * 1', '2026-10-21 12:00:00', '2026-10-26 11:15'],
            'yearly, over the new year' => ['0 0 1 1 *', '2026-10-21 12:00:00', '2027-01-01 00:00'],
        ];
    }

    /**
     * The last minute a run was due before a moment — 03:20 for a pass at 03:21:09, the minute
     * the production incident lost.
     */
    public function testThePreviousRun(): void
    {
        // Arrange
        $cron = new CronExpression('5,20,35,50 * * * *');

        // Act
        $previous = $cron->previousRunBefore(new \DateTimeImmutable('2026-10-21 03:21:09'));
        $acrossMidnight = (new CronExpression('30 23 * * *'))->previousRunBefore(new \DateTimeImmutable('2026-10-21 00:10'));

        // Assert
        $this->assertSame('2026-10-21 03:20', $previous?->format('Y-m-d H:i'));
        $this->assertSame('2026-10-20 23:30', $acrossMidnight?->format('Y-m-d H:i'));
    }

    /**
     * An expression that never matches answers null rather than looping for ever.
     */
    public function testAnExpressionThatNeverMatchesHasNoRun(): void
    {
        // Arrange — the 31st of February
        $cron = new CronExpression('0 0 31 2 *');

        // Act / Assert
        $this->assertNull($cron->nextRunAfter(new \DateTimeImmutable('2026-10-21')));
        $this->assertNull($cron->previousRunBefore(new \DateTimeImmutable('2026-10-21')));
    }

    /**
     * The expression in words, and the expression itself for a shape with no wording.
     */
    #[DataProvider('descriptions')]
    public function testTheExpressionInWords(string $expression, string $words): void
    {
        // Act
        $described = (new CronExpression($expression))->describe();

        // Assert
        $this->assertSame($words, $described);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function descriptions(): array
    {
        return [
            'every minute'       => ['* * * * *', 'Every minute'],
            'every 5 minutes'    => ['*/5 * * * *', 'Every 5 minutes'],
            'a list of minutes'  => ['5,20,35,50 * * * *', 'At :05, :20, :35, :50 every hour'],
            'hourly'             => ['0 * * * *', 'At :00 every hour'],
            'every 6 hours'      => ['15 */6 * * *', 'Every 6 hours at :15'],
            'daily'              => ['30 2 * * *', 'Daily at 02:30'],
            'weekly'             => ['15 11 * * 1', 'Every Monday at 11:15'],
            'monthly'            => ['0 3 1 * *', 'Monthly on day 1 at 03:00'],
            'no wording: raw'    => ['0 9-17 * * 1-5', '0 9-17 * * 1-5'],
            'a month: raw'       => ['0 0 1 1 *', '0 0 1 1 *'],
        ];
    }
}
