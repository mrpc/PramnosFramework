<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Auth\Role;
use Pramnos\Html\Icon;

/**
 * Admin controller for RBAC roles — `authserver.roles` and who holds them.
 *
 * ## Why this exists
 *
 * The `auth` feature's migrations create `authserver.roles` and
 * `authserver.user_roles`, and `PermissionsController` will happily grant a
 * permission to role 7. Nothing in the framework could create role 7, or give it to
 * anybody. The tables shipped with no way to reach them, so an installation that
 * enabled the feature got two thirds of an RBAC system and had to write the last
 * third itself — or, more often, discover the gap after designing around it.
 *
 * ## Actions
 *
 *   - `display()`                — DataTable of roles
 *   - `data()`                   — AJAX rows for it
 *   - `view($id)`                — one role, its permissions and its holders
 *   - `edit($id)`                — create/edit form
 *   - `save()`                   — POST handler
 *   - `delete($id)`              — deactivate the role
 *   - `members($id)`             — who holds it
 *   - `addmember($id)`           — POST: give it to a user
 *   - `removemember($id)`        — take it away
 *
 * ## The organisation rule lives in the model, not here
 *
 * A role belonging to an organisation may only be given to a member of it, and
 * {@see Role::assignTo()} is what refuses. This controller reports the refusal; it
 * does not re-implement the check, because an API caller reaching the model
 * directly has to get the same answer as somebody using this screen.
 *
 * Requires usertype >= 90, matching `PermissionsController`: deciding what a role
 * *is* is the same order of privilege as deciding what it may do.
 *
 * Scaffold wrappers at `src/Controllers/Roles.php` (authserver feature).
 */
class RolesController extends Controller
{
    /** Minimum usertype to access any roles action. */
    protected int $requiredUserType = 90;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction([
            'display', 'data', 'view', 'edit', 'save', 'delete',
            'members', 'addmember', 'removemember',
        ]);
        parent::__construct($application);
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    /** DataTable of roles — shell only; rows come from data(). */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Roles';

        $dt = new \Pramnos\Html\Datatable('dt-roles');
        $dt->source    = adminUrl('roles/data');
        $dt->bootstrap = false;
        $dt->footerTextSearch = true;

        $activeFilter = new \Pramnos\Html\Select('active_filter');
        $activeFilter->id = 'dt-roles-active';
        $activeFilter->addOptions(['' => 'Any', '1' => 'Active', '0' => 'Inactive']);

        $dt->addColumn('ID',           true, true,  true,  'num', '', true, 'left', true)
           ->addColumn('Name',         true, true,  true,  '',    '', true, 'left', true)
           ->addColumn('Organisation', true, true,  true,  '',    '', true, 'left', true)
           ->addColumn('Description',  true, true,  true,  '',    '', true, 'left', true)
           ->addColumn(
               'Active',
               true,
               true,
               true,
               'html',
               $activeFilter->render(),
               true,
               'left',
               'dt-roles-active',
               (string) \Pramnos\Http\Request::staticGet('active_filter', '', 'get')
           )
           ->addColumn('Actions', true, false, false, 'html');

        $view            = $this->getView('roles');
        $view->datatable = $dt;

        return $view->display();
    }

    /** AJAX rows for the roles DataTable. */
    public function data(): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }
        \Pramnos\Framework\Factory::getDocument('json');

        $fields = [
            'roleid', 'role_name', Role::organizationColumn(),
            'description', 'is_active',
        ];
        $result = \Pramnos\Html\Datatable\Datasource::getList(
            'authserver.roles',
            $fields,
            false
        );

        $names   = $this->organizationNames();
        $dataKey = array_key_exists('data', $result) ? 'data' : 'aaData';

        foreach ($result[$dataKey] as &$row) {
            $id      = (int) $row[0];
            $viewUrl = adminUrl('roles/view/') . $id;

            $row[0] = '<a href="' . $viewUrl . '">' . $id . '</a>';
            $row[1] = '<a href="' . $viewUrl . '">'
                . htmlspecialchars((string) $row[1], ENT_QUOTES, 'UTF-8') . '</a>';

            // A NULL organisation is the meaningful case, not a blank cell: it means
            // the role applies everywhere, and an empty column reads as missing data.
            $orgId  = $row[2] === null || $row[2] === '' ? null : (int) $row[2];
            $row[2] = $orgId === null
                ? '<em>System-wide</em>'
                : htmlspecialchars($names[$orgId] ?? ('#' . $orgId), ENT_QUOTES, 'UTF-8');

            $row[3] = htmlspecialchars((string) ($row[3] ?? ''), ENT_QUOTES, 'UTF-8');
            $row[4] = $row[4]
                ? '<span class="pf-state pf-state-on">Yes</span>'
                : '<span class="pf-state pf-state-off">No</span>';
            $row[]  = Icon::link($viewUrl, 'view', 'View this role')
                    . Icon::link(adminUrl('roles/edit/') . $id, 'edit', 'Edit this role')
                    . Icon::link(adminUrl('roles/members/') . $id, 'members', 'Holders')
                    . Icon::link(
                        adminUrl('roles/delete/') . $id,
                        'delete',
                        'Deactivate this role',
                        ['data-confirm' => 'Deactivate this role?', 'class' => 'pf-action-danger']
                    );
            unset($row['DT_RowId']);
        }
        unset($row);

        return \Pramnos\Http\Response::json($result);
    }

    /**
     * One role: what it is, what it grants, and who holds it.
     *
     * The permissions are worth showing here because a role is otherwise an opaque
     * name — "operator" tells nobody what an operator may do, and the answer lives
     * in a different screen filtered by a subject id most people do not know.
     */
    public function view(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $roleId = (int) \Pramnos\Http\Request::staticGetOption();
        if ($roleId <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(adminUrl('roles'));
            return null;
        }

        $role = $this->loadRole($roleId);
        if ($role === null) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Role: ' . $role->role_name;

        $view               = $this->getView('roles');
        $view->role         = $role;
        $view->organisation = $this->organizationLabel($role);
        $view->permissions  = $this->permissionsOfRole($roleId);
        $view->holders      = $this->holderRows($role);

        return $view->display('view');
    }

    /** Create/edit form. No id, or 0, opens the create form. */
    public function edit(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $id = (int) \Pramnos\Http\Request::staticGetOption();

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = $id > 0 ? 'Edit Role' : 'New Role';

        $view                = $this->getView('roles');
        $view->role          = null;
        $view->organizations = $this->organizationNames();

        if ($id > 0) {
            $role = $this->loadRole($id);
            if ($role === null) {
                return null;
            }
            $view->role = $role;
        }

        return $view->display('edit');
    }

    /** POST: create or update a role. */
    public function save(): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id          = (int) ($_POST['roleid'] ?? 0);
        $name        = trim((string) ($_POST['role_name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $orgId       = (int) ($_POST[Role::organizationColumn()] ?? 0);
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        $session = \Pramnos\Http\Session::getInstance();
        if (!$session->verifyCsrfToken((string) ($_POST['_csrf_token'] ?? ''))) {
            $this->addError('That form had expired. Please try again.');
            $this->redirect(adminUrl('roles/edit/') . $id);
            return;
        }

        if ($name === '') {
            $this->addError('A role name is required.');
            $this->redirect(adminUrl('roles/edit/') . $id);
            return;
        }

        $role = $id > 0 ? $this->loadRole($id) : new Role($this);
        if ($role === null) {
            return;
        }

        // Changing which organisation a role belongs to would silently invalidate
        // every assignment of it — the holders who are not members of the new
        // organisation stop counting, with nothing said. Refused rather than
        // performed quietly; create a role for the other organisation instead.
        if ($id > 0
            && (int) ($role->organization_id ?? 0) !== $orgId
            && $role->holders() !== []
        ) {
            $this->addError(
                'This role is held by ' . count($role->holders()) . ' user(s), so its '
                . 'organisation cannot be changed — the holders who are not members of '
                . 'the new organisation would silently lose it. Create a separate role '
                . 'for that organisation instead.'
            );
            $this->redirect(adminUrl('roles/edit/') . $id);
            return;
        }

        $role->role_name       = $name;
        $role->description     = $description !== '' ? $description : null;
        $role->organization_id = $orgId > 0 ? $orgId : null;
        $role->is_active       = $id > 0 ? $isActive : 1;
        $role->save();

        $this->addMessage('Saved.');
        $this->redirect(adminUrl('roles'));
    }

    /**
     * Deactivate a role.
     *
     * `is_active = 0`, not a delete: the assignments and the permission rows that
     * name it stay readable, and the resolver already ignores an inactive role.
     * Removing the row would leave `authserver.permissions` pointing at a subject
     * that no longer exists.
     */
    public function delete(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $id = (int) \Pramnos\Http\Request::staticGetOption();
        if ($id <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(adminUrl('roles'));
            return;
        }

        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('authserver.roles')
            ->where('roleid', $id)
            ->update(['is_active' => 0]);

        $this->addMessage('Deactivated.');
        $this->redirect(adminUrl('roles'));
    }

    /** Who holds this role. */
    public function members(mixed $id = null): mixed
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return null;
        }

        $roleId = (int) \Pramnos\Http\Request::staticGetOption();
        if ($roleId <= 0) {
            $this->addError('The id in that link is not valid.');
            $this->redirect(adminUrl('roles'));
            return null;
        }

        $role = $this->loadRole($roleId);
        if ($role === null) {
            return null;
        }

        $doc        = \Pramnos\Framework\Factory::getDocument();
        $doc->title = 'Holders of ' . $role->role_name;

        $view               = $this->getView('roles');
        $view->role         = $role;
        $view->organisation = $this->organizationLabel($role);
        $view->holders      = $this->holderRows($role);

        return $view->display('members');
    }

    /**
     * POST: give this role to a user.
     *
     * The refusal that matters — an organisation's role for somebody outside it —
     * comes from {@see Role::assignTo()} and is reported verbatim, because it tells
     * the administrator what to do next (add them to the organisation first).
     */
    public function addmember(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $roleId = $this->idFromRoute($id, (int) ($_POST['roleid'] ?? 0));
        $userId = (int) ($_POST['userid'] ?? 0);

        if ($roleId <= 0 || $userId <= 0) {
            $this->addError('No valid entries were selected.');
            $this->redirect(adminUrl('roles/') . $roleId . '/members');
            return;
        }

        $session = \Pramnos\Http\Session::getInstance();
        if (!$session->verifyCsrfToken((string) ($_POST['_csrf_token'] ?? ''))) {
            $this->addError('That form had expired. Please try again.');
            $this->redirect(adminUrl('roles/') . $roleId . '/members');
            return;
        }

        $role = $this->loadRole($roleId);
        if ($role === null) {
            return;
        }

        $current   = \Pramnos\User\User::getCurrentUser();
        $grantedBy = $current ? (int) $current->userid : null;

        if ($role->assignTo($userId, $grantedBy)) {
            $this->addMessage('Added.');
        } else {
            $this->addError($role->getLastError());
        }

        $this->redirect(adminUrl('roles/') . $roleId . '/members');
    }

    /** Take this role away from a user. */
    public function removemember(mixed $id = null): void
    {
        if ($this->requireMinUserType($this->requiredUserType)) {
            return;
        }

        $roleId = $this->idFromRoute($id, (int) ($_POST['roleid'] ?? 0));
        $userId = (int) \Pramnos\Http\Request::staticGet('userid', 0, 'get', 'int');

        if ($roleId <= 0 || $userId <= 0) {
            $this->addError('No valid entries were selected.');
            $this->redirect(adminUrl('roles/') . $roleId . '/members');
            return;
        }

        $role = $this->loadRole($roleId);
        if ($role === null) {
            return;
        }

        $role->revokeFrom($userId);
        $this->addMessage('Removed.');
        $this->redirect(adminUrl('roles/') . $roleId . '/members');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The role, or null with the operator already redirected and told why.
     */
    protected function loadRole(int $roleId): ?Role
    {
        $role = new Role($this, '', $roleId);

        if ((int) $role->roleid !== $roleId) {
            $this->addError('That role no longer exists.');
            $this->redirect(adminUrl('roles'));
            return null;
        }

        return $role;
    }

    /**
     * The id from the route segment, falling back to a posted one.
     *
     * @param mixed $routeId What the router passed, which may be a string.
     * @param int   $posted  The `roleid` field of the form.
     */
    protected function idFromRoute(mixed $routeId, int $posted): int
    {
        $fromRoute = (int) \Pramnos\Http\Request::staticGetOption();
        if ($fromRoute > 0) {
            return $fromRoute;
        }

        return is_numeric($routeId) ? (int) $routeId : $posted;
    }

    /**
     * Organisation names by id, for the list and the form's select.
     *
     * @return array<int, string>
     */
    protected function organizationNames(): array
    {
        $db = \Pramnos\Framework\Factory::getDatabase();

        if (!$db->schema()->hasTable('organizations')) {
            return [];
        }

        $result = $db->queryBuilder()
            ->table('organizations')
            ->select(['organization_id', 'name'])
            ->orderBy('name')
            ->get();

        $names = [];
        while ($result && $result->fetch()) {
            $names[(int) $result->fields['organization_id']] = (string) $result->fields['name'];
        }

        return $names;
    }

    /** "System-wide", or the organisation's name. */
    protected function organizationLabel(Role $role): string
    {
        $orgId = (int) ($role->organization_id ?? 0);
        if ($orgId <= 0) {
            return 'System-wide';
        }

        return $this->organizationNames()[$orgId] ?? ('#' . $orgId);
    }

    /**
     * The permission rows granted to this role.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function permissionsOfRole(int $roleId): array
    {
        $db = \Pramnos\Framework\Factory::getDatabase();

        if (!$db->schema()->hasTable('authserver.permissions')) {
            return [];
        }

        $result = $db->queryBuilder()
            ->table('authserver.permissions')
            ->select(['permissionid', 'object_type', 'object_id', 'action', 'grant_type', 'is_active'])
            ->where('subject_type', 'role')
            ->where('subject_id', $roleId)
            ->orderBy('object_type')
            ->get();

        $rows = [];
        while ($result && $result->fetch()) {
            $rows[] = $result->fields;
        }

        return $rows;
    }

    /**
     * The holders of a role, with the names an administrator recognises.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function holderRows(Role $role): array
    {
        $holders = $role->holders();
        if ($holders === []) {
            return [];
        }

        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('#PREFIX#users')
            ->select(['userid', 'username', 'email'])
            ->whereIn('userid', array_keys($holders))
            ->orderBy('username')
            ->get();

        $rows = [];
        while ($result && $result->fetch()) {
            $userId = (int) $result->fields['userid'];
            $rows[] = [
                'userid'     => $userId,
                'username'   => (string) $result->fields['username'],
                'email'      => (string) $result->fields['email'],
                'granted_at' => $holders[$userId] ?? '',
            ];
        }

        return $rows;
    }
}
