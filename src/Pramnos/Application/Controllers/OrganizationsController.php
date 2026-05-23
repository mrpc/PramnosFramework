<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Application\Settings;

/**
 * Admin controller for managing organizations (generic organisation registry).
 *
 * Operates on the `public.organizations` table created by the
 * `create_organizations_table` migration and the membership table
 * `authserver.user_organizations` (name may be overridden via Settings key
 * `authserver_organization_table`).
 *
 * Actions:
 *   - display($page)              — paginated DataTable list of organizations
 *   - edit($id)                   — create/edit form
 *   - save()                      — POST handler for create/update
 *   - delete($id)                 — soft-delete (sets is_active=false) or hard delete
 *   - members($id)                — list of users who belong to an organization
 *   - addmember($orgId)           — POST: assign a user to an organization
 *   - removemember($orgId, $userId) — remove a user from an organization
 *
 * All actions require authentication + usertype >= 80.
 *
 * Scaffold wrappers at `src/Controllers/Organizations.php`.
 *
 * @package     PramnosFramework
 * @subpackage  Application\Controllers
 */
class OrganizationsController extends Controller
{
    /** Minimum usertype to access any organizations action. */
    protected int $requiredUserType = 80;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction([
            'display', 'edit', 'save', 'delete',
            'members', 'addmember', 'removemember',
        ]);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * Paginated list of organizations (active + inactive).
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Organizations';

        $db   = \Pramnos\Framework\Factory::getDatabase();
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $view  = $this->getView('organizations');
        $view->organizations = $db->queryBuilder()
            ->table('organizations')
            ->select(['organization_id', 'name', 'description', 'org_type', 'is_active', 'created_at'])
            ->orderBy('name')
            ->forPage($page, 50)
            ->get();
        $view->total = $db->queryBuilder()->table('organizations')->count();
        $view->page  = $page;

        return $view->display();
    }

    /**
     * Create/edit form for an organization.
     * Passing no $id (or id=0) opens the create form.
     */
    public function edit(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $id  = (int) ($id ?? 0);
        $doc = \Pramnos\Framework\Factory::getDocument();
        $doc->title = $id > 0 ? 'Edit Organization' : 'New Organization';

        $view = $this->getView('organizations');
        $view->organization = null;

        if ($id > 0) {
            $db     = \Pramnos\Framework\Factory::getDatabase();
            $result = $db->queryBuilder()
                ->table('organizations')
                ->where('organization_id', $id)
                ->first();

            if (!$result || $result->numRows === 0) {
                $this->redirect(sURL . 'organizations?error=not_found');
                return null;
            }

            $view->organization = $result->fields;
        }

        return $view->display('edit');
    }

    /**
     * POST handler: create a new organization or update an existing one.
     * Redirects to display on success.
     */
    public function save(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id          = (int)    ($_POST['organization_id'] ?? 0);
        $name        = trim((string) ($_POST['name']        ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $orgType     = trim((string) ($_POST['org_type']    ?? ''));
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            $this->redirect(sURL . 'organizations/edit/' . $id . '?error=name_required');
            return;
        }

        $db = \Pramnos\Framework\Factory::getDatabase();

        if ($id > 0) {
            $db->queryBuilder()
                ->table('organizations')
                ->where('organization_id', $id)
                ->update([
                    'name'        => $name,
                    'description' => $description !== '' ? $description : null,
                    'org_type'    => $orgType !== '' ? $orgType : null,
                    'is_active'   => $isActive,
                ]);
        } else {
            $db->queryBuilder()
                ->table('organizations')
                ->insert([
                    'name'        => $name,
                    'description' => $description !== '' ? $description : null,
                    'org_type'    => $orgType !== '' ? $orgType : null,
                    'is_active'   => 1,
                ]);
        }

        $this->redirect(sURL . 'organizations?message=saved');
    }

    /**
     * Deactivate an organization (soft delete: sets is_active=0).
     * Hard deletion is intentionally not supported from the UI to preserve FK references.
     */
    public function delete(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id = (int) ($id ?? 0);
        if ($id <= 0) {
            $this->redirect(sURL . 'organizations?error=invalid_id');
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('organizations')
            ->where('organization_id', $id)
            ->update(['is_active' => 0]);

        $this->redirect(sURL . 'organizations?message=deleted');
    }

    /**
     * List users who are members of an organization.
     * Joins `authserver.user_organizations` (or the configurable table name) with `users`.
     */
    public function members(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $orgId = (int) ($id ?? 0);
        if ($orgId <= 0) {
            $this->redirect(sURL . 'organizations?error=invalid_id');
            return null;
        }

        $db     = \Pramnos\Framework\Factory::getDatabase();
        $orgTable = $this->resolveOrgMembershipTable();
        $orgCol   = $this->resolveOrgColumn();

        $org = $db->queryBuilder()
            ->table('organizations')
            ->where('organization_id', $orgId)
            ->first();

        if (!$org || $org->numRows === 0) {
            $this->redirect(sURL . 'organizations?error=not_found');
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Members — ' . htmlspecialchars((string) ($org->fields['name'] ?? ''), ENT_QUOTES);

        $members = $db->queryBuilder()
            ->table($orgTable . ' uo')
            ->join('#PREFIX#users u', 'uo.userid', '=', 'u.userid')
            ->select(['u.userid', 'u.username', 'u.email', 'uo.granted_at', 'uo.is_active'])
            ->where('uo.' . $orgCol, $orgId)
            ->orderBy('u.username')
            ->get();

        $view           = $this->getView('organizations');
        $view->org      = $org->fields;
        $view->members  = $members;

        return $view->display('members');
    }

    /**
     * POST handler: assign a user to an organization.
     * Expects POST fields: userid, org_id (or orgId from route).
     */
    public function addmember(mixed $orgId = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $orgId  = (int) ($orgId  ?? $_POST['org_id'] ?? 0);
        $userId = (int) ($_POST['userid'] ?? 0);

        if ($orgId <= 0 || $userId <= 0) {
            $this->redirect(sURL . 'organizations/' . $orgId . '/members?error=invalid_ids');
            return;
        }

        $db       = \Pramnos\Framework\Factory::getDatabase();
        $orgTable = $this->resolveOrgMembershipTable();
        $orgCol   = $this->resolveOrgColumn();
        $current  = \Pramnos\User\User::getCurrentUser();
        $grantedBy = $current ? (int) $current->userid : null;

        $db->queryBuilder()
            ->table($orgTable)
            ->upsert(
                [
                    'userid'     => $userId,
                    $orgCol      => $orgId,
                    'granted_by' => $grantedBy,
                    'is_active'  => 1,
                ],
                ['userid', $orgCol],
                ['granted_by', 'is_active']
            );

        $this->redirect(sURL . 'organizations/' . $orgId . '/members?message=added');
    }

    /**
     * Remove a user's membership from an organization.
     * Sets is_active=0 rather than deleting the row to preserve the audit trail.
     */
    public function removemember(mixed $orgId = null, mixed $userId = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $orgId  = (int) ($orgId  ?? 0);
        $userId = (int) ($userId ?? 0);

        if ($orgId <= 0 || $userId <= 0) {
            $this->redirect(sURL . 'organizations/' . $orgId . '/members?error=invalid_ids');
            return;
        }

        $db       = \Pramnos\Framework\Factory::getDatabase();
        $orgTable = $this->resolveOrgMembershipTable();
        $orgCol   = $this->resolveOrgColumn();

        $db->queryBuilder()
            ->table($orgTable)
            ->where('userid', $userId)
            ->where($orgCol, $orgId)
            ->update(['is_active' => 0]);

        $this->redirect(sURL . 'organizations/' . $orgId . '/members?message=removed');
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

    /**
     * Returns the fully-qualified membership table name, respecting the
     * `authserver_organization_table` Settings override.
     * Defaults to `authserver.user_organizations`.
     */
    private function resolveOrgMembershipTable(): string
    {
        $setting = Settings::getSetting('authserver_organization_table', '');
        if ($setting !== '') {
            return 'authserver.' . $setting;
        }

        return 'authserver.user_organizations';
    }

    /**
     * Returns the organization FK column name, respecting the
     * `authserver_organization_column` Settings override.
     * Defaults to `organization_id`.
     */
    private function resolveOrgColumn(): string
    {
        return Settings::getSetting('authserver_organization_column', 'organization_id');
    }
}
