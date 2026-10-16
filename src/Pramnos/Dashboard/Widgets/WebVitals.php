<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;
use Pramnos\Logs\ClientVitals;

/**
 * Core Web Vitals from real visitors over the last week: the 75th percentile of LCP, INP, CLS and
 * TTFB, per page group and device, each coloured by web.dev's thresholds. Not on a new dashboard;
 * offered where vitals are measured (`vitals.sample`), to whoever may open the logs.
 */
final class WebVitals extends Widget
{
    /** The columns, in the order they are read. */
    private const SHOWN = ['LCP', 'INP', 'CLS', 'TTFB'];

    public function id(): string
    {
        return 'vitals';
    }

    public function title(): string
    {
        return 'Web vitals (7 days)';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'How fast pages are for real visitors: the 75th percentile of LCP, INP, CLS and TTFB per page group and device.';
    }

    /** Where vitals are measured, for whoever may open the logs. */
    public function allowed(?object $user): bool
    {
        return ClientVitals::sample() > 0 && Figures::opens($user, Figures::ability($this->id(), 'admin.logs'));
    }

    public function byDefault(): bool
    {
        return false;
    }

    public function size(): string
    {
        return self::LARGE;
    }

    public function render(): string
    {
        $summary = (new ClientVitals())->summary();
        if ($summary === []) {
            return '<p class="pf-dashboard-health-message">' . self::e(Figures::words('Nothing measured yet.')) . '</p>';
        }

        $html = '<table class="pf-dashboard-vitals"><thead><tr><th>' . self::e(Figures::words('Pages')) . '</th><th>' . self::e(Figures::words('Device')) . '</th>';
        foreach (self::SHOWN as $metric) {
            $html .= '<th>' . $metric . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($summary as $group => $devices) {
            foreach ($devices as $device => $metrics) {
                $html .= '<tr><td>' . self::e((string) $group) . '</td><td>' . self::e(Figures::words((string) $device)) . '</td>';
                foreach (self::SHOWN as $metric) {
                    $one   = $metrics[$metric] ?? null;
                    $html .= $one === null ? '<td>—</td>'
                        : '<td class="pf-dashboard-status pf-dashboard-status--' . self::e($one['rating']) . '" title="' . (int) $one['count'] . '">'
                            . self::e($metric === 'CLS' ? number_format($one['p75'], 2) : number_format($one['p75']) . ' ms') . '</td>';
                }
                $html .= '</tr>';
            }
        }

        return $html . '</tbody></table>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
