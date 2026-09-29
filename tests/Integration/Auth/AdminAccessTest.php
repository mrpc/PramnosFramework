<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\NavItem;
use Pramnos\Application\NavRegistry;
use Pramnos\Application\NavSection;
use Pramnos\Application\Settings;
use Pramnos\Auth\AdminAccess;
use Pramnos\Auth\Permissions;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Who may open which administration screen — decided against the real permission store.
 *
 * The point of `admin_access = permissions` is that two administrators can be given different
 * areas, so every case here is about a grant: made to the user, made to a role they hold, denied,
 * absent. A stub of the store would assert the stub; the resolver that folds a user's roles in is
 * the part worth running.
 */
#[CoversClass(AdminAccess::class)]
#[CoversClass(NavRegistry::class)]
class AdminAccessTest extends BaseTestCase
{
    protected \Pramnos\Database\Database $db;

    private const ALICE = 4501;
    private const BOB   = 4502;
    private const ROLE  = 4510;

    /** @var array<string, mixed> Settings this class changes, to put back */
    private array $savedSettings = [];

    /** @var list<NavItem> */
    private array $savedNav = [];

    /**
     * Whether this test built `authserver.permissions`. Other suites build their own copy with
     * other columns, and `Permissions` remembers which store it found for the rest of the run, so
     * a table left behind here changes what every later permission test reads.
     */
    private bool $builtPermissionsTable = false;

    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->builtPermissionsTable = !$this->db->schema()->hasTable('authserver.permissions');
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreateOrganizationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverRolesTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverUserRolesTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverUserOrganizationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverPermissionsTable::class,
        ], $this->db);
        $this->clearRows();

        foreach ([AdminAccess::MODE_SETTING, AdminAccess::SUPERUSER_SETTING] as $name) {
            $this->savedSettings[$name] = Settings::getSetting($name, null);
        }

        $this->savedNav = NavRegistry::all();
        NavRegistry::reset();
        NavRegistry::register(new NavItem('admin.dashboard', 'Dashboard', '/admin', NavSection::Admin, 1,
            requireAuth: true, minUserType: 80));
        NavRegistry::register(new NavItem('admin.users', 'Users', '/admin/users', NavSection::Admin, 5,
            requireAuth: true, minUserType: 80, group: 'People'));
        NavRegistry::register(new NavItem('admin.roles', 'Roles', '/admin/Roles', NavSection::Admin, 23,
            requireAuth: true, minUserType: 90, group: 'Access'));
        NavRegistry::register(new NavItem('admin.queue', 'Queue', '/admin/Queue', NavSection::Admin, 30,
            requireAuth: true, minUserType: 80, feature: 'queue'));
        NavRegistry::register(new NavItem('home', 'Home', '/', NavSection::Main, 1));

        $_SESSION = ['logged' => true, 'uid' => self::ALICE];
    }

    protected function tearDown(): void
    {
        $this->clearRows();
        if ($this->builtPermissionsTable) {
            $this->db->schema()->dropTableIfExists('authserver.permissions');
        }
        // Forget the store it found, so the next suite finds its own.
        (new \ReflectionProperty(Permissions::class, '_store'))->setValue(Permissions::getInstance(), null);
        Permissions::getInstance()->clearCache();
        foreach ($this->savedSettings as $name => $value) {
            Settings::setSetting($name, $value === null ? '' : (string) $value, false);
        }
        NavRegistry::reset();
        foreach ($this->savedNav as $item) {
            NavRegistry::register($item);
        }
        $_SESSION = [];

        parent::tearDown();
    }

    // ── The two models ──────────────────────────────────────────────────────────

    /**
     * Under `usertype`, the default, the screen's floor decides and grants are ignored.
     *
     * An installation that sets nothing must behave exactly as before this existed.
     */
    public function testInUsertypeModeTheFloorDecides(): void
    {
        // Arrange
        $this->mode('usertype');
        $this->grantToUser(self::ALICE, 'admin.roles');

        // Act & Assert
        $this->assertTrue(AdminAccess::allows($this->user(self::ALICE, 90), 'admin.roles', 90));
        $this->assertFalse(AdminAccess::allows($this->user(self::ALICE, 80), 'admin.roles', 90),
            'a grant must not open a screen while access is by usertype');
    }

    /**
     * Under `permissions`, a screen nobody granted is closed — whatever the usertype.
     *
     * Deny by default: in this mode the grant is the access, so silence cannot mean yes.
     */
    public function testInPermissionsModeNoGrantMeansNoAccess(): void
    {
        // Arrange
        $this->mode('permissions');

        // Act & Assert
        $this->assertFalse(AdminAccess::allows($this->user(self::ALICE, 95), 'admin.users', 80));
    }

    /** A grant made to the user opens the screen, and only that screen. */
    public function testADirectGrantOpensThatScreen(): void
    {
        // Arrange
        $this->mode('permissions');
        $this->grantToUser(self::ALICE, 'admin.users');

        // Act & Assert
        $alice = $this->user(self::ALICE, 80);
        $this->assertTrue(AdminAccess::allows($alice, 'admin.users', 80));
        $this->assertFalse(AdminAccess::allows($alice, 'admin.roles', 90));
    }

    /**
     * A grant made to a role opens the screen for everybody holding the role.
     *
     * This is the normal way to use it — "Support" gets Users and Logs — and it depends on the
     * resolver folding the user's roles in, which is why this runs against the store.
     */
    public function testARoleGrantOpensTheScreenForItsHolders(): void
    {
        // Arrange
        $this->mode('permissions');
        $this->roleHeldBy(self::ROLE, self::BOB);
        AdminAccess::setGrants('role', self::ROLE, ['admin.roles'], ['admin.roles']);

        // Act & Assert
        $this->assertTrue(AdminAccess::allows($this->user(self::BOB, 80), 'admin.roles', 90));
        $this->assertFalse(AdminAccess::allows($this->user(self::ALICE, 80), 'admin.roles', 90),
            'somebody without the role must not get its screens');
    }

    /** An explicit deny beats a role's allow. */
    public function testADenyBeatsARolesGrant(): void
    {
        // Arrange
        $this->mode('permissions');
        $this->roleHeldBy(self::ROLE, self::BOB);
        AdminAccess::setGrants('role', self::ROLE, ['admin.roles'], ['admin.roles']);
        Permissions::getInstance()->deny(self::BOB, 'admin.roles', AdminAccess::PRIVILEGE);

        // Act & Assert
        $this->assertFalse(AdminAccess::allows($this->user(self::BOB, 80), 'admin.roles', 90));
    }

    /**
     * The superuser opens everything without a grant, so switching to permissions before granting
     * anything cannot lock the last administrator out. The threshold is a setting.
     */
    public function testTheSuperuserOpensEverythingAndTheThresholdIsASetting(): void
    {
        // Arrange
        $this->mode('permissions');

        // Act & Assert
        $this->assertSame(98, AdminAccess::superuserUsertype());
        $this->assertTrue(AdminAccess::allows($this->user(self::ALICE, 98), 'admin.roles', 90));

        Settings::setSetting(AdminAccess::SUPERUSER_SETTING, '99', false);
        $this->assertFalse(AdminAccess::allows($this->user(self::ALICE, 98), 'admin.roles', 90));
        $this->assertTrue(AdminAccess::allows($this->user(self::ALICE, 99), 'admin.roles', 90));

        Settings::setSetting(AdminAccess::SUPERUSER_SETTING, '0', false);
        $this->assertSame(98, AdminAccess::superuserUsertype(), 'an unusable value falls back to 98');
    }

    /** Nobody signed in, and the system account, open nothing in either model. */
    public function testAGuestAndTheSystemAccountOpenNothing(): void
    {
        // Arrange
        $this->mode('usertype');

        // Act & Assert
        $this->assertFalse(AdminAccess::allows(null, 'admin.users', 0));
        $this->assertFalse(AdminAccess::allows($this->user(1, 99), 'admin.users', 0));

        $this->mode('permissions');
        $this->assertFalse(AdminAccess::allows($this->user(1, 99), 'admin.users', 0));
    }

    // ── Mixed ───────────────────────────────────────────────────────────────────

    /**
     * Under `mixed`, with nothing decided, the floors decide — so switching an installation to it
     * changes nothing until somebody allows or denies a screen.
     */
    public function testMixedWithNoDecisionsIsTheUsertypeModel(): void
    {
        // Arrange
        $this->mode('mixed');
        $alice = $this->user(self::ALICE, 80);

        // Act & Assert
        $this->assertTrue(AdminAccess::allows($alice, 'admin.users', 80));
        $this->assertFalse(AdminAccess::allows($alice, 'admin.roles', 90));
        $this->assertSame(['admin.dashboard', 'admin.users'], $this->adminMenu($this->navUser(self::ALICE, 80)));
    }

    /** An allow opens a screen below its floor; a deny closes one above it. */
    public function testMixedAllowOpensBelowTheFloorAndDenyClosesAboveIt(): void
    {
        // Arrange
        $this->mode('mixed');
        $all = ['admin.users', 'admin.roles'];
        AdminAccess::setDecisions('user', self::ALICE, ['admin.roles' => 'allow', 'admin.users' => 'deny'], $all);

        // Act & Assert
        $alice = $this->user(self::ALICE, 80);
        $this->assertTrue(AdminAccess::allows($alice, 'admin.roles', 90), 'allowed below its floor of 90');
        $this->assertFalse(AdminAccess::allows($alice, 'admin.users', 80), 'denied above its floor of 80');
    }

    /** A deny from the user beats an allow from a role they hold, in every mode that reads grants. */
    public function testADirectDenyBeatsARolesAllow(): void
    {
        // Arrange
        $this->roleHeldBy(self::ROLE, self::BOB);
        AdminAccess::setDecisions('role', self::ROLE, ['admin.roles' => 'allow'], ['admin.roles']);
        AdminAccess::setDecisions('user', self::BOB, ['admin.roles' => 'deny'], ['admin.roles']);

        foreach (['mixed', 'permissions'] as $mode) {
            // Act
            $this->mode($mode);

            // Assert
            $this->assertFalse(AdminAccess::allows($this->user(self::BOB, 95), 'admin.roles', 90), $mode);
        }
    }

    /** An unknown mode is read as usertype, so a typo cannot open or close the area. */
    public function testAnUnknownModeIsUsertype(): void
    {
        // Arrange
        Settings::setSetting(AdminAccess::MODE_SETTING, 'Permisions', false);

        // Act & Assert
        $this->assertSame('usertype', AdminAccess::mode());
        $this->assertFalse(AdminAccess::usesPermissions());
    }

    /**
     * setDecisions() records allow, deny or neither, changes only what the editor may grant, and
     * decisionsFor() reads it back — a deny recorded next to an allow shows as the deny it is.
     */
    public function testDecisionsAreRecordedAndReadBack(): void
    {
        // Arrange
        $all = ['admin.dashboard', 'admin.users', 'admin.roles'];
        AdminAccess::setDecisions('user', self::ALICE, ['admin.users' => 'allow', 'admin.roles' => 'deny'], $all);

        // Act
        $changed = AdminAccess::setDecisions('user', self::ALICE, [
            'admin.users'     => 'default',   // cleared
            'admin.roles'     => 'deny',      // unchanged
            'admin.dashboard' => 'allow',     // not this editor's to give
        ], ['admin.users', 'admin.roles']);
        // An allow written beside the deny, as a hand-made row could be
        $this->db->queryBuilder()->table('authserver.permissions')->insert([
            'subject_type' => 'user', 'subject_id' => self::ALICE, 'object_type' => 'admin.roles',
            'object_id' => null, 'action' => AdminAccess::PRIVILEGE, 'grant_type' => 'allow',
            'priority' => 100, 'is_active' => true,
        ]);

        // Assert
        $this->assertSame(['admin.users'], $changed);
        $this->assertSame(['admin.roles' => 'deny'], AdminAccess::decisionsFor('user', self::ALICE));
        $this->assertSame([], AdminAccess::setDecisions('user', self::ALICE, ['admin.nonsense' => 'allow'], ['admin.nonsense']));
    }

    /** Decisions go to a user or a role. */
    public function testDecisionsRefuseAnythingButAUserOrARole(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        AdminAccess::setDecisions('organization', 3, ['admin.users' => 'allow'], ['admin.users']);
    }

    /** The panel saves a deny as well as an allow. */
    public function testThePanelSavesADeny(): void
    {
        // Arrange
        $this->mode('mixed');
        $this->signIn(self::ALICE, 98);
        $users = new \Pramnos\Application\Controllers\UsersController(\Pramnos\Application\Application::getInstance());

        // Act
        $this->post($users, self::BOB, ['admin.users' => 'deny', 'admin.roles' => 'allow']);

        // Assert
        $decisions = AdminAccess::decisionsFor('user', self::BOB);
        ksort($decisions);
        $this->assertSame(['admin.roles' => 'allow', 'admin.users' => 'deny'], $decisions);
    }

    // ── Grants ──────────────────────────────────────────────────────────────────

    /**
     * setGrants() makes the list exactly what is granted — and touches nothing else.
     *
     * A deny row and an unrelated permission on the same subject must survive a save of the
     * administration panel; they belong to the Permissions screen.
     */
    public function testSetGrantsSyncsOnlyAdministrationGrants(): void
    {
        // Arrange
        $all = ['admin.dashboard', 'admin.users', 'admin.roles'];
        AdminAccess::setGrants('user', self::ALICE, ['admin.users', 'admin.roles'], $all);
        Permissions::getInstance()->allow(self::ALICE, 'reports', 'export');
        Permissions::getInstance()->deny(self::ALICE, 'admin.dashboard', AdminAccess::PRIVILEGE);

        // Act
        $changed = AdminAccess::setGrants('user', self::ALICE, ['admin.dashboard', 'admin.users'], $all);

        // Assert
        $this->assertSame(['admin.dashboard'], $changed['added']);
        $this->assertSame(['admin.roles'], $changed['removed']);
        $this->assertEqualsCanonicalizing(['admin.dashboard', 'admin.users'],
            AdminAccess::grantsFor('user', self::ALICE));
        $this->assertTrue(Permissions::getInstance()->isAllowed(self::ALICE, 'reports', 'export'),
            'an unrelated permission must survive');
        $this->assertSame(1, $this->countRows(self::ALICE, 'admin.dashboard', 'deny'),
            'a deny written elsewhere must survive');
    }

    /**
     * Only what the editor may grant can change: an ability outside `$grantable` is neither added
     * nor removed, so nobody hands out — or strips — a screen they do not hold.
     */
    public function testSetGrantsChangesOnlyWhatTheEditorMayGrant(): void
    {
        // Arrange
        AdminAccess::setGrants('user', self::ALICE, ['admin.roles'], ['admin.roles']);

        // Act — an editor who may grant only Users asks for Users and Dashboard, and no Roles
        AdminAccess::setGrants('user', self::ALICE, ['admin.users', 'admin.dashboard'], ['admin.users']);

        // Assert
        $this->assertEqualsCanonicalizing(['admin.users', 'admin.roles'],
            AdminAccess::grantsFor('user', self::ALICE));
    }

    /** A grant is made to a user or a role, and to a real id. */
    public function testSetGrantsRefusesAnythingButAUserOrARole(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        AdminAccess::setGrants('organization', 3, ['admin.users'], ['admin.users']);
    }

    /** An ability nobody registered is not grantable, even when asked for. */
    public function testAnUnknownAbilityIsIgnored(): void
    {
        // Act
        $changed = AdminAccess::setGrants('user', self::ALICE, ['admin.nonsense'], ['admin.nonsense']);

        // Assert
        $this->assertSame([], $changed['added']);
        $this->assertSame([], AdminAccess::grantsFor('user', self::ALICE));
    }

    /**
     * An installation without `authserver.permissions` has nothing granted — an empty list, not
     * an exception on the user's page.
     */
    public function testWithNoPermissionsTableNothingIsGranted(): void
    {
        // Arrange
        $this->db->schema()->dropTableIfExists('authserver.permissions');

        // Act
        $granted = AdminAccess::grantsFor('user', self::ALICE);

        // Assert
        $this->assertSame([], $granted);

        // The table back, for tearDown and the next test
        $this->runMigrations([\Pramnos\Framework\Migrations\AuthServer\CreateAuthserverPermissionsTable::class], $this->db);
    }

    /** The panel saves on POST only: a GET — a prefetch, a pasted link — changes nothing. */
    public function testThePanelIgnoresAGet(): void
    {
        // Arrange
        $this->mode('permissions');
        $this->signIn(self::ALICE, 98);
        $roles = new \Pramnos\Auth\Controllers\RolesController(\Pramnos\Application\Application::getInstance());

        // Act
        $this->post($roles, self::ROLE, ['admin.users'], null, 'GET');

        // Assert
        $this->assertSame([], AdminAccess::grantsFor('role', self::ROLE));
    }

    // ── What is listed ──────────────────────────────────────────────────────────

    /**
     * The grantable screens are the Admin menu items, labelled by group — and an item gated on a
     * feature the installation does not have is left out.
     */
    public function testAbilitiesAreTheAdminMenuItems(): void
    {
        // Act
        $withoutQueue = AdminAccess::abilities(['auth']);
        $everything   = AdminAccess::abilities();

        // Assert
        $this->assertSame([
            'admin.dashboard' => 'Dashboard',
            'admin.users'     => 'People › Users',
            'admin.roles'     => 'Access › Roles',
        ], $withoutQueue);
        $this->assertArrayHasKey('admin.queue', $everything);
        $this->assertArrayNotHasKey('home', $everything, 'a public menu item is not a screen to grant');
    }

    /**
     * Two administrators see different menus — the reason for all of this — and the menu hides
     * exactly what the screen would refuse.
     */
    public function testTwoAdministratorsSeeDifferentMenus(): void
    {
        // Arrange
        $this->mode('permissions');
        $this->grantToUser(self::ALICE, 'admin.users');
        $this->roleHeldBy(self::ROLE, self::BOB);
        AdminAccess::setGrants('role', self::ROLE, ['admin.roles', 'admin.dashboard'], ['admin.roles', 'admin.dashboard']);

        // Act
        $alice = $this->adminMenu($this->navUser(self::ALICE, 80));
        $_SESSION['uid'] = self::BOB;
        $bob   = $this->adminMenu($this->navUser(self::BOB, 80));

        // Assert
        $this->assertSame(['admin.users'], $alice);
        $this->assertSame(['admin.dashboard', 'admin.roles'], $bob);
    }

    /** Under `usertype`, the menu is what it always was: the floors decide. */
    public function testInUsertypeModeTheMenuFollowsTheFloors(): void
    {
        // Arrange
        $this->mode('usertype');

        // Act
        $menu = $this->adminMenu($this->navUser(self::ALICE, 80));

        // Assert
        $this->assertSame(['admin.dashboard', 'admin.users'], $menu);
    }

    /**
     * Somebody refused a screen is sent to the first one they may open, in menu order, never back
     * to the one refused — and nowhere when there is none.
     */
    public function testTheLandingIsTheFirstScreenTheyMayOpen(): void
    {
        // Arrange
        $this->mode('permissions');
        $this->grantToUser(self::ALICE, 'admin.users');
        $this->grantToUser(self::ALICE, 'admin.roles');
        $alice = $this->user(self::ALICE, 80);

        // Act & Assert
        $this->assertSame('/admin/users', AdminAccess::landingFor($alice, ['auth'], 'admin.dashboard'));
        $this->assertSame('/admin/Roles', AdminAccess::landingFor($alice, ['auth'], 'admin.users'));
        $this->assertNull(AdminAccess::landingFor($this->user(self::BOB, 80), ['auth'], 'admin.dashboard'));
    }

    // ── The screens themselves ──────────────────────────────────────────────────

    /**
     * An administration screen is checked in exec(), before any action runs.
     *
     * So an action that forgets its own check — the log viewer had none at all — is still behind
     * the screen's ability. Refused, a signed-in administrator is sent to the first screen they may
     * open rather than out of the area.
     */
    public function testExecRefusesAScreenThatWasNotGrantedAndSendsToOneThatWas(): void
    {
        // Arrange
        $this->mode('permissions');
        $this->grantToUser(self::ALICE, 'admin.users');
        $this->signIn(self::ALICE, 80);
        $screen = $this->probeScreen('admin.roles');

        // Act
        $redirect = $this->runAction($screen, 'display');

        // Assert
        $this->assertSame('/admin/users', $redirect);
        $this->assertFalse($screen->ran, 'the action must not run');
    }

    /** A granted screen runs, and an action listed as public runs without the grant. */
    public function testExecRunsAGrantedScreenAndAPublicAction(): void
    {
        // Arrange
        $this->mode('permissions');
        $this->grantToUser(self::ALICE, 'admin.users');
        $this->signIn(self::ALICE, 80);

        // Act & Assert
        $granted = $this->probeScreen('admin.users');
        $this->assertNull($this->runAction($granted, 'display'));
        $this->assertTrue($granted->ran);

        $monitor = $this->probeScreen('admin.roles', ['open']);
        $this->assertNull($this->runAction($monitor, 'open'));
        $this->assertTrue($monitor->ran, 'a public action must answer without the ability');
    }

    /**
     * Outside a configured administration area, exec() does not treat the controller as a screen.
     *
     * An application built its public status page by extending the Health screen; once exec()
     * checked the ability, every visitor to it was redirected. Inside the area it is still checked.
     */
    public function testExecChecksTheAbilityOnlyInsideAConfiguredArea(): void
    {
        // Arrange
        $this->mode('permissions');
        $this->signIn(self::ALICE, 50);
        \Pramnos\Http\AdminArea::reset();
        $savedRoute = $_GET['r'] ?? null;
        $_GET['r'] = 'status';
        \Pramnos\Http\AdminArea::detect('admin', 80);

        try {
            // Act — outside the area
            $public = $this->probeScreen('admin.health');
            $outside = $this->runAction($public, 'display');

            // …and inside it
            \Pramnos\Http\AdminArea::reset();
            $_GET['r'] = 'admin/health';
            \Pramnos\Http\AdminArea::detect('admin', 80);
            $screen = $this->probeScreen('admin.health');
            $inside = $this->runAction($screen, 'display');
        } finally {
            \Pramnos\Http\AdminArea::reset();
            if ($savedRoute === null) {
                unset($_GET['r']);
            } else {
                $_GET['r'] = $savedRoute;
            }
        }

        // Assert
        $this->assertNull($outside);
        $this->assertTrue($public->ran, 'a page outside the area must answer');
        $this->assertNotNull($inside, 'inside the area the ability decides');
        $this->assertFalse($screen->ran);
    }

    /** Under `usertype`, exec() keeps the screen's own floor: 80 opens a screen declared at 80. */
    public function testExecInUsertypeModeUsesTheScreensFloor(): void
    {
        // Arrange
        $this->mode('usertype');
        $this->signIn(self::ALICE, 79);

        // Act
        $screen   = $this->probeScreen('admin.users');
        $redirect = $this->runAction($screen, 'display');

        // Assert
        $this->assertNotNull($redirect, 'below the floor must be refused');
        $this->assertFalse($screen->ran);
    }

    /**
     * A controller that is not an administration screen keeps the plain floor: below it, the
     * visitor goes to the site root, whatever `admin_access` says.
     */
    public function testAScreenWithNoAbilityKeepsThePlainFloor(): void
    {
        // Arrange
        $this->mode('permissions');
        $this->signIn(self::ALICE, 50);
        $plain = new class (\Pramnos\Application\Application::getInstance()) extends \Pramnos\Application\Controller {
            public function guard(int $floor): bool
            {
                return $this->requireMinUserType($floor);
            }
        };
        $app = \Pramnos\Application\Application::getInstance();
        $app->setRedirect(null);

        // Act
        $allowed = $plain->guard(50);
        try {
            ob_start();
            $plain->guard(80);
        } catch (\Pramnos\Application\ApplicationClosedException) {
            // Refused: the redirect ended the request.
        } finally {
            ob_end_clean();
        }

        // Assert
        $this->assertFalse($allowed, 'at the floor it must pass');
        $this->assertSame(defined('sURL') ? \sURL : '/', $app->getRedirect());
    }

    /**
     * The panel saves what was ticked, for a role — and only with a valid form token.
     */
    public function testThePanelSavesARolesScreens(): void
    {
        // Arrange
        $this->mode('permissions');
        $this->signIn(self::ALICE, 98);
        $roles = new \Pramnos\Auth\Controllers\RolesController(\Pramnos\Application\Application::getInstance());

        // Act — first with a stale token, then with a real one
        $this->post($roles, self::ROLE, ['admin.users', 'admin.roles'], 'not-the-token');
        $afterStale = AdminAccess::grantsFor('role', self::ROLE);
        $this->post($roles, self::ROLE, ['admin.users', 'admin.roles']);

        // Assert
        $this->assertSame([], $afterStale, 'a stale form must change nothing');
        $this->assertEqualsCanonicalizing(['admin.users', 'admin.roles'], AdminAccess::grantsFor('role', self::ROLE));
    }

    /**
     * An editor without `admin.permissions` changes nothing, and one with it hands out only the
     * screens they can open themselves.
     */
    public function testThePanelGrantsOnlyWhatTheEditorHolds(): void
    {
        // Arrange
        $this->mode('permissions');
        $users = new \Pramnos\Application\Controllers\UsersController(\Pramnos\Application\Application::getInstance());
        $this->grantToUser(self::ALICE, 'admin.users');
        $this->signIn(self::ALICE, 80);

        // Act — Alice may open Users but not Permissions
        $this->post($users, self::BOB, ['admin.users']);
        $withoutPermissionsScreen = AdminAccess::grantsFor('user', self::BOB);

        NavRegistry::register(new NavItem('admin.permissions', 'Permissions', '/admin/Permissions',
            NavSection::Admin, 24, requireAuth: true, minUserType: 90));
        $this->grantToUser(self::ALICE, 'admin.permissions');
        $this->post($users, self::BOB, ['admin.users', 'admin.roles']);

        // Assert
        $this->assertSame([], $withoutPermissionsScreen);
        $this->assertSame(['admin.users'], AdminAccess::grantsFor('user', self::BOB),
            'Roles is not hers to give');
    }

    /** The panel's data shows the user's own grants and what they can open in the end. */
    public function testThePanelDataShowsDirectAndEffectiveAccess(): void
    {
        // Arrange
        $this->mode('permissions');
        $this->signIn(self::ALICE, 98);
        $this->roleHeldBy(self::ROLE, self::BOB);
        AdminAccess::setGrants('role', self::ROLE, ['admin.roles'], ['admin.roles']);
        $this->grantToUser(self::BOB, 'admin.users');
        $users = new class (\Pramnos\Application\Application::getInstance()) extends \Pramnos\Application\Controllers\UsersController {
            public function panelData(int $id, object $holder): array
            {
                return $this->adminScreensFor($id, $holder);
            }
        };

        // Act
        $panel = $users->panelData(self::BOB, $this->user(self::BOB, 80));
        $rows  = array_column($panel['rows'], null, 'ability');

        // Assert
        $this->assertTrue($panel['permissionsMode']);
        $this->assertTrue($panel['canEdit']);
        $this->assertTrue($rows['admin.users']['granted'] && $rows['admin.users']['effective']);
        $this->assertFalse($rows['admin.roles']['granted'], 'a role grant is not a direct one');
        $this->assertTrue($rows['admin.roles']['effective'], 'but it opens the screen');
        $this->assertFalse($rows['admin.dashboard']['effective']);
    }

    // ── Fixture ─────────────────────────────────────────────────────────────────

    private function signIn(int $userId, int $usertype): void
    {
        \Pramnos\Http\RequestIdentity::seal((object) ['userid' => $userId, 'usertype' => $usertype], 'test');
        $_SESSION['uid'] = $userId;
    }

    /**
     * An administration screen with one ability, recording whether its action ran.
     *
     * @param list<string> $public
     */
    private function probeScreen(string $ability, array $public = []): \Pramnos\Application\Controller
    {
        $screen = new class (\Pramnos\Application\Application::getInstance()) extends \Pramnos\Application\Controller {
            public bool $ran = false;
            protected int $requiredUserType = 80;

            public function setUp(string $ability, array $public): void
            {
                $this->adminAbility = $ability;
                $this->adminPublicActions = $public;
                $this->actions = ['display', 'open'];
            }

            public function display(): string
            {
                $this->ran = true;

                return 'ok';
            }

            public function open(): string
            {
                $this->ran = true;

                return 'ok';
            }
        };
        $screen->setUp($ability, $public);

        return $screen;
    }

    /** Run an action; the destination if it redirected, null if it ran. */
    private function runAction(\Pramnos\Application\Controller $screen, string $action): ?string
    {
        $app = \Pramnos\Application\Application::getInstance();
        $app->setRedirect(null);
        ob_start();
        try {
            $screen->exec($action);
        } catch (\Pramnos\Application\ApplicationClosedException) {
            // A refusal redirects, which ends the request; where it went is returned.
        } finally {
            ob_end_clean();
        }

        return $app->getRedirect();
    }

    /** @param list<string> $abilities */
    private function post(object $controller, int $subjectId, array $abilities, ?string $token = null, string $method = 'POST'): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        // A list means "allow these"; a map is sent as it is — `ability => allow|deny|default`.
        $decisions = array_is_list($abilities) ? array_fill_keys($abilities, 'allow') : $abilities;
        $_POST = [
            '_csrf_token' => $token ?? \Pramnos\Http\Session::getInstance()->getCsrfToken(),
            'subject_id'  => (string) $subjectId,
            'abilities'   => $decisions,
        ];
        \Pramnos\Http\Request::resetInstance();
        ob_start();
        try {
            $controller->adminscreens();
        } catch (\Pramnos\Application\ApplicationClosedException) {
            // It redirects back to the page the panel is on.
        } finally {
            ob_end_clean();
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
        Permissions::getInstance()->clearCache();
    }

    private function mode(string $mode): void
    {
        Settings::setSetting(AdminAccess::MODE_SETTING, $mode, false);
        Settings::setSetting(AdminAccess::SUPERUSER_SETTING, '98', false);
        Permissions::getInstance()->clearCache();
    }

    private function user(int $userId, int $usertype): object
    {
        return (object) ['userid' => $userId, 'usertype' => $usertype];
    }

    private function navUser(int $userId, int $usertype): \Pramnos\User\User
    {
        return new class ($userId, $usertype) extends \Pramnos\User\User {
            public function __construct(int $userId, int $usertype)
            {
                parent::__construct();
                $this->userid   = $userId;
                $this->usertype = $usertype;
            }
        };
    }

    /** @return list<string> */
    private function adminMenu(\Pramnos\User\User $user): array
    {
        $nav = NavRegistry::getForUser($user, ['auth']);

        return array_column($nav[NavSection::Admin->value] ?? [], 'id');
    }

    private function grantToUser(int $userId, string $ability): void
    {
        $current = AdminAccess::grantsFor('user', $userId);
        AdminAccess::setGrants('user', $userId, array_merge($current, [$ability]), [$ability, ...$current]);
    }

    private function roleHeldBy(int $roleId, int $userId): void
    {
        $qb = fn () => $this->db->queryBuilder();
        $qb()->table('authserver.roles')->insert([
            'roleid' => $roleId, 'role_name' => 'Admin screens probe', 'description' => '', 'is_active' => 1,
        ]);
        $qb()->table('authserver.user_roles')->insert([
            'userid' => $userId, 'roleid' => $roleId, 'is_active' => 1,
        ]);
        Permissions::getInstance()->clearCache();
    }

    private function countRows(int $userId, string $objectType, string $grant): int
    {
        $result = $this->db->queryBuilder()->table('authserver.permissions')
            ->where('subject_type', 'user')->where('subject_id', $userId)
            ->where('object_type', $objectType)->where('grant_type', $grant)
            ->get();

        return $result ? (int) $result->numRows : 0;
    }

    private function clearRows(): void
    {
        $qb = fn () => $this->db->queryBuilder();
        foreach ([self::ALICE, self::BOB] as $userId) {
            $qb()->table('authserver.permissions')->where('subject_type', 'user')->where('subject_id', $userId)->delete();
            $qb()->table('authserver.user_roles')->where('userid', $userId)->delete();
        }
        $qb()->table('authserver.permissions')->where('subject_type', 'role')->where('subject_id', self::ROLE)->delete();
        $qb()->table('authserver.roles')->where('roleid', self::ROLE)->delete();
        Permissions::getInstance()->clearCache();
    }
}
