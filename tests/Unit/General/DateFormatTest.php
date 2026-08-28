<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\General;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\General\DateFormat;

/**
 * A date is written the way the language writes dates.
 *
 * `date('Y-m-d H:i')` was in about fifty views. It is the right answer in one language and the
 * wrong one everywhere else — a Greek page showing `2026-08-28` is not wrong the way a
 * mistranslation is wrong, it is read, understood, and quietly filed as software written by
 * somebody else. Which is the whole reason a project translates its screens.
 *
 * What is asserted here is the order the pattern is looked up in, because that order is the
 * design: a site-wide setting beats the application's table, which beats the framework's, and
 * every step exists because the one before it is not enough for somebody.
 */
#[CoversClass(DateFormat::class)]
class DateFormatTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $saved = null;

    protected function setUp(): void
    {
        $this->saved = (array) (new \ReflectionProperty(Settings::class, 'settings'))->getValue();
    }

    protected function tearDown(): void
    {
        if ($this->saved !== null) {
            (new \ReflectionProperty(Settings::class, 'settings'))->setValue(null, $this->saved);
            $this->saved = null;
        }

        parent::tearDown();
    }

    /** @param array<string, mixed> $settings */
    private function withSettings(array $settings): void
    {
        (new \ReflectionProperty(Settings::class, 'settings'))->setValue(null, $settings);
    }

    /**
     * Greek writes the day first; the default is ISO.
     *
     * ISO for the default rather than an American or British guess: unambiguous, sortable, and
     * not pretending to be a local convention it is not.
     */
    public function testTheLanguageDecidesThePattern(): void
    {
        // Arrange
        $this->withSettings([]);

        // Act & Assert
        $this->assertSame('d/m/Y', DateFormat::pattern('date', 'el'));
        $this->assertSame('d/m/Y H:i', DateFormat::pattern('datetime', 'greek'));
        $this->assertSame('Y-m-d', DateFormat::pattern('date', 'english'));
        $this->assertSame('Y-m-d H:i', DateFormat::pattern('datetime', 'a-language-nobody-added'));
    }

    /**
     * A site-wide setting wins over everything.
     *
     * An installation that has said what it wants has said it, and the language table is a
     * default rather than a rule.
     */
    public function testASettingOverridesTheLanguage(): void
    {
        // Arrange
        $this->withSettings(['date_format' => 'j M Y']);

        // Act & Assert
        $this->assertSame('j M Y', DateFormat::pattern('date', 'el'));
        $this->assertSame('j M Y', DateFormat::pattern('date', 'english'));
    }

    /**
     * Zero is no date, not the first of January 1970.
     *
     * A column with no date is the ordinary case in the tables this formats — a user who has
     * never signed in, a message never sent — and "1970-01-01" in a listing is a value somebody
     * has to be told to ignore. Worse in a sorted column, where it sits at one end looking
     * meaningful.
     */
    public function testNoDateIsNotTheEpoch(): void
    {
        // Arrange
        $this->withSettings([]);

        // Act & Assert
        $this->assertSame('', DateFormat::date(0));
        $this->assertSame('—', DateFormat::date(0, '—'));
        $this->assertSame('—', DateFormat::date(null, '—'));
        $this->assertSame('—', DateFormat::date('', '—'));
        $this->assertSame('—', DateFormat::date('not a timestamp', '—'));
    }

    /**
     * A real timestamp is formatted in the language's convention.
     */
    public function testATimestampIsFormatted(): void
    {
        // Arrange
        $this->withSettings(['date_format' => 'd/m/Y', 'datetime_format' => 'd/m/Y H:i']);
        $when = mktime(14, 32, 0, 8, 28, 2026);

        // Act & Assert
        $this->assertSame('28/08/2026', DateFormat::date($when));
        $this->assertSame('28/08/2026 14:32', DateFormat::dateTime($when));
    }

    /**
     * A numeric string counts as a timestamp.
     *
     * Every one of these values comes out of a database column, and a driver that returns
     * integers as strings is the normal case rather than the exception.
     */
    public function testANumericStringIsATimestamp(): void
    {
        // Arrange
        $this->withSettings(['date_format' => 'Y-m-d']);

        // Act & Assert
        $this->assertSame(date('Y-m-d', 1787900000), DateFormat::date('1787900000'));
    }

    /**
     * The helpers are the view-facing surface, and they are one call.
     *
     * A view that has to remember a class name and a method will keep using `date()`, which is
     * how fifty of them came to.
     */
    public function testTheHelpersAreAvailable(): void
    {
        // Act & Assert
        $this->assertTrue(function_exists('localDate'));
        $this->assertTrue(function_exists('localDateTime'));
        $this->assertTrue(function_exists('localTime'));

        $this->withSettings(['date_format' => 'd/m/Y']);
        $this->assertSame('28/08/2026', localDate(mktime(0, 0, 0, 8, 28, 2026)));
    }
}
