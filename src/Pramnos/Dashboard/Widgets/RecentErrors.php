<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;
use Pramnos\Html\StatTile;

/**
 * Errors in the logs over the last day: how many, and the commonest five with their counts. Opens
 * the log viewer. Not on a new dashboard — it reads the log files, which is the slowest figure
 * here — and a card away in the catalogue.
 */
final class RecentErrors extends Widget
{
    /** The levels counted as errors. */
    private const LEVELS = ['emergency', 'alert', 'critical', 'error'];

    /** How many of the commonest errors are listed. */
    private const SHOWN = 5;

    public function id(): string
    {
        return 'logs.errors';
    }

    public function title(): string
    {
        return 'Errors (24 hours)';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'Errors written to the logs in the last 24 hours, and the commonest of them.';
    }

    /** Whoever may open the logs. */
    public function allowed(?object $user): bool
    {
        return Figures::opens($user, Figures::ability($this->id(), 'admin.logs'));
    }

    public function byDefault(): bool
    {
        return false;
    }

    public function link(): ?string
    {
        return Figures::link($this->id(), 'logs');
    }

    public function render(): string
    {
        $summary = \Pramnos\Logs\LogAnalytics::summary('24h');
        $levels  = array_change_key_case((array) ($summary['levels'] ?? []));
        $count   = array_sum(array_intersect_key($levels, array_flip(self::LEVELS)));

        $html = StatTile::grid([StatTile::render('Errors', number_format((int) $count), link: $this->link(), state: $count > 0 ? StatTile::WARNING : StatTile::GOOD)]);
        $items = '';
        foreach (array_slice((array) ($summary['topErrors'] ?? []), 0, self::SHOWN) as $error) {
            $items .= '<li><span class="pf-dashboard-health-message">' . self::e(mb_strimwidth((string) ($error['message'] ?? ''), 0, 160, '…')) . '</span>'
                . '<span class="pf-dashboard-status">' . (int) ($error['count'] ?? 0) . '×</span></li>';
        }

        return $html . ($items === '' ? '' : '<ul class="pf-dashboard-health">' . $items . '</ul>');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
