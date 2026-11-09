<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Widgets;

use Pramnos\Dashboard\Widgets\Figures;

/**
 * The assistant's words on a report: written from the report's own numbers — its tables, as its
 * CSV holds them — each time the report is drawn for sending, and kept for the period, so that
 * opening the report does not ask again. On the page it shows what was written for that period.
 * A routine of the assistant's becomes a report with one of these.
 *
 * Settings: `title`, `instructions` — what to look at, in the reader's words.
 */
class AnalysisWidget extends ReportWidget
{
    /** How long words written for a period are kept: a closed period's numbers do not change. */
    public const KEEP = 400 * 86400;

    /** @var (\Closure(): \Pramnos\Llm\Client)|null A test's own client */
    private static ?\Closure $client = null;

    /** A test's own store in place of the cache's 'reports' category. */
    private static ?\Pramnos\Cache\Cache $cache = null;

    public function id(): string
    {
        return 'report.analysis';
    }

    protected function name(): string
    {
        return 'Analysis';
    }

    public function description(): string
    {
        return Figures::words('The assistant\'s reading of the report\'s numbers, written when it is sent');
    }

    public function options(): array
    {
        return [
            'title'        => self::titleOption(),
            'instructions' => ['label' => 'What to look at', 'input' => 'textarea', 'clean' => static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 2000) : null],
        ];
    }

    public function render(): string
    {
        $report = $this->report();
        $entry  = $report['entry'] ?? null;
        if ($entry === null) {
            return self::note(Figures::words('An analysis is written on a saved report.'));
        }
        $key   = 'analysis.' . md5($entry['id'] . '|' . $report['window']->key() . '|' . json_encode($this->setting('instructions')) . '|' . json_encode($report['filters'] ?? []));
        $words = self::store()->load($key, null, self::KEEP);
        // Written when the report is drawn for sending; the page shows what was written.
        if ((!is_string($words) || $words === '') && $report['static']) {
            $words = $this->write($entry, $report);
            if ($words !== '') {
                $store          = self::store();
                $store->timeout = self::KEEP;
                $store->save($words, $key);
            }
        }
        if (!is_string($words) || $words === '') {
            return self::note(Figures::words('The assistant writes its reading when the report is sent.'));
        }

        return '<div class="pf-report-analysis">' . \Pramnos\Llm\Chat\Markdown::toHtml($words) . '</div>';
    }

    /**
     * The assistant's words on the report's numbers for the period, or '' where it could not.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $report
     */
    private function write(array $entry, array $report): string
    {
        try {
            $numbers = \Pramnos\Metrics\ReportBoard::csv($entry, $report['at'], $report['user']);
            $client  = self::$client !== null ? (self::$client)() : new \Pramnos\Llm\Client();
            $asked   = trim((string) $this->setting('instructions'));
            $answer  = $client->askCheaply(
                'You read a business report\'s numbers and say, in a few short paragraphs, what stands out: what rose or fell, '
                . 'against what, and what may explain it. Only what the numbers show; no advice they cannot support. '
                . 'Write in the language the reader writes in, or the report\'s.',
                [['role' => 'user', 'content' => 'Report: ' . $entry['name'] . "\nPeriod: " . $report['window']->label()
                    . ($asked !== '' ? "\nLook at: " . $asked : '') . "\n\nNumbers (CSV):\n" . mb_substr($numbers, 0, 20000)]],
            );

            return ($answer['ok'] ?? false) ? trim((string) ($answer['reply'] ?? '')) : '';
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Report ' . $entry['id'] . ': no analysis written: ' . $e->getMessage(), 'metrics');

            return '';
        }
    }

    /** A test's own client, or null for the application's. @param (\Closure(): \Pramnos\Llm\Client)|null $client */
    public static function useClient(?\Closure $client): void
    {
        self::$client = $client;
    }

    /** A test's own store, or null for the cache. */
    public static function useCache(?\Pramnos\Cache\Cache $cache): void
    {
        self::$cache = $cache;
    }

    private static function store(): \Pramnos\Cache\Cache
    {
        return self::$cache ?? \Pramnos\Cache\Cache::getInstance('reports');
    }
}
