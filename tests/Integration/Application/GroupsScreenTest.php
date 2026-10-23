<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Groups;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Auth\CreateUsersTable;
use Pramnos\Framework\Migrations\UserGroups\CreateUsergroupsTables;
use Pramnos\Framework\Testing\Schema;

/** The screen, with the admin floor lifted and its output captured. */
class ProbeGroups extends Groups
{
    public ?string $redirectedTo = null;

    public ?object $lastView = null;

    public string $lastLayout = '';

    protected function requireMinUserType(int $minType): bool
    {
        return false;
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirectedTo = (string) $url;
    }

    public function &getView($name = '', $type = '', $args = [])
    {
        $probe = $this;
        $view  = new #[\AllowDynamicProperties] class ($probe) {
            public function __construct(private ProbeGroups $probe)
            {
            }

            public function display(string $layout = 'default'): bool
            {
                $this->probe->lastLayout = $layout;
                $this->probe->lastView   = $this;

                return true;
            }
        };

        return $view;
    }
}

/** The screen as somebody below its floor sees it. */
class BelowTheFloorGroups extends ProbeGroups
{
    protected function requireMinUserType(int $minType): bool
    {
        return true;
    }
}

/**
 * `/admin/Groups` against a real database, on MySQL and PostgreSQL.
 *
 * Each assertion reads the tables back, because the screen's job is the rows: a group made,
 * a member added once and only once, a membership gone with its group. Built in a throwaway
 * database so the shared `users` table the rest of the suite uses is not touched.
 */
#[CoversClass(Groups::class)]
#[CoversClass(CreateUsergroupsTables::class)]
class GroupsScreenTest extends TestCase
{
    use \Pramnos\Tests\Support\ReusesProbeDatabase;

    private const PROBE = 'pramnos_groups_probe';

    private ?Database $admin = null;

    private ?Database $db = null;

    private ?Database $previous = null;

    /** @var array<string, bool> */
    private array $savedFeatures = [];

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    private static function connection(string $type, string $host, int $port, string $user, string $name): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = $name;

        return $db;
    }

    private function boot(string $type, string $host, int $port, string $user): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');

        $this->admin = self::connection($type, $host, $port, $user, TEST_DATABASE);
        try {
            if (!$this->admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        // Built once per class and engine, emptied for each test; see ReusesProbeDatabase.
        $this->db = $this->openProbe(
            $this->admin,
            self::PROBE,
            static fn (string $name): Database => self::connection($type, $host, $port, $user, $name),
            [
                CreateUsersTable::class,
                \Pramnos\Framework\Migrations\AuthServer\CreateAuthserverRolesTable::class,
                CreateUsergroupsTables::class,
                \Pramnos\Framework\Migrations\UserGroups\CreateAuthserverGroupRolesTable::class,
            ]
        );

        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;

        $enabled             = new \ReflectionProperty(FeatureRegistry::class, 'enabled');
        $this->savedFeatures = (array) $enabled->getValue();
        FeatureRegistry::loadFromConfig(['auth', 'usergroups']);

        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(FeatureRegistry::class, 'enabled'))->setValue(null, $this->savedFeatures);
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        $this->db?->close();
        $this->admin?->close();
        $_POST = [];
        $_GET  = [];
        \Pramnos\Audience\Groups::reset();
    }

    private function user(string $username): int
    {
        $this->db->queryBuilder()->table('users')->insert([
            'username' => $username,
            'email'    => $username . '@example.com',
        ]);

        return (int) $this->db->queryBuilder()->table('users')->where('username', $username)->first()->fields['userid'];
    }

    private function group(string $name): int
    {
        $this->db->queryBuilder()->table('usergroups')->insert(['name' => $name, 'description' => '']);

        return (int) $this->db->queryBuilder()->table('usergroups')->where('name', $name)->first()->fields['groupid'];
    }

    private function members(int $groupId): array
    {
        $ids = [];
        foreach ($this->db->queryBuilder()->table('userstogroups')->where('groupid', $groupId)->get() as $row) {
            $ids[] = (int) $row['userid'];
        }
        sort($ids);

        return $ids;
    }

    /**
     * Saving a new group creates it and opens it; a missing name is refused and nothing
     * is written.
     */
    #[DataProvider('databases')]
    public function testSaveCreatesAGroupAndRefusesAMissingName(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $screen = new ProbeGroups();

        // Act
        $_POST = ['name' => '  Volunteers ', 'description' => 'Weekend help'];
        $screen->save();
        $created = $this->db->queryBuilder()->table('usergroups')->where('name', 'Volunteers')->first();
        $_POST = ['name' => ''];
        $refused = new ProbeGroups();
        $refused->save();

        // Assert
        $this->assertSame(1, (int) $created->numRows, 'the name is trimmed and stored');
        $this->assertSame('Weekend help', $created->fields['description']);
        $this->assertStringEndsWith('Groups/view/' . $created->fields['groupid'], (string) $screen->redirectedTo);
        $this->assertSame(1, (int) $this->db->queryBuilder()->table('usergroups')->count(), 'the empty name wrote nothing');
        $this->assertContains('A group needs a name.', $_SESSION['_errors'] ?? []);
    }

    /**
     * A member can be added by username, email or id, once; the guest and system accounts
     * cannot be; an unknown one is reported.
     */
    #[DataProvider('databases')]
    public function testMembersAreAddedByUsernameEmailOrIdAndOnlyOnce(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $groupId = $this->group('Editors');
        $alice   = $this->user('alice');
        $bob     = $this->user('bob');
        $carol   = $this->user('carol');
        $_GET['_option'] = $groupId;

        // Act
        foreach (['ALICE', 'bob@example.com', (string) $carol, 'alice', '1', 'nobody'] as $needle) {
            $_POST = ['user' => $needle];
            (new ProbeGroups())->addmember();
        }

        // Assert
        $this->assertSame([$alice, $bob, $carol], $this->members($groupId), 'username, email and id each found their account, alice once');
        $this->assertContains('Already a member.', $_SESSION['_messages'] ?? []);
        // "1" is the system account and "nobody" matches nothing: both refused the same way.
        $this->assertContains('No account matches that username, email or id.', $_SESSION['_errors'] ?? []);
    }

    /**
     * Removing a member removes that membership only; deleting a group removes its
     * memberships with it, so no row is left granting a group id that could be reused.
     */
    #[DataProvider('databases')]
    public function testRemovingAMemberAndDeletingAGroup(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $keep    = $this->group('Keep');
        $drop    = $this->group('Drop');
        $alice   = $this->user('alice');
        $bob     = $this->user('bob');
        foreach ([[$keep, $alice], [$keep, $bob], [$drop, $alice]] as [$g, $u]) {
            $this->db->queryBuilder()->table('userstogroups')->insert(['groupid' => $g, 'userid' => $u]);
        }

        // Act
        $_GET['_option'] = $keep;
        $_POST = ['userid' => $bob];
        (new ProbeGroups())->removemember();
        $_GET['_option'] = $drop;
        (new ProbeGroups())->delete();

        // Assert
        $this->assertSame([$alice], $this->members($keep));
        $this->assertSame([], $this->members($drop));
        $this->assertSame(0, (int) $this->db->queryBuilder()->table('usergroups')->where('groupid', $drop)->count());
    }

    /** One answer of a datatable action, with these parameters, decoded. */
    private static function answer(callable $action, array $post): array
    {
        $_POST = $post + ['draw' => 2, 'start' => 0, 'length' => 10];

        return json_decode((string) $action()->getBody(), true);
    }

    /**
     * The list is a datatable whose rows `Groups/data` answers: number, name, description, order
     * and members — by the order first, a group with none after those with one — searched by
     * number, name and description, sorted by any column; a group that is not there sends the
     * operator back to the list with a message.
     */
    #[DataProvider('databases')]
    public function testTheListAndAMissingGroup(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $readers = $this->group('Readers');
        $press   = $this->group('Press');
        $staff   = $this->group('Staff');
        $this->db->queryBuilder()->table('usergroups')->where('groupid', $press)->update(['order' => -5]);
        $this->db->queryBuilder()->table('usergroups')->where('groupid', $readers)->update(['order' => 10]);
        $this->db->queryBuilder()->table('userstogroups')->insert(['groupid' => $readers, 'userid' => $this->user('alice')]);
        $data = static fn (): mixed => (new ProbeGroups())->data();

        // Act
        $list = new ProbeGroups();
        $list->display();
        $byOrder   = self::answer($data, ['order' => [['column' => 3, 'dir' => 'asc']]]);
        $byMembers = self::answer($data, ['order' => [['column' => 4, 'dir' => 'desc']]]);
        $byNumber  = self::answer($data, ['search' => ['value' => '#' . $staff]]);
        $byName    = self::answer($data, ['search' => ['value' => 'PRE'], 'length' => 1]);
        $missing   = new ProbeGroups();
        $_GET['_option'] = 999999;
        $missing->view();

        // Assert
        $names = static fn (array $answer): array => array_map(static fn (array $row): string => strip_tags($row[1]), $answer['data']);
        $this->assertSame(adminUrl('Groups/data'), $list->lastView->datatable->source);
        $this->assertSame([3, 'asc'], [$list->lastView->datatable->sortColumn, $list->lastView->datatable->sortOrder], 'by the order first');
        $this->assertSame(['Press', 'Readers', 'Staff'], $names($byOrder), 'a group with no order comes after');
        $this->assertSame([(string) $press, '-5', '0'], [$byOrder['data'][0][0], $byOrder['data'][0][3], $byOrder['data'][0][4]]);
        $this->assertSame('', $byOrder['data'][2][3], 'no order shows nothing');
        $this->assertSame(['Readers', '1'], [$names($byMembers)[0], $byMembers['data'][0][4]], 'the most members first');
        $this->assertSame(['Staff'], $names($byNumber), 'found by its number');
        $this->assertSame([3, 1, ['Press']], [$byName['recordsTotal'], $byName['recordsFiltered'], $names($byName)], 'searched ignoring case');
        $this->assertStringEndsWith('Groups', (string) $missing->redirectedTo);
        $this->assertContains('That group no longer exists.', $_SESSION['_errors'] ?? []);
    }

    /**
     * A group's page reads its members a page at a time from `Groups/members/{id}` — searched by
     * username, name, email or number, sorted, paged in the database — and says how many there
     * are, what uses the group and where the application lists them with its own tools.
     */
    #[DataProvider('databases')]
    public function testAGroupsPageAndItsMembers(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $groupId = $this->group('Readers');
        $other   = $this->group('Other');
        $ids     = [];
        foreach (['alice', 'bob', 'carol'] as $name) {
            $ids[$name] = $this->user($name);
            $this->db->queryBuilder()->table('userstogroups')->insert(['groupid' => $groupId, 'userid' => $ids[$name]]);
        }
        $this->db->queryBuilder()->table('users')->where('userid', $ids['bob'])->update(['firstname' => 'Robert', 'lastname' => 'Smith']);
        $this->db->queryBuilder()->table('userstogroups')->insert(['groupid' => $other, 'userid' => $this->user('dave')]);
        \Pramnos\Audience\Groups::usage('coupons', static fn (int $id): array => $id === $groupId ? [['name' => 'coupon SUMMER', 'url' => '/admin/Coupons/edit/4'], 'package Gold'] : []);
        \Pramnos\Application\Application::getInstance()->applicationInfo['audience']['group_members_url'] = '/admin/users/display/groupid/{id}';
        $_GET['_option'] = $groupId;
        $members = static fn (): mixed => (new ProbeGroups())->members();

        // Act
        $page = new ProbeGroups();
        $page->view();
        $all      = self::answer($members, ['order' => [['column' => 1, 'dir' => 'desc']]]);
        $byName   = self::answer($members, ['search' => ['value' => 'SMITH']]);
        $byEmail  = self::answer($members, ['search' => ['value' => 'carol@']]);
        $byNumber = self::answer($members, ['search' => ['value' => (string) $ids['alice']]]);
        $second   = self::answer($members, ['order' => [['column' => 1, 'dir' => 'asc']], 'start' => 1, 'length' => 1]);
        $every    = self::answer($members, ['length' => -1, 'search' => 'o']);
        unset(\Pramnos\Application\Application::getInstance()->applicationInfo['audience']['group_members_url']);

        // Assert
        $users = static fn (array $answer): array => array_map(static fn (array $row): string => strip_tags($row[1]), $answer['data']);
        $this->assertSame('view', $page->lastLayout);
        $this->assertSame(adminUrl('Groups/members/') . $groupId, $page->lastView->members->source);
        $this->assertSame(3, $page->lastView->count);
        $this->assertSame('/admin/users/display/groupid/' . $groupId, $page->lastView->membersUrl);
        $this->assertSame([['name' => 'coupon SUMMER', 'url' => '/admin/Coupons/edit/4'], ['name' => 'package Gold', 'url' => null]], $page->lastView->uses);
        $this->assertSame(['carol', 'bob', 'alice'], $users($all), 'only this group, by username descending');
        $this->assertSame([3, 3], [$all['recordsTotal'], $all['recordsFiltered']]);
        $this->assertStringContainsString('name="userid" value="' . $ids['carol'] . '"', $all['data'][0][4], 'each row removes its member');
        $this->assertSame(['bob'], $users($byName), 'by surname, ignoring case');
        $this->assertSame('Robert Smith', strip_tags($byName['data'][0][2]));
        $this->assertSame(['carol'], $users($byEmail));
        $this->assertContains('alice', $users($byNumber), 'by number');
        $this->assertSame([['bob'], 3], [$users($second), $second['recordsFiltered']], 'the second of three');
        $this->assertSame(['bob', 'carol'], array_values(array_intersect(['bob', 'carol'], $users($every))), 'every row, searched as a plain string');
    }

    /**
     * The order is a whole number from -127 to 127, or empty for none; anything else is refused
     * and changes nothing.
     */
    #[DataProvider('databases')]
    public function testTheOrderIsSavedWithinItsRange(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $groupId = $this->group('Ordered');
        $order   = fn (): mixed => $this->db->queryBuilder()->table('usergroups')->where('groupid', $groupId)->first()->fields['order'];

        // Act
        $_POST = ['groupid' => $groupId, 'name' => 'Ordered', 'order' => '-12'];
        (new ProbeGroups())->save();
        $set = $order();
        $_POST = ['groupid' => $groupId, 'name' => 'Ordered', 'order' => '200'];
        $refused = new ProbeGroups();
        $refused->save();
        $kept = $order();
        $errors = $_SESSION['_errors'] ?? [];
        $_POST = ['groupid' => $groupId, 'name' => 'Ordered', 'order' => ''];
        (new ProbeGroups())->save();

        // Assert
        $this->assertSame([-12, -12], [(int) $set, (int) $kept], 'an order out of range changes nothing');
        $this->assertContains('The order is a whole number from -127 to 127, or empty.', $errors);
        $this->assertStringEndsWith('Groups/edit/' . $groupId, (string) $refused->redirectedTo);
        $this->assertNull($order(), 'empty is no order');
    }

    /**
     * A group in use is not deleted — by a segment whose filter names it, by a rule that puts
     * people in it, or by what the application reports — and the refusal says what uses it.
     */
    #[DataProvider('databases')]
    public function testAGroupInUseIsNotDeleted(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $used = $this->group('Used');
        \Pramnos\Audience\Groups::usage('support', static fn (int $id): array => $id === $used ? ['support team Billing'] : []);
        $_GET['_option'] = $used;

        // Act
        $screen = new ProbeGroups();
        $screen->delete();

        // Assert
        $this->assertSame(1, (int) $this->db->queryBuilder()->table('usergroups')->where('groupid', $used)->count(), 'still there');
        $this->assertContains('This group is in use, so it is not deleted: support team Billing.', $_SESSION['_errors'] ?? []);
        $this->assertStringEndsWith('Groups/view/' . $used, (string) $screen->redirectedTo);
    }

    /**
     * With the feature off, a typed URL is turned away before any table is read — the
     * tables may not exist on such a site.
     */
    #[DataProvider('databases')]
    public function testTheScreenIsClosedWhenTheFeatureIsOff(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        (new \ReflectionProperty(FeatureRegistry::class, 'enabled'))->setValue(null, ['auth' => true]);
        $this->db->query('DROP TABLE userstogroups');
        $this->db->query('DROP TABLE usergroups');
        $this->probeChanged($type);
        $screen = new ProbeGroups();

        // Act
        $screen->display();

        // Assert
        $this->assertNull($screen->lastView, 'nothing was rendered');
        $this->assertContains('User groups are not enabled on this site.', $_SESSION['_errors'] ?? []);
    }

    /**
     * A membership change is seen by the next permission check at once. `getGroups()`
     * cached for a minute under no category, so a grant through a group took that long to
     * apply — and to be withdrawn.
     */
    #[DataProvider('databases')]
    public function testMembershipIsSeenAtOnceByGetGroups(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        // Not group 1: getGroups() always includes the account's main group, which is 1.
        $this->group('Placeholder');
        $groupId = $this->group('Staff');
        $alice   = $this->user('alice');
        $account = new \Pramnos\User\User();
        $account->userid = $alice;
        $before = array_keys($account->getGroups());

        // Act
        $_GET['_option'] = $groupId;
        $_POST = ['user' => 'alice'];
        (new ProbeGroups())->addmember();
        $after = array_keys($account->getGroups());

        // Assert
        $this->assertNotContains($groupId, $before);
        $this->assertContains($groupId, $after);
    }

    /**
     * The form opens empty for a new group and filled for an existing one; an id that is
     * not there sends the operator back rather than opening a blank form that would
     * create a second group.
     */
    #[DataProvider('databases')]
    public function testTheFormForNewExistingAndMissingGroups(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $groupId = $this->group('Existing');

        // Act
        $new = new ProbeGroups();
        $new->edit();
        $existing = new ProbeGroups();
        $_GET['_option'] = $groupId;
        $existing->edit();
        $missing = new ProbeGroups();
        $_GET['_option'] = 999999;
        $missing->edit();

        // Assert
        $this->assertSame('edit', $new->lastLayout);
        $this->assertNull($new->lastView->group);
        $this->assertSame('Existing', $existing->lastView->group['name']);
        $this->assertNull($missing->lastView);
        $this->assertStringEndsWith('Groups', (string) $missing->redirectedTo);
    }

    /**
     * Saving an existing group updates it in place; an over-long name is refused before
     * the database truncates or rejects it; an id that is not there writes nothing.
     */
    #[DataProvider('databases')]
    public function testSaveUpdatesAndRefusesWhatItCannotStore(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $groupId = $this->group('Before');

        // Act
        $_POST = ['groupid' => $groupId, 'name' => 'After', 'description' => 'changed'];
        (new ProbeGroups())->save();
        $_POST = ['groupid' => $groupId, 'name' => str_repeat('x', 81)];
        $tooLong = new ProbeGroups();
        $tooLong->save();
        // Read now: the next screen writes its own errors over these.
        $tooLongErrors = $_SESSION['_errors'] ?? [];
        $_POST = ['groupid' => 999999, 'name' => 'Ghost'];
        (new ProbeGroups())->save();

        // Assert
        $row = $this->db->queryBuilder()->table('usergroups')->where('groupid', $groupId)->first()->fields;
        $this->assertSame(['After', 'changed'], [$row['name'], $row['description']]);
        $this->assertStringEndsWith('Groups/edit/' . $groupId, (string) $tooLong->redirectedTo);
        $this->assertContains('A group name is at most 80 characters.', $tooLongErrors);
        $this->assertSame(0, (int) $this->db->queryBuilder()->table('usergroups')->where('name', 'Ghost')->count());
    }

    /**
     * Every write aimed at a group that is not there is turned back with a message and
     * changes nothing; an empty member field finds nobody.
     */
    #[DataProvider('databases')]
    public function testWritesToAMissingGroupChangeNothing(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $groupId = $this->group('Real');
        $alice   = $this->user('alice');

        // Act
        $_GET['_option'] = 999999;
        $_POST = ['user' => 'alice', 'userid' => $alice];
        foreach (['addmember', 'removemember', 'delete', 'addrole', 'removerole'] as $action) {
            $screen = new ProbeGroups();
            $screen->$action();
            $this->assertStringEndsWith('Groups', (string) $screen->redirectedTo, $action . ' did not go back to the list');
        }
        $_GET['_option'] = $groupId;
        $_POST = ['user' => '   '];
        (new ProbeGroups())->addmember();

        // Assert
        $this->assertSame(1, (int) $this->db->queryBuilder()->table('usergroups')->count(), 'nothing was deleted');
        $this->assertSame([], $this->members($groupId), 'an empty field added nobody');
    }

    /**
     * With the feature off every action is turned away, not only the list.
     */
    #[DataProvider('databases')]
    public function testEveryActionIsClosedWhenTheFeatureIsOff(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $groupId = $this->group('Closed');
        (new \ReflectionProperty(FeatureRegistry::class, 'enabled'))->setValue(null, ['auth' => true]);
        $_GET['_option'] = $groupId;
        $_POST = ['name' => 'Changed', 'groupid' => $groupId, 'user' => 'x'];

        // Act
        foreach (['view', 'edit', 'save', 'delete', 'addmember', 'removemember', 'addrole', 'removerole'] as $action) {
            $screen = new ProbeGroups();
            $screen->$action();
            $this->assertNull($screen->lastView, $action . ' rendered with the feature off');
        }

        // Assert — the group is exactly as it was.
        $this->assertSame('Closed', $this->db->queryBuilder()->table('usergroups')->where('groupid', $groupId)->first()->fields['name']);
    }

    /**
     * Below the screen's usertype floor nothing is read or rendered, and a link with no
     * id is a missing group rather than a query for group 0.
     */
    #[DataProvider('databases')]
    public function testBelowTheFloorAndAnEmptyId(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->group('Anything');

        // Act
        $refused = new BelowTheFloorGroups();
        $refused->display();
        $rows = (new BelowTheFloorGroups())->data();
        $members = (new BelowTheFloorGroups())->members();
        $noId = new ProbeGroups();
        $_GET['_option'] = 0;
        $noId->view();

        // Assert
        $this->assertNull($refused->lastView, 'a screen rendered below its floor');
        $this->assertNull($rows, 'nor its rows');
        $this->assertNull($members, 'nor a group\'s members');
        $this->assertNull($noId->lastView);
        $this->assertStringEndsWith('Groups', (string) $noId->redirectedTo);
    }

    private function role(string $name, bool $active = true): int
    {
        $this->db->queryBuilder()->table('authserver.roles')->insert(['role_name' => $name, 'is_active' => $active]);

        return (int) $this->db->queryBuilder()->table('authserver.roles')->where('role_name', $name)->first()->fields['roleid'];
    }

    /** @return array<int, bool> roleid => is_active for the group */
    private function groupRoles(int $groupId): array
    {
        $roles = [];
        foreach ($this->db->queryBuilder()->table('authserver.group_roles')->where('groupid', $groupId)->get() as $row) {
            $roles[(int) $row['roleid']] = (bool) $row['is_active'];
        }

        return $roles;
    }

    /**
     * Giving a role writes one active assignment; withdrawing switches it off and keeps
     * it; giving it again switches the same row back on instead of adding a second. A role
     * that does not exist, or is switched off, is refused.
     */
    #[DataProvider('databases')]
    public function testRolesAreGivenWithdrawnAndGivenAgain(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $groupId = $this->group('Staff');
        $editor  = $this->role('Editor');
        $off     = $this->role('Retired', false);
        $alice   = $this->user('alice');
        $this->join($alice, $groupId);
        $_GET['_option'] = $groupId;

        // Act
        $_POST = ['roleid' => $editor];
        (new ProbeGroups())->addrole();
        $afterGive = $this->groupRoles($groupId);
        (new ProbeGroups())->removerole();
        $afterWithdraw = $this->groupRoles($groupId);
        (new ProbeGroups())->addrole();
        $afterGiveAgain = $this->groupRoles($groupId);
        $_POST = ['roleid' => $off];
        $refused = new ProbeGroups();
        $refused->addrole();
        $refusedErrors = $_SESSION['_errors'] ?? [];
        $_POST = ['roleid' => 0];
        (new ProbeGroups())->addrole();
        $noRoleErrors = $_SESSION['_errors'] ?? [];

        // Assert
        $this->assertSame([$editor => true], $afterGive);
        $this->assertSame([$editor => false], $afterWithdraw, 'withdrawn is kept, switched off');
        $this->assertSame([$editor => true], $afterGiveAgain, 'given again is the same row, back on');
        $this->assertContains('That role does not exist or is switched off.', $refusedErrors);
        $this->assertArrayNotHasKey($off, $this->groupRoles($groupId));
        $this->assertContains('That role does not exist or is switched off.', $noRoleErrors, 'no role at all was not refused');
    }

    /**
     * The group page shows the roles it holds and offers the active ones it does not.
     */
    #[DataProvider('databases')]
    public function testTheGroupPageListsHeldAndAvailableRoles(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $groupId = $this->group('Staff');
        $held    = $this->role('Held');
        $this->role('Offered');
        $this->role('Switched off', false);
        $this->db->queryBuilder()->table('authserver.group_roles')->insert(['groupid' => $groupId, 'roleid' => $held, 'is_active' => true]);
        $_GET['_option'] = $groupId;

        // Act
        $page = new ProbeGroups();
        $page->view();

        // Assert
        $this->assertTrue($page->lastView->rolesEnabled);
        $this->assertSame(['Held'], array_column($page->lastView->roles, 'role_name'));
        $this->assertSame(['Offered'], array_column($page->lastView->availableRoles, 'role_name'), 'a switched-off role was offered');
    }

    /**
     * Membership changes on a group holding roles, and deleting it, take the roles with
     * them — the deleted group's assignments are gone, not left for a reused id.
     */
    #[DataProvider('databases')]
    public function testMembershipAndDeletionOfAGroupWithRoles(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $groupId = $this->group('Holders');
        $roleId  = $this->role('Editor');
        $this->db->queryBuilder()->table('authserver.group_roles')->insert(['groupid' => $groupId, 'roleid' => $roleId, 'is_active' => true]);
        $alice = $this->user('alice');
        $_GET['_option'] = $groupId;

        // Act — join and leave (each tells the applications), then delete the group.
        $_POST = ['user' => 'alice'];
        (new ProbeGroups())->addmember();
        $joined = $this->members($groupId);
        $_POST = ['userid' => $alice];
        (new ProbeGroups())->removemember();
        $this->join($alice, $groupId);
        (new ProbeGroups())->delete();

        // Assert
        $this->assertSame([$alice], $joined);
        $this->assertSame([], $this->groupRoles($groupId), 'the deleted group kept its roles');
        $this->assertSame([], $this->members($groupId));
    }

    /**
     * Without the roles store the page offers no roles, and giving one is refused.
     */
    #[DataProvider('databases')]
    public function testWithoutTheRolesStoreThereIsNoRolesSection(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $groupId = $this->group('Plain');
        $this->db->schema()->dropTableIfExists('authserver.group_roles');
        $this->probeChanged($type); // the next test rebuilds what this one dropped
        $_GET['_option'] = $groupId;

        // Act
        $page = new ProbeGroups();
        $page->view();
        $_POST = ['roleid' => 1];
        (new ProbeGroups())->addrole();
        (new ProbeGroups())->removerole();
        $withdrawMessages = $_SESSION['_messages'] ?? [];
        // A membership change here has no roles to report.
        $this->user('bob');
        $_POST = ['user' => 'bob'];
        (new ProbeGroups())->addmember();

        // Assert
        $this->assertFalse($page->lastView->rolesEnabled);
        $this->assertCount(1, $this->members($groupId));
        $this->assertContains('Role withdrawn from the group.', $withdrawMessages);
    }

    private function join(int $userId, int $groupId): void
    {
        $this->db->queryBuilder()->table('userstogroups')->insert(['userid' => $userId, 'groupid' => $groupId]);
    }
}
