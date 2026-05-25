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
 * Provides six actions:
 *   - display      — full HTML overview (active users + DB stats + API performance + health badges)
 *   - activeusers  — JSON: authenticated user counts per time window
 *   - apistats     — JSON: API performance summary (error rate, avg/p95/p99 latency)
 *   - dbstats      — JSON: database server metrics (size, connections, cache hit ratio)
 *   - database     — HTML detail page: DB processes, table sizes, TimescaleDB hypertables/aggregates/jobs
 *   - cache        — HTML detail page: cache adapter info, namespace list, item browser
 *
 * All six actions require authentication (manager level: usertype >= 80).
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
        $this->addAuthAction(['display', 'activeusers', 'apistats', 'dbstats', 'database', 'cache']);
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

        $processes  = $this->collectProcessList($db);
        $tableSizes = $this->collectTableSizes($db);
        $tsData     = $this->collectTimescaleData($db);

        $view             = $this->getView('dashboard');
        $view->stats      = $stats;
        $view->processes  = $processes;
        $view->tableSizes = $tableSizes;
        $view->tsData     = $tsData;
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

        $items = [];
        foreach ($categories as $cat) {
            $catName = is_array($cat) ? ($cat['name'] ?? $cat[0] ?? '') : (string) $cat;
            $catItems = $cache->getAllItems($catName, 50);
            if (!empty($catItems)) {
                $items[$catName] = $catItems;
            }
        }

        $view             = $this->getView('dashboard');
        $view->cacheStats = $cacheStats;
        $view->categories = $categories;
        $view->items      = $items;
        return $view->display('cache');
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
                    "SELECT pid, usename, application_name, client_addr,
                            state, wait_event_type, wait_event,
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
            $rows = [(array) $r->fields];
            while ($r->nextRecord()) {
                $rows[] = (array) $r->fields;
            }
            return $rows;
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
            $rows = [(array) $r->fields];
            while ($r->nextRecord()) {
                $rows[] = (array) $r->fields;
            }
            return $rows;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Returns TimescaleDB-specific data if the extension is available.
     * Returns empty arrays for all keys on non-PostgreSQL or when TimescaleDB
     * is not installed.
     *
     * @return array{hypertables: array, aggregates: array, jobs: array, ts_version: string|null}
     */
    private function collectTimescaleData(\Pramnos\Database\Database $db): array
    {
        $empty = ['hypertables' => [], 'aggregates' => [], 'jobs' => [], 'ts_version' => null];
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
            $hypertables = [];
            if ($ht && $ht->numRows > 0) {
                $hypertables[] = (array) $ht->fields;
                while ($ht->nextRecord()) {
                    $hypertables[] = (array) $ht->fields;
                }
            }

            $ca = $db->query(
                "SELECT view_name, materialization_hypertable_name, compression_enabled, refresh_lag
                 FROM timescaledb_information.continuous_aggregates
                 ORDER BY view_name"
            );
            $aggregates = [];
            if ($ca && $ca->numRows > 0) {
                $aggregates[] = (array) $ca->fields;
                while ($ca->nextRecord()) {
                    $aggregates[] = (array) $ca->fields;
                }
            }

            $jr = $db->query(
                "SELECT job_id, proc_name, schedule_interval::text, max_runtime::text,
                        last_run_started_at, last_successful_finish, last_run_status, next_start
                 FROM timescaledb_information.jobs
                 ORDER BY job_id"
            );
            $jobs = [];
            if ($jr && $jr->numRows > 0) {
                $jobs[] = (array) $jr->fields;
                while ($jr->nextRecord()) {
                    $jobs[] = (array) $jr->fields;
                }
            }

            return [
                'hypertables' => $hypertables,
                'aggregates'  => $aggregates,
                'jobs'        => $jobs,
                'ts_version'  => $tsVersion,
            ];
        } catch (\Exception $e) {
            return $empty;
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
