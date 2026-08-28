<?php

declare(strict_types=1);

namespace Pramnos\General;

/**
 * How a date is written, in the language the page is written in.
 *
 * `date('Y-m-d H:i')` appeared in about forty views, and it is the wrong answer in every
 * language but one. A Greek page saying `2026-08-28` is not wrong the way a mistranslation is
 * wrong — it is read, understood, and quietly noted as software written by somebody else. Which
 * is the whole reason a project translates its screens in the first place.
 *
 * ```php
 * echo localDate($row['created']);       // 28/08/2026   (el)   2026-08-28   (en)
 * echo localDateTime($row['created']);   // 28/08/2026 14:32    2026-08-28 14:32
 * echo localTime($row['created']);       // 14:32
 * ```
 *
 * ## Where the patterns come from
 *
 * Three places, in order, and each one exists because the one before it is not enough:
 *
 * 1. **The settings** — `date_format` and `datetime_format`. A site-wide override for an
 *    installation whose audience expects something the language table does not say.
 * 2. **`app.php`** — `'dates' => ['el' => ['date' => 'j/n/Y'], ...]`, per language, versioned
 *    with the code.
 * 3. **{@see PATTERNS}** — the framework's own table, so a project that configures nothing gets
 *    a Greek page with Greek dates rather than a placeholder.
 *
 * ## Why not `IntlDateFormatter`
 *
 * Because `intl` is not everywhere, and a formatter that renders a different date depending on
 * whether an extension happens to be compiled in is worse than a plain one. What is here is
 * `date()` with a pattern chosen by language: predictable, testable, and the same on every host.
 * An application that wants full ICU formatting has the seam — override the pattern in
 * `app.php`, or format it itself.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class DateFormat
{
    /**
     * The framework's own patterns, by language.
     *
     * `default` is ISO, which is the right answer when nothing is known: unambiguous, sortable,
     * and not pretending to be a local convention it is not.
     *
     * Only languages whose convention differs are listed. A language that is missing falls to
     * `default` rather than to a guess.
     *
     * @var array<string, array{date: string, datetime: string, time: string}>
     */
    public const PATTERNS = [
        'default' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i', 'time' => 'H:i'],
        // Day first, and 24-hour: `d/m/Y` is what a Greek reader writes by hand.
        'el'      => ['date' => 'd/m/Y', 'datetime' => 'd/m/Y H:i', 'time' => 'H:i'],
        'greek'   => ['date' => 'd/m/Y', 'datetime' => 'd/m/Y H:i', 'time' => 'H:i'],
        'gr'      => ['date' => 'd/m/Y', 'datetime' => 'd/m/Y H:i', 'time' => 'H:i'],
    ];

    /**
     * A date, in the current language's convention.
     *
     * A timestamp of `0` returns the empty string rather than *1 January 1970*. A column with no
     * date is the ordinary case in every table this formats, and "1970" in a listing is a date
     * somebody has to be told to ignore. Pass `$empty` for a dash or a word.
     *
     * @param  int|string|null $timestamp Unix timestamp; 0, null and '' mean "no date"
     * @param  string          $empty     What to return for no date
     */
    public static function date($timestamp, string $empty = ''): string
    {
        return static::format($timestamp, 'date', $empty);
    }

    /**
     * A date and a time.
     *
     * @param  int|string|null $timestamp
     * @param  string          $empty
     */
    public static function dateTime($timestamp, string $empty = ''): string
    {
        return static::format($timestamp, 'datetime', $empty);
    }

    /**
     * A time of day.
     *
     * @param  int|string|null $timestamp
     * @param  string          $empty
     */
    public static function time($timestamp, string $empty = ''): string
    {
        return static::format($timestamp, 'time', $empty);
    }

    /**
     * The pattern for one kind of value, in one language.
     *
     * @param string  $kind     `date`, `datetime` or `time`
     * @param ?string $language Defaults to the current one
     */
    public static function pattern(string $kind, ?string $language = null): string
    {
        $language = strtolower(trim($language ?? static::currentLanguage()));

        // 1. A site-wide setting wins: an installation that has said what it wants has said it.
        $setting = $kind === 'time' ? 'time_format' : ($kind === 'date' ? 'date_format' : 'datetime_format');
        $configured = \Pramnos\Application\Settings::getSetting($setting);

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        // 2. The application's own table, per language.
        $fromApp = static::applicationPatterns()[$language][$kind] ?? null;

        if (is_string($fromApp) && trim($fromApp) !== '') {
            return trim($fromApp);
        }

        // 3. The framework's.
        return static::PATTERNS[$language][$kind] ?? static::PATTERNS['default'][$kind];
    }

    /**
     * `'dates' => ['el' => ['date' => 'j/n/Y']]` from `app.php`, lower-cased.
     *
     * @return array<string, array<string, string>>
     */
    protected static function applicationPatterns(): array
    {
        $configured = \Pramnos\Application\Application::currentInstance()
            ->applicationInfo['dates'] ?? null;

        if (!is_array($configured)) {
            return [];
        }

        $patterns = [];

        foreach ($configured as $language => $kinds) {
            if (is_array($kinds)) {
                $patterns[strtolower(trim((string) $language))] = $kinds;
            }
        }

        return $patterns;
    }

    /**
     * The language the page is being written in.
     *
     * Failure answers `default` rather than throwing: a formatter that can bring a page down
     * over a missing language file is not worth having, and ISO is a readable fallback.
     */
    protected static function currentLanguage(): string
    {
        try {
            return (string) \Pramnos\Framework\Factory::getLanguage()->currentlang();
        } catch (\Throwable) {
            return 'default';
        }
    }

    /**
     * @param int|string|null $timestamp
     */
    protected static function format($timestamp, string $kind, string $empty): string
    {
        if ($timestamp === null || $timestamp === '' || !is_numeric($timestamp)) {
            return $empty;
        }

        $timestamp = (int) $timestamp;

        if ($timestamp === 0) {
            return $empty;
        }

        return date(static::pattern($kind), $timestamp);
    }
}
