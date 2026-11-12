<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Todos;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Todos\Todos;

/**
 * When a repeating to-do comes next: the first occurrence after now, at the same time of day in
 * the organisation's zone — through a change of clocks — never the same one when done early, never
 * one for each missed; a month and a year counted from the first day, kept where the month has it.
 */
#[CoversClass(Todos::class)]
class NextOccurrenceTest extends TestCase
{
    /** @return array<string, array{string, string, string, string}> from, repeat, now, expected — Athens time */
    public static function cases(): array
    {
        return [
            'daily'                         => ['2026-11-12 09:00', 'daily', '2026-11-12 10:00', '2026-11-13 09:00'],
            'weekly'                        => ['2026-11-12 09:00', 'weekly', '2026-11-12 10:00', '2026-11-19 09:00'],
            'yearly'                        => ['2026-11-12 09:00', 'yearly', '2026-11-12 10:00', '2027-11-12 09:00'],
            'done early: the next, not it'  => ['2026-11-12 18:00', 'daily', '2026-11-12 10:00', '2026-11-13 18:00'],
            'missed days: no copies'        => ['2026-11-01 18:00', 'daily', '2026-11-12 10:00', '2026-11-12 18:00'],
            'weekdays: Friday to Monday'    => ['2026-11-13 09:00', 'weekdays', '2026-11-13 10:00', '2026-11-16 09:00'],
            'monthly: 31 Jan to 28 Feb'     => ['2027-01-31 09:00', 'monthly', '2027-01-31 10:00', '2027-02-28 09:00'],
            'monthly: then 31 Mar'          => ['2027-01-31 09:00', 'monthly', '2027-03-01 10:00', '2027-03-31 09:00'],
            'yearly: 29 Feb to 28 Feb'      => ['2028-02-29 09:00', 'yearly', '2028-02-29 10:00', '2029-02-28 09:00'],
            'across the change of clocks'   => ['2027-03-27 09:00', 'daily', '2027-03-28 10:00', '2027-03-29 09:00'],
        ];
    }

    /**
     * Each case from the request, in the organisation's zone.
     */
    #[DataProvider('cases')]
    public function testTheNextOccurrence(string $from, string $repeat, string $now, string $expected): void
    {
        // Arrange
        $zone = new \DateTimeZone('Europe/Athens');
        $at   = static fn (string $local): int => (new \DateTimeImmutable($local, $zone))->getTimestamp();

        // Act
        $next = Todos::nextOccurrence($at($from), $repeat, $at($now), $zone);

        // Assert — read back on the zone's clock
        $this->assertSame($expected, (new \DateTimeImmutable('@' . $next))->setTimezone($zone)->format('Y-m-d H:i'));
    }

    /**
     * A repeat not known is refused.
     */
    public function testARepeatNotKnownIsRefused(): void
    {
        // Arrange / Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        Todos::nextOccurrence(0, 'hourly', 0);
    }
}
