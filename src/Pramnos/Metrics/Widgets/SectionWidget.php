<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;

/**
 * A section of a report: a thin heading across the whole width — a line, not a card — over the
 * cards under it, up to the next section. On the page the heading folds them away and back
 * (`data-pf-report-section`, its arrow drawn by the report's style); in a mail it is the heading
 * alone.
 *
 * Settings: `title`.
 */
class SectionWidget extends ReportWidget
{
    public function id(): string
    {
        return 'report.section';
    }

    protected function name(): string
    {
        return 'Section';
    }

    public function description(): string
    {
        return Figures::words('A heading over the cards under it, that folds them away');
    }

    public function options(): array
    {
        return ['title' => self::titleOption()];
    }

    public function render(): string
    {
        $e = htmlspecialchars($this->title(), ENT_QUOTES, 'UTF-8');
        if ($this->report()['static']) {
            return '<h2 style="margin:24px 0 8px">' . $e . '</h2>';
        }

        return '<button type="button" class="pf-report-section-toggle" data-pf-report-section aria-expanded="true">' . $e . '</button>';
    }
}
