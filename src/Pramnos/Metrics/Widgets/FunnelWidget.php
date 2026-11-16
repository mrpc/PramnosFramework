<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Audience\Attributes;
use Pramnos\Audience\Funnels;
use Pramnos\Audience\PersonEvents;
use Pramnos\Audience\Segments;
use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\General\NumberFormat;

/**
 * A funnel as a report card ({@see Funnels::run()}): steps over person events, the people who
 * reached each, their share of the first and the usual time from the step before.
 *
 * Settings: `title`, `events` (comma-separated, in order), `by` (a segment or an attribute).
 */
class FunnelWidget extends ReportWidget
{
    public function id(): string
    {
        return 'report.funnel';
    }

    protected function name(): string
    {
        return 'Funnel';
    }

    public function description(): string
    {
        return Figures::words('Steps over person events: who reached each, and how long it took');
    }

    public function options(): array
    {
        $by = ['' => Figures::words('(none)')];
        try {
            foreach (Segments::all() as $segment) {
                $by['segment:' . $segment['id']] = (string) $segment['name'];
            }
            foreach (Attributes::visible() as $key => $attribute) {
                $by[$key] = $attribute->labelText();
            }
        } catch (\Throwable) {
            // not installed: no choices, every reader goes to the overall funnel alone
        }

        return [
            'title'  => self::titleOption(),
            'events' => ['label' => 'Steps, in order', 'input' => 'text', 'clean' => static function (mixed $v): ?string {
                $known = PersonEvents::all();
                $kept  = array_values(array_filter(array_map('trim', explode(',', (string) $v)), static fn (string $e): bool => isset($known[$e])));

                return $kept === [] ? null : implode(',', array_slice($kept, 0, 6));
            }],
            'by' => ['label' => 'Broken down by', 'values' => $by],
        ];
    }

    public function render(): string
    {
        $events = array_filter(explode(',', (string) $this->setting('events')));
        if (count($events) < 2) {
            return self::note(Figures::words('Choose at least two steps, in order, in the card\'s settings.'));
        }
        $report  = $this->report();
        $options = ['from' => $report['window']->startsAt(), 'to' => $report['window']->endsAt()];
        $by      = (string) $this->setting('by');
        if ($by !== '') {
            $options['by'] = $by;
        }
        try {
            $funnel = Funnels::run(array_values($events), $options);
        } catch (\InvalidArgumentException $e) {
            return self::note($e->getMessage());
        }
        $e    = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '<table class="pf-report table table-sm"><thead><tr><th scope="col">' . $e(Figures::words('Step'))
            . '</th><th scope="col" style="text-align:right">' . $e(Figures::words('People')) . '</th><th scope="col" style="text-align:right">'
            . $e(Figures::words('Share')) . '</th><th scope="col" style="text-align:right">' . $e(Figures::words('Median time')) . '</th></tr></thead><tbody>';
        foreach ($funnel['steps'] as $step) {
            $html .= '<tr><th scope="row">' . $e($step['label']) . '</th><td style="text-align:right">' . $e((string) $step['people']) . '</td>'
                . '<td style="text-align:right">' . $e(NumberFormat::number(100 * $step['share'], 1) . '%') . '</td>'
                . '<td style="text-align:right">' . ($step['median_seconds'] === null ? '—' : $e(self::duration((int) $step['median_seconds']))) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        if ($funnel['by'] !== []) {
            $html .= '<table class="pf-report table table-sm"><thead><tr><th scope="col">' . $e(Figures::words('Value')) . '</th><th scope="col" style="text-align:right">'
                . $e(Figures::words('People')) . '</th></tr></thead><tbody>';
            foreach ($funnel['by'] as $label => $count) {
                $html .= '<tr><th scope="row">' . $e($label) . '</th><td style="text-align:right">' . $e((string) $count) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        return $html;
    }

    /** Seconds, the coarsest unit that fits: minutes, hours or days. */
    private static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 3600 => sprintf(Figures::words('%d min'), (int) round($seconds / 60)),
            $seconds < 86400 => sprintf(Figures::words('%.1f h'), $seconds / 3600),
            default => sprintf(Figures::words('%.1f d'), $seconds / 86400),
        };
    }
}
