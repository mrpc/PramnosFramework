<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\RolesController;
use Pramnos\Auth\Role;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The roles screens, executed rather than described.
 *
 * `RoleAssignmentTest` covers the rule — an organisation's role goes only to a member of it — and
 * `RolesScreenIsReachableTest` covers the address. Neither runs an action, so everything between
 * the request and the model was unexecuted: the ids read from the route, the refusals that
 * redirect, the organisation label, the holder rows, and the one decision this controller makes on
 * its own — refusing to move a held role to another organisation.
 *
 * That last one is why this is worth a database rather than a mock. The refusal reads
 * `$role->holders()`, so a test that stubs the model asserts my own stub, and the case it exists
 * for (a role with holders, changing organisation) is exactly the case a stub cannot produce
 * honestly.
 *
 * Requires the Docker MySQL container.
 */
// `Role` is named too: `loadRole()` constructs one for every screen, and `save()` goes through
// the model's own write path — lines the report credited to nobody while these tests ran them.
#[CoversClass(RolesController::class)]
#[CoversClass(Role::class)]
class RolesControllerTest extends BaseTestCase
{
    private \Pramnos\Database\Database $db;
    private ?\Pramnos\Database\Database $previousSingleton = null;

    private string $tRoles;
    private string $tUserRoles;
    private string $tMembers;
    private string $tPermissions;

    private const UID = 4300;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $this->previousSingleton = $dbRef;
        $dbRef = null;
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('Runs on MySQL; the QueryBuilder abstracts the dialect.');
        }

        $p = $this->db->prefix;
        $this->tRoles       = $p . 'authserver_roles';
        $this->tUserRoles   = $p . 'authserver_user_roles';
        $this->tMembers     = $p . 'authserver_user_organizations';
        $this->tPermissions = $p . 'authserver_permissions';

        $this->buildTables();
        $_POST = [];
        $_GET  = [];
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        foreach ([$this->tUserRoles, $this->tMembers, $this->tRoles] as $t) {
            $this->db->query("DELETE FROM `{$t}` WHERE 1=1");
        }
        $this->db->query("DELETE FROM `{$this->db->prefix}users` WHERE userid = " . self::UID);

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $this->previousSingleton;

        $_POST = [];
        $_GET  = [];
        \Pramnos\Http\Request::resetInstance();
    }

    private function buildTables(): void
    {
        $p = $this->db->prefix;

        foreach ([$this->tUserRoles, $this->tRoles, $this->tMembers] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreateOrganizationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverRolesTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverUserRolesTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverUserOrganizationsTable::class,
        ], $this->db);

        $this->db->query(
            "INSERT IGNORE INTO `{$p}organizations` (organization_id, name) VALUES (7, 'Org 7')"
        );
        $this->db->query(
            "INSERT IGNORE INTO `{$p}organizations` (organization_id, name) VALUES (8, 'Org 8')"
        );

        foreach ([$this->tUserRoles, $this->tMembers, $this->tRoles] as $t) {
            $this->db->query("DELETE FROM `{$t}` WHERE 1=1");
        }

        $this->db->query(
            "INSERT IGNORE INTO `{$p}users` (userid, username, email) "
            . "VALUES (" . self::UID . ", 'roleholder', 'roleholder@example.com')"
        );
    }

    /** A role row. Returns its id. */
    private function seedRole(int $roleId, ?int $orgId, int $active = 1): int
    {
        $org = $orgId === null ? 'NULL' : (string) $orgId;
        $this->db->query(
            "INSERT INTO `{$this->tRoles}` (roleid, role_name, description, "
            . Role::organizationColumn() . ", is_active) "
            . "VALUES ({$roleId}, 'role-{$roleId}', 'the {$roleId}', {$org}, {$active})"
        );

        return $roleId;
    }

    private function join(int $orgId): void
    {
        $this->db->query(
            "INSERT INTO `{$this->tMembers}` (userid, organization_id, is_active) "
            . "VALUES (" . self::UID . ", {$orgId}, 1)"
        );
    }

    /**
     * The controller, with the three things a screen needs from outside it replaced.
     *
     * The usertype gate, the redirect and the view — everything else runs, including every
     * query. `getView()` hands back a recorder rather than a template, because what the action
     * decided is in the variables it assigned, and rendering a theme would only test the theme.
     */
    private function controller(bool $refused = false): object
    {
        return new class ($refused, $this->db) extends RolesController {
            public ?object $view = null;

            public array $redirects = [];

            public array $errors = [];

            public array $messages = [];

            public function __construct(private bool $refused, \Pramnos\Database\Database $db)
            {
                $app = Application::getInstance();
                $app->database = $db;
                $this->application    = $app;
                $this->controllerName = 'Roles';
            }

            protected function requireMinUserType(int $minType): bool
            {
                return $this->refused;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }

            protected function addError($error)
            {
                $this->errors[] = (string) $error;

                return $this;
            }

            protected function addMessage($message)
            {
                $this->messages[] = (string) $message;

                return $this;
            }

            public function &getView($name = '', $type = '', $args = [])
            {
                $this->view = new class ($name) {
                    public array $assigned = [];

                    public string $layout = '';

                    public function __construct(public string $name)
                    {
                    }

                    public function __set($key, $value)
                    {
                        $this->assigned[$key] = $value;
                    }

                    public function __get($key)
                    {
                        return $this->assigned[$key] ?? null;
                    }

                    public function display($layout = '')
                    {
                        $this->layout = (string) $layout;

                        return 'rendered';
                    }
                };

                return $this->view;
            }
        };
    }

    /** What the route said, which is where every action reads its id from. */
    private function route(int $id): void
    {
        $_GET['_option'] = (string) $id;
        \Pramnos\Http\Request::resetInstance();
    }

    // ── The gate ──────────────────────────────────────────────────────────────

    /**
     * Below usertype 90, no action renders or writes.
     *
     * Deciding what a role *is* is the same order of privilege as deciding what it may do, and
     * `save()`/`delete()` return before touching the table — a gate that only skipped the render
     * would leave the writes open.
     */
    public function testEveryActionStopsBelowTheFloor(): void
    {
        // Arrange
        $this->seedRole(1, null);
        $controller = $this->controller(refused: true);
        $this->route(1);

        // Act
        $controller->display();
        $controller->view(1);
        $controller->edit(1);
        $controller->members(1);
        $controller->save();
        $controller->delete(1);
        $controller->addmember(1);
        $controller->removemember(1);

        // Assert
        $this->assertNull($controller->view, 'nothing was rendered');
        $this->assertSame([], $controller->messages);
        $row = $this->db->query("SELECT is_active FROM `{$this->tRoles}` WHERE roleid = 1");
        $this->assertSame(1, (int) $row->fields['is_active'], 'delete() wrote through the gate');
    }

    /**
     * The floor is 90, and the actions are all auth-registered.
     */
    public function testTheFloorIsAdminAndTheActionsAreProtected(): void
    {
        // Arrange
        $controller = new RolesController(null);
        $ref        = new \ReflectionClass(RolesController::class);

        // Assert
        $this->assertGreaterThanOrEqual(
            90,
            $ref->getProperty('requiredUserType')->getValue($controller)
        );
        $registered = $ref->getProperty('actions_auth')->getValue($controller);
        foreach (['display', 'data', 'view', 'edit', 'save', 'delete',
                  'members', 'addmember', 'removemember'] as $action) {
            $this->assertContains($action, $registered, $action . ' is not auth-protected');
        }
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    /** The list screen builds its table with the columns the AJAX rows fill. */
    public function testTheListScreenBuildsItsTable(): void
    {
        // Arrange
        $controller = $this->controller();

        // Act
        $controller->display();

        // Assert
        $dt = $controller->view->datatable;
        $this->assertInstanceOf(\Pramnos\Html\Datatable::class, $dt);
        $this->assertCount(6, $dt->aoColumns, 'six columns, and data() appends the sixth cell');
    }

    /**
     * The AJAX rows: every cell the screen shows, and the one it must not.
     *
     * `data()` is the half of the list screen that touches data, and it is where a plain string
     * from the database becomes HTML — a role named `<script>` would otherwise arrive in the
     * table as markup, from a field an administrator typed.
     */
    public function testTheRowsAreBuiltAndEscaped(): void
    {
        // Arrange
        $this->seedRole(20, 7);
        $this->db->query(
            "UPDATE `{$this->tRoles}` SET role_name = '<b>bold</b>' WHERE roleid = 20"
        );
        $this->seedRole(21, null, 0);

        $controller = $this->controller();

        // Act
        $response = $controller->data();
        $payload  = json_decode((string) $response->getBody(), true);

        // Assert
        $key  = array_key_exists('data', $payload) ? 'data' : 'aaData';
        $rows = [];
        foreach ($payload[$key] as $row) {
            $rows[(int) strip_tags((string) $row[0])] = $row;
        }

        $this->assertArrayHasKey(20, $rows, 'ids seen: ' . implode(',', array_keys($rows)));
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $rows[20][1]);
        $this->assertStringNotContainsString('<b>bold</b>', $rows[20][1]);
        $this->assertStringContainsString('Org 7', $rows[20][2]);
        $this->assertStringContainsString('pf-state-on', $rows[20][4]);
        $this->assertStringContainsString('roles/delete/', $rows[20][5] ?? '');

        // And the row that is switched off says so, rather than showing an empty cell.
        $this->assertStringContainsString('pf-state-off', $rows[21][4]);
        $this->assertStringContainsString('System-wide', $rows[21][2]);


        // The organisation-that-has-no-row case is asserted on the single-role screen instead:
        // `organizations` restricts the delete while a role still points at it, so the row cannot
        // be produced from here — and both screens read the name through `organizationNames()`.
    }

    /**
     * One role: its permissions, its holders, and the organisation named rather than numbered.
     */
    public function testOneRoleCarriesItsPermissionsAndHolders(): void
    {
        // Arrange
        $this->seedRole(2, 7);
        $this->join(7);
        $role = new Role($this->controller(), '', 2);
        $role->assignTo(self::UID);
        $controller = $this->controller();
        $this->route(2);

        // Act
        $controller->view(2);

        // Assert
        $this->assertSame('view', $controller->view->layout);
        $this->assertSame('Org 7', $controller->view->organisation);
        $holders = $controller->view->holders;
        $this->assertCount(1, $holders);
        $this->assertSame('roleholder', $holders[0]['username']);
        $this->assertSame(self::UID, $holders[0]['userid']);
    }

    /**
     * A NULL organisation reads as system-wide, not as a blank.
     *
     * The distinction the column exists for: no organisation means the role applies everywhere,
     * and an empty label reads as missing data — the reader cannot tell which.
     */
    public function testANullOrganisationReadsAsSystemWide(): void
    {
        // Arrange
        $this->seedRole(3, null);
        $controller = $this->controller();
        $this->route(3);

        // Act
        $controller->view(3);

        // Assert
        $this->assertSame('System-wide', $controller->view->organisation);
        $this->assertSame([], $controller->view->holders, 'nobody holds it');
        $this->assertSame([], $controller->view->permissions);
    }

    /**
     * An organisation that no longer has a row is shown by number.
     *
     * `organizations` is the application's table and a role outlives a delete in it. `#8` is a
     * lead somebody can follow; a blank cell is not.
     */
    public function testAnUnnamedOrganisationIsShownByNumber(): void
    {
        // Arrange
        $this->seedRole(4, 8);
        $this->db->query("DELETE FROM `{$this->db->prefix}organizations` WHERE organization_id = 8");
        $controller = $this->controller();
        $this->route(4);

        // Act
        $controller->view(4);

        // Assert
        $this->assertSame('#8', $controller->view->organisation);
    }

    /** A role that is not there redirects and says so, rather than rendering an empty screen. */
    public function testAMissingRoleRedirectsAndSaysSo(): void
    {
        // Arrange
        $controller = $this->controller();
        $this->route(9999);

        // Act
        $result = $controller->view(9999);

        // Assert
        $this->assertNull($result);
        $this->assertNull($controller->view);
        $this->assertSame(['That role no longer exists.'], $controller->errors);
        $this->assertCount(1, $controller->redirects);
    }

    /** And an id that is not a number at all. */
    public function testAnInvalidIdIsRefusedByEveryScreenThatTakesOne(): void
    {
        // Arrange
        $controller = $this->controller();
        $this->route(0);

        // Act
        $controller->view(0);
        $controller->members(0);
        $controller->delete(0);

        // Assert
        $this->assertCount(3, $controller->errors);
        $this->assertSame('The id in that link is not valid.', $controller->errors[0]);
    }

    /**
     * A role deleted between one screen and the next stops every action that takes an id.
     *
     * The link is valid, the id is a number, and the row is gone — somebody else deactivated it,
     * or the operator has the members screen open in another tab. Each action has to stop where
     * it read the role, not carry on with a blank model and write a new one.
     */
    public function testARoleThatVanishedStopsEveryAction(): void
    {
        // Arrange
        $controller = $this->controller();
        $this->route(9998);
        // `removemember` takes the user from the query string, the others from the form.
        $_GET['userid'] = (string) self::UID;
        \Pramnos\Http\Request::resetInstance();
        $_POST = ['userid' => self::UID, '_csrf_token' => $this->csrf()];

        // Act
        $controller->edit(9998);
        $controller->members(9998);
        $controller->addmember(9998);
        $controller->removemember(9998);
        $_POST = ['roleid' => 9998, 'role_name' => 'ghost', '_csrf_token' => $this->csrf()];
        $controller->save();

        // Assert — `edit()` asks for its view before it loads the role, so one exists; what
        // matters is that it was never displayed and carries no role.
        $this->assertSame('', $controller->view->layout, 'a screen was rendered');
        $this->assertNull($controller->view->role);
        $this->assertSame([], $controller->messages);
        $this->assertCount(5, $controller->errors);
        $this->assertSame(['That role no longer exists.'], array_unique($controller->errors));
        $this->assertSame(0, (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM `{$this->tRoles}` WHERE role_name = 'ghost'"
        )->fields['c'], 'save() created a role from an id that was gone');
    }

    /**
     * With no `organizations` table, the screens work and simply offer no organisation.
     *
     * It is the application's table, not the framework's — an installation that never created
     * one is not broken, it just has no organisations, and every role there is system-wide.
     */
    public function testWithNoOrganizationsTableTheScreensStillWork(): void
    {
        // Arrange
        $this->seedRole(30, null);
        // The membership table's foreign key points at it, so the drop needs the check off —
        // which is fine here: the point is a schema that never had the table, and this is the
        // MySQL lane by construction.
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->query("DROP TABLE IF EXISTS `{$this->db->prefix}organizations`");
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $controller = $this->controller();
        $this->route(30);

        // Act
        $controller->edit(30);
        $editOrganizations = $controller->view->organizations;
        $controller->view(30);

        // Assert
        $this->assertSame([], $editOrganizations, 'nothing to choose from, and no exception');
        $this->assertSame('System-wide', $controller->view->organisation);

        // Cleanup — the shared table, put back with its own migration.
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreateOrganizationsTable::class,
        ], $this->db);
    }

    /**
     * The permissions a role grants are listed beside it, when there are any.
     *
     * A role is otherwise an opaque name: "operator" tells nobody what an operator may do, and
     * the answer lives on a different screen filtered by a subject id most people do not know.
     */
    public function testTheRolesPermissionsAreListedWithIt(): void
    {
        // Arrange
        $this->seedRole(31, null);
        $permissions = $this->db->schema()->resolveTableName('authserver.permissions');
        if (!$this->db->schema()->hasTable('authserver.permissions')) {
            $this->markTestSkipped('The permissions table belongs to another migration set.');
        }
        $this->db->query(
            "INSERT INTO `{$permissions}` (subject_type, subject_id, object_type, object_id, "
            . "action, grant_type, is_active) "
            . "VALUES ('role', 31, 'invoice', 0, 'read', 'allow', 1)"
        );
        $controller = $this->controller();
        $this->route(31);

        // Act
        $controller->view(31);

        // Assert
        $rows = $controller->view->permissions;
        $this->assertCount(1, $rows);
        $this->assertSame('invoice', $rows[0]['object_type']);
        $this->assertSame('read', $rows[0]['action']);

        // Cleanup
        $this->db->query(
            "DELETE FROM `{$permissions}` WHERE subject_type = 'role' AND subject_id = 31"
        );
    }

    /** The create form opens with no role and the organisations to choose from. */
    public function testTheCreateFormOffersTheOrganisations(): void
    {
        // Arrange
        $controller = $this->controller();
        $this->route(0);

        // Act
        $controller->edit();

        // Assert
        $this->assertSame('edit', $controller->view->layout);
        $this->assertNull($controller->view->role, 'no role: this is the create form');
        // Other suites seed their own rows in the shared table, so this asserts the two it
        // needs are named and in order rather than that nothing else exists.
        $organizations = $controller->view->organizations;
        $this->assertSame('Org 7', $organizations[7] ?? null);
        $this->assertSame('Org 8', $organizations[8] ?? null);
        $byName = array_values($organizations);
        $sorted = $byName;
        sort($sorted);
        $this->assertSame($sorted, $byName, 'the select is ordered by name, not by id');
    }

    /** And the edit form opens on the role the route names. */
    public function testTheEditFormOpensOnTheRole(): void
    {
        // Arrange
        $this->seedRole(5, 7);
        $controller = $this->controller();
        $this->route(5);

        // Act
        $controller->edit(5);

        // Assert
        $this->assertSame('role-5', $controller->view->role->role_name);
    }

    /** The holders screen is the same list on its own page. */
    public function testTheHoldersScreenListsThem(): void
    {
        // Arrange
        $this->seedRole(6, null);
        (new Role($this->controller(), '', 6))->assignTo(self::UID);
        $controller = $this->controller();
        $this->route(6);

        // Act
        $controller->members(6);

        // Assert
        $this->assertSame('members', $controller->view->layout);
        $this->assertCount(1, $controller->view->holders);
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    /** A new role is written active, with its organisation. */
    public function testANewRoleIsSaved(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST      = [
            'roleid'      => 0,
            'role_name'   => 'operator',
            'description' => 'runs things',
            Role::organizationColumn() => 7,
            '_csrf_token' => $this->csrf(),
        ];

        // Act
        $controller->save();

        // Assert
        $this->assertSame(['Saved.'], $controller->messages);
        $row = $this->db->query(
            "SELECT * FROM `{$this->tRoles}` WHERE role_name = 'operator'"
        );
        $this->assertSame(1, (int) $row->numRows);
        $this->assertSame(7, (int) $row->fields[Role::organizationColumn()]);
        $this->assertSame(1, (int) $row->fields['is_active'], 'a new role is active');
    }

    /**
     * A save with no name is refused, not written as an unnamed row.
     */
    public function testASaveWithNoNameIsRefused(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST      = ['roleid' => 0, 'role_name' => '   ', '_csrf_token' => $this->csrf()];

        // Act
        $controller->save();

        // Assert
        $this->assertSame(['A role name is required.'], $controller->errors);
        $count = $this->db->query("SELECT COUNT(*) AS c FROM `{$this->tRoles}`");
        $this->assertSame(0, (int) $count->fields['c']);
    }

    /** A stale form is refused before anything is written. */
    public function testAStaleFormIsRefused(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST      = ['roleid' => 0, 'role_name' => 'operator', '_csrf_token' => 'not-the-token'];

        // Act
        $controller->save();

        // Assert
        $this->assertSame(['That form had expired. Please try again.'], $controller->errors);
        $count = $this->db->query("SELECT COUNT(*) AS c FROM `{$this->tRoles}`");
        $this->assertSame(0, (int) $count->fields['c']);
    }

    /**
     * Moving a held role to another organisation is refused, and the message counts the holders.
     *
     * The one decision this controller makes on its own. Performing it would leave every holder
     * who is not a member of the new organisation with a role the resolver ignores — an admin
     * screen reporting success and users who quietly lost access, which is the failure shape
     * this whole area keeps producing.
     */
    public function testMovingAHeldRoleToAnotherOrganisationIsRefused(): void
    {
        // Arrange
        $this->seedRole(10, 7);
        $this->join(7);
        (new Role($this->controller(), '', 10))->assignTo(self::UID);

        $controller = $this->controller();
        $_POST      = [
            'roleid'    => 10,
            'role_name' => 'role-10',
            Role::organizationColumn() => 8,
            'is_active' => 1,
            '_csrf_token' => $this->csrf(),
        ];

        // Act
        $controller->save();

        // Assert
        $this->assertSame([], $controller->messages);
        $this->assertStringContainsString('held by 1 user(s)', $controller->errors[0]);
        $row = $this->db->query("SELECT * FROM `{$this->tRoles}` WHERE roleid = 10");
        $this->assertSame(7, (int) $row->fields[Role::organizationColumn()], 'not moved');
    }

    /** With no holders, the same move goes through. */
    public function testAnUnheldRoleCanChangeOrganisation(): void
    {
        // Arrange
        $this->seedRole(11, 7);
        $controller = $this->controller();
        $_POST      = [
            'roleid'    => 11,
            'role_name' => 'role-11',
            Role::organizationColumn() => 8,
            'is_active' => 1,
            '_csrf_token' => $this->csrf(),
        ];

        // Act
        $controller->save();

        // Assert
        $this->assertSame(['Saved.'], $controller->messages);
        $row = $this->db->query("SELECT * FROM `{$this->tRoles}` WHERE roleid = 11");
        $this->assertSame(8, (int) $row->fields[Role::organizationColumn()]);
    }

    /**
     * An unticked `is_active` deactivates an existing role — and never a new one.
     *
     * The `__KEEP__` shape from the settings screens, in the other direction: a checkbox that is
     * absent when unticked cannot be told from a field the form never had, so `save()` treats a
     * create as active regardless.
     */
    public function testAnUntickedActiveDeactivatesAnExistingRole(): void
    {
        // Arrange
        $this->seedRole(12, null);
        $controller = $this->controller();
        $_POST      = [
            'roleid'      => 12,
            'role_name'   => 'role-12',
            '_csrf_token' => $this->csrf(),
        ];

        // Act
        $controller->save();

        // Assert
        $row = $this->db->query("SELECT * FROM `{$this->tRoles}` WHERE roleid = 12");
        $this->assertSame(0, (int) $row->fields['is_active']);
        $this->assertNull($row->fields['description'], 'an empty description is NULL, not ""');
    }

    /**
     * Delete deactivates rather than removing the row.
     *
     * `authserver.permissions` names the role as a subject id. Removing the row would leave those
     * grants pointing at nothing, and the resolver already ignores an inactive role.
     */
    public function testDeleteDeactivatesAndKeepsTheRow(): void
    {
        // Arrange
        $this->seedRole(13, null);
        $controller = $this->controller();
        $this->route(13);

        // Act
        $controller->delete(13);

        // Assert
        $this->assertSame(['Deactivated.'], $controller->messages);
        $row = $this->db->query("SELECT * FROM `{$this->tRoles}` WHERE roleid = 13");
        $this->assertSame(1, (int) $row->numRows, 'the row is still there');
        $this->assertSame(0, (int) $row->fields['is_active']);
    }

    // ── Membership ────────────────────────────────────────────────────────────

    /** Giving a role to a user, and taking it back. */
    public function testARoleIsGivenAndTakenBack(): void
    {
        // Arrange
        $this->seedRole(14, null);
        $controller = $this->controller();
        $this->route(14);
        $_POST = ['userid' => self::UID, '_csrf_token' => $this->csrf()];

        // Act
        $controller->addmember(14);

        // Assert
        $this->assertSame(['Added.'], $controller->messages);
        $this->assertSame(1, (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM `{$this->tUserRoles}` WHERE roleid = 14"
        )->fields['c']);

        // Act
        $_GET['_option'] = '14';
        $_GET['userid']  = (string) self::UID;
        \Pramnos\Http\Request::resetInstance();
        $controller->removemember(14);

        // Assert
        $this->assertContains('Removed.', $controller->messages);
    }

    /**
     * The model's refusal is reported verbatim, not translated into "failed".
     *
     * It tells the administrator what to do next — add them to the organisation first — and a
     * generic failure makes them guess.
     */
    public function testTheModelsRefusalIsReportedVerbatim(): void
    {
        // Arrange
        $this->seedRole(15, 7);
        $controller = $this->controller();
        $this->route(15);
        $_POST = ['userid' => self::UID, '_csrf_token' => $this->csrf()];

        // Act
        $controller->addmember(15);

        // Assert
        $this->assertSame([], $controller->messages);
        $this->assertNotSame('', $controller->errors[0] ?? '');
        $this->assertSame(0, (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM `{$this->tUserRoles}` WHERE roleid = 15"
        )->fields['c']);
    }

    /** No user selected is a refusal, not an assignment to account 0. */
    public function testNoUserSelectedIsRefused(): void
    {
        // Arrange
        $this->seedRole(16, null);
        $controller = $this->controller();
        $this->route(16);
        $_POST = ['userid' => 0, '_csrf_token' => $this->csrf()];

        // Act
        $controller->addmember(16);
        $controller->removemember(16);

        // Assert
        $this->assertSame(
            ['No valid entries were selected.', 'No valid entries were selected.'],
            $controller->errors
        );
    }

    /** A stale membership form is refused too. */
    public function testAStaleMembershipFormIsRefused(): void
    {
        // Arrange
        $this->seedRole(17, null);
        $controller = $this->controller();
        $this->route(17);
        $_POST = ['userid' => self::UID, '_csrf_token' => 'stale'];

        // Act
        $controller->addmember(17);

        // Assert
        $this->assertSame(['That form had expired. Please try again.'], $controller->errors);
        $this->assertSame(0, (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM `{$this->tUserRoles}` WHERE roleid = 17"
        )->fields['c']);
    }

    /**
     * With nothing in the route, the posted `roleid` is used.
     *
     * The membership forms POST rather than following a link, so the id may arrive either way and
     * an action that read only the route would drop the assignment silently.
     */
    public function testThePostedIdIsUsedWhenTheRouteHasNone(): void
    {
        // Arrange
        $this->seedRole(18, null);
        $controller = $this->controller();
        $this->route(0);
        $_POST = ['roleid' => 18, 'userid' => self::UID, '_csrf_token' => $this->csrf()];

        // Act
        $controller->addmember(null);

        // Assert
        $this->assertSame(['Added.'], $controller->messages);
        $this->assertSame(1, (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM `{$this->tUserRoles}` WHERE roleid = 18"
        )->fields['c']);
    }

    /** The token the form would have carried. */
    private function csrf(): string
    {
        return \Pramnos\Http\Session::getInstance()->getCsrfToken();
    }
}
