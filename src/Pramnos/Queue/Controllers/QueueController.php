<?php

declare(strict_types=1);

namespace Pramnos\Queue\Controllers;

use Pramnos\Application\Controller;

/**
 * Admin controller for background job queue management.
 *
 * Operates on the `queueitems` table created by the `create_queueitems_table`
 * migration (queue feature). Queue items are never permanently deleted — the
 * `clear()` action soft-deletes by marking status='deleted'.
 *
 * Status values: 'pending', 'processing', 'completed', 'failed', 'deleted'.
 *
 * Actions:
 *   - display()    — DataTable of jobs with filters (status, type)
 *   - retry($id)   — re-schedule a single failed job (reset to pending, increment attempts)
 *   - retryall()   — bulk retry all failed jobs
 *   - delete($id)  — mark a job as deleted (status='deleted')
 *   - clear()      — bulk-delete jobs by status (POST: status filter required)
 *   - stats()      — JSON: counts by status, throughput, avg processing time
 *
 * All actions require authentication + usertype >= 80.
 *
 * Scaffold wrappers at `src/Controllers/Queue.php` (queue feature only).
 *
 * @package     PramnosFramework
 * @subpackage  Queue\Controllers
 */
class QueueController extends Controller
{
    /** Minimum usertype to access any queue action. */
    protected int $requiredUserType = 80;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'retry', 'retryall', 'delete', 'clear', 'stats']);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Paginated DataTable of queue items.
     * Supports optional GET filters: status, type.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Queue';

        $db   = \Pramnos\Framework\Factory::getDatabase();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $qb = $db->queryBuilder()
            ->table('queueitems')
            ->select(['taskid', 'type', 'status', 'priority', 'attempts', 'maxattempts', 'createdat', 'startedat', 'completedat', 'error']);

        $filterStatus = trim((string) ($_GET['status'] ?? ''));
        $filterType   = trim((string) ($_GET['type']   ?? ''));

        if ($filterStatus !== '') {
            $qb->where('status', $filterStatus);
        }
        if ($filterType !== '') {
            $qb->where('type', $filterType);
        }

        $view       = $this->getView('queue');
        $view->jobs = $qb->orderBy('createdat', 'desc')->forPage($page, 50)->get();
        $view->total = (clone $qb)->count();
        $view->page  = $page;

        return $view->display();
    }

    /**
     * Re-schedule a single failed job.
     * Resets status to 'pending', clears the error field, and preserves the
     * attempts count so maxattempts enforcement still applies.
     */
    public function retry(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $taskId = (int) ($id ?? 0);
        if ($taskId <= 0) {
            $this->redirect(sURL . 'queue?error=invalid_id');
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('queueitems')
            ->where('taskid', $taskId)
            ->where('status', 'failed')
            ->update([
                'status'    => 'pending',
                'error'     => null,
                'lockedby'  => null,
                'lockexpires' => null,
            ]);

        $this->redirect(sURL . 'queue?message=retried');
    }

    /**
     * Bulk-retry all failed jobs.
     * Resets all failed jobs to 'pending' status.
     */
    public function retryall(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('queueitems')
            ->where('status', 'failed')
            ->update([
                'status'    => 'pending',
                'error'     => null,
                'lockedby'  => null,
                'lockexpires' => null,
            ]);

        $this->redirect(sURL . 'queue?message=retried_all');
    }

    /**
     * Soft-delete a single queue item (status='deleted').
     * Preserves the row for audit purposes; workers skip 'deleted' items.
     */
    public function delete(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $taskId = (int) ($id ?? 0);
        if ($taskId <= 0) {
            $this->redirect(sURL . 'queue?error=invalid_id');
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('queueitems')
            ->where('taskid', $taskId)
            ->update(['status' => 'deleted']);

        $this->redirect(sURL . 'queue?message=deleted');
    }

    /**
     * Bulk soft-delete jobs by status.
     * POST field `status` is required. Allowed targets: 'failed', 'completed', 'deleted'.
     * 'pending' and 'processing' jobs cannot be bulk-cleared to avoid data loss.
     */
    public function clear(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $targetStatus = trim((string) ($_POST['status'] ?? ''));
        $allowed      = ['failed', 'completed', 'deleted'];

        if (!in_array($targetStatus, $allowed, true)) {
            $this->redirect(sURL . 'queue?error=invalid_status');
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('queueitems')
            ->where('status', $targetStatus)
            ->update(['status' => 'deleted']);

        $this->redirect(sURL . 'queue?message=cleared');
    }

    /**
     * JSON endpoint: queue statistics — counts by status and processing time.
     *
     * Response shape:
     *   {
     *     "counts": {"pending": int, "processing": int, "completed": int, "failed": int},
     *     "avg_processing_ms": float|null
     *   }
     */
    public function stats(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $counts = [];

        foreach (['pending', 'processing', 'completed', 'failed'] as $status) {
            $counts[$status] = $db->queryBuilder()
                ->table('queueitems')
                ->where('status', $status)
                ->count();
        }

        // Average processing time for completed jobs where both timestamps are present
        $avgResult = null;
        try {
            if ($db->type === 'postgresql') {
                $r = $db->query(
                    "SELECT AVG(EXTRACT(EPOCH FROM (completedat - startedat)) * 1000) AS avg_ms
                     FROM queueitems
                     WHERE status = 'completed' AND startedat IS NOT NULL AND completedat IS NOT NULL"
                );
            } else {
                $r = $db->query(
                    "SELECT AVG(TIMESTAMPDIFF(SECOND, startedat, completedat) * 1000) AS avg_ms
                     FROM queueitems
                     WHERE status = 'completed' AND startedat IS NOT NULL AND completedat IS NOT NULL"
                );
            }
            if ($r && $r->numRows > 0 && $r->fields['avg_ms'] !== null) {
                $avgResult = round((float) $r->fields['avg_ms'], 2);
            }
        } catch (\Exception $e) {
            // Table not yet created or insufficient schema
        }

        header('Content-Type: application/json');
        echo json_encode([
            'counts'             => $counts,
            'avg_processing_ms'  => $avgResult,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Redirects to sURL if the current user's usertype is below $minType.
     * Returns true if the redirect was issued (caller should return early).
     */
    private function requireMinUserType(int $minType): bool
    {
        $user = \Pramnos\User\User::getCurrentUser();

        if ($user === null || (int) $user->usertype < $minType) {
            $this->redirect(sURL);
            return true;
        }

        return false;
    }
}
