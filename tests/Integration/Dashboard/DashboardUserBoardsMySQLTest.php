<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\NavItem;
use Pramnos\Application\NavRegistry;
use Pramnos\Application\NavSection;
use Pramnos\Application\Settings;
use Pramnos\Dashboard\Layouts;
use Pramnos\Dashboard\Presets;
use Pramnos\Dashboard\UserBoards;
use Pramnos\Dashboard\WidgetRegistry;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Core\CreateDashboardBoardsTable;
use Pramnos\Framework\Migrations\Core\CreateDashboardPresetsTable;
use Pramnos\Framework\Migrations\Core\CreateUsersettingsTable;

/**
 * Dashboards people make for themselves, against the real database — MySQL here, PostgreSQL in
 * the sibling.
 *
 * What holds: a board is its owner's until shared, and then in the menus of whom it is shared
 * with; it offers the administration dashboard's cards; its owner starts it empty, somebody it
 * was shared with starts from the owner's arrangement and then keeps their own; only its owner
 * renames or shares it; deleting it takes every viewer's arrangement with it; and the menu lists
 * the boards each person sees under Dashboard.
 */
#[CoversClass(UserBoards::class)]
#[CoversClass(\Pramnos\Application\Controllers\DashboardController::class)]
#[CoversClass(NavRegistry::class)]
#[CoversClass(CreateDashboardBoardsTable::class)]
class DashboardUserBoardsMySQLTest extends TestCase
{
    /** The owner. */
    protected const OWNER = 990301;

    /** A manager it is shared with. */
    protected const MANAGER = 990302;

    /** Somebody below every role. */
    protected const STAFF = 990303;

    protected Database $db;

    /** @var array<string, mixed> */
    private array $info = [];

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $this->db = $this->connect();
        \Pramnos\Framework\Testing\Schema::ensure([CreateUsersettingsTable::class, CreateDashboardPresetsTable::class, CreateDashboardBoardsTable::class], $this->db);
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
        $people = [self::OWNER, self::MANAGER, self::STAFF];
        $this->db->queryBuilder()->table(UserBoards::TABLE)->whereIn('ownerid', $people)->delete();
        $this->db->queryBuilder()->table(Presets::TABLE)->whereIn('ownerid', $people)->delete();
        $this->db->queryBuilder()->table('#PREFIX#usersettings')->whereIn('userid', $people)->where('setting', 'like', Layouts::SETTING . '%')->delete();
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
     * A board is its owner's until shared: then a manager has it, somebody below the role does
     * not. Its owner starts it empty; the manager starts from the owner's arrangement, cleaned for
     * them, and keeps their own once they change it. It offers the admin dashboard's cards.
     */
    public function testABoardIsSharedAndEachViewerArrangesItForThemselves(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['dashboard'] = ['cards' => ['database' => ['min_role' => 'admin']]];
        $boards  = new UserBoards($this->db);
        $owner   = self::person(self::OWNER, 99);
        $manager = self::person(self::MANAGER, 80);
        $staff   = self::person(self::STAFF, 10);
        $id      = $boards->create($owner, 'Losses');
        $name    = UserBoards::name($id);
        $layouts = new Layouts($this->db);

        // Act
        $emptyAtFirst = $layouts->for($owner, $name);
        $layouts->save($owner, [['id' => 'database'], ['id' => 'health', 'size' => 'full']], $name);
        $beforeSharing = $boards->find($name, $manager);
        $boards->update($id, $owner, 'Water losses', Presets::ROLE, 'manager');
        $managerStarts = $layouts->for($manager, $name);
        $layouts->save($manager, [['id' => 'users.active']], $name);
        $managerKeeps = array_column($layouts->for($manager, $name), 'id');
        $ownerKeeps   = array_column($layouts->for($owner, $name), 'id');

        // Assert
        $this->assertSame([], $emptyAtFirst, 'its owner adds the cards they want');
        $this->assertNull($beforeSharing, 'not shared yet: nobody else has it');
        $this->assertSame([['id' => 'health', 'size' => 'full', 'settings' => []]], $managerStarts, 'the owner\'s, without the card a manager may not have');
        $this->assertSame(['users.active'], $managerKeeps);
        $this->assertSame(['database', 'health'], $ownerKeeps, 'each keeps their own');
        $this->assertSame(['Water losses'], array_column($boards->visibleTo($manager), 'name'));
        $this->assertSame([], $boards->visibleTo($staff));
        $this->assertArrayHasKey('users.active', WidgetRegistry::available($owner, $name), 'the admin dashboard\'s cards');
        $this->assertTrue(UserBoards::isUserBoard($name));
        $this->assertFalse(UserBoards::isUserBoard('my-0'));
    }

    /**
     * Only the owner renames or shares a board; a wrong name or audience is refused; deleting it
     * — the owner's, or an administrator's — takes every viewer's arrangement with it.
     */
    public function testOnlyItsOwnerChangesABoardAndDeletingItTakesTheArrangements(): void
    {
        // Arrange
        $boards  = new UserBoards($this->db);
        $owner   = self::person(self::OWNER, 90);
        $manager = self::person(self::MANAGER, 80);
        $id      = $boards->create($owner, 'Mine', [['id' => 'health']]);
        $boards->update($id, $owner, 'Mine', Presets::EVERYONE);
        (new Layouts($this->db))->save($manager, [['id' => 'health']], UserBoards::name($id));
        $refusals = 0;

        // Act
        $byOther = $boards->update($id, $manager, 'Theirs', Presets::EVERYONE);
        foreach ([['', Presets::PRIVATE, null], ['X', Presets::ROLE, 'ghost']] as [$name, $visibility, $audience]) {
            try {
                $boards->update($id, $owner, $name, $visibility, $audience);
            } catch (\InvalidArgumentException) {
                $refusals++;
            }
        }
        try {
            $boards->create($owner, '   ');
        } catch (\InvalidArgumentException) {
            $refusals++;
        }
        $deletedByOther = $boards->delete($id, $manager);
        $deleted        = $boards->delete($id, $owner);
        $arrangements   = $this->db->queryBuilder()->table('#PREFIX#usersettings')->where('setting', Layouts::SETTING . UserBoards::name($id))->count();

        // Assert
        $this->assertFalse($byOther);
        $this->assertSame(3, $refusals);
        $this->assertFalse($deletedByOther);
        $this->assertTrue($deleted);
        $this->assertSame(0, $arrangements, 'every viewer\'s arrangement went with it');
        $this->assertNull($boards->find($id, $owner));
    }

    /**
     * Through the controller: a board made from a preset starts as the preset and is shown; its
     * owner shares it; a manager sees it, cannot change or delete it; its owner deletes it.
     */
    public function testTheControllerMakesSharesAndDeletesABoard(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['dashboard'] = ['presets' => ['Ops' => ['health']]];
        $controller = new LayoutDashboardController(null);
        $owner      = self::person(self::OWNER, 90);
        $manager    = self::person(self::MANAGER, 80);
        \Pramnos\Http\RequestIdentity::seal($owner, 'test');

        // Act — made from the app's preset
        $_POST = ['name' => 'From ops', 'preset' => 'app:Ops'];
        $controller->newboard();
        $wentTo = (string) $controller->redirectedTo;
        $board  = (new UserBoards($this->db))->visibleTo($owner)[0];
        $_GET   = ['board' => $board['board']];
        $controller->display();
        $view = $controller->view;
        $_POST = ['name' => 'Shared', 'visibility' => Presets::ROLE, 'audience_role' => 'manager'];
        $controller->boardsettings();
        $_POST = ['name' => ''];
        $controller->newboard();
        // …the manager sees it and may not change or delete it
        \Pramnos\Http\RequestIdentity::seal($manager, 'test');
        $managerSees = array_column((new UserBoards($this->db))->visibleTo($manager), 'name');
        $_POST = ['name' => 'Hijacked', 'visibility' => Presets::EVERYONE];
        $controller->boardsettings();
        $controller->deleteboard();
        $stillThere = (new UserBoards($this->db))->find($board['board'], $manager);
        // …its owner deletes it
        \Pramnos\Http\RequestIdentity::seal($owner, 'test');
        $controller->deleteboard();

        // Assert
        $this->assertStringContainsString('board=' . $board['board'], $wentTo, 'to the new board');
        $this->assertSame(['health'], array_column($view->widgets, 'id'), 'it starts as the preset');
        $this->assertSame('From ops', $view->boardTitle, 'its name over it');
        $this->assertTrue($view->userBoard['mine']);
        $this->assertSame(['Shared'], $managerSees);
        $this->assertSame('Shared', $stillThere['name'] ?? null, 'the manager changed nothing and deleted nothing');
        $this->assertSame([], (new UserBoards($this->db))->visibleTo($owner), 'its owner deleted it');
        $said = (string) json_encode($_SESSION['_errors'] ?? []);
        $this->assertStringContainsString('A dashboard needs a name of up to 120 characters.', $said);
        $this->assertStringContainsString('Only its owner changes a dashboard.', $said);
    }

    /**
     * The menu: the boards a person sees, under Dashboard, opening with Dashboard's own ability;
     * a provider that fails adds nothing and the rest of the menu still draws.
     */
    public function testTheMenuListsThePersonsBoards(): void
    {
        // Arrange
        NavRegistry::reset();
        $owner = self::person(self::OWNER, 99);
        (new UserBoards($this->db))->create($owner, 'Night shift');
        Application::getInstance()->registerDefaultNavItems([]);
        NavRegistry::provide(static fn (): array => throw new \RuntimeException('down'));
        \Pramnos\Http\RequestIdentity::seal($owner, 'test');
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = self::OWNER;

        try {
            // Act
            $admin = NavRegistry::getForUser($owner)[NavSection::Admin->value] ?? [];
            $mine  = array_values(array_filter($admin, static fn (NavItem $item): bool => $item->parent === 'admin.dashboard'));

            // Assert
            $this->assertSame(['Night shift'], array_map(static fn (NavItem $i): string => $i->label, $mine));
            $this->assertSame('admin.dashboard', $mine[0]->ability);
            $this->assertStringContainsString('board=my-', $mine[0]->url);
            $this->assertNotEmpty(array_filter($admin, static fn (NavItem $item): bool => $item->id === 'admin.dashboard'), 'the rest still draws');
        } finally {
            NavRegistry::reset();
            unset($_SESSION['logged'], $_SESSION['uid']);
        }
    }

    /**
     * What a board must survive: a store that cannot be read has no boards, and making one there
     * is said, not thrown; a name that is not a board's finds nothing; nobody signed in makes
     * nothing; a wrong audience is said; and the menu of nobody signed in lists no boards.
     */
    public function testWhatABoardSurvives(): void
    {
        // Arrange
        $broken = new class extends Database {
            /** No table answers. */
            public function queryBuilder(): \Pramnos\Database\QueryBuilder
            {
                throw new \RuntimeException('down');
            }
        };
        $controller = new class (null, $broken) extends LayoutDashboardController {
            /** Whether the boards' store should fail. */
            public bool $failing = false;

            /** @param Database $broken A store that cannot be read */
            public function __construct(?\Pramnos\Application\Application $app, private Database $broken)
            {
                parent::__construct($app);
            }

            /** The real store, or the broken one. */
            protected function userBoards(): UserBoards
            {
                return $this->failing ? new UserBoards($this->broken) : parent::userBoards();
            }
        };
        $owner = self::person(self::OWNER, 90);

        // Act
        $none     = (new UserBoards($broken))->visibleTo($owner);
        $notABoard = (new UserBoards($this->db))->find('wcm', $owner);
        $controller->newboard();
        $anonymousTo = $controller->redirectedTo;
        \Pramnos\Http\RequestIdentity::seal($owner, 'test');
        $controller->failing = true;
        $_POST = ['name' => 'Down'];
        $controller->newboard();
        $controller->failing = false;
        $id = (new UserBoards($this->db))->create($owner, 'Mine');
        $_GET  = ['board' => UserBoards::name($id)];
        $_POST = ['name' => 'Mine', 'visibility' => Presets::ORGANIZATION, 'audience_org' => 'bad id!'];
        $controller->boardsettings();
        NavRegistry::reset();
        Application::getInstance()->registerDefaultNavItems([]);
        $guestMenu = NavRegistry::getForUser(null);
        NavRegistry::reset();

        // Assert
        $this->assertSame([], $none);
        $this->assertNull($notABoard);
        $this->assertNotNull($anonymousTo, 'nobody signed in: sent away');
        $said = (string) json_encode($_SESSION['_errors'] ?? []);
        $this->assertStringContainsString('The dashboard could not be made.', $said);
        $this->assertStringContainsString('Choose the organisation to share it with.', $said);
        $this->assertSame([], array_filter($guestMenu[NavSection::Admin->value] ?? [], static fn (NavItem $i): bool => $i->parent === 'admin.dashboard'));
    }
}
