<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Dashboard\HasPeriod;
use Pramnos\Dashboard\Widget;

/** A card whose period each person sets, at most 31 days. */
final class PeriodCard extends Widget
{
    use HasPeriod;

    /** Its id. */
    public function id(): string { return 'period'; }

    /** Its heading. */
    public function title(): string { return 'Period'; }

    /** Its inside. */
    public function render(): string { return ''; }

    /** The period, as each person sets it. */
    public function options(): array { return $this->periodOptions(); }

    /** The last 30 days unless set otherwise. */
    public function defaultPeriod(): string { return '30'; }

    /** Its source answers for 31 days. */
    public function maxDays(): int { return 31; }
}

/**
 * The period a card's reader chooses: offered with the card's default first, kept cleaned with the
 * layout, read back as the analyst's tools take a window, as two moments, and as words.
 *
 * What holds: `today`, the last 7, 30 or 90 days, or two days of one's own; a custom period without
 * two days in order, or a value not offered, is the card's default; a period longer than the source
 * answers for is cut to it; the dates are shown only for `custom`.
 */
#[CoversClass(HasPeriod::class)]
class HasPeriodTest extends TestCase
{
    /** The card, configured as a person set it. */
    private static function card(array $settings): PeriodCard
    {
        return (new PeriodCard())->configure($settings);
    }

    /** The default first, every period offered, the dates only for custom. */
    public function testTheOptions(): void
    {
        // Act
        $options = (new PeriodCard())->options();
        $field   = Widget::field($options['from']);

        // Assert
        $this->assertSame(['30', 'today', '7', '90', 'custom'], array_map('strval', array_keys($options['period']['values'])), 'the default first');
        $this->assertSame('Last 30 days', $options['period']['values']['30']);
        $this->assertSame(['input' => 'date', 'when' => ['period' => 'custom']], ['input' => $field['input'], 'when' => $field['when']]);
        // Kept with the layout, cleaned: a date that is not one is dropped, an unknown period is the default
        $this->assertSame(['period' => '30'], Widget::cleanSettings(new PeriodCard(), ['from' => 'nonsense', 'period' => 'forever']));
    }

    /**
     * Each period as the tools take it, as two moments and as words; what is not a period, an
     * unfinished custom one and one longer than the source fall back.
     */
    public function testThePeriodRead(): void
    {
        // Act
        $today   = self::card(['period' => 'today']);
        $week    = self::card(['period' => '7']);
        $custom  = self::card(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-03']);
        $backTo  = self::card(['period' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-01']);
        $tooLong = self::card(['period' => '90']);
        $none    = self::card([]);

        // Assert
        $this->assertSame([['days' => 1], 'Today'], [$today->window(), $today->periodLabel()]);
        $this->assertSame([['days' => 7], 'Last 7 days'], [$week->window(), $week->periodLabel()]);
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-03'], $custom->window());
        $this->assertSame('01/10/2026 – 03/10/2026', $custom->periodLabel());
        $this->assertSame([strtotime('2026-10-01 00:00:00'), strtotime('2026-10-03 23:59:59')], $custom->periodRange());
        $this->assertSame('30', $backTo->period(), 'two days out of order: the default');
        $this->assertSame('31', $tooLong->period(), 'cut to what the source answers for');
        $this->assertSame('30', $none->period());
        [$from, $to] = $week->periodRange();
        $this->assertEqualsWithDelta(7 * 86400, $to - $from, 1);
        $this->assertSame(['today', '7', '30', '90', 'custom'], PeriodCard::periods());
    }
}
