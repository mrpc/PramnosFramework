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
 */
class OrganizationsController extends Controller
{
    /** Minimum usertype to access any organizations action. */
    protected int $requiredUserType = 80;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction([
            'display', 'data', 'edit', 'save', 'delete',
            'members', 'addmember', 'removemember',
        ]);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /**
     * DataTable list of organizations — shell only; rows loaded via AJAX from data().
     */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Organizations';

        $dt = new \Pramnos\Html\Datatable('dt-organizations');
        $dt->source    = sURL . 'organizations/data';
        $dt->bootstrap = false;
        $dt->addColumn('ID',          true,  true,  true,  'num')
           ->addColumn('Name',        true,  true,  true)
           ->addColumn('Type',        true,  true,  true)
           ->addColumn('Active',      true,  true,  false, 'html')
           ->addColumn('Created',     true,  true,  false)
           ->addColumn('Actions',     true,  false, false, 'html');

        $view           = $this->getView('organizations');
        $view->datatable = $dt;
        return $view->display();
    }

    /**
     * AJAX data endpoint for the organizations DataTable.
     */
    public function data(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument('json');

        $fields = ['organization_id', 'name', 'org_type', 'is_active', 'created_at'];
        $result = \Pramnos\Html\Datatable\Datasource::getList(
            'organizations',
            $fields,
            false
        );

        $dataKey = array_key_exists('data', $result) ? 'data' : 'aaData';
        foreach ($result[$dataKey] as &$row) {
            $id     = (int) $row[0];
            $row[3] = $row[3] ? '<span style="color:green">Yes</span>' : '<span style="color:#888">No</span>';
            $row[]  = '<a href="' . sURL . 'organizations/edit/'   . $id . '">Edit</a> '
                    . '<a href="' . sURL . 'organizations/members/' . $id . '">Members</a> '
                    . '<a href="' . sURL . 'organizations/delete/'  . $id . '" data-confirm="Delete this organization?">Delete</a>';
            unset($row['DT_RowId']);
        }
        unset($row);

        return \Pramnos\Http\Response::json($result);
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

        $id  = (int) \Pramnos\Http\Request::staticGetOption();
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
                $this->addError('That record no longer exists.');
                $this->redirect(sURL . 'organizations');
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

        // CSRF validation.
        $session = \Pramnos\Http\Session::getInstance();
        if (!$session->verifyCsrfToken((string) ($_POST['_csrf_token'] ?? ''))) {
            $this->addError('That form had expired. Please try again.');
            $this->redirect(sURL . 'organizations/edit/' . $id);
            return;
        }

        if ($name === '') {
            $this->addError('A name is required.');
            $this->redirect(sURL . 'organizations/edit/' . $id);
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

        $this->addMessage('Saved.');
        $this->redirect(sURL . 'organizations');
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

        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(sURL . 'organizations');
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()
            ->queryBuilder()
            ->table('organizations')
            ->where('organization_id', $id)
            ->update(['is_active' => 0]);

        $this->addMessage('Deleted.');
        $this->redirect(sURL . 'organizations');
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

        $orgId = (int) \Pramnos\Http\Request::staticGetOption();
        if ($orgId <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(sURL . 'organizations');
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
            $this->addError('That record no longer exists.');
            $this->redirect(sURL . 'organizations');
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Members — ' . htmlspecialchars((string) ($org->fields['name'] ?? ''), ENT_QUOTES);

        // Current members only. `removemember()` keeps the row and sets
        // `is_active = 0` for the audit trail, and this listed every row — so a
        // removed member stayed on the screen, indistinguishable from one who
        // still has access. The Remove button looked broken, and the page
        // answered "who is in this organization" with a list of everyone who ever
        // was.
        $members = $db->queryBuilder()
            ->table($orgTable . ' uo')
            ->join('#PREFIX#users u', 'uo.userid', '=', 'u.userid')
            ->select(['u.userid', 'u.username', 'u.email', 'uo.granted_at', 'uo.is_active'])
            ->where('uo.' . $orgCol, $orgId)
            ->where('uo.is_active', 1)
            ->orderBy('u.username')
            ->getAll();

        $view           = $this->getView('organizations');
        $view->org      = $org->fields;
        $view->members  = $members;

        return $view->display('members');
    }

    /**
     * POST handler: assign a user to an organization.
     *
     * The organization comes from the URL (`organizations/addmember/{id}`, which
     * is what the members screen posts to), or from an `org_id` POST field. The
     * user comes from the `userid` POST field.
     */
    public function addmember(mixed $orgId = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $orgId  = $this->idFromRoute($orgId, (int) ($_POST['org_id'] ?? 0));
        $userId = (int) ($_POST['userid'] ?? 0);

        if ($orgId <= 0 || $userId <= 0) {
            $this->addError('No valid entries were selected.');
            $this->redirect(sURL . 'organizations/' . $orgId . '/members');
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

        $this->addMessage('Added.');
        $this->redirect(sURL . 'organizations/' . $orgId . '/members');
    }

    /**
     * Remove a user's membership from an organization.
     * Sets is_active=0 rather than deleting the row to preserve the audit trail.
     *
     * `organizations/removemember/{orgId}?userid={userId}` — the organization in
     * the URL segment, the user as a query parameter. Not two segments: the
     * framework's URL parser turns `action/a/b` into `$_GET['a'] = 'b'` rather
     * than into two options, so the second id never arrived as an argument.
     */
    public function removemember(mixed $orgId = null, mixed $userId = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $userIdFromRoute = $userId;
        $orgId = $this->idFromRoute($orgId, 0);
        // The user id comes from the request, never from the URL option — that
        // option *is* the organization segment, so resolving both the same way
        // made the user id equal the organization id. The update then matched no
        // row, and the screen still reported "Removed."
        $userId = (int) ($_GET['userid'] ?? $_POST['userid'] ?? 0);
        if ($userId <= 0 && is_scalar($userIdFromRoute) && (int) $userIdFromRoute > 0) {
            $userId = (int) $userIdFromRoute;
        }

        if ($orgId <= 0 || $userId <= 0) {
            $this->addError('No valid entries were selected.');
            $this->redirect(sURL . 'organizations/' . $orgId . '/members');
            return;
        }

        $db       = \Pramnos\Framework\Factory::getDatabase();
        $orgTable = $this->resolveOrgMembershipTable();
        $orgCol   = $this->resolveOrgColumn();

        // Say what happened, rather than "Removed." regardless. A stale link — a
        // second click, a back button, a bookmark from before someone else
        // removed them — matched no row and still reported success, which is the
        // report an operator would act on.
        $membership = $db->queryBuilder()
            ->table($orgTable)
            ->where('userid', $userId)
            ->where($orgCol, $orgId)
            ->where('is_active', 1)
            ->first();

        if (!$membership || $membership->numRows === 0) {
            $this->addError('That person is not a member of this organization.');
            $this->redirect(sURL . 'organizations/' . $orgId . '/members');
            return;
        }

        $db->queryBuilder()
            ->table($orgTable)
            ->where('userid', $userId)
            ->where($orgCol, $orgId)
            ->update(['is_active' => 0]);

        $this->addMessage('Removed.');
        $this->redirect(sURL . 'organizations/' . $orgId . '/members');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * An id from a route argument, the URL option, or a fallback.
     *
     * The classic dispatcher hands every action the request's arguments **array**,
     * not the individual segments, so an action declaring `mixed $id = null`
     * receives something `(int)` turns into 0 or 1 — and `addmember` and
     * `removemember` both trusted it. The result was that adding a member to an
     * organization was impossible: the form posted to
     * `organizations/addmember/{id}`, the id never arrived, and the screen
     * answered "No valid entries were selected" and redirected to
     * `organizations/0/members`. Removing one failed the same way. So the two
     * actions that make the members screen a screen rather than a list could
     * neither of them run.
     *
     * `members()` had it right all along, reading `staticGetOption()`. This is
     * the same read, in one place.
     */
    private function idFromRoute(mixed $argument, int $fallback): int
    {
        if (is_scalar($argument) && (int) $argument > 0) {
            return (int) $argument;
        }

        $fromUrl = \Pramnos\Http\Request::staticGetOption();
        if (is_scalar($fromUrl) && (int) $fromUrl > 0) {
            return (int) $fromUrl;
        }

        return $fallback;
    }

    protected function terminate(): void
    {
        exit;
    }

    /**
     * Redirects to sURL if the current user's usertype is below $minType.
     * Returns true if the redirect was issued (caller should return early).
     */
    protected function requireMinUserType(int $minType): bool
    {
        $user = \Pramnos\User\User::getCurrentUser();

        if (!$user || (int) $user->usertype < $minType) {
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
