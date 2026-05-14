<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Scheduling\CronExpression;

/**
 * Unit tests for Pramnos\Scheduling\CronExpression.
 *
 * CronExpression parses 5-field cron strings and evaluates whether an expression
 * is due at a given moment.  Supported field syntax:
 *   - `*`        — any value
 *   - `5`        — specific value
 *   - `1-5`      — inclusive range
 *   - `*\/5`     — step over full range
 *   - `1-15\/3`  — step within a range
 *   - `1,3,5`    — comma-separated list (each item may be a range or step)
 *
 * All tests use fixed DateTimeImmutable instances so they do not depend on the
 * system clock and are deterministic.
 */
#[CoversClass(CronExpression::class)]
class CronExpressionTest extends TestCase
{
    // =========================================================================
    // Constructor validation
    // =========================================================================

    /**
     * A valid 5-field expression is accepted without throwing.
     */
    public function testConstructorAcceptsValidFiveFieldExpression(): void
    {
        // Arrange / Act — no exception expected
        $cron = new CronExpression('0 2 * * *');

        // Assert – expression is stored correctly
        $this->assertSame('0 2 * * *', $cron->getExpression());
    }

    /**
     * A 4-field expression (too few fields) throws InvalidArgumentException.
     */
    public function testConstructorRejectsFourFieldExpression(): void
    {
        // Assert / Act
        $this->expectException(\InvalidArgumentException::class);
        new CronExpression('0 2 * *');
    }

    /**
     * A 6-field expression (too many fields) also throws InvalidArgumentException.
     */
    public function testConstructorRejectsSixFieldExpression(): void
    {
        // Assert / Act
        $this->expectException(\InvalidArgumentException::class);
        new CronExpression('0 2 * * * *');
    }

    /**
     * Extra internal whitespace between fields is tolerated — the expression is
     * split on any run of whitespace.
     */
    public function testConstructorToleratesExtraWhitespaceBetweenFields(): void
    {
        // Arrange / Act — two spaces between some fields
        $cron = new CronExpression('0  2  *  *  *');

        // Assert – no exception thrown
        $this->assertNotEmpty($cron->getExpression());
    }

    // =========================================================================
    // getExpression
    // =========================================================================

    /**
     * getExpression() returns the original string passed to the constructor,
     * preserving the exact form given (not a re-serialised normalised form).
     */
    public function testGetExpressionReturnsOriginalString(): void
    {
        // Arrange
        $expr = '30 8 1 1 *';
        $cron = new CronExpression($expr);

        // Act / Assert
        $this->assertSame($expr, $cron->getExpression());
    }

    // =========================================================================
    // withTime
    // =========================================================================

    /**
     * withTime('02:30') replaces the minute and hour fields in the expression.
     * The three remaining fields (day, month, dow) are preserved.
     */
    public function testWithTimeReplacesMinuteAndHourFields(): void
    {
        // Arrange – every-day-at-midnight base expression
        $base = new CronExpression('0 0 * * *');

        // Act
        $modified = $base->withTime('14:45');

        // Assert – new expression has the correct minute and hour
        $parts = preg_split('/\s+/', $modified->getExpression());
        $this->assertSame('45', $parts[0]);  // minute
        $this->assertSame('14', $parts[1]);  // hour
        // Assert – trailing fields are preserved
        $this->assertSame('*', $parts[2]);
        $this->assertSame('*', $parts[3]);
        $this->assertSame('*', $parts[4]);
    }

    /**
     * withTime() returns a new CronExpression instance — it does not mutate
     * the original (expressions are immutable).
     */
    public function testWithTimeReturnsNewInstanceWithoutMutatingOriginal(): void
    {
        // Arrange
        $original = new CronExpression('0 0 * * *');

        // Act
        $modified = $original->withTime('09:00');

        // Assert – different object
        $this->assertNotSame($original, $modified);
        // Assert – original is unchanged
        $this->assertSame('0 0 * * *', $original->getExpression());
    }

    /**
     * withTime() throws InvalidArgumentException for a malformed time string.
     */
    public function testWithTimeThrowsForMalformedTimeString(): void
    {
        // Arrange
        $cron = new CronExpression('0 0 * * *');

        // Assert / Act
        $this->expectException(\InvalidArgumentException::class);
        $cron->withTime('not-a-time');
    }

    /**
     * withTime() throws InvalidArgumentException when hour is > 23.
     */
    public function testWithTimeThrowsForHourOutOfRange(): void
    {
        // Arrange
        $cron = new CronExpression('0 0 * * *');

        // Assert / Act
        $this->expectException(\InvalidArgumentException::class);
        $cron->withTime('25:00');
    }

    /**
     * withTime() throws InvalidArgumentException when minute is > 59.
     */
    public function testWithTimeThrowsForMinuteOutOfRange(): void
    {
        // Arrange
        $cron = new CronExpression('0 0 * * *');

        // Assert / Act
        $this->expectException(\InvalidArgumentException::class);
        $cron->withTime('23:60');
    }

    // =========================================================================
    // isDue — wildcard (*)
    // =========================================================================

    /**
     * '* * * * *' (run every minute) is always due regardless of the datetime.
     */
    public function testIsDueReturnsTrueForEveryMinuteExpression(): void
    {
        // Arrange
        $cron = new CronExpression('* * * * *');
        $time = new \DateTimeImmutable('2024-06-15 14:37:00');

        // Act / Assert
        $this->assertTrue($cron->isDue($time));
    }

    // =========================================================================
    // isDue — exact value
    // =========================================================================

    /**
     * '0 2 * * *' is due exactly at 02:00 and not at 02:01.
     */
    public function testIsDueMatchesExactMinuteAndHour(): void
    {
        // Arrange
        $cron    = new CronExpression('0 2 * * *');
        $dueAt   = new \DateTimeImmutable('2024-01-15 02:00:00');
        $notDue  = new \DateTimeImmutable('2024-01-15 02:01:00');

        // Assert
        $this->assertTrue($cron->isDue($dueAt));
        $this->assertFalse($cron->isDue($notDue));
    }

    /**
     * '0 0 1 1 *' matches only on 1 January at midnight and not on any other date.
     */
    public function testIsDueMatchesSpecificDayMonthCombination(): void
    {
        // Arrange
        $cron    = new CronExpression('0 0 1 1 *');
        $dueAt   = new \DateTimeImmutable('2024-01-01 00:00:00');
        $notDue  = new \DateTimeImmutable('2024-01-02 00:00:00');

        // Assert
        $this->assertTrue($cron->isDue($dueAt));
        $this->assertFalse($cron->isDue($notDue));
    }

    // =========================================================================
    // isDue — range (N-M)
    // =========================================================================

    /**
     * '* 9-17 * * *' is due during business hours (9–17) and not before or after.
     */
    public function testIsDueWithHourRange(): void
    {
        // Arrange
        $cron      = new CronExpression('* 9-17 * * *');
        $business  = new \DateTimeImmutable('2024-06-15 12:00:00');  // noon
        $before    = new \DateTimeImmutable('2024-06-15 08:59:00');  // 08:59
        $after     = new \DateTimeImmutable('2024-06-15 18:00:00');  // 18:00

        // Assert
        $this->assertTrue($cron->isDue($business));
        $this->assertFalse($cron->isDue($before));
        $this->assertFalse($cron->isDue($after));
    }

    // =========================================================================
    // isDue — step (*/N and start-end/N)
    // =========================================================================

    /**
     * Every-15-minutes expression fires at :00, :15, :30, :45 within each hour.
     * Cron syntax: '*' + '/15' (step) in the minute field.
     */
    public function testIsDueWithMinuteStep(): void
    {
        // Arrange
        $cron = new CronExpression('*/15 * * * *');

        // Assert – multiples of 15 within 0-59
        foreach ([0, 15, 30, 45] as $minute) {
            $time = new \DateTimeImmutable("2024-06-15 10:{$minute}:00");
            $this->assertTrue($cron->isDue($time), "Expected due at :{$minute}");
        }

        // Assert – non-multiples are not due
        foreach ([1, 14, 16, 29, 31] as $minute) {
            $minuteStr = str_pad((string)$minute, 2, '0', STR_PAD_LEFT);
            $time = new \DateTimeImmutable("2024-06-15 10:{$minuteStr}:00");
            $this->assertFalse($cron->isDue($time), "Expected not due at :{$minute}");
        }
    }

    /**
     * Every-2-hours expression fires at 00:00, 02:00, 04:00, … 22:00.
     * Cron syntax: '*' + '/2' (step) in the hour field.
     */
    public function testIsDueWithHourStep(): void
    {
        // Arrange
        $cron = new CronExpression('0 */2 * * *');

        // Assert – even hours at :00
        foreach ([0, 2, 4, 22] as $hour) {
            $hourStr = str_pad((string)$hour, 2, '0', STR_PAD_LEFT);
            $time = new \DateTimeImmutable("2024-06-15 {$hourStr}:00:00");
            $this->assertTrue($cron->isDue($time), "Expected due at {$hour}:00");
        }

        // Assert – odd hours not due
        $time = new \DateTimeImmutable('2024-06-15 03:00:00');
        $this->assertFalse($cron->isDue($time));
    }

    // =========================================================================
    // isDue — comma-separated list
    // =========================================================================

    /**
     * '0 8,12,18 * * *' fires at 08:00, 12:00, and 18:00 only.
     */
    public function testIsDueWithCommaList(): void
    {
        // Arrange
        $cron = new CronExpression('0 8,12,18 * * *');

        // Assert – all three listed hours
        foreach ([8, 12, 18] as $hour) {
            $hourStr = str_pad((string)$hour, 2, '0', STR_PAD_LEFT);
            $time = new \DateTimeImmutable("2024-06-15 {$hourStr}:00:00");
            $this->assertTrue($cron->isDue($time), "Expected due at {$hour}:00");
        }

        // Assert – an hour not in the list is not due
        $time = new \DateTimeImmutable('2024-06-15 10:00:00');
        $this->assertFalse($cron->isDue($time));
    }

    // =========================================================================
    // isDue — day-of-week
    // =========================================================================

    /**
     * '0 9 * * 1' (every Monday at 09:00) is due only on Mondays.
     * 2024-06-17 is a Monday; 2024-06-18 is a Tuesday.
     */
    public function testIsDueWithDayOfWeek(): void
    {
        // Arrange
        $cron    = new CronExpression('0 9 * * 1');
        $monday  = new \DateTimeImmutable('2024-06-17 09:00:00');
        $tuesday = new \DateTimeImmutable('2024-06-18 09:00:00');

        // Assert
        $this->assertTrue($cron->isDue($monday));
        $this->assertFalse($cron->isDue($tuesday));
    }

    /**
     * '0 9 * * 0' matches Sunday (day-of-week = 0).
     * 2024-06-16 is a Sunday.
     */
    public function testIsDueWithSundayDayOfWeek(): void
    {
        // Arrange
        $cron   = new CronExpression('0 9 * * 0');
        $sunday = new \DateTimeImmutable('2024-06-16 09:00:00');

        // Assert
        $this->assertTrue($cron->isDue($sunday));
    }

    // =========================================================================
    // isDue — combined realistic examples
    // =========================================================================

    /**
     * '30 2 * * 0' (2:30 AM every Sunday) — a typical weekly maintenance job.
     * 2024-06-16 02:30 is a Sunday.
     */
    public function testIsDueForWeeklySundayMaintenanceJob(): void
    {
        // Arrange
        $cron = new CronExpression('30 2 * * 0');
        $due  = new \DateTimeImmutable('2024-06-16 02:30:00');
        $notDue = new \DateTimeImmutable('2024-06-16 02:31:00');

        // Assert
        $this->assertTrue($cron->isDue($due));
        $this->assertFalse($cron->isDue($notDue));
    }

    /**
     * '0 0 1 * *' (midnight on the 1st of every month) — monthly invoice run.
     */
    public function testIsDueForMonthlyFirstOfMonth(): void
    {
        // Arrange
        $cron   = new CronExpression('0 0 1 * *');
        $first  = new \DateTimeImmutable('2024-03-01 00:00:00');
        $second = new \DateTimeImmutable('2024-03-02 00:00:00');

        // Assert
        $this->assertTrue($cron->isDue($first));
        $this->assertFalse($cron->isDue($second));
    }

    // =========================================================================
    // Step edge-cases (covers lines 138, 144-148 of CronExpression::matchesField)
    // =========================================================================

    /**
     * A step value of 0 (`*\/0`) is invalid and must never match.
     *
     * The implementation checks `$step < 1` and returns false immediately
     * (line 138).  Without this guard an infinite loop or division-by-zero
     * would occur, so covering this branch is safety-critical.
     */
    public function testIsDueWithZeroStepReturnsFalse(): void
    {
        // Arrange — `*\/0` in the minute field: step is 0 (invalid)
        $cron = new CronExpression('*/0 * * * *');
        $time = new \DateTimeImmutable('2024-06-15 10:00:00');

        // Act / Assert — must not match any minute value
        $this->assertFalse($cron->isDue($time));
    }

    /**
     * A range-with-step field like `1-9\/2` means "every 2nd value starting
     * from 1 up to 9": i.e. minutes 1, 3, 5, 7, 9.
     *
     * This exercises lines 144-145 (parseRange($rangeStr) path inside the
     * step branch), which are only reached when the left side of `/` contains
     * a `-`.
     */
    public function testIsDueWithRangeAndStep(): void
    {
        // Arrange — minutes 1, 3, 5, 7, 9
        $cron   = new CronExpression('1-9/2 * * * *');
        $due    = new \DateTimeImmutable('2024-06-15 10:01:00');
        $notDue = new \DateTimeImmutable('2024-06-15 10:02:00');

        // Act / Assert
        $this->assertTrue($cron->isDue($due));
        $this->assertFalse($cron->isDue($notDue));
    }

    /**
     * A number-with-step field like `5\/10` means "every 10th value starting
     * from 5 up to the field maximum (59 for minutes)": i.e. 5, 15, 25, 35, 45, 55.
     *
     * This exercises lines 147-148 ($start = (int) $rangeStr; $end = $max),
     * which are only reached when the left side of `/` is a bare integer
     * (no range separator `-`).
     */
    public function testIsDueWithNumberAndStep(): void
    {
        // Arrange — minutes 5, 15, 25, 35, 45, 55
        $cron   = new CronExpression('5/10 * * * *');
        $due    = new \DateTimeImmutable('2024-06-15 10:05:00');
        $notDue = new \DateTimeImmutable('2024-06-15 10:06:00');

        // Act / Assert
        $this->assertTrue($cron->isDue($due));
        $this->assertFalse($cron->isDue($notDue));
    }
}
