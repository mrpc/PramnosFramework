<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Dashboard\Layouts;
use Pramnos\Dashboard\Presets;
use Pramnos\Dashboard\WidgetRegistry;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Core\CreateDashboardPresetsTable;
use Pramnos\Framework\Migrations\Core\CreateUsersettingsTable;

/**
 * Dashboard presets against the real database — MySQL here, PostgreSQL in the sibling.
 *
 * What holds: a saved preset is seen by whom its visibility says and nobody else; the app's
 * presets come first; one preset per role and board is a role's default, and a person with no
 * layout of their own starts from it; applying is a copy, cleaned for the person, with the cards
 * they may not have counted; only the owner, or an administrator, deletes; only an administrator
 * sets a default or copies a board to somebody.
 */
#[CoversClass(Presets::class)]
#[CoversClass(\Pramnos\Application\Controllers\DashboardController::class)]
#[CoversClass(CreateDashboardPresetsTable::class)]
class DashboardPresetsMySQLTest extends TestCase
{
    /** The administrator who saves the presets. */
    protected const ADMIN = 990201;

    /** A manager they are shared with. */
    protected const MANAGER = 990202;

    /** Somebody below every role. */
    protected const STAFF = 990203;

    protected Database $db;

    /** @var array<string, mixed> */
    private array $info = [];

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $this->db = $this->connect();
        \Pramnos\Framework\Testing\Schema::ensure([CreateUsersettingsTable::class, CreateDashboardPresetsTable::class], $this->db);
        $this->clean();
        $this->info = Application::getInstance()->applicationInfo;
        WidgetRegistry::reset();
        $_SESSION['_messages'] = [];
        $_SESSION['_errors']   = [];
    }

    protected function tearDown(): void
    {
        $this->clean();
        Application::getInstance()->applicationInfo = $this->info;
        \Pramnos\Http\RequestIdentity::reset();
        $_POST = [];
        $_GET  = [];
    }

    /** The connection under test. */
    protected function connect(): Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect(false);
        }
        if (!$db->connected) {
            $this->markTestSkipped('MySQL is not reachable.');
        }

        return $db;
    }

    /** Remove what these tests wrote. */
    private function clean(): void
    {
        $this->db->queryBuilder()->table(Presets::TABLE)->whereIn('ownerid', [self::ADMIN, self::MANAGER, self::STAFF])->delete();
        $this->db->queryBuilder()->table('#PREFIX#usersettings')->whereIn('userid', [self::ADMIN, self::MANAGER, self::STAFF])
            ->where('setting', 'like', Layouts::SETTING . '%')->delete();
    }

    /** A person of an id and usertype. */
    private static function person(int $id, int $type): \Pramnos\User\User
    {
        $user = new \Pramnos\User\User();
        $user->userid   = $id;
        $user->usertype = $type;
        $user->username = 'p' . $id;

        return $user;
    }

    /**
     * Who sees a saved preset is what its visibility says: the owner always, a role from it up,
     * everybody signed in for `everyone`, nobody else for `private`, and members only for an
     * organisation — none here, without the membership table. The app's presets come first.
     */
    public function testAPresetIsSeenByWhomItsVisibilitySays(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['dashboard'] = ['presets' => ['Operations' => ['health']]];
        $store = new Presets($this->db);
        $admin = self::person(self::ADMIN, 90);
        $store->save($admin, 'admin', 'Private', [['id' => 'health']]);
        $store->save($admin, 'admin', 'Managers', [['id' => 'health']], Presets::ROLE, 'manager');
        $store->save($admin, 'admin', 'All', [['id' => 'health']], Presets::EVERYONE);
        $store->save($admin, 'admin', 'Org', [['id' => 'health']], Presets::ORGANIZATION, '77');

        // Act
        $names = static fn (array $list): array => array_column($list, 'name');
        $mine    = $names($store->visibleTo($admin, 'admin'));
        $manager = $names($store->visibleTo(self::person(self::MANAGER, 80), 'admin'));
        $staff   = $names($store->visibleTo(self::person(self::STAFF, 10), 'admin'));
        $other   = $names($store->visibleTo($admin, 'pf-elsewhere'));

        // Assert
        $this->assertSame(['Operations', 'Private', 'Managers', 'All', 'Org'], $mine, 'the app\'s first, then the owner\'s own, all of them');
        $this->assertSame(['Operations', 'Managers', 'All'], $manager);
        $this->assertSame(['Operations', 'All'], $staff, 'below the role: only everyone\'s');
        $this->assertSame(['Operations'], $other, 'a saved preset is its board\'s; an unlisted board is the top level, with its app presets');
        $this->assertTrue($store->visibleTo($admin, 'admin')[1]['mine']);
    }

    /**
     * A name, a visibility the store knows, and an audience that is a role or an organisation id
     * are required: each wrong one is refused before anything is written.
     */
    public function testAPresetWithAWrongShapeIsRefused(): void
    {
        // Arrange
        $store  = new Presets($this->db);
        $admin  = self::person(self::ADMIN, 90);
        $errors = 0;

        // Act
        foreach ([['', Presets::PRIVATE, null], ['X', 'friends', null], ['X', Presets::ROLE, 'nope'], ['X', Presets::ORGANIZATION, 'abc']] as [$name, $visibility, $audience]) {
            try {
                $store->save($admin, 'admin', $name, [], $visibility, $audience);
            } catch (\InvalidArgumentException) {
                $errors++;
            }
        }

        // Assert
        $this->assertSame(4, $errors);
        $this->assertSame([], $store->all(), 'nothing written');
    }

    /**
     * A preset made a role's default is what a person of that role starts from when they have no
     * layout of their own; marking another one for the role takes the mark from the first; the
     * highest role a person holds wins; and an empty role takes the mark away.
     */
    public function testARolesDefaultIsWhereANewBoardStarts(): void
    {
        // Arrange
        $store = new Presets($this->db);
        $admin = self::person(self::ADMIN, 90);
        $first = $store->save($admin, 'admin', 'First', [['id' => 'database']], Presets::EVERYONE);
        $second = $store->save($admin, 'admin', 'Second', [['id' => 'health']], Presets::EVERYONE);
        $forAdmins = $store->save($admin, 'admin', 'Admins', [['id' => 'users.active']], Presets::EVERYONE);

        // Act
        $store->setDefaultForRole($first, 'manager');
        $store->setDefaultForRole($second, 'manager');
        $store->setDefaultForRole($forAdmins, 'admin');
        $layouts = new Layouts($this->db);
        $manager = array_column($layouts->for(self::person(self::MANAGER, 80), 'admin'), 'id');
        $admins  = array_column($layouts->for($admin, 'admin'), 'id');
        $staff   = $layouts->for(self::person(self::STAFF, 10), 'admin');
        $marks   = array_column($store->all(), 'default_for_role', 'name');
        $store->setDefaultForRole($second, null);

        // Assert
        $this->assertSame(['health'], $manager, 'the second took the mark');
        $this->assertSame(['users.active'], $admins, 'the highest role held wins');
        $this->assertSame(Layouts::defaults(self::person(self::STAFF, 10), 'admin'), $staff, 'no role: the board\'s default');
        $this->assertNull($marks['First']);
        $this->assertNull($store->all()[1]['default_for_role'], 'an empty role takes the mark away');
        $this->assertFalse($store->setDefaultForRole(999999999, 'admin'), 'no such preset');
    }

    /**
     * Through the controller: saving keeps the board as it is now; applying copies a preset into
     * the person's own layout and counts the cards they may not have; deleting is the owner's or
     * an administrator's; a default and a copy to somebody are an administrator's only.
     */
    public function testTheControllerAppliesSavesAndDeletesPresets(): void
    {
        // Arrange — a card only root may have, in the preset a manager applies
        Application::getInstance()->applicationInfo['dashboard'] = ['cards' => ['database' => ['min_role' => 'root']]];
        $controller = new LayoutDashboardController(null);
        $admin      = self::person(self::ADMIN, 99);
        $manager    = self::person(self::MANAGER, 80);
        (new Layouts($this->db))->save($admin, [['id' => 'database'], ['id' => 'health']], 'admin');
        \Pramnos\Http\RequestIdentity::seal($admin, 'test');

        // Act — the administrator saves their board for everyone
        $_POST = ['name' => 'Ops', 'visibility' => Presets::ROLE, 'audience_role' => 'manager'];
        $controller->savepreset();
        $saved = (new Presets($this->db))->all()[0];
        // …the manager applies it
        \Pramnos\Http\RequestIdentity::seal($manager, 'test');
        $_POST = ['preset' => (string) $saved['presetid']];
        $controller->applypreset();
        $applied  = array_column((new Layouts($this->db))->for($manager, 'admin'), 'id');
        $messages = $_SESSION['_messages'] ?? [];
        // …cannot delete it, set a default, or copy a board
        $controller->deletepreset();
        $_POST = ['preset' => (string) $saved['presetid'], 'role' => 'manager'];
        $controller->presetdefault();
        $_POST = ['userid' => (string) self::ADMIN];
        $controller->copyboard();
        $stillThere = count((new Presets($this->db))->all());
        // …and an unknown preset is said
        $_POST = ['preset' => 'app:nope'];
        $controller->applypreset();
        // The administrator deletes it
        \Pramnos\Http\RequestIdentity::seal($admin, 'test');
        $_POST = ['preset' => (string) $saved['presetid']];
        $controller->deletepreset();

        // Assert
        $this->assertSame('Ops', $saved['name']);
        $this->assertSame('["database","health"]', json_encode(array_column(json_decode($saved['layout'], true), 'id')), 'the board as it was');
        $this->assertSame(['health'], $applied, 'a copy, cleaned for the manager');
        $this->assertNotEmpty(array_filter($messages, static fn ($m) => str_contains((string) $m, '1 cards')), 'the dropped card is counted');
        $this->assertSame(1, $stillThere, 'a manager deletes nobody else\'s preset');
        $this->assertNull((new Presets($this->db))->defaultLayoutFor($manager, 'admin'), 'and sets no default');
        $this->assertSame([], (new Presets($this->db))->all(), 'the administrator may delete it');
        $errors = $_SESSION['_errors'] ?? ($_SESSION['_error'] ?? []);
        $this->assertNotEmpty($errors);
    }

    /** The administration's list draws every preset with its owner's name; others are sent away. */
    public function testTheListOfPresets(): void
    {
        // Arrange
        $controller = new LayoutDashboardController(null);
        (new Presets($this->db))->save(self::person(self::ADMIN, 99), 'admin', 'Listed', [['id' => 'health']]);
        \Pramnos\Http\RequestIdentity::seal(self::person(self::MANAGER, 80), 'test');

        // Act
        $controller->presets();
        $refusedTo = $controller->redirectedTo;
        \Pramnos\Http\RequestIdentity::seal(self::person(self::ADMIN, 99), 'test');
        $controller->presets();

        // Assert
        $this->assertNotNull($refusedTo, 'a manager is sent back');
        $this->assertSame('presets', $controller->view->shown);
        $this->assertSame(['Listed'], array_column($controller->view->presets, 'name'));
        $this->assertArrayHasKey('default', $controller->view->urls);
    }

    /**
     * An organisation's presets are seen by its active, unexpired members only — read from the
     * membership table the permission system uses, here one of the test's own.
     */
    public function testAnOrganisationsActiveMembersSeeItsPresets(): void
    {
        // Arrange — a membership table of the test's own, named through the setting
        $schema = $this->db->schema();
        $schema->dropTableIfExists('authserver.pf_dash_members');
        $schema->createTable('authserver.pf_dash_members', function ($table) {
            $table->integer('userid');
            $table->integer('organization_id');
            $table->boolean('is_active')->default(true);
            $table->string('expires_at', 32)->nullable();
        });
        Settings::setSetting('authserver_organization_table', 'pf_dash_members', false);
        $members = $this->db->queryBuilder()->table('authserver.pf_dash_members');
        $members->insert(['userid' => self::MANAGER, 'organization_id' => 77, 'is_active' => true]);
        $this->db->queryBuilder()->table('authserver.pf_dash_members')->insert(['userid' => self::STAFF, 'organization_id' => 77, 'is_active' => true, 'expires_at' => '2001-01-01 00:00:00']);
        $store = new Presets($this->db);
        $store->save(self::person(self::ADMIN, 90), 'admin', 'Org', [['id' => 'health']], Presets::ORGANIZATION, '77');

        try {
            // Act
            $member  = array_column($store->visibleTo(self::person(self::MANAGER, 80), 'admin'), 'name');
            $expired = array_column($store->visibleTo(self::person(self::STAFF, 80), 'admin'), 'name');
            $guest   = array_column($store->visibleTo(self::person(0, 0), 'admin'), 'name');

            // Assert
            $this->assertSame(['Org'], $member);
            $this->assertSame([], $expired, 'an expired membership sees nothing');
            $this->assertSame([], $guest, 'nobody signed in sees nothing');
        } finally {
            Settings::setSetting('authserver_organization_table', '', false);
            $schema->dropTableIfExists('authserver.pf_dash_members');
        }
    }

    /**
     * A role default naming a role app.php no longer defines marks nothing, rather than failing
     * the dashboard of everybody who opens it.
     */
    public function testADefaultForAnUnknownRoleMarksNothing(): void
    {
        // Arrange
        $id = (new Presets($this->db))->save(self::person(self::ADMIN, 90), 'admin', 'Stale', [['id' => 'health']], Presets::EVERYONE);
        $this->db->queryBuilder()->table(Presets::TABLE)->where('presetid', $id)->update(['default_for_role' => 'ghost']);

        // Act & Assert
        $this->assertNull((new Presets($this->db))->defaultLayoutFor(self::person(self::ADMIN, 99), 'admin'));
    }

    /**
     * An administrator sets a role's default (an unknown role is said) and copies their board to
     * an account (none is said); a wrong shape is said; a store that fails is said, not thrown;
     * nobody signed in is only sent back; and the way back is the page the form was on.
     */
    public function testAnAdministratorsPresetActions(): void
    {
        // Arrange — an account to copy to, and a store that fails on demand
        $manager    = self::person(self::MANAGER, 80);
        $controller = new class (null, $manager) extends LayoutDashboardController {
            /** Whether the store should fail. */
            public bool $failing = false;

            /** @param object $receiver The one account there is */
            public function __construct(?\Pramnos\Application\Application $app, private object $receiver)
            {
                parent::__construct($app);
            }

            /** The receiver, by its id only. */
            protected function account(int $userId): ?object
            {
                return (int) $this->receiver->userid === $userId ? $this->receiver : null;
            }

            /** The real store, or one that cannot write. */
            protected function presetStore(): \Pramnos\Dashboard\Presets
            {
                if (!$this->failing) {
                    return parent::presetStore();
                }
                $db = new class extends \Pramnos\Database\Database {
                    /** No table answers. */
                    public function queryBuilder(): \Pramnos\Database\QueryBuilder
                    {
                        throw new \RuntimeException('down');
                    }
                };

                return new \Pramnos\Dashboard\Presets($db);
            }
        };
        $admin = self::person(self::ADMIN, 99);
        (new Layouts($this->db))->save($admin, [['id' => 'health']], 'admin');
        $id = (new Presets($this->db))->save($admin, 'admin', 'Mine', [['id' => 'database']], Presets::EVERYONE);
        $anonymous = $controller->applypreset();
        $sentBack  = $controller->redirectedTo;
        \Pramnos\Http\RequestIdentity::seal($admin, 'test');

        // Act
        $_POST = ['preset' => (string) $id, 'role' => 'manager'];
        $controller->presetdefault();
        $_POST = ['preset' => (string) $id, 'role' => 'ghost'];
        $controller->presetdefault();
        $_POST = ['userid' => (string) self::MANAGER];
        $controller->copyboard();
        $copied = array_column((new Layouts($this->db))->for($manager, 'admin'), 'id');
        $_POST = ['userid' => '424242'];
        $controller->copyboard();
        $_POST = ['name' => 'X', 'visibility' => Presets::ORGANIZATION, 'audience_org' => 'abc'];
        $controller->savepreset();
        $controller->failing = true;
        $_POST = ['name' => 'Y'];
        $controller->savepreset();
        $_SERVER['HTTP_REFERER'] = \sURL . 'wcm';
        $controller->failing = false;
        $_POST = ['preset' => (string) $id];
        $controller->applypreset();
        $back = $controller->redirectedTo;
        unset($_SERVER['HTTP_REFERER']);

        // Assert
        $this->assertNull($anonymous);
        $this->assertNotNull($sentBack, 'nobody signed in: sent back, nothing changed');
        $this->assertSame('manager', (new Presets($this->db))->all()[0]['default_for_role'], 'the unknown role did not replace it');
        $this->assertSame(['health'], $copied, 'the administrator\'s board, on the manager\'s');
        $this->assertSame(\sURL . 'wcm', $back, 'back to the page the form was on');
        $errors = json_encode($_SESSION['_errors'] ?? $_SESSION);
        foreach (['There is no such account.', 'Choose the organisation to share it with.', 'The preset could not be saved.'] as $said) {
            $this->assertStringContainsString($said, (string) $errors);
        }
    }

    /**
     * The failures a dashboard must survive: a store that cannot be read shows the app's presets
     * only; a role nobody defines, or a membership table that is not there, shares with nobody; an
     * account that does not exist is none; and with no controller of its own the framework's is
     * the one a board talks to.
     */
    public function testWhatAFailingStoreOrAMissingPieceLeaves(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['dashboard'] = ['presets' => ['Operations' => ['health']]];
        $broken = new Presets(new class extends Database {
            /** No table answers. */
            public function queryBuilder(): \Pramnos\Database\QueryBuilder
            {
                throw new \RuntimeException('down');
            }
        });
        $manager = self::person(self::MANAGER, 80);
        Settings::setSetting('authserver_organization_table', 'pf_dash_nobody', false);

        try {
            // Act
            $fromBroken = array_column($broken->visibleTo($manager, 'admin'), 'name');
            $unknownRole = Presets::canSee(['ownerid' => self::ADMIN, 'visibility' => Presets::ROLE, 'audience' => 'ghost'], $manager);
            $noTable     = Presets::canSee(['ownerid' => self::ADMIN, 'visibility' => Presets::ORGANIZATION, 'audience' => '77'], $manager);
            $account     = (new \ReflectionMethod(LayoutDashboardController::class, 'account'))->invoke(new LayoutDashboardController(null), 424242);
            Application::getInstance()->applicationInfo['namespace'] = 'Pf\\NoSuchApp';
            $controller  = \Pramnos\Dashboard\Board::controller();

            // Assert
            $this->assertSame(['Operations'], $fromBroken);
            $this->assertFalse($unknownRole);
            $this->assertFalse($noTable);
            $this->assertNull($account);
            $this->assertSame(\Pramnos\Application\Controllers\DashboardController::class, $controller::class);
        } finally {
            Settings::setSetting('authserver_organization_table', '', false);
        }
    }
}
