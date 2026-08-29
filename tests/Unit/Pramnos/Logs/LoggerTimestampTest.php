<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Logs;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Logs\Logger;

/**
 * Reading a log line's timestamp back as a number.
 *
 * The log writes `d/m/Y H:i:s`, which is the one common format that sorts **wrong** as a
 * string. Every reader that wanted "the most recent" compared them as strings and was correct
 * for most of every month.
 */
#[CoversClass(Logger::class)]
class LoggerTimestampTest extends TestCase
{
    /**
     * The log's own format is read as the date it is.
     */
    public function testTheLogsOwnFormatIsRead(): void
    {
        // Assert
        $this->assertSame(
            mktime(14, 30, 5, 8, 29, 2026),
            Logger::timestampOf('29/08/2026 14:30:05')
        );
    }

    /**
     * September comes after August.
     *
     * `'01/09/2026' > '29/08/2026'` is false as a string comparison, so "the last error" became
     * the oldest one for the first days of every month — and was right again by the tenth,
     * which is why nobody ever reported it.
     */
    public function testSeptemberComesAfterAugust(): void
    {
        // Act
        $august    = Logger::timestampOf('29/08/2026 23:59:00');
        $september = Logger::timestampOf('01/09/2026 00:01:00');

        // Assert
        $this->assertGreaterThan($august, $september);
        $this->assertLessThan(
            '29/08/2026 23:59:00',
            '01/09/2026 00:01:00',
            'the string comparison this replaces, asserted so the reason stays visible'
        );
    }

    /**
     * A day is not a month.
     *
     * `03/04/2026` is the third of April in this format and March the fourth in the American
     * reading, and the two select different days from a log — silently, and only for the first
     * twelve days of a month.
     */
    public function testTheDayIsTheDayNotTheMonth(): void
    {
        // Act
        $parsed = Logger::timestampOf('03/04/2026 00:00:00');

        // Assert
        $this->assertSame('2026-04-03', date('Y-m-d', $parsed));
    }

    /**
     * An ISO timestamp is read too, because a line written by something else is still a line.
     */
    public function testIsoIsReadAsWell(): void
    {
        // Assert
        $this->assertSame(
            strtotime('2026-08-29 14:30:05'),
            Logger::timestampOf('2026-08-29 14:30:05')
        );
    }

    /**
     * Anything that is not a timestamp is zero, not now.
     *
     * `strtotime('')` is the current time, so a line with no timestamp would sort as the most
     * recent thing in the log — and "the last error" would be whichever malformed line was read
     * first.
     */
    public function testSomethingThatIsNotATimestampIsZero(): void
    {
        // Assert
        foreach (['', '   ', 'not a date', '???'] as $value) {
            $this->assertSame(0, Logger::timestampOf($value), $value);
        }
    }
}
