<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;

/**
 * Admin controller for RBAC permissions management.
 *
 * Operates on the `authserver.permissions` table (fine-grained RBAC grants).
 * Requires both the `authserver` feature and the RBAC schema to be present
 * (i.e., the `create_authserver_permissions_table` migration must have run).
 *
 * Subject types: 'user', 'role'.
 * Object types: any resource identifier (e.g. 'reports', 'users', 'settings').
 * Actions: any verb (e.g. 'view', 'edit', 'delete', '*').
 * Grant types: 'allow' | 'deny' — deny entries take absolute priority.
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
    /** Minimum usertype to access any permissions action. */
    protected int $requiredUserType = 90;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'edit', 'save', 'delete', 'assign']);
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

        $id  = (int) ($id ?? 0);
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
                $this->redirect(sURL . 'permissions?error=not_found');
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

        if ($subjectType === '' || $objectType === '' || $action === '') {
            $this->redirect(sURL . 'permissions/edit/' . $id . '?error=required_fields');
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

        $this->redirect(sURL . 'permissions?message=saved');
    }

    /**
     * Delete a permission record by permissionid.
     */
    public function delete(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id = (int) ($id ?? 0);
        if ($id <= 0) {
            $this->redirect(sURL . 'permissions?error=invalid_id');
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('authserver.permissions')
            ->where('permissionid', $id)
            ->delete();

        $this->redirect(sURL . 'permissions?message=deleted');
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
            $this->redirect(sURL . 'permissions?error=required_fields');
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

        $this->redirect(sURL . 'permissions?message=assigned');
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
