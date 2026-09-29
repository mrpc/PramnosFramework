<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Auth\WebhookService;

/**
 * Admin controller for RBAC permissions management.
 *
 * Operates on the `authserver.permissions` table (fine-grained RBAC grants).
 * Requires both the `authserver` feature and the RBAC schema to be present
 * (i.e., the `create_authserver_permissions_table` migration must have run).
 *
 * Subject types: 'user', 'role' — the two the resolver reads; anything else is refused.
 * Object types: any resource identifier (e.g. 'reports', 'users', 'settings').
 * Actions: any verb (e.g. 'view', 'edit', 'delete', '*').
 * Grant types: 'allow' | 'deny' — the higher priority wins, and a deny wins a tie.
 *
 * Actions:
 *   - display()         — DataTable of all permission records
 *   - edit($id)         — create/edit form for a permission entry
 *   - save()            — POST handler for create/update
 *   - delete($id)       — remove a permission entry
 *   - assign($userId)   — POST: quickly assign a named permission to a user
 *
 * All actions require authentication + usertype >= 90 (admin).
 *
 * Scaffold wrappers at `src/Controllers/Permissions.php` (authserver feature).
 *
 */
class PermissionsController extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.permissions';

    /** Minimum usertype to access any permissions action. */
    protected int $requiredUserType = 90;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'edit', 'save', 'delete', 'assign']);
        // POST with the session's token, or refused before the action runs: see Controller::exec().
        $this->addWriteAction(['save', 'delete', 'assign']);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Paginated DataTable of permission records.
     * Supports optional GET filters: subject_type, subject_id, object_type, action.
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Permissions';

        $db   = \Pramnos\Framework\Factory::getDatabase();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $qb = $db->queryBuilder()
            ->table('authserver.permissions')
            ->select([
                'permissionid', 'subject_type', 'subject_id', 'object_type',
                'object_id', 'action', 'grant_type', 'priority', 'granted_at',
            ]);

        $this->applyDisplayFilters($qb);

        $view              = $this->getView('permissions');
        $view->permissions = $qb->orderBy('subject_type')->orderBy('subject_id')->forPage($page, 50)->getAll();
        $view->total       = (clone $qb)->count();
        $view->page        = $page;

        return $view->display();
    }

    /**
     * Create/edit form for a permission record.
     */
    public function edit(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $id  = (int) \Pramnos\Http\Request::staticGetOption();
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = $id > 0 ? 'Edit Permission' : 'New Permission';

        $view            = $this->getView('permissions');
        $view->permission = null;

        if ($id > 0) {
            $db     = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->queryBuilder()
                ->table('authserver.permissions')
                ->where('permissionid', $id)
                ->first();

            if (!$result || $result->numRows === 0) {
                $this->addError('That record no longer exists.');
                $this->redirect(adminUrl('permissions'));
                return null;
            }

            $view->permission = $result->fields;
        }

        return $view->display('edit');
    }

    /**
     * POST handler: create or update a permission record.
     */
    public function save(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id          = (int)    ($_POST['permissionid'] ?? 0);
        $subjectType = trim((string) ($_POST['subject_type'] ?? ''));
        $subjectId   = (int)    ($_POST['subject_id']   ?? 0);
        $objectType  = trim((string) ($_POST['object_type']  ?? ''));
        $objectId    = trim((string) ($_POST['object_id']    ?? ''));
        $action      = trim((string) ($_POST['action']       ?? ''));
        $grantType   = in_array($_POST['grant_type'] ?? '', ['allow', 'deny'], true)
            ? (string) $_POST['grant_type'] : 'allow';
        $priority    = max(0, (int) ($_POST['priority'] ?? 100));
        $appId       = (int) ($_POST['app_id'] ?? 0);
        $expiresAt   = trim((string) ($_POST['expires_at'] ?? ''));
        $conditions  = trim((string) ($_POST['conditions'] ?? ''));

        if ($subjectType === '' || $objectType === '' || $action === '') {
            $this->addError('Please fill in the required fields.');
            $this->redirect(adminUrl('permissions/edit/') . $id);
            return;
        }
        // The resolver reads users and roles; a grant to anything else would be stored and never apply.
        if (!in_array($subjectType, ['user', 'role'], true)) {
            $this->addError('A permission is granted to a user or a role.');
            $this->redirect(adminUrl('permissions/edit/') . $id);
            return;
        }
        if ($conditions !== '' && !is_array(json_decode($conditions, true))) {
            $this->addError('Conditions must be a JSON object, such as {"location_id": [1, 2]}.');
            $this->redirect(adminUrl('permissions/edit/') . $id);
            return;
        }
        $expires = $expiresAt !== '' ? strtotime($expiresAt) : null;
        if ($expires === false) {
            $this->addError('The expiry is not a date.');
            $this->redirect(adminUrl('permissions/edit/') . $id);
            return;
        }

        $db      = \Pramnos\Framework\Factory::getDatabase();
        $current = \Pramnos\User\User::getCurrentUser();
        $grantedBy = $current ? (int) $current->userid : null;

        $data = [
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'object_type'  => $objectType,
            'object_id'    => $objectId !== '' ? $objectId : null,
            'action'       => $action,
            'grant_type'   => $grantType,
            'priority'     => $priority,
            'granted_by'   => $grantedBy,
        ];
        // Each only when the form sends it, so an application's older copy of the form does not
        // clear an expiry, an audience or a condition it cannot see.
        if (array_key_exists('expires_at', $_POST)) {
            $data['expires_at'] = $expires !== null ? date('Y-m-d H:i:s', $expires) : null;
        }
        // Audience and ABAC arrived with a later migration; an installation without it keeps working.
        $hasAudience = $db->schema()->hasColumn('authserver.permissions', 'app_id');
        if ($hasAudience && array_key_exists('app_id', $_POST)) {
            $data['app_id'] = $appId > 0 ? $appId : null;
        }
        if ($hasAudience && array_key_exists('conditions', $_POST)) {
            $data['conditions'] = $conditions !== '' ? $conditions : null;
        }

        if ($id > 0) {
            $db->queryBuilder()
                ->table('authserver.permissions')
                ->where('permissionid', $id)
                ->update($data);
        } else {
            $db->queryBuilder()
                ->table('authserver.permissions')
                ->insert($data);
        }

        // Instant invalidation (feature 7): tell subscribers this subject's
        // permissions changed so they drop their local cache and re-fetch.
        $this->emitPermissionsChanged($subjectType, $subjectId, [
            'object_type' => $objectType,
            'action'      => $action,
            'operation'   => $id > 0 ? 'update' : 'create',
        ]);

        $this->addMessage('Saved.');
        $this->redirect(adminUrl('permissions'));
    }

    /**
     * Delete a permission record by permissionid.
     */
    public function delete(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(adminUrl('permissions'));
            return;
        }

        $db = \Pramnos\Framework\Factory::getDatabase();

        // Read the row first so we know whose cache to invalidate after deletion.
        $row = $db->queryBuilder()
            ->table('authserver.permissions')
            ->where('permissionid', $id)
            ->first();

        $db->queryBuilder()
            ->table('authserver.permissions')
            ->where('permissionid', $id)
            ->delete();

        if ($row && $row->numRows > 0) {
            $this->emitPermissionsChanged(
                (string) ($row->fields['subject_type'] ?? ''),
                (int) ($row->fields['subject_id'] ?? 0),
                ['operation' => 'delete']
            );
        }

        $this->addMessage('Deleted.');
        $this->redirect(adminUrl('permissions'));
    }

    /**
     * Queue a `permissions_changed` webhook so subscribed applications drop the
     * affected user's cached permissions and re-fetch (feature 7 invalidation).
     *
     * For a user-subject the event targets that user; for a role it names no user
     * (NULL — `user_id` is a foreign key, so 0 is not a value it can hold) and the
     * payload carries the subject, letting a subscriber invalidate every holder.
     * The same event every other writer sends through {@see WebhookService::permissionsChanged()};
     * this one keeps its own seam so the screen's tests can spy on it. Delivery failures are swallowed:
     * a webhook problem must never break permission administration.
     *
     * @param array<string,mixed> $context extra payload fields (object_type, action, operation…)
     */
    protected function emitPermissionsChanged(string $subjectType, int $subjectId, array $context): void
    {
        $userId  = $subjectType === 'user' ? $subjectId : null;
        $payload = ['subject_type' => $subjectType, 'subject_id' => $subjectId] + $context;

        try {
            $this->webhookService()->queueEvent('permissions_changed', $userId, $payload);
        } catch (\Throwable) {
            // Non-fatal: invalidation is best-effort.
        }
    }

    /** The webhook service (seam so tests can inject a spy). */
    protected function webhookService(): WebhookService
    {
        return new WebhookService(\Pramnos\Framework\Factory::getDatabase());
    }

    /**
     * Assign a named permission to a user.
     * POST fields: userid, object_type, object_id, action, grant_type, priority.
     */
    public function assign(mixed $userId = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $userId    = (int) ($userId ?? $_POST['userid'] ?? 0);
        $objectType = trim((string) ($_POST['object_type'] ?? ''));
        $action     = trim((string) ($_POST['action']      ?? ''));

        if ($userId <= 0 || $objectType === '' || $action === '') {
            $this->addError('Please fill in the required fields.');
            $this->redirect(adminUrl('permissions'));
            return;
        }

        $db      = \Pramnos\Framework\Factory::getDatabase();
        $current = \Pramnos\User\User::getCurrentUser();

        $db->queryBuilder()
            ->table('authserver.permissions')
            ->insert([
                'subject_type' => 'user',
                'subject_id'   => $userId,
                'object_type'  => $objectType,
                'object_id'    => trim((string) ($_POST['object_id'] ?? '')) ?: null,
                'action'       => $action,
                'grant_type'   => 'allow',
                'priority'     => max(0, (int) ($_POST['priority'] ?? 100)),
                'granted_by'   => $current ? (int) $current->userid : null,
            ]);

        $this->emitPermissionsChanged('user', $userId, [
            'object_type' => $objectType,
            'action'      => $action,
            'operation'   => 'create',
        ]);

        $this->addMessage('Assigned.');
        $this->redirect(adminUrl('permissions'));
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Apply common GET filters to a QueryBuilder instance for display.
     *
     * @param \Pramnos\Database\QueryBuilder $qb
     */
    private function applyDisplayFilters(\Pramnos\Database\QueryBuilder $qb): void
    {
        $subjectType = trim((string) ($_GET['subject_type'] ?? ''));
        $subjectId   = (int) ($_GET['subject_id'] ?? 0);
        $objectType  = trim((string) ($_GET['object_type']  ?? ''));
        $action      = trim((string) ($_GET['action']       ?? ''));

        if ($subjectType !== '') {
            $qb->where('subject_type', $subjectType);
        }
        if ($subjectId > 0) {
            $qb->where('subject_id', $subjectId);
        }
        if ($objectType !== '') {
            $qb->where('object_type', $objectType);
        }
        if ($action !== '') {
            $qb->where('action', $action);
        }
    }

}
