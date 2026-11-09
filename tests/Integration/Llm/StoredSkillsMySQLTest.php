<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Migrations\Llm\CreateChatSkillsTable;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\StoredSkills;
use Pramnos\Llm\Chat\Turn;
use Pramnos\User\User;

/**
 * Skills of a role and of a person, against the real database — MySQL here, PostgreSQL in the
 * sibling.
 *
 * What holds: a person's own skill is offered in their own conversations only, and only while it
 * is on — never a draft Ask proposed, never in an answer somebody else reads or a scheduled run;
 * a role's is offered to everybody from that role up, its least privileged reader deciding in a
 * shared answer; only an administrator writes a role's skill or approves one offered to a role,
 * and an offer is nobody's until approved; nobody changes or deletes another person's skill.
 */
#[CoversClass(StoredSkills::class)]
#[CoversClass(CreateChatSkillsTable::class)]
class StoredSkillsMySQLTest extends TestCase
{
    protected Database $db;

    /** @var array<string, mixed> */
    private array $info = [];

    /** @var array<string, User> The people the tests act as, by role */
    private array $people = [];

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $this->db = $this->connect();
        User::setupDb();
        \Pramnos\Framework\Testing\Schema::ensure([CreateChatSkillsTable::class], $this->db);
        $this->info = Application::getInstance()->applicationInfo;
        Application::getInstance()->applicationInfo['llm'] = ['chat' => ['stored_skills' => true]];
        foreach (['admin' => 90, 'manager' => 80, 'staff' => 10] as $role => $type) {
            $this->people[$role] = $this->person($role, $type);
        }
    }

    protected function tearDown(): void
    {
        $ids = array_map(static fn (User $u): int => (int) $u->userid, $this->people);
        if ($ids !== []) {
            $this->db->queryBuilder()->table(StoredSkills::TABLE)->whereIn('userid', $ids)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $ids)->delete();
        }
        User::clearUserCache();
        Application::getInstance()->applicationInfo = $this->info;
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

    /** A saved account of a usertype. */
    private function person(string $role, int $type): User
    {
        $name = 'skills_' . $role . '_' . bin2hex(random_bytes(3));
        $user = new User();
        $user->username = $name;
        $user->email    = $name . '@example.com';
        $user->usertype = $type;
        $user->save();
        User::clearUserCache();

        return $user;
    }

    /** A skill's input. */
    private static function input(string $name, array $more = []): array
    {
        return $more + ['name' => $name, 'description' => 'For ' . $name, 'tools' => 'sql, export_csv', 'body' => 'Step one. Step two.'];
    }

    /** A turn of a person's conversation, optionally read by somebody else too, or scheduled. */
    private function turn(User $asker, ?User $reader = null, bool $scheduled = false): Turn
    {
        $conversations = (new \ReflectionClass(Conversations::class))->newInstanceWithoutConstructor();

        return new Turn(new Scope((int) $asker->userid, null, Scope::CHAT), null, $scheduled, [], [],
            $reader !== null ? ['rights_of' => (int) $reader->userid] : [], $conversations);
    }

    /**
     * A person's own skill is offered in their own conversation, named `mine:`, its tools as a
     * list and its method marked as theirs — and to nobody else, nor while it is off.
     */
    public function testAPersonsOwnSkillIsOfferedToThemAlone(): void
    {
        // Arrange
        $store = new StoredSkills($this->db);
        $id    = $store->save($this->people['staff'], self::input('weekly-sales'));

        // Act
        $mine   = $store->forTurn($this->turn($this->people['staff']));
        $theirs = $store->forTurn($this->turn($this->people['manager']));
        $store->turn($this->people['staff'], (int) $id, false);
        $off    = $store->forTurn($this->turn($this->people['staff']));

        // Assert
        $this->assertIsInt($id);
        $this->assertSame(['mine:weekly-sales'], array_keys($mine));
        $this->assertSame(['sql', 'export_csv'], $mine['mine:weekly-sales']['tools']);
        $this->assertStringContainsString('wrote for themselves', $mine['mine:weekly-sales']['instructions'], 'marked as the person\'s own');
        $this->assertStringContainsString('Step one.', $mine['mine:weekly-sales']['instructions']);
        $this->assertSame([], $theirs, 'nobody else is offered it');
        $this->assertSame([], $off, 'not while it is off');
    }

    /**
     * In an answer somebody else reads too, or a scheduled run, a person's own skill is not
     * offered: only a role's, and the role of the least privileged reader.
     */
    public function testASharedOrScheduledAnswerTakesOnlyTheReadersRolesSkills(): void
    {
        // Arrange
        $store = new StoredSkills($this->db);
        $store->save($this->people['manager'], self::input('my-way'));
        $store->save($this->people['admin'], self::input('managers-way', ['level' => StoredSkills::ROLE, 'role' => '80']));

        // Act
        $own       = array_keys($store->forTurn($this->turn($this->people['manager'])));
        $withStaff = array_keys($store->forTurn($this->turn($this->people['manager'], $this->people['staff'])));
        $withAdmin = array_keys($store->forTurn($this->turn($this->people['manager'], $this->people['admin'])));
        $scheduled = array_keys($store->forTurn($this->turn($this->people['manager'], null, true)));

        // Assert
        $this->assertSame(['mine:my-way', 'role:managers-way'], $own);
        $this->assertSame([], $withStaff, 'a staff reader holds no role\'s skill, and the asker\'s own stays out');
        $this->assertSame(['role:managers-way'], $withAdmin, 'the reader\'s roles only');
        $this->assertSame(['role:managers-way'], $scheduled, 'nobody typed a scheduled run: no own skill');
    }

    /**
     * Only an administrator writes a role's skill; it reaches everybody from that role up and
     * nobody below.
     */
    public function testOnlyAnAdministratorWritesARolesSkill(): void
    {
        // Arrange
        $store = new StoredSkills($this->db);

        // Act
        $refused = $store->save($this->people['manager'], self::input('support', ['level' => StoredSkills::ROLE, 'role' => '80']));
        $noRole  = $store->save($this->people['admin'], self::input('support', ['level' => StoredSkills::ROLE, 'role' => 'nobody']));
        $made    = $store->save($this->people['admin'], self::input('support', ['level' => StoredSkills::ROLE, 'role' => '80']));

        // Assert
        $this->assertSame('Only an administrator writes a role\'s skills.', $refused);
        $this->assertSame('Choose the role it is for.', $noRole, 'a role nobody defined');
        $this->assertIsInt($made);
        $this->assertArrayHasKey('role:support', $store->forReader((int) $this->people['manager']->userid, false));
        $this->assertArrayHasKey('role:support', $store->forReader((int) $this->people['admin']->userid, false));
        $this->assertSame([], $store->forReader((int) $this->people['staff']->userid, false), 'below the role');
    }

    /**
     * A skill offered to a role is a copy waiting for an administrator: nobody's until approved,
     * then the role's; turned down, the offer goes and its author's own stays.
     */
    public function testAnOfferToARoleWaitsForAnAdministrator(): void
    {
        // Arrange
        $store = new StoredSkills($this->db);
        $own   = (int) $store->save($this->people['manager'], self::input('pricing'));
        $other = (int) $store->save($this->people['manager'], self::input('seo'));

        // Act
        $offer    = $store->share($this->people['manager'], $own, '80');
        $waiting  = $store->forReader((int) $this->people['admin']->userid, false);
        $byPeer   = $store->approve($this->people['manager'], (int) $offer);
        $approved = $store->approve($this->people['admin'], (int) $offer);
        $second   = (int) $store->share($this->people['manager'], $other, '80');
        $rejected = $store->reject($this->people['admin'], $second);

        // Assert
        $this->assertIsInt($offer);
        $this->assertSame([], $waiting, 'an offer is nobody\'s yet');
        $this->assertFalse($byPeer, 'only an administrator approves');
        $this->assertTrue($approved);
        $this->assertArrayHasKey('role:pricing', $store->forReader((int) $this->people['manager']->userid, false));
        $this->assertTrue($rejected);
        $this->assertNull($store->find($second), 'the offer turned down is gone');
        $this->assertNotNull($store->find($other), 'its author\'s own stays');
        $this->assertSame('There is no such skill.', $store->share($this->people['staff'], $own, '80'), 'only its author offers it');
    }

    /**
     * Ask's proposal from a conversation is a draft: kept, not offered, until its person turns it
     * on; the tool reports the draft, and an invalid one says why.
     */
    public function testAProposalIsADraftUntilItsPersonTurnsItOn(): void
    {
        // Arrange
        $store = new StoredSkills($this->db);
        $scope = new Scope((int) $this->people['staff']->userid, null, Scope::CHAT);
        $tool  = $store->tools($scope)['propose_skill']['run'];

        // Act
        $said    = $tool(['name' => 'Monthly-Close', 'description' => 'Closing the month', 'tools' => ['sql'], 'instructions' => 'Reconcile first.']);
        $bad     = $tool(['name' => 'no spaces allowed', 'description' => 'x', 'instructions' => 'y']);
        $before  = $store->forTurn($this->turn($this->people['staff']));
        $draft   = $store->mine((int) $this->people['staff']->userid)[0];
        $store->turn($this->people['staff'], $draft['id'], true);
        $after   = $store->forTurn($this->turn($this->people['staff']));

        // Assert
        $this->assertStringStartsWith('Kept as a draft', $said);
        $this->assertStringStartsWith('Error: ', $bad);
        $this->assertSame(StoredSkills::DRAFT, $draft['status']);
        $this->assertSame('monthly-close', $draft['name'], 'names are kept lowercase');
        $this->assertSame([], $before, 'a draft is not offered');
        $this->assertSame(['mine:monthly-close'], array_keys($after));
    }

    /**
     * Names are unique among a person's own and among the roles', and a skill needs its fields;
     * nobody edits, turns or deletes another person's.
     */
    public function testNamesAreUniqueAndOnlyItsOwnerChangesASkill(): void
    {
        // Arrange
        $store = new StoredSkills($this->db);
        $id    = (int) $store->save($this->people['staff'], self::input('notes'));

        // Act
        $again   = $store->save($this->people['staff'], self::input('notes'));
        $empty   = $store->save($this->people['staff'], ['name' => 'empty']);
        $theirs  = $store->save($this->people['manager'], self::input('notes'));
        $edit    = $store->save($this->people['manager'], ['body' => 'Mine now.'], $id);
        $turned  = $store->turn($this->people['manager'], $id, false);
        $deleted = $store->delete($this->people['manager'], $id);
        $renamed = $store->save($this->people['staff'], ['description' => 'Changed'], $id);
        $gone    = $store->delete($this->people['staff'], $id);

        // Assert
        $this->assertSame('A skill of that name exists already.', $again);
        $this->assertSame('A skill needs a description and a method.', $empty);
        $this->assertIsInt($theirs, 'another person may have one of the same name');
        $this->assertSame('There is no such skill.', $edit);
        $this->assertFalse($turned);
        $this->assertFalse($deleted);
        $this->assertSame($id, $renamed, 'its owner edits it');
        $this->assertTrue($gone);
        $this->assertNull($store->find($id));
    }

    /** Off unless the application turns them on: nothing offered, no tool. */
    public function testOffNothingIsOfferedAndNoToolIsGiven(): void
    {
        // Arrange
        $store = new StoredSkills($this->db);
        $store->save($this->people['staff'], self::input('anything'));
        Application::getInstance()->applicationInfo['llm'] = [];

        // Act
        $offered = $store->forTurn($this->turn($this->people['staff']));
        $tools   = $store->tools(new Scope((int) $this->people['staff']->userid, null, Scope::CHAT));

        // Assert
        $this->assertSame([], $offered);
        $this->assertSame([], $tools);
        $this->assertFalse(StoredSkills::enabled());
    }

    /**
     * The roles' list for administrators puts offers waiting first; an administrator edits and
     * deletes a role's; an offer to a role nobody defined, or under a role's name in use, is
     * refused; a status nobody may set falls back to on; an unknown reader is offered nothing.
     */
    public function testAdministratorsListAndChangeTheRolesSkills(): void
    {
        // Arrange
        $store = new StoredSkills($this->db);
        $role  = (int) $store->save($this->people['admin'], self::input('zeta', ['level' => StoredSkills::ROLE, 'role' => '80', 'status' => 'pending']));
        $own   = (int) $store->save($this->people['manager'], self::input('zeta'));
        $alpha = (int) $store->save($this->people['manager'], self::input('alpha'));
        $offer = (int) $store->share($this->people['manager'], $alpha, '80');

        // Act
        $listed  = array_map(static fn (array $s): string => $s['name'] . ':' . $s['status'], $store->roles());
        $badRole = $store->share($this->people['manager'], $own, 'nobody');
        $taken   = $store->share($this->people['manager'], $own, '80');
        $edited  = $store->save($this->people['admin'], ['description' => 'Changed'], $role);
        $deleted = $store->delete($this->people['admin'], $role);

        // Assert
        $this->assertSame(['alpha:pending', 'zeta:on'], $listed, 'offers first; a status nobody may set is on');
        $this->assertGreaterThan(0, $offer);
        $this->assertSame('Choose the role it is for.', $badRole);
        $this->assertSame('A role\'s skill of that name exists already.', $taken);
        $this->assertSame($role, $edited);
        $this->assertTrue($deleted);
        $this->assertSame([], $store->forReader(0, true), 'nobody');
        $this->assertSame([], $store->forReader(987654321, true), 'an account that does not exist');
    }

    /**
     * The skills under the chat API: listed, kept, changed, turned off, offered to a role and
     * approved by an administrator, deleted — as the skills page does, in the API's shape; a
     * skill not one's own a 404, an invalid one a 422.
     */
    public function testTheSkillsUnderTheChatApi(): void
    {
        // Arrange
        $as = function (string $role, string $method, array $body = [], mixed ...$args): array {
            \Pramnos\Http\RequestIdentity::seal($this->people[$role], 'test');
            // As a request's JSON body arrives: in $_POST, where the Request puts it.
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = $body;
            \Pramnos\Http\Request::setRawInput((string) json_encode($body));
            \Pramnos\Http\Request::resetInstance();

            return (new \Pramnos\Llm\Chat\Api\ChatController())->{$method}(...$args);
        };

        try {
            // Act
            $made     = $as('manager', 'skillSave', self::input('pricing', ['for' => 'user']));
            $id       = (int) ($made['data']['skill']['id'] ?? 0);
            $invalid  = $as('manager', 'skillSave', ['name' => '']);
            $changed  = $as('manager', 'skillSave', self::input('pricing', ['description' => 'Prices, again']), $id);
            $off      = $as('manager', 'skillTurn', ['on' => false], $id);
            $notMine  = $as('staff', 'skillDelete', [], $id);
            $offered  = $as('manager', 'skillShare', ['role' => '80'], $id);
            $byPeer   = $as('manager', 'skillApprove', [], (int) ($offered['data']['offered'] ?? 0));
            $approved = $as('admin', 'skillApprove', [], (int) ($offered['data']['offered'] ?? 0));
            $list     = $as('manager', 'skillList');
            $deleted  = $as('manager', 'skillDelete', [], $id);
        } finally {
            \Pramnos\Http\RequestIdentity::reset();
            \Pramnos\Http\Request::setRawInput('');
            $_POST = [];
        }

        // Assert
        $this->assertSame([201, 'pricing'], [$made['status'], $made['data']['skill']['name']]);
        $this->assertSame(422, $invalid['status']);
        $this->assertSame([200, 'Prices, again'], [$changed['status'], $changed['data']['skill']['description']]);
        $this->assertSame([200, false], [$off['status'], $off['data']['on']]);
        $this->assertSame(404, $notMine['status'], 'not hers to delete');
        $this->assertSame(201, $offered['status']);
        $this->assertSame([404, 200], [$byPeer['status'], $approved['status']], 'only an administrator approves');
        $this->assertTrue($list['data']['enabled']);
        $this->assertContains('pricing', array_column($list['data']['offered'], 'name'), 'the role\'s, approved');
        $this->assertSame(200, $deleted['status']);
    }
}
