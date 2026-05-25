<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Application\Statistics\ActiveUsersService;
use Pramnos\Application\Statistics\DatabaseStatsService;
use Pramnos\Application\Statistics\ApiPerformanceService;

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
 * @package     PramnosFramework
 * @subpackage  Application\Controllers
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

        $processes   = $this->collectProcessList($db);
        $tableSizes  = $this->collectTableSizes($db);
        $tsData      = $this->collectTimescaleData($db);
        $replication = $this->collectReplicationData($db);
        $publicViews = $this->collectPublicViews($db);

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

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Returns active database processes/queries.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectProcessList(\Pramnos\Database\Database $db): array
    {
        try {
            if ($db->type === 'postgresql') {
                $r = $db->query(
                    "SELECT pid, usename, datname, application_name,
                            client_addr::text AS client_addr,
                            state, wait_event_type, wait_event,
                            to_char(backend_start, 'YYYY-MM-DD HH24:MI:SS') AS backend_start,
                            EXTRACT(EPOCH FROM (now() - query_start))::int AS duration_sec,
                            left(query, 200) AS query
                     FROM pg_stat_activity
                     WHERE datname = current_database() AND pid <> pg_backend_pid()
                     ORDER BY duration_sec DESC NULLS LAST
                     LIMIT 50"
                );
            } else {
                $r = $db->query('SHOW PROCESSLIST');
            }
            if (!$r || $r->numRows === 0) {
                return [];
            }
            return $r->fetchAll();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Returns table sizes sorted by total bytes descending.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectTableSizes(\Pramnos\Database\Database $db): array
    {
        try {
            if ($db->type === 'postgresql') {
                $r = $db->query(
                    "SELECT schemaname, tablename AS table_name,
                            pg_total_relation_size(quote_ident(schemaname)||'.'||quote_ident(tablename)) AS total_bytes,
                            pg_relation_size(quote_ident(schemaname)||'.'||quote_ident(tablename)) AS data_bytes,
                            pg_total_relation_size(quote_ident(schemaname)||'.'||quote_ident(tablename))
                              - pg_relation_size(quote_ident(schemaname)||'.'||quote_ident(tablename)) AS index_bytes,
                            (SELECT reltuples::bigint FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                             WHERE n.nspname = schemaname AND c.relname = tablename) AS row_estimate
                     FROM information_schema.tables
                     WHERE table_schema NOT IN ('pg_catalog', 'information_schema')
                       AND table_type = 'BASE TABLE'
                     ORDER BY total_bytes DESC
                     LIMIT 30"
                );
            } else {
                $r = $db->query(
                    "SELECT table_name, data_length AS data_bytes, index_length AS index_bytes,
                            data_length + index_length AS total_bytes, table_rows AS row_estimate
                     FROM information_schema.tables
                     WHERE table_schema = DATABASE()
                     ORDER BY total_bytes DESC
                     LIMIT 30"
                );
            }
            if (!$r || $r->numRows === 0) {
                return [];
            }
            return $r->fetchAll();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Returns TimescaleDB-specific data if the extension is available.
     * Returns empty arrays for all keys on non-PostgreSQL or when TimescaleDB
     * is not installed.
     *
     * @return array{hypertables: array, aggregates: array, jobs: array, jobHistory: array, chunkCount: int, ts_version: string|null}
     */
    private function collectTimescaleData(\Pramnos\Database\Database $db): array
    {
        $empty = ['hypertables' => [], 'aggregates' => [], 'jobs' => [], 'jobHistory' => [], 'chunkCount' => 0, 'ts_version' => null];
        if ($db->type !== 'postgresql') {
            return $empty;
        }
        try {
            $vr = $db->query("SELECT extversion FROM pg_extension WHERE extname = 'timescaledb'");
            if (!$vr || $vr->numRows === 0) {
                return $empty;
            }
            $tsVersion = (string) $vr->fields['extversion'];

            $ht = $db->query(
                "SELECT hypertable_name, num_chunks, num_dimensions,
                        compression_enabled, tablespaces
                 FROM timescaledb_information.hypertables
                 ORDER BY hypertable_name"
            );
            $hypertables = ($ht && $ht->numRows > 0) ? $ht->fetchAll() : [];

            $ca = $db->query(
                "SELECT view_schema, view_name,
                        materialization_hypertable_schema AS materialization_schema,
                        materialization_hypertable_name AS materialization_name,
                        CASE WHEN materialized_only THEN 'Yes' ELSE 'No' END AS materialized_only,
                        compression_enabled
                 FROM timescaledb_information.continuous_aggregates
                 ORDER BY view_name"
            );
            $aggregates = ($ca && $ca->numRows > 0) ? $ca->fetchAll() : [];

            $jr = $db->query(
                "SELECT job_id, proc_schema, proc_name, schedule_interval::text,
                        last_run_started_at::text AS last_run_started_at,
                        last_successful_finish::text AS last_successful_finish,
                        last_run_status, next_start::text AS next_start
                 FROM timescaledb_information.jobs
                 ORDER BY job_id"
            );
            $jobs = ($jr && $jr->numRows > 0) ? $jr->fetchAll() : [];

            $jh = $db->query(
                "SELECT job_id, start_time::text AS start_time,
                        finish_time::text AS finish_time,
                        succeeded::text AS succeeded,
                        proc_schema, proc_name, err_message
                 FROM timescaledb_information.job_history
                 ORDER BY start_time DESC
                 LIMIT 200"
            );
            $jobHistory = ($jh && $jh->numRows > 0) ? $jh->fetchAll() : [];

            $cc = $db->query("SELECT COUNT(*) AS total FROM timescaledb_information.chunks");
            $chunkCount = ($cc && $cc->numRows > 0) ? (int) $cc->fields['total'] : 0;

            return [
                'hypertables' => $hypertables,
                'aggregates'  => $aggregates,
                'jobs'        => $jobs,
                'jobHistory'  => $jobHistory,
                'chunkCount'  => $chunkCount,
                'ts_version'  => $tsVersion,
            ];
        } catch (\Exception $e) {
            return $empty;
        }
    }

    /**
     * Returns PostgreSQL streaming replication status rows.
     * Empty array on non-PostgreSQL or when no standbys are connected.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectReplicationData(\Pramnos\Database\Database $db): array
    {
        if ($db->type !== 'postgresql') {
            return [];
        }
        try {
            $r = $db->query(
                "SELECT client_addr::text AS client_addr, state, sync_state,
                        EXTRACT(EPOCH FROM write_lag)::int AS lag_sec
                 FROM pg_stat_replication
                 ORDER BY client_addr"
            );
            return ($r && $r->numRows > 0) ? $r->fetchAll() : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Returns public-schema view definitions.
     * Empty array on non-PostgreSQL databases.
     *
     * @return array<int, array<string, mixed>>
     */
    private function collectPublicViews(\Pramnos\Database\Database $db): array
    {
        if ($db->type !== 'postgresql') {
            return [];
        }
        try {
            $r = $db->query(
                "SELECT table_name AS view_name, view_definition
                 FROM information_schema.views
                 WHERE table_schema = 'public'
                 ORDER BY table_name"
            );
            return ($r && $r->numRows > 0) ? $r->fetchAll() : [];
        } catch (\Exception $e) {
            return [];
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
