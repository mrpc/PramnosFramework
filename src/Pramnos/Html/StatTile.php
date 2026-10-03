<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * A figure with a label: the tile every administration dashboard draws.
 *
 * ```php
 * echo StatTile::grid([
 *     StatTile::render('MRR', '€1,240', 'ARR €14,880'),
 *     StatTile::render('Waiting in the queue', '312', 'oldest 4 min', state: StatTile::WARNING),
 *     StatTile::render('Failed today', '0', link: adminUrl('Queue?show=failed')),
 *     StatTile::progress('Paying customers', 18, 50, 'the plan\'s 90-day target'),
 * ]);
 * ```
 *
 * Each application that added a dashboard wrote its own card markup, and they drifted: spacing,
 * numbers that jump as they change because the digits are not tabular, a tile that is unreadable
 * in dark mode. This emits neutral `pf-stat-*` hooks that each scaffold theme's stylesheet
 * dresses from its own colours, so a tile looks like the rest of the theme in light and dark.
 *
 * The value is shown as given: format it first (currency, thousands, a percentage), because
 * only the caller knows what it is. Everything is escaped.
 */
final class StatTile
{
    /** A figure that is where it should be. */
    public const GOOD = 'good';

    /** A figure that wants a look. */
    public const WARNING = 'warning';

    /** A figure that wants action. */
    public const CRITICAL = 'critical';

    /**
     * One tile.
     *
     * @param string      $label What the figure is
     * @param string|int|float $value The figure, formatted
     * @param string      $note  A line under it: the comparison, the breakdown, the unit
     * @param string|null $link  Where the tile leads, e.g. the list behind the number
     * @param string|null $state {@see GOOD}, {@see WARNING}, {@see CRITICAL}, or null
     */
    public static function render(
        string $label,
        string|int|float $value,
        string $note = '',
        ?string $link = null,
        ?string $state = null
    ): string {
        return self::tile($label, (string) $value, $note, $link, $state, '');
    }

    /**
     * A tile measuring progress toward a target, with a bar.
     *
     * The state follows the progress: good at the target, none below it. The bar is a
     * `<progress>` element, which a screen reader announces as a percentage.
     *
     * @param string      $label   What is being counted
     * @param float       $current Where it is
     * @param float       $target  Where it should get to; 0 or less shows no bar
     * @param string      $note    A line under it
     * @param string|null $link    Where the tile leads
     */
    public static function progress(
        string $label,
        float $current,
        float $target,
        string $note = '',
        ?string $link = null
    ): string {
        $percent = $target > 0 ? (int) round(min(100, max(0, $current / $target * 100))) : null;
        $value   = self::number($current) . ($target > 0 ? ' / ' . self::number($target) : '');
        $bar     = $percent === null ? '' : '<progress class="' . self::cls('stat_tile') . '__progress" value="'
            . $percent . '" max="100" aria-label="' . self::e($label) . ': ' . $percent . '%">'
            . $percent . '%</progress>';

        return self::tile($label, $value, $note, $link, $percent !== null && $percent >= 100 ? self::GOOD : null, $bar);
    }

    /**
     * Tiles side by side, wrapping on a narrow screen.
     *
     * @param list<string> $tiles Rendered tiles
     */
    public static function grid(array $tiles): string
    {
        return '<div class="' . self::cls('stat_grid') . '">' . implode('', $tiles) . '</div>';
    }

    private static function tile(string $label, string $value, string $note, ?string $link, ?string $state, string $bar): string
    {
        $base  = self::cls('stat_tile');
        $state = in_array($state, [self::GOOD, self::WARNING, self::CRITICAL], true) ? $state : null;
        $class = $base . ($state !== null ? ' ' . $base . '--' . $state : '');
        $tag   = $link !== null && $link !== '' ? 'a' : 'div';

        return '<' . $tag . ' class="' . self::e($class) . '"'
            . ($tag === 'a' ? ' href="' . self::e((string) $link) . '"' : '')
            . ($state !== null ? ' data-state="' . $state . '"' : '') . '>'
            . '<span class="' . $base . '__label">' . self::e($label) . '</span>'
            . '<span class="' . $base . '__value">' . self::e($value) . '</span>'
            . ($note !== '' ? '<span class="' . $base . '__note">' . self::e($note) . '</span>' : '')
            . $bar
            . '</' . $tag . '>';
    }

    /** A whole number without decimals, otherwise one decimal. */
    private static function number(float $n): string
    {
        return floor($n) === $n ? number_format($n, 0) : number_format($n, 1);
    }

    /** The configured class for a hook, or the hook itself. */
    private static function cls(string $key): string
    {
        return ComponentClasses::get($key);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
