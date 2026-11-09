<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;
use Pramnos\Dashboard\Widgets\MetricCard;
use Pramnos\Metrics\Metrics;

/**
 * A metric on a map, in the report's period: a circle at each value of a dimension that has places
 * ({@see \Pramnos\Metrics\Dimension::geo()}), sized by its figure. Drawn with Leaflet by pf-utils
 * where the page has it, over OpenStreetMap; the figures as a table under it, and alone without it.
 *
 * Settings: `title`, `metric`, `dimension`, `filters`.
 */
class MapWidget extends ReportWidget
{
    public function id(): string
    {
        return 'report.map';
    }

    protected function name(): string
    {
        return 'Map';
    }

    public function description(): string
    {
        return Figures::words('A metric by place, on a map');
    }

    public function size(): string
    {
        return self::LARGE;
    }

    public function options(): array
    {
        $dimensions = ['' => Figures::words('Choose…')];
        foreach (Metrics::dimensions() as $name => $dimension) {
            if ($dimension->hasGeo()) {
                $dimensions[(string) $name] = Figures::words($dimension->label);
            }
        }

        return [
            'title'     => self::titleOption(),
            'metric'    => ['label' => 'Metric', 'values' => ['' => Figures::words('Choose…')] + self::metricChoices()],
            'dimension' => ['label' => 'Places', 'values' => $dimensions],
            'filters'   => self::filtersField(),
        ];
    }

    public function render(): string
    {
        $name      = (string) $this->setting('metric');
        $dimension = Metrics::dimensionOf((string) ($this->setting('dimension') ?: 'none'));
        $report    = $this->report();
        $metric    = Metrics::all($report['user'], false)[$name] ?? null;
        if ($metric === null || !$dimension->hasGeo()) {
            return self::note(Figures::words('Choose a metric and a dimension with places in the card\'s settings.'));
        }
        try {
            $values = Metrics::query($name)->grain($report['window']->grain)->at($report['window']->key())
                ->where((array) ($this->setting('filters') ?? []))->by($dimension->name)->for($report['user'])->series()['values'];
        } catch (\InvalidArgumentException|\LogicException $e) {
            return self::note($e->getMessage());
        }
        $points = [];
        foreach ($values as $key => $series) {
            $where = $dimension->geoOf((string) $key);
            if ($where !== null && ($series[0] ?? null) !== null) {
                $points[] = ['lat' => $where[0], 'lng' => $where[1], 'label' => $dimension->labelOf((string) $key), 'value' => (float) $series[0],
                    'text' => MetricCard::format((float) $series[0], $metric->getUnit())];
            }
        }
        if ($points === []) {
            return self::note(Figures::words('Nothing in this period.'));
        }
        $e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach ($points as $point) {
            $rows .= '<tr><th scope="row">' . $e($point['label']) . '</th><td style="text-align:right">' . $e($point['text']) . '</td></tr>';
        }
        $this->enqueueMap();

        return '<div class="pf-report-map" data-pf-map="' . $e((string) json_encode($points, JSON_UNESCAPED_UNICODE)) . '" style="height:320px" hidden></div>'
            . '<table class="pf-report table table-sm" data-pf-map-table><tbody>' . $rows . '</tbody></table>';
    }

    /** Leaflet on the page, where the application registered it. */
    private function enqueueMap(): void
    {
        try {
            $doc = \Pramnos\Framework\Factory::getDocument();
            foreach (['leaflet'] as $handle) {
                if (is_object($doc) && method_exists($doc, 'isScriptRegistered') && $doc->isScriptRegistered($handle)) {
                    $doc->enqueueScript($handle);
                    if (method_exists($doc, 'enqueueStyle')) {
                        $doc->enqueueStyle($handle);
                    }
                }
            }
        } catch (\Throwable) {
            // No document: the table stands alone.
        }
    }
}
