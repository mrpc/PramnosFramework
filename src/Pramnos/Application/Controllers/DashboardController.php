<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Application\Statistics\ActiveUsersService;
use Pramnos\Application\Statistics\DatabaseStatsService;
use Pramnos\Application\Statistics\ApiPerformanceService;
use Pramnos\Database\Inspector\DatabaseInspector;
use Pramnos\Database\Inspector\TimescaleInspector;

/**
 * Admin/ops overview dashboard controller.
 *
 * Provides eight actions:
 *   - display      — full HTML overview (active users + DB stats + API performance + health badges)
 *   - activeusers  — JSON: authenticated user counts per time window
 *   - apistats     — JSON: API performance summary (error rate, avg/p95/p99 latency)
 *   - dbstats      — JSON: database server metrics (size, connections, cache hit ratio)
 *   - database     — HTML detail page: DB processes, replication, table sizes, TimescaleDB detail
 *   - cache        — HTML detail page: cache adapter info, namespace list, item browser
 *   - cacheitem    — JSON: single cache item content (GET ?key=…)
 *   - clearcache   — JSON: clear all cache entries (POST)
 *
 * All eight actions require authentication (manager level: usertype >= 80).
 *
 * Note: This controller is distinct from \Pramnos\Auth\Controllers\Dashboard, which
 * handles the end-user account management view. This controller is admin/ops-focused.
 *
 * Thin wrappers in scaffolded apps live at src/Controllers/Dashboard.php.
 *
 */
class DashboardController extends Controller
{
    /** Minimum usertype to access any dashboard action. */
    protected int $requiredUserType = 80;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'activeusers', 'apistats', 'dbstats', 'database', 'cache', 'cacheitem', 'clearcache']);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * HTML overview dashboard combining all data widgets.
     *
     * Aggregates: active-user counts, DB metrics, API performance (last 24h),
     * and health check badges from HealthRegistry::runAll().
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Admin Dashboard';

        $view = $this->getView('dashboard');

        $view->activeUsers   = (new ActiveUsersService())->getCounts();
        $view->dbStats       = (new DatabaseStatsService())->getStats();
        $view->apiStats      = (new ApiPerformanceService())->getSummary(ApiPerformanceService::WINDOW_24H);
        $view->healthResults = \Pramnos\Health\HealthRegistry::runAll()['checks'] ?? [];

        return $view->display('admin_dashboard');
    }

    /**
     * JSON endpoint: authenticated user counts per time window.
     * Suitable for AJAX dashboard widget refresh.
     *
     * Response shape:
     *   {"now": int, "last_1h": int, "last_24h": int, "last_7d": int, "last_30d": int}
     */
    public function activeusers(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        header('Content-Type: application/json');
        echo json_encode((new ActiveUsersService())->getCounts());
    }

    /**
     * JSON endpoint: API performance summary.
     * Accepts optional query-string parameter `window` (seconds, default 86400).
     *
     * Response shape: see ApiPerformanceService::getSummary() return type.
     */
    public function apistats(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $validWindows = [
            ApiPerformanceService::WINDOW_1H,
            ApiPerformanceService::WINDOW_24H,
            ApiPerformanceService::WINDOW_7D,
        ];
        $window = (int) ($_GET['window'] ?? ApiPerformanceService::WINDOW_24H);
        if (!in_array($window, $validWindows, true)) {
            $window = ApiPerformanceService::WINDOW_24H;
        }

        header('Content-Type: application/json');
        echo json_encode((new ApiPerformanceService())->getSummary($window));
    }

    /**
     * JSON endpoint: database server metrics.
     *
     * Response shape: see DatabaseStatsService::getStats() return type.
     */
    public function dbstats(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        header('Content-Type: application/json');
        echo json_encode((new DatabaseStatsService())->getStats());
    }

    /**
     * Detailed database information page.
     *
     * Shows server version, size, connection counts, cache hit ratio, active
     * processes, table sizes, and (for PostgreSQL+TimescaleDB) hypertables,
     * continuous aggregates, and scheduled jobs.
     */
    public function database(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Database Details';

        $db    = \Pramnos\Framework\Factory::getDatabase();
        $stats = (new DatabaseStatsService())->getStats();

        $dbInspector = new DatabaseInspector($db);
        $processes   = $dbInspector->getProcessList();
        $tableSizes  = $dbInspector->getTableSizes();
        $replication = $dbInspector->getReplicationStatus();
        $publicViews = $dbInspector->getPublicViews();
        $tsData      = (new TimescaleInspector($db))->getData();

        $view              = $this->getView('dashboard');
        $view->stats       = $stats;
        $view->processes   = $processes;
        $view->tableSizes  = $tableSizes;
        $view->tsData      = $tsData;
        $view->replication = $replication;
        $view->publicViews = $publicViews;
        return $view->display('database');
    }

    /**
     * Detailed cache information page.
     *
     * Shows cache adapter, categories list, and item browser.
     */
    public function cache(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Cache Details';

        $cache      = \Pramnos\Cache\Cache::getInstance();
        $cacheStats = $cache->getStats();
        $categories = $cache->getCategories();

        $items           = [];
        $cacheCategories = [];
        $namespaceStats  = [];
        $cacheItems      = [];

        foreach ($categories as $cat) {
            $catName          = is_array($cat) ? ($cat['name'] ?? $cat[0] ?? '') : (string) $cat;
            $catItems         = $cache->getAllItems($catName, 50);
            $cacheCategories[] = $catName;
            $namespaceStats[$catName] = count($catItems);
            if (!empty($catItems)) {
                $items[$catName] = $catItems;
                foreach ($catItems as $item) {
                    if (is_array($item) && !array_key_exists('namespace', $item)) {
                        $item['namespace'] = $catName;
                    }
                    $cacheItems[] = $item;
                }
            }
        }

        $method              = strtolower($cacheStats['method'] ?? 'none');
        $cacheStatus         = $method !== 'none' && $method !== '';
        $isMemcached         = in_array($method, ['memcached', 'memcache'], true);
        $memcachedLimitation = $isMemcached && empty($cacheItems) && ($cacheStats['items'] ?? 0) > 0;

        $view                             = $this->getView('dashboard');
        $view->cacheStats                 = $cacheStats;
        $view->categories                 = $categories;
        $view->items                      = $items;
        $view->cacheStatus                = $cacheStatus;
        $view->namespaceStats             = $namespaceStats;
        $view->cacheCategories            = $cacheCategories;
        $view->cacheItems                 = $cacheItems;
        $view->memcachedLimitation        = $memcachedLimitation;
        $view->memcachedLimitationMessage = $memcachedLimitation
            ? 'Memcached does not support listing individual cache items. Statistics are available but item details cannot be displayed. Consider using Redis for full cache management.'
            : '';
        return $view->display('cache');
    }

    /**
     * JSON endpoint: single cache item content.
     * GET parameter: key (required).
     *
     * Response: {success: bool, content: mixed, metadata: {size, type, created, ttl}}
     */
    public function cacheitem(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        header('Content-Type: application/json');

        $key = $_GET['key'] ?? '';
        if ($key === '') {
            echo json_encode(['success' => false, 'error' => 'No key provided']);
            return;
        }

        try {
            $cache   = \Pramnos\Cache\Cache::getInstance();
            $content = $cache->load($key);
            if ($content === false || $content === null) {
                echo json_encode(['success' => false, 'error' => 'Item not found or expired']);
                return;
            }

            $raw  = serialize($content);
            $size = strlen($raw);
            if ($size >= 1048576) {
                $sizeStr = round($size / 1048576, 2) . ' MB';
            } elseif ($size >= 1024) {
                $sizeStr = round($size / 1024, 2) . ' KB';
            } else {
                $sizeStr = $size . ' B';
            }

            echo json_encode([
                'success'  => true,
                'content'  => $content,
                'metadata' => [
                    'size'    => $sizeStr,
                    'type'    => gettype($content),
                    'created' => '—',
                    'ttl'     => '—',
                ],
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * JSON endpoint: clear all cache entries.
     * Must be called with POST method.
     *
     * Response: {success: bool}
     */
    public function clearcache(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            return;
        }

        header('Content-Type: application/json');

        try {
            $cache = \Pramnos\Cache\Cache::getInstance();
            $cache->clear();
            echo json_encode(['success' => true]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Redirects to sURL if the current user's usertype is below $minType.
     * Returns true if the redirect was issued (caller should return early).
     */
    protected function requireMinUserType(int $minType): bool
    {
        $user = \Pramnos\User\User::getCurrentUser();

        if ($user === null || (int) $user->usertype < $minType) {
            $this->redirect(sURL);
            return true;
        }

        return false;
    }
}
