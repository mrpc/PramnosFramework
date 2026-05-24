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
 * Provides four actions:
 *   - display      — full HTML overview (active users + DB stats + API performance + health badges)
 *   - activeusers  — JSON: authenticated user counts per time window
 *   - apistats     — JSON: API performance summary (error rate, avg/p95/p99 latency)
 *   - dbstats      — JSON: database server metrics (size, connections, cache hit ratio)
 *
 * All four actions require authentication (manager level: usertype >= 80).
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
        $this->addAuthAction(['display', 'activeusers', 'apistats', 'dbstats']);
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

    // ── Private helpers ───────────────────────────────────────────────────────

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
