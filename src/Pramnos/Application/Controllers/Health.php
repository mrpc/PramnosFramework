<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Health\HealthRegistry;
use Pramnos\User\User;

/**
 * Framework health-check controller.
 *
 * Provides every application with a ready-made HTTP health dashboard and JSON
 * endpoint — no application-level code required.
 *
 * Actions:
 *   display() — HTML dashboard: all check results, DB info, cache stats, PHP version.
 *   check()   — JSON endpoint: `{"status":"ok|degraded|down","checks":{…}}`
 *               Suitable for uptime monitoring (Uptime Robot, Grafana, etc.).
 *   status()  — the same verdict, flattened: `{"status":"healthy|unhealthy",…}`.
 *               For monitors that cannot read a nested document, and for a public
 *               endpoint that should give away nothing about what is inside.
 *   phpinfo() — phpinfo() output (superadmin only: usertype >= 90).
 *
 * All actions require an authenticated user.  phpinfo() additionally requires
 * usertype >= 90 to prevent info leakage.
 *
 * Scaffold wrapper:
 *   pramnos init generates `src/Controllers/Health.php` extending this class.
 *
 */
class Health extends Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'phpinfo']);
        // check() and status() are intentionally PUBLIC — a monitor calls them
        // with no credentials.
        $this->actions[] = 'check';
        $this->actions[] = 'status';
        parent::__construct($application);
    }

    // =========================================================================
    // Actions
    // =========================================================================

    /**
     * HTML health dashboard.
     *
     * Shows all registered health checks with colour-coded status badges
     * (ok=green, degraded=yellow, down=red), DB info (type/version), cache
     * adapter, active user count, and PHP version.
     *
     * Renders via the view system (theme-aware scaffolding fallback at
     * scaffolding/themes/{theme}/views/health/health.html.php) so applications
     * can override the layout by publishing the view.
     */
    public function display(): mixed
    {
        $report = HealthRegistry::runAll();
        $doc    = \Pramnos\Framework\Factory::getDocument();

        $doc->title = 'Health';

        $checks = $report['checks'] ?? [];

        // DB info — read from DatabaseConnectivityCheck details (no extra query)
        $dbDetails = $checks['database']['details'] ?? [];
        $db        = \Pramnos\Framework\Factory::getDatabase();
        $dbType    = ucfirst((string) ($dbDetails['driver'] ?? $db?->type ?? 'unknown'));
        if ($dbType === 'Unknown' && !$db) {
            $dbType = 'not connected';
        }
        $dbVersion = (string) ($dbDetails['version'] ?? '—');

        // Cache info
        $cacheAdapter = '—';
        if (\Pramnos\Application\FeatureRegistry::isEnabled('cache')) {
            try {
                $cacheAdapter = \Pramnos\Cache\Cache::getInstance()->method;
            } catch (\Throwable) {
            // A check that cannot run reports as unhealthy below rather than
            // breaking the health endpoint itself.
        }
        }

        // Active users — delegated to User model
        $activeCount = User::countActiveSessions();
        $activeUsers = $activeCount !== null ? (string) $activeCount : '—';

        $overallStatus = $report['status'];
        $peakMemory    = $this->humanBytes(memory_get_peak_usage(true));

        $view                = $this->getView('health');
        $view->overallStatus = $overallStatus;
        $view->checks        = $checks;
        $view->dbType        = $dbType;
        $view->dbVersion     = $dbVersion;
        $view->cacheAdapter  = $cacheAdapter;
        $view->activeUsers   = $activeUsers;
        $view->peakMemory    = $peakMemory;
        return $view->display();
    }

    /**
     * JSON health endpoint for monitoring systems.
     *
     * Returns HTTP 200 for ok, 503 for degraded/down.
     * Response format:
     *   {"status":"ok|degraded|down","checks":{name:{status,message,details},...}}
     */
    public function check(): mixed
    {
        $report = HealthRegistry::runAll();

        $httpCode = match ($report['status']) {
            'ok'       => 200,
            'degraded' => 503,
            'down'     => 503,
            default    => 503,
        };

        return \Pramnos\Http\Response::json($report, $httpCode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->withHeader('Cache-Control', 'no-cache, no-store');
    }

    /**
     * The same verdict as check(), flattened to three keys.
     *
     * ```json
     * {"status":"healthy","timestamp":"2026-08-25T14:46:08+00:00","service":"my-app"}
     * ```
     *
     * Two reasons this exists next to check() rather than instead of it.
     *
     * **Some monitors cannot read a nested document.** A status page, a load
     * balancer probe or a shell script wants one field, and asking it to walk
     * `checks.*.status` is how a probe ends up parsing with `grep`.
     *
     * **It gives away nothing.** `check()` publishes versions, drivers, paths and
     * latencies in `details`, which is a fair trade for a monitoring endpoint on a
     * private network and not one to make on the open internet. This answers only
     * whether the application is well, and — when it is not — the *names* of the
     * failing checks, so an operator knows where to look without the endpoint
     * describing the inside of the system to everybody.
     *
     * It does not re-probe anything: the report comes from the same
     * `HealthRegistry::runAll()` that check() uses. Two endpoints answering the
     * same question from two sets of probes is how they come to disagree.
     *
     * `OPTIONS` is answered for the CORS preflight a browser-based status page
     * sends.
     */
    public function status(): mixed
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            return \Pramnos\Http\Response::make('', 204)
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
                ->withHeader('Access-Control-Max-Age', '86400');
        }

        $report  = HealthRegistry::runAll();
        $healthy = ($report['status'] ?? 'down') === 'ok';

        $payload = [
            'status'    => $healthy ? 'healthy' : 'unhealthy',
            'timestamp' => date('c'),
            'service'   => (string) ($this->application->applicationInfo['name'] ?? ''),
        ];

        // Names only. What exactly is wrong is for the authenticated dashboard.
        if (!$healthy) {
            $payload['errors'] = array_keys(array_filter(
                $report['checks'] ?? [],
                static fn (array $check): bool => ($check['status'] ?? '') !== 'ok'
            ));
        }

        return \Pramnos\Http\Response::json($payload, $healthy ? 200 : 503, JSON_UNESCAPED_SLASHES)
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Cache-Control', 'no-cache, no-store');
    }

    /**
     * PHP Info page — admin-only.
     *
     * Requires usertype >= 90 to prevent PHP configuration leakage.
     */
    public function phpinfo(): mixed
    {
        $user = \Pramnos\User\User::getCurrentUser();
        if ($user === null || (int) ($user->usertype ?? 0) < 90) {
            http_response_code(403);
            return '<p>Access denied.</p>';
        }

        ob_start();
        \phpinfo();
        $phpInfoRaw = ob_get_clean();

        $phpInfoRaw = preg_replace('/^.*<body>/si', '', $phpInfoRaw);
        $phpInfoRaw = preg_replace('/<\/body>.*$/si', '', $phpInfoRaw);

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'PHP Info';

        return $phpInfoRaw;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
