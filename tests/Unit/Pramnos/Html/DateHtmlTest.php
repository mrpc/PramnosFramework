<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Html;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Date;

/**
 * Unit tests for Pramnos\Html\Date.
 *
 * Only the pure-static getHtmlDate() method is tested here because render()
 * depends on Document::getInstance(), Language, and script-enqueue side effects
 * that require a running framework environment.
 *
 * getHtmlDate() converts an HTML5 date input value (YYYY-MM-DD) to a Unix
 * timestamp, treating the time component as midnight (00:00:00).
 */
#[CoversClass(Date::class)]
class DateHtmlTest extends TestCase
{
    // =========================================================================
    // getHtmlDate
    // =========================================================================

    /** @return array<string,array{string,int}> */
    public static function htmlDateProvider(): array
    {
        return [
            'epoch day'          => ['1970-01-01', mktime(0, 0, 0, 1, 1, 1970)],
            'Y2K'                => ['2000-01-01', mktime(0, 0, 0, 1, 1, 2000)],
            'leap day 2024'      => ['2024-02-29', mktime(0, 0, 0, 2, 29, 2024)],
            'arbitrary date'     => ['2023-07-15', mktime(0, 0, 0, 7, 15, 2023)],
            'end of year'        => ['2023-12-31', mktime(0, 0, 0, 12, 31, 2023)],
        ];
    }

    /**
     * getHtmlDate() parses an HTML5 date string (YYYY-MM-DD) and returns the
     * corresponding Unix timestamp at midnight local time.
     *
     * @param string $input    HTML5 date string from a <input type="date"> field
     * @param int    $expected Expected Unix timestamp
     */
    #[DataProvider('htmlDateProvider')]
    public function testGetHtmlDateParsesIso8601DateString(string $input, int $expected): void
    {
        // Arrange / Act
        $result = Date::getHtmlDate($input);

        // Assert
        $this->assertSame($expected, $result);
    }

    /**
     * getHtmlDate() always sets time to 00:00:00, so the resulting timestamp
     * is evenly divisible by 86400 only for UTC. To verify the zero-time
     * behaviour, we check that format('H:i:s') on the result equals midnight.
     */
    public function testGetHtmlDateTimeMidnight(): void
    {
        // Arrange
        $input = '2024-06-15';

        // Act
        $timestamp = Date::getHtmlDate($input);

        // Assert – time component is midnight
        $this->assertSame('00:00:00', date('H:i:s', $timestamp));
    }

    /**
     * getHtmlDate() round-trips correctly: converting the result back to
     * 'Y-m-d' format yields the original input string.
     */
    public function testGetHtmlDateRoundTrip(): void
    {
        // Arrange
        $input = '2025-03-22';

        // Act
        $timestamp = Date::getHtmlDate($input);

        // Assert – round-trip through date()
        $this->assertSame($input, date('Y-m-d', $timestamp));
    }

    // =========================================================================
    // Constructor — default properties
    // =========================================================================

    /**
     * Date::__construct() stores name and date, stripping spaces from the name.
     * The timestamp 0 is the default when no date is provided.
     */
    public function testConstructorStoresNameAndDate(): void
    {
        // Arrange / Act
        $widget = new Date('my field', 1_000_000);

        // Assert – space stripped from name
        $this->assertSame('myfield', $widget->name);
        // Assert – date stored as-is
        $this->assertSame(1_000_000, $widget->date);
    }

    /**
     * Date::__construct() with no arguments leaves name empty and date at 0.
     */
    public function testConstructorDefaultsToEmptyNameAndZeroDate(): void
    {
        // Arrange / Act
        $widget = new Date();

        // Assert
        $this->assertSame('', $widget->name);
        $this->assertSame(0, $widget->date);
    }
}
