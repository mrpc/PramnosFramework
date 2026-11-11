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
        $e  = htmlspecialchars($this->title(), ENT_QUOTES, 'UTF-8');
        $id = htmlspecialchars(self::anchor($this->title()), ENT_QUOTES, 'UTF-8');
        if ($this->report()['static']) {
            return '<h2 id="' . $id . '" style="margin:24px 0 8px">' . $e . '</h2>';
        }

        return '<button type="button" id="' . $id . '" class="pf-report-section-toggle" data-pf-report-section aria-expanded="true">' . $e . '</button>';
    }

    /** Where a section's heading is on its page, for the report's table of contents: by its title. */
    public static function anchor(string $title): string
    {
        return 'pf-report-' . substr(md5(trim($title)), 0, 10);
    }

    /**
     * A report's table of contents: a link to each section's heading — those that are headings of
     * their own, not tabs — where there are more than two.
     *
     * @param list<array<string, mixed>> $sections As a report's definition has them
     */
    public static function contents(array $sections): string
    {
        $titles = array_values(array_unique(array_filter(array_map(
            static fn (array $s): string => ($s['tab'] ?? '') === '' ? trim((string) ($s['title'] ?? '')) : '', $sections
        ))));
        if (count($titles) < 3) {
            return '';
        }
        $e    = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $html = '<nav class="pf-report-contents" aria-label="' . $e(Figures::words('Contents')) . '"><ol>';
        foreach ($titles as $title) {
            $html .= '<li><a href="#' . $e(self::anchor($title)) . '">' . $e($title) . '</a></li>';
        }

        return $html . '</ol></nav>';
    }
}
