<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * A report built from words: "sales by pillar against last year" becomes a report's definition,
 * which the editor shows filled in, with its numbers — nothing is saved until the person saves.
 *
 * ```php
 * $report = ReportProposal::fromWords('Orders by region, against last year', $user);
 * ```
 *
 * The assistant writes the report as the editor's text ({@see Report::sectionsFromText()},
 * {@see Report::columnsFromText()}) from the catalogue the person may read, and only from it: a
 * row naming any other metric, or a dimension the metric does not keep, is left out.
 */
final class ReportProposal
{
    /** @var (\Closure(): \Pramnos\Llm\Client)|null A test's own client */
    private static ?\Closure $client = null;

    /**
     * The report the words describe.
     *
     * @throws \InvalidArgumentException When the assistant could not make one of what the person may read
     */
    public static function fromWords(string $words, object $user): Report
    {
        $words   = mb_substr(trim($words), 0, 1000);
        $metrics = Metrics::all($user, false);
        if ($words === '' || $metrics === []) {
            throw new \InvalidArgumentException('There is nothing to build a report from.');
        }
        $catalogue = [];
        foreach ($metrics as $metric) {
            $catalogue[] = ['metric' => $metric->name, 'label' => $metric->label(), 'unit' => $metric->getUnit(), 'kind' => $metric->kind,
                'aggregate' => $metric->getAggregate(), 'dimensions' => $metric->getDimensions()];
        }
        $client = self::$client !== null ? (self::$client)() : new \Pramnos\Llm\Client();
        $answer = $client->askCheaply(
            'You turn a request for a business report into the report, as JSON only: '
            . '{"name": "...", "grain": "day|week|month|quarter|year", "compare": "previous|year|", "columns": "...", "sections": "..."}. '
            . "columns is one of: `periods=N` (the last N periods), or `dimension=<dimension> <value>,<value> total`. "
            . "sections is lines: `# Title [chart=line|bar]` starts a section; each other line is a row, "
            . '`Label | metric | dimension=value | by=dimension total` (the last two parts optional). '
            . 'Use only the metrics and dimensions in the catalogue. Name the report in the language of the request.',
            [['role' => 'user', 'content' => 'Catalogue: ' . json_encode($catalogue, JSON_UNESCAPED_UNICODE) . "\n\nRequest: " . $words]],
        );
        $reply = (string) ($answer['reply'] ?? '');
        $json  = ($answer['ok'] ?? false) ? json_decode(preg_match('/\{.*\}/s', $reply, $m) === 1 ? $m[0] : $reply, true) : null;
        if (!is_array($json)) {
            throw new \InvalidArgumentException('The assistant could not build a report from that.');
        }

        $sections = [];
        foreach (Report::sectionsFromText((string) ($json['sections'] ?? '')) as $section) {
            $section['rows'] = array_values(array_filter($section['rows'], static function (array $row) use ($metrics): bool {
                $metric = $metrics[$row['metric']] ?? null;
                $known  = $metric?->getDimensions() ?? [];

                return $metric !== null && ($row['breakdown'] === null || in_array($row['breakdown'], $known, true))
                    && array_diff(array_keys($row['filters']), $known) === [];
            }));
            if ($section['rows'] !== []) {
                $sections[] = $section;
            }
        }
        if ($sections === []) {
            throw new \InvalidArgumentException('The assistant found nothing you may read for that.');
        }
        $grain   = in_array($json['grain'] ?? null, Window::GRAINS, true) ? (string) $json['grain'] : Window::MONTH;
        $compare = in_array($json['compare'] ?? null, [Metrics::PREVIOUS, Metrics::YEAR_EARLIER], true) ? (string) $json['compare'] : null;
        $columns = Report::columnsFromText((string) ($json['columns'] ?? ''));
        // Columns by a dimension none of its rows keeps — or written any other way — are one period.
        $kept    = array_merge(...array_map(static fn (array $s): array => array_merge(...array_map(static fn (array $r): array => $metrics[$r['metric']]->getDimensions(), $s['rows'])), $sections));
        if (!isset($columns['periods']) && !in_array($columns['dimension'] ?? null, $kept, true)) {
            $columns = ['periods' => 1];
        }

        return Report::fromArray(['name' => mb_substr(trim((string) ($json['name'] ?? '')), 0, 120) ?: $words, 'grain' => $grain,
            'compare' => $compare, 'columns' => $columns, 'sections' => $sections]);
    }

    /** A test's own client, or null for the application's. @param (\Closure(): \Pramnos\Llm\Client)|null $client */
    public static function useClient(?\Closure $client): void
    {
        self::$client = $client;
    }
}
