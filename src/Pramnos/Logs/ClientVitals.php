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
     *
     * `ui` names the page's look — `tag(['ui' => 'new'])` — sent with every report, so one theme's
     * numbers can be set against another's.
     *
     * @param array{ui?: string} $options
     */
    public static function tag(array $options = []): string
    {
        $sample = self::sample();
        if ($sample <= 0.0) {
            return '';
        }
        $site = defined('sURL') ? (string) \sURL : '/';
        $e    = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $ui   = mb_substr(self::text($options['ui'] ?? ''), 0, 16);

        return '<meta name="pf-vitals" content="' . $e($site . 'ClientVitals') . '" data-sample="' . $e((string) $sample) . '"'
            . ($ui !== '' ? ' data-ui="' . $e($ui) . '"' : '') . '>' . "\n"
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
     * Keep what one page view sent — `metrics`, each `{name, value, rating, navigation, target}`,
     * and what they share: `path`, `device`, `delivery`, `worker`, `workerMs`, `source`, `ui`. A
     * metric that is not one of {@see METRICS}, or not a number, is left out. How many were kept.
     *
     * Not kept at all from a lab or a robot — Lighthouse, PageSpeed, GTmetrix, Pingdom, a
     * headless browser, a crawler ({@see isRobot()}): their numbers are not a visitor's. A report
     * with no device takes it from the user agent. The path is masked ({@see PagePath}) — or given to
     * `vitals.path`, `fn (string $path): string` — before its group is found. `vitals.dimension` — `fn (string $path, array
     * $report, array $server): ?string` — gives the application's own value, a market say.
     *
     * @param array<string, mixed>      $report
     * @param array<string, mixed>|null $server `$_SERVER`, where the user agent and the rest are read
     */
    public function record(array $report, bool $loggedIn, ?array $server = null): int
    {
        $server ??= $_SERVER;
        $agent    = (string) ($server['HTTP_USER_AGENT'] ?? '');
        if (self::isRobot($agent)) {
            return 0;
        }
        // Masked before anything reads it: a path may carry a live token ({@see PagePath}).
        $raw    = is_string($report['path'] ?? null) ? $report['path'] : '';
        $own    = self::config()['path'] ?? null;
        $path   = mb_substr(is_callable($own) ? (string) $own($raw) : PagePath::mask($raw), 0, 255);
        $device = in_array($report['device'] ?? '', ['mobile', 'desktop'], true) ? (string) $report['device']
            : ($agent === '' ? '' : (\Pramnos\Visits\Hits::device($agent) === 'desktop' ? 'desktop' : 'mobile'));
        $shared = [
            'page_group' => self::groupFor($path),
            'path'       => $path,
            'device'     => $device,
            'delivery'   => mb_substr(self::text($report['delivery'] ?? ''), 0, 32),
            'worker'     => in_array($report['worker'] ?? '', ['controlled', 'supported', 'unsupported'], true) ? (string) $report['worker'] : '',
            'worker_ms'  => is_numeric($report['workerMs'] ?? null) ? max(0, min(600000, (int) $report['workerMs'])) : null,
            'source'     => mb_substr(self::text($report['source'] ?? ''), 0, 16),
            'logged_in'  => $loggedIn,
            'created_at' => time(),
        ];
        $more = $this->hasDetails();
        if ($more) {
            $dimension = self::config()['dimension'] ?? null;
            $value     = is_callable($dimension) ? $dimension($path, $report, $server) : null;
            $shared += ['dimension' => is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, 20) : null,
                'ui' => mb_substr(self::text($report['ui'] ?? ''), 0, 16) ?: null];
        }

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
            ] + ($more ? ['target' => self::selector($metric['target'] ?? null)] : []));
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

    /**
     * Whether a user agent is a lab or a robot rather than a visitor: the crawlers
     * {@see \Pramnos\Http\Middleware\BotDetector} knows, and the testing tools that run a real
     * browser and say so.
     */
    public static function isRobot(string $agent): bool
    {
        return $agent !== '' && (preg_match('/headless|lighthouse|pagespeed|gtmetrix|pingdom|ptst|speedcurve|webpagetest|phantomjs|puppeteer|playwright|selenium/i', $agent) === 1
            || (new \Pramnos\Http\Middleware\BotDetector())->isBot($agent));
    }

    /** An element's selector as the attribution names it: one line, at most 100 characters. */
    private static function selector(mixed $value): ?string
    {
        $value = is_string($value) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)) : '';

        return $value === '' ? null : mb_substr($value, 0, 100);
    }

    /** Whether the table has `dimension`, `ui` and `target` yet: asked once per connection. */
    private function hasDetails(): bool
    {
        static $known = null;
        $known ??= new \WeakMap();
        $db = $this->db ?? \Pramnos\Framework\Factory::getDatabase();
        if (!isset($known[$db])) {
            $known[$db] = $db->schema()->hasColumn('pramnos.client_vitals', 'target');
        }

        return $known[$db];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? preg_replace('/[^a-z0-9_-]/i', '', $value) ?? '' : '';
    }

    private function table(): \Pramnos\Database\QueryBuilder
    {
        return ($this->db ?? \Pramnos\Framework\Factory::getDatabase())->queryBuilder()->table('pramnos.client_vitals');
    }
}
