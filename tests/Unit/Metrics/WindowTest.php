<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Metrics\Window;

/**
 * A metric's period: half-open, in the application's zone, at five grains.
 *
 * What holds: each grain starts where people expect (a week on Monday, a quarter on its first
 * month) and ends where the next starts; the period before and a year earlier are the same kind;
 * a period has begun and ended against the moment given; the zone is app.php's, an unknown one UTC.
 */
#[CoversClass(Window::class)]
class WindowTest extends TestCase
{
    private array $info;

    protected function setUp(): void
    {
        $this->info = Application::getInstance()->applicationInfo;
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'Europe/Athens'];
    }

    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
    }

    /** Each grain's span for one day — a Thursday in the third quarter. */
    public function testEachGrainsSpan(): void
    {
        // Arrange
        $expected = [
            Window::DAY     => ['2026-09-17 00:00:00', '2026-09-18 00:00:00', '2026-09-17'],
            Window::WEEK    => ['2026-09-14 00:00:00', '2026-09-21 00:00:00', '2026-W38'],
            Window::MONTH   => ['2026-09-01 00:00:00', '2026-10-01 00:00:00', '2026-09'],
            Window::QUARTER => ['2026-07-01 00:00:00', '2026-10-01 00:00:00', '2026-Q3'],
            Window::YEAR    => ['2026-01-01 00:00:00', '2027-01-01 00:00:00', '2026'],
        ];

        foreach ($expected as $grain => [$start, $end, $label]) {
            // Act
            $window = Window::of($grain, '2026-09-17');

            // Assert
            $this->assertSame($start, $window->startText(), $grain);
            $this->assertSame($end, $window->endText(), $grain . ': ends where the next starts');
            $this->assertSame($label, $window->label(), $grain);
            $this->assertSame(substr($start, 0, 10), $window->key());
            $this->assertSame($window->endsAt(), $window->next()->startsAt());
        }
    }

    /**
     * The moments are the zone's: midnight in Athens is 21:00 UTC the day before in summer, and a
     * Unix moment lands in the zone's day, not UTC's.
     */
    public function testTheZoneIsTheApplications(): void
    {
        // Arrange
        $athensMidnight = (new \DateTimeImmutable('2026-09-01 00:00:00', new \DateTimeZone('Europe/Athens')))->getTimestamp();

        // Act
        $month = Window::of(Window::MONTH, '2026-09-10');
        $fromUnix = Window::of(Window::DAY, $athensMidnight + 60);
        $fromObject = Window::of(Window::DAY, new \DateTimeImmutable('2026-08-31 21:30:00', new \DateTimeZone('UTC')));

        // Assert
        $this->assertSame($athensMidnight, $month->startsAt());
        $this->assertSame('2026-09-01', $fromUnix->key(), 'a minute past midnight in Athens is that day');
        $this->assertSame('2026-09-01', $fromObject->key(), '21:30 UTC is 00:30 in Athens');
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'Nowhere/Else'];
        $this->assertSame('UTC', Window::zone()->getName(), 'an unknown zone is UTC');
    }

    /** Before and a year earlier, across a year's turn, a leap day and an ISO week. */
    public function testPreviousAndYearEarlier(): void
    {
        // Arrange
        $january = Window::of(Window::MONTH, '2026-01-05');
        $leap    = Window::of(Window::DAY, '2028-02-29');
        $week    = Window::of(Window::WEEK, '2026-09-17');
        $quarter = Window::of(Window::QUARTER, '2026-02-01');

        // Act & Assert
        $this->assertSame('2025-12', $january->previous()->label());
        $this->assertSame('2025-01', $january->yearEarlier()->label());
        $this->assertSame('2027-03-01', $leap->yearEarlier()->key(), 'no 29 February a year earlier: PHP rolls to 1 March');
        $this->assertSame('2025-W38', $week->yearEarlier()->label(), 'the same ISO week');
        $this->assertSame('2025-Q4', $quarter->previous()->label());
    }

    /** Begun and ended against a given moment; a range lists every period, in order. */
    public function testBegunEndedAndRange(): void
    {
        // Arrange
        $day = Window::of(Window::DAY, '2026-09-17');

        // Act
        $range = Window::range(Window::MONTH, '2026-11-20', '2027-02-03');

        // Assert
        $this->assertFalse($day->begun($day->startsAt() - 1));
        $this->assertTrue($day->begun($day->startsAt()));
        $this->assertFalse($day->ended($day->endsAt() - 1));
        $this->assertTrue($day->ended($day->endsAt()), 'half-open: the next start is past it');
        $this->assertSame(['2026-11', '2026-12', '2027-01', '2027-02'], array_map(static fn (Window $w): string => $w->label(), $range));
        $this->assertSame([], Window::range(Window::DAY, '2026-09-02', '2026-09-01'), 'backwards is nothing');
    }

    /** A grain that is not one of the five is refused. */
    public function testAnUnknownGrainIsRefused(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        Window::of('fortnight', '2026-09-17');
    }
}
