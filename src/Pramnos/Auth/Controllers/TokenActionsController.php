<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;

/**
 * Read-only audit log controller for token actions.
 *
 * Provides visibility into the `#PREFIX#tokenactions` table — every API call
 * made with a user token is logged there. This controller is read-only: no
 * write operations are permitted from the UI to preserve the audit trail.
 *
 * Actions:
 *   - display()   — paginated DataTable with filters (token, user, endpoint, status, date range)
 *   - show($id)   — detailed view of a single token action entry
 *   - stats()     — JSON: top endpoints, error rate, avg/p95/p99 latency
 *   - export()    — CSV download with applied filters (compliance/auditing)
 *
 * All actions require authentication + usertype >= 80.
 * Write operations (delete/update) are intentionally absent to protect audit integrity.
 *
 * Scaffold wrappers at `src/Controllers/TokenActions.php` (auth feature).
 *
 * @package     PramnosFramework
 * @subpackage  Auth\Controllers
 */
class TokenActionsController extends Controller
{
    /** Minimum usertype to access any token-actions action. */
    protected int $requiredUserType = 80;

    /** Maximum rows returned by the CSV export action. */
    protected int $maxExportRows = 10000;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'show', 'stats', 'export']);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Paginated audit log with optional filters.
     *
     * Supported GET filters: token_id, user_id, status_code (HTTP), date_from, date_to.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Token Actions';

        $db   = \Pramnos\Framework\Factory::getDatabase();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $qb = $db->queryBuilder()
            ->table('#PREFIX#tokenactions ta')
            ->join('#PREFIX#usertokens ut', 'ta.tokenid', '=', 'ut.tokenid')
            ->join('#PREFIX#users u',      'ut.userid',  '=', 'u.userid')
            ->select([
                'ta.id', 'u.username', 'ta.tokenid', 'ta.urlid',
                'ta.method', 'ta.return_status', 'ta.execution_time_ms',
                'ta.servertime',
            ]);

        $this->applyDisplayFilters($qb);

        $view         = $this->getView('tokenactions');
        $view->actions = $qb->orderBy('ta.servertime', 'desc')->forPage($page, 50)->getAll();
        $view->total   = (clone $qb)->count();
        $view->page    = $page;

        return $view->display();
    }

    /**
     * Detailed view of a single token action entry.
     */
    public function show(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $actionId = (int) ($id ?? 0);
        if ($actionId <= 0) {
            $this->redirect(sURL . 'tokenactions?error=invalid_id');
            return null;
        }

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $result = $db->queryBuilder()
            ->table('#PREFIX#tokenactions ta')
            ->join('#PREFIX#usertokens ut', 'ta.tokenid', '=', 'ut.tokenid')
            ->join('#PREFIX#users u',      'ut.userid',  '=', 'u.userid')
            ->select([
                'ta.*', 'u.username', 'u.email',
            ])
            ->where('ta.id', $actionId)
            ->first();

        if (!$result || $result->numRows === 0) {
            $this->redirect(sURL . 'tokenactions?error=not_found');
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Token Action #' . $actionId;

        $view         = $this->getView('tokenactions');
        $view->action = $result->fields;

        return $view->display('show');
    }

    /**
     * JSON endpoint: performance statistics for the last 24h.
     * Delegates to ApiPerformanceService for consistency with the Dashboard.
     */
    public function stats(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $service = new \Pramnos\Application\Statistics\ApiPerformanceService();
        $window  = (int) ($_GET['window'] ?? \Pramnos\Application\Statistics\ApiPerformanceService::WINDOW_24H);

        header('Content-Type: application/json');
        echo json_encode([
            'summary'        => $service->getSummary($window),
            'top_slow'       => $service->getTopSlowEndpoints(10, $window),
            'top_called'     => $service->getTopCalledEndpoints(10, $window),
        ]);
    }

    /**
     * CSV export of token actions matching the current filters.
     * Streams the output directly without buffering to handle large exports.
     */
    public function export(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $db = \Pramnos\Framework\Factory::getDatabase();

        $qb = $db->queryBuilder()
            ->table('#PREFIX#tokenactions ta')
            ->join('#PREFIX#usertokens ut', 'ta.tokenid', '=', 'ut.tokenid')
            ->join('#PREFIX#users u',       'ut.userid',  '=', 'u.userid')
            ->select([
                'ta.id', 'u.username', 'ta.tokenid', 'ta.urlid',
                'ta.method', 'ta.return_status', 'ta.execution_time_ms',
                'ta.servertime',
            ]);

        $this->applyDisplayFilters($qb);

        $result = $qb->orderBy('ta.servertime', 'desc')->limit($this->maxExportRows)->get();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="token_actions_' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-cache, must-revalidate');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'username', 'tokenid', 'urlid', 'method', 'return_status', 'execution_time_ms', 'servertime']);

        if ($result) {
            while ($result->fetch()) {
                fputcsv($out, [
                    $result->fields['id'],
                    $result->fields['username'],
                    $result->fields['tokenid'],
                    $result->fields['urlid'],
                    $result->fields['method'],
                    $result->fields['return_status'],
                    $result->fields['execution_time_ms'],
                    $result->fields['servertime'],
                ]);
            }
        }

        fclose($out);
        exit;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Apply common GET filters to a QueryBuilder instance for display and export.
     *
     * @param \Pramnos\Database\QueryBuilder $qb
     */
    private function applyDisplayFilters(\Pramnos\Database\QueryBuilder $qb): void
    {
        $tokenId    = (int) ($_GET['token_id']    ?? 0);
        $userId     = (int) ($_GET['user_id']     ?? 0);
        $statusCode = (int) ($_GET['status_code'] ?? 0);
        $dateFrom   = (string) ($_GET['date_from'] ?? '');
        $dateTo     = (string) ($_GET['date_to']   ?? '');

        if ($tokenId > 0) {
            $qb->where('ta.tokenid', $tokenId);
        }
        if ($userId > 0) {
            $qb->where('ut.userid', $userId);
        }
        if ($statusCode > 0) {
            $qb->where('ta.return_status', $statusCode);
        }
        if ($dateFrom !== '') {
            $ts = strtotime($dateFrom);
            if ($ts !== false) {
                $qb->where('ta.servertime', '>=', $ts);
            }
        }
        if ($dateTo !== '') {
            $ts = strtotime($dateTo);
            if ($ts !== false) {
                $qb->where('ta.servertime', '<=', $ts);
            }
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
