<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\StoredSkills;
use Pramnos\Llm\Chat\Web\ChatPageController;
use Pramnos\Tests\Unit\Llm\LlmTestCase;
use Pramnos\User\User;

/**
 * `chat/skills` — a person's skills, Ask's drafts, offers to a role and, for an administrator, the
 * roles' skills — drawn through each theme's real view, and its actions, on MySQL and PostgreSQL.
 *
 * What holds: a person sees their own, drafts marked, words escaped, and no role's section; an
 * administrator sees the roles' skills with the offers to approve and may write one for a role;
 * each action does what it says or says why not; a skill of somebody else's never opens to edit;
 * off, the page says so; nobody signed in is sent to sign in.
 */
#[CoversClass(ChatPageController::class)]
#[CoversClass(\Pramnos\Llm\Chat\Web\KeepsSkills::class)]
class SkillsPageTest extends LlmTestCase
{
    use ChatDatabase;

    private const THEMES = ['tailwind', 'bootstrap', 'plain-css'];

    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configure(['chat' => ['stored_skills' => true]]);
        $_POST = [];
        $_GET  = [];
    }

    protected function tearDown(): void
    {
        $ids = array_map(static fn (User $u): int => (int) $u->userid, $this->people);
        if ($ids !== [] && isset($this->db)) {
            $this->db->queryBuilder()->table(StoredSkills::TABLE)->whereIn('userid', $ids)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $ids)->delete();
        }
        User::clearUserCache();
        $_POST = [];
        $_GET  = [];
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** Connect, with the table and an administrator and a member of staff. */
    private function arrange(string $type, string $host, int $port): void
    {
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Llm\CreateChatSkillsTable::class], $this->db);
        foreach (['admin' => 90, 'staff' => 10] as $role => $usertype) {
            $name = 'skillspage_' . $role . '_' . bin2hex(random_bytes(3));
            $user = new User();
            $user->username = $name;
            $user->email    = $name . '@example.com';
            $user->usertype = $usertype;
            $user->save();
            $this->people[$role] = $user;
        }
        User::clearUserCache();
    }

    /** The page as one of the people, in a theme. */
    private function page(string $who, string $theme = 'tailwind'): PageFixture
    {
        return new PageFixture(new Scope((int) $this->people[$who]->userid, null), $theme);
    }

    /**
     * Each theme lists a person's own skills, a draft marked, their words escaped, and no role's
     * section; an administrator sees the roles' skills, an offer with Approve, and the "For" choice.
     */
    #[DataProvider('databases')]
    public function testThePageInEachTheme(string $type, string $host, int $port): void
    {
        // Arrange
        $this->arrange($type, $host, $port);
        $store = new StoredSkills($this->db);
        $own   = (int) $store->save($this->people['staff'], ['name' => 'weekly', 'description' => 'Sales <weekly>', 'tools' => 'sql', 'body' => 'Do <b>this</b>']);
        $store->propose((int) $this->people['staff']->userid, ['name' => 'drafted', 'description' => 'From a talk', 'body' => 'x']);
        $store->share($this->people['staff'], $own, '10');

        foreach (self::THEMES as $theme) {
            // Act
            $staff = (string) $this->page('staff', $theme)->skills();
            $admin = (string) $this->page('admin', $theme)->skills();

            // Assert
            $this->assertStringContainsString('Sales &lt;weekly&gt;', $staff, $theme . ': escaped');
            $this->assertStringContainsString('Do &lt;b&gt;this&lt;/b&gt;', $staff, $theme . ': the method escaped');
            $this->assertStringContainsString('Keep and turn on', $staff, $theme . ': a draft is kept by turning it on');
            $this->assertStringContainsString('Offered to a role', $staff, $theme);
            $this->assertStringNotContainsString('Roles&#039; skills', $staff, $theme . ': not an administrator');
            $this->assertStringNotContainsString('name="for"', $staff, $theme);
            $this->assertStringContainsString('Roles&#039; skills', $admin, $theme);
            $this->assertStringContainsString('Approve', $admin, $theme . ': the offer waits for them');
            $this->assertStringContainsString('name="for"', $admin, $theme);
        }
    }

    /**
     * The actions: save a person's own, write a role's as an administrator — refused to anybody
     * else — turn one on and off, offer it, approve and turn down offers, and delete; a skill that
     * is not theirs is "no such skill", and opens to nobody else's edit.
     */
    #[DataProvider('databases')]
    public function testTheActions(string $type, string $host, int $port): void
    {
        // Arrange
        $this->arrange($type, $host, $port);
        $store = new StoredSkills($this->db);
        $staff = $this->page('staff');
        $admin = $this->page('admin');

        // Act — save, then a role's by each of them
        $_POST = ['name' => 'notes', 'description' => 'Notes', 'tools' => 'sql', 'body' => 'Write them down.', 'for' => 'user'];
        $staff->skillsave();
        $id = $store->mine((int) $this->people['staff']->userid)[0]['id'];
        $_POST = ['name' => 'support', 'description' => 'Support', 'body' => 'Be kind.', 'for' => 'role:10'];
        $staff->skillsave();
        $admin->skillsave();

        // Assert
        $this->assertSame(['Saved.', 'Saved.'], array_merge($staff->messages, $admin->messages));
        $this->assertSame(["Only an administrator writes a role's skills."], $staff->errors);
        $this->assertArrayHasKey('role:support', $store->forReader((int) $this->people['staff']->userid, false));

        // Act — off, offer, approve; offer again under another name and turn it down; delete
        $_POST = ['skill_id' => (string) $id];
        $staff->skillturn();
        $_POST = ['skill_id' => (string) $id, 'role' => '10'];
        $staff->skillshare();
        $offer = $store->mine((int) $this->people['staff']->userid)[0]['id'];
        $_POST = ['skill_id' => (string) $offer];
        $staff->skillapprove();
        $admin->skillapprove();
        $_POST = ['skill_id' => (string) $id];
        $admin->skilldelete();
        $staff->skilldelete();

        // Assert
        $this->assertSame(StoredSkills::OFF, $store->find($id) === null ? StoredSkills::OFF : $store->find($id)['status']);
        $this->assertContains('Offered. An administrator approves it before the role has it.', $staff->messages);
        $this->assertContains('There is no such skill.', $staff->errors, 'staff does not approve');
        $this->assertContains('Approved.', $admin->messages);
        $this->assertContains('There is no such skill.', $admin->errors, 'an administrator does not delete a person\'s own');
        $this->assertContains('Deleted.', $staff->messages);
        $this->assertNull($store->find($id));
        $this->assertArrayHasKey('role:notes', $store->forReader((int) $this->people['staff']->userid, false));

        // Act — turn down an offer; an edit form opens only for its owner
        $other = (int) $store->save($this->people['staff'], ['name' => 'seo', 'description' => 'SEO', 'body' => 'Titles first.']);
        $_POST = ['skill_id' => (string) $other, 'role' => '10'];
        $staff->skillshare();
        $_POST = ['skill_id' => (string) $store->mine((int) $this->people['staff']->userid)[0]['id']];
        $admin->skillreject();
        $_POST = [];
        $_GET  = ['skill' => (string) $other];
        $mine  = (string) $this->page('staff')->skills();
        $not   = (string) $this->page('admin')->skills();

        // Assert
        $this->assertContains('Turned down.', $admin->messages);
        $this->assertStringContainsString('Edit a skill', $mine);
        $this->assertStringContainsString('Titles first.', $mine);
        $this->assertStringNotContainsString('Edit a skill', $not, 'somebody else\'s does not open');
        $this->assertStringEndsWith('chat/skills', end($staff->redirectedTo));
    }

    /** Off, the page says so and lists nothing; nobody signed in is sent to sign in. */
    #[DataProvider('databases')]
    public function testOffOrSignedOut(string $type, string $host, int $port): void
    {
        // Arrange
        $this->arrange($type, $host, $port);
        $this->configure(['chat' => []]);
        $nobody = new PageFixture(null, 'tailwind');

        // Act
        $html = (string) $this->page('staff')->skills();
        $nobody->skills();
        $nobody->skillsave();
        $nobody->skillshare();
        $nobody->skillturn();

        // Assert
        $this->assertStringContainsString('Skills are not turned on here.', $html);
        $this->assertStringNotContainsString('data-pf-skill-form', $html);
        $this->assertCount(4, $nobody->redirectedTo, 'every one sent to sign in');
        $this->assertStringContainsString('chat', $nobody->redirectedTo[0]);
    }

    /**
     * A skill's tools are chosen from those the person's Ask has, not typed; and the roles a skill
     * may be for are those that reach this Ask — `skills_roles` narrows them to exactly the listed.
     */
    #[DataProvider('databases')]
    public function testToolsAreChosenAndRolesAreThoseThatReachAsk(string $type, string $host, int $port): void
    {
        // Arrange
        $this->arrange($type, $host, $port);
        $this->configure(['chat' => ['stored_skills' => true, 'skills_roles' => [90]]]);

        // Act
        $html = (string) $this->page('admin')->skills();

        // Assert
        $this->assertStringContainsString('data-pf-skill-tools', $html, 'a list to choose from');
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="tools\[\]" value="[a-z_]+"/', $html);
        $this->assertStringContainsString('value="role:90"', $html, 'the role listed');
        $this->assertStringNotContainsString('value="role:10"', $html, 'not one the application left out');
    }
}
