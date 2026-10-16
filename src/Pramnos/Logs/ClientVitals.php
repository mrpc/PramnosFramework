<?php

declare(strict_types=1);

namespace Pramnos\Logs;

use Pramnos\Database\Database;

/**
 * Core Web Vitals as visitors' browsers measured them — real users, not a lab run.
 *
 * `pf-vitals.js` measures a sampled share of page views with the `web-vitals` library and sends
 * them, once the page is hidden, to `ClientVitals` (the controller); they are kept here, one row
 * per metric, beside what a number depends on: the page group, the device, how the page was
 * reached (a reload, the back/forward cache, a prerender), and whether a service worker answered.
 *
 * ```php
 * // app/app.php — off until a sample is set
 * 'vitals' => [
 *     'sample'    => 0.1,                                   // a tenth of page views
 *     'groups'    => ['jobs' => '#^/(el|en)/jobs#', 'home' => '#^/(el|en)?/?$#'],
 *     'keep_days' => 30,
 * ],
 *
 * // the theme, before </body>
 * <?php echo \Pramnos\Logs\ClientVitals::tag(); ?>
 * ```
 *
 * The dashboard's `vitals` card shows the 75th percentile per page group and device — the figure
 * Google's thresholds are about.
 */
final class ClientVitals
{
    /** The metrics, as web-vitals names them. */
    public const METRICS = ['TTFB', 'FCP', 'LCP', 'INP', 'CLS'];

    /** Good up to the first number, poor from the second — web.dev's thresholds. */
    public const THRESHOLDS = [
        'TTFB' => [800, 1800],
        'FCP'  => [1800, 3000],
        'LCP'  => [2500, 4000],
        'INP'  => [200, 500],
        'CLS'  => [0.1, 0.25],
    ];

    /** The most metrics one page view sends. */
    private const PER_REPORT = 10;

    /** The web-vitals build pf-vitals.js reads, as `scaffolding/assets.json` installs it. */
    public const LIBRARY = 'assets/vendor/web-vitals/4.2.4/web-vitals.attribution.iife.js';

    public function __construct(private readonly ?Database $db = null)
    {
    }

    /** `'vitals' => [...]` in app.php. @return array<string, mixed> */
    public static function config(): array
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $config = is_object($app) ? ($app->applicationInfo['vitals'] ?? []) : [];

        return is_array($config) ? $config : [];
    }

    /** The share of page views measured, 0 to 1; 0 — the default — is off. */
    public static function sample(): float
    {
        $sample = self::config()['sample'] ?? 0;

        return is_numeric($sample) ? max(0.0, min(1.0, (float) $sample)) : 0.0;
    }

    /**
     * The tags a page needs, for the theme to print before `</body>`: where to send, the sample,
     * the library and the script. Nothing while the sample is 0, so a theme can print it always.
     */
    public static function tag(): string
    {
        $sample = self::sample();
        if ($sample <= 0.0) {
            return '';
        }
        $site = defined('sURL') ? (string) \sURL : '/';
        $e    = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        return '<meta name="pf-vitals" content="' . $e($site . 'ClientVitals') . '" data-sample="' . $e((string) $sample) . '">' . "\n"
            . '<script src="' . $e(assetUrl(self::LIBRARY)) . '" defer></script>' . "\n"
            . '<script src="' . $e(assetUrl('assets/js/pf-vitals.js')) . '" defer></script>' . "\n";
    }

    /**
     * The page group of a path: the first of `vitals.groups` whose pattern matches, or `other`.
     */
    public static function groupFor(string $path): string
    {
        foreach ((array) (self::config()['groups'] ?? []) as $name => $pattern) {
            if (is_string($pattern) && @preg_match($pattern, $path) === 1) {
                return mb_substr((string) $name, 0, 64);
            }
        }

        return 'other';
    }

    /**
     * Keep what one page view sent — `metrics`, each `{name, value, rating, navigation}`, and
     * what they share: `path`, `device`, `delivery`, `worker`, `workerMs`, `source`. A metric
     * that is not one of {@see METRICS}, or not a number, is left out. How many were kept.
     *
     * @param array<string, mixed> $report
     */
    public function record(array $report, bool $loggedIn): int
    {
        $path   = mb_substr((string) (is_string($report['path'] ?? null) ? $report['path'] : ''), 0, 255);
        $shared = [
            'page_group' => self::groupFor($path),
            'path'       => $path,
            'device'     => in_array($report['device'] ?? '', ['mobile', 'desktop'], true) ? (string) $report['device'] : '',
            'delivery'   => mb_substr(self::text($report['delivery'] ?? ''), 0, 32),
            'worker'     => in_array($report['worker'] ?? '', ['controlled', 'supported', 'unsupported'], true) ? (string) $report['worker'] : '',
            'worker_ms'  => is_numeric($report['workerMs'] ?? null) ? max(0, min(600000, (int) $report['workerMs'])) : null,
            'source'     => mb_substr(self::text($report['source'] ?? ''), 0, 16),
            'logged_in'  => $loggedIn,
            'created_at' => time(),
        ];

        $kept = 0;
        foreach (array_slice(is_array($report['metrics'] ?? null) ? $report['metrics'] : [], 0, self::PER_REPORT) as $metric) {
            $name = is_array($metric) ? (string) ($metric['name'] ?? '') : '';
            if (!in_array($name, self::METRICS, true) || !is_numeric($metric['value'] ?? null)) {
                continue;
            }
            $this->table()->insert($shared + [
                'metric'     => $name,
                'value'      => max(0.0, min(1e7, (float) $metric['value'])),
                'rating'     => in_array($metric['rating'] ?? '', ['good', 'needs-improvement', 'poor'], true) ? (string) $metric['rating'] : '',
                'navigation' => mb_substr(self::text($metric['navigation'] ?? ''), 0, 32),
            ]);
            $kept++;
        }

        // Now and then, rather than a job of its own: what is older than keep_days goes.
        if ($kept > 0 && random_int(1, 100) === 1) {
            $this->prune();
        }

        return $kept;
    }

    /** Forget what is older than `vitals.keep_days` (30 by default). How many went. */
    public function prune(): int
    {
        $days   = (int) (self::config()['keep_days'] ?? 30);
        $before = time() - max(1, $days) * 86400;
        $count  = $this->table()->where('created_at', '<', $before)->count();
        $this->table()->where('created_at', '<', $before)->delete();

        return $count;
    }

    /**
     * The 75th percentile of each metric, per page group and device, over the last `$days`:
     * `[group][device][metric] => ['p75' => float, 'count' => int, 'rating' => string]`, from the
     * newest `$limit` rows.
     *
     * @return array<string, array<string, array<string, array{p75: float, count: int, rating: string}>>>
     */
    public function summary(int $days = 7, int $limit = 50000): array
    {
        $values = [];
        $result = $this->table()->select(['metric', 'value', 'page_group', 'device'])
            ->where('created_at', '>=', time() - $days * 86400)
            ->orderBy('id', 'desc')->limit($limit)->get();
        while (($row = $result->fetch()) !== null) {
            $values[(string) $row['page_group']][(string) $row['device'] ?: 'unknown'][(string) $row['metric']][] = (float) $row['value'];
        }

        $summary = [];
        ksort($values);
        foreach ($values as $group => $devices) {
            ksort($devices);
            foreach ($devices as $device => $metrics) {
                foreach ($metrics as $metric => $list) {
                    sort($list);
                    $p75 = $list[(int) max(0, ceil(0.75 * count($list)) - 1)];
                    $summary[$group][$device][$metric] = ['p75' => $p75, 'count' => count($list), 'rating' => self::rate($metric, $p75)];
                }
            }
        }

        return $summary;
    }

    /** good, needs-improvement or poor, by {@see THRESHOLDS}. */
    public static function rate(string $metric, float $value): string
    {
        [$good, $poor] = self::THRESHOLDS[$metric] ?? [INF, INF];

        return $value <= $good ? 'good' : ($value <= $poor ? 'needs-improvement' : 'poor');
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? preg_replace('/[^a-z0-9_-]/i', '', $value) ?? '' : '';
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return ($this->db ?? \Pramnos\Framework\Factory::getDatabase())->queryBuilder()->table('#PREFIX#client_vitals');
    }
}
