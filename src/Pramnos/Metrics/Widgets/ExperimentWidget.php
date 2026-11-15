<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Audience\Experiments;
use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\General\NumberFormat;

/**
 * An A/B test as a report card ({@see Experiments::results()}): each variant's people, its
 * conversion rate and how sure the difference from the first variant is.
 *
 * Settings: `title`, `experiment` (its id).
 */
class ExperimentWidget extends ReportWidget
{
    public function id(): string
    {
        return 'report.experiment';
    }

    protected function name(): string
    {
        return 'A/B test';
    }

    public function description(): string
    {
        return Figures::words("How a test's variants are doing: people, conversion, confidence");
    }

    public function options(): array
    {
        $tests = [];
        try {
            foreach (Experiments::all() as $test) {
                $tests[(string) $test['id']] = $test['name'];
            }
        } catch (\Throwable) {
            // not installed: no choices, the note below is shown instead
        }

        return [
            'title'      => self::titleOption(),
            'experiment' => ['label' => 'Test', 'values' => ['' => Figures::words('Choose…')] + $tests],
        ];
    }

    public function render(): string
    {
        $id = (int) $this->setting('experiment');
        try {
            if ($id < 1 || Experiments::get($id) === null) {
                return self::note(Figures::words('Choose a test in the card\'s settings.'));
            }
            $results = Experiments::results($id);
        } catch (\Throwable) {
            return self::note(Figures::words('Choose a test in the card\'s settings.'));
        }
        $e       = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $pct     = static fn (?float $v): string => $v === null ? '—' : NumberFormat::number(100 * $v, 1) . '%';
        $html    = '<table class="pf-report table table-sm"><thead><tr><th scope="col">' . $e(Figures::words('Variant'))
            . '</th><th scope="col" style="text-align:right">' . $e(Figures::words('People')) . '</th><th scope="col" style="text-align:right">'
            . $e(Figures::words('Converted')) . '</th><th scope="col" style="text-align:right">' . $e(Figures::words('Rate'))
            . '</th><th scope="col" style="text-align:right">' . $e(Figures::words('Confidence')) . '</th></tr></thead><tbody>';
        foreach ($results['variants'] as $i => $variant) {
            $html .= '<tr><th scope="row">' . $e($variant['label']) . ($i === 0 ? ' ' . $e(Figures::words('(control)')) : '') . '</th>'
                . '<td style="text-align:right">' . $e((string) $variant['people']) . '</td>'
                . '<td style="text-align:right">' . $e((string) $variant['converted']) . '</td>'
                . '<td style="text-align:right">' . $pct($variant['rate']) . '</td>'
                . '<td style="text-align:right">' . $pct($variant['confidence']) . '</td></tr>';
        }
        $html .= '</tbody></table>';
        if (!$results['counted']) {
            $html .= self::note(Figures::words('No goal event, or no event log: only people and shares are counted.'));
        } elseif ($results['visitors'] > 0) {
            $html .= self::note(sprintf(Figures::words('%d visitor(s) not yet signed in are also in the test.'), $results['visitors']));
        }

        return $html;
    }
}
