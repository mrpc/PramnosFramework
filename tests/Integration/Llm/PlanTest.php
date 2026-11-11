<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Plan;
use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

require_once __DIR__ . '/ChatApiTest.php';

/**
 * A person's plan (FW-470), on MySQL and PostgreSQL.
 *
 * What has to hold: a project is added once, changed a field at a time and taken out whole —
 * and only one the plan has, so a change never lands on a project the person did not name; the
 * same plan is read by every persona and by the Ask; every question carries it a line a project;
 * it offers a weekday morning and a Sunday review; and it is there only where it is turned on.
 */
#[CoversClass(Plan::class)]
#[CoversClass(Routines::class)]
#[CoversClass(\Pramnos\Llm\Chat\FollowUps::class)]
#[CoversClass(\Pramnos\Llm\Chat\Audience::class)]
class PlanTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->configure(['chat' => ['plan' => true, 'memory' => ['learn' => false]]]);
    }

    protected function tearDown(): void
    {
        if ($this->users !== []) {
            $this->db->queryBuilder()->table('assist.chat_artifacts')->whereIn('userid', $this->users)->delete();
            $this->db->queryBuilder()->table('pramnos.ai_routines')->whereIn('userid', $this->users)->delete();
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->whereIn('userid', $this->users)->delete();
            $this->db->queryBuilder()->table('#PREFIX#usersettings')->whereIn('userid', $this->users)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $this->users)->delete();
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A person to keep a plan for. */
    private function user(): int
    {
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Core\CreateUsersettingsTable::class, \Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class], $this->db);
        $mark = 'pl' . bin2hex(random_bytes(4));
        $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark, 'email' => $mark . '@example.com', 'password' => 'x', 'regdate' => time()]);
        $id = (int) $this->db->getInsertId();
        $this->users[] = $id;
        \Pramnos\User\User::clearUserCache();

        return $id;
    }

    /**
     * Added, changed by its name whatever the case, refused for a project it does not have or a
     * phase that is not one, taken out with nothing kept; the same plan from another persona and
     * from the Ask; in the next question's context, a line a project.
     */
    #[DataProvider('databases')]
    public function testAProjectIsAddedChangedAndClosedByItsName(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $userId = $this->user();
        $scope  = new Scope($userId, null, Scope::CHAT, 'tesla');
        $tools  = (new Plan($scope, 'Europe/Athens'))->tools();
        $run    = static fn (string $tool, array $a): string => ($tools[$tool]['run'])($a);

        // Act
        $added    = $run('plan_add', ['project' => 'Garden', 'kind' => 'home', 'status' => 'beds dug']);
        $twice    = $run('plan_add', ['project' => 'garden']);
        $run('plan_add', ['project' => 'Novel', 'kind' => 'hobby', 'phase' => 'waiting']);
        $changed  = $run('plan_update', ['project' => 'GARDEN', 'field' => 'blockers', 'value' => 'rain']);
        $wrong    = $run('plan_update', ['project' => 'Gardn', 'field' => 'status', 'value' => 'x']);
        $badPhase = $run('plan_update', ['project' => 'Novel', 'field' => 'phase', 'value' => 'finished']);
        $badField = $run('plan_update', ['project' => 'Novel', 'field' => 'updated', 'value' => '2020-01-01']);
        $fromAsk  = (new Plan(new Scope($userId, null, Scope::ADMIN)))->projects();
        $this->answer([[200, self::says('Noted.')]]);
        (new Conversations(new BaseAssistant()))->as(new Scope($userId, null, Scope::CHAT))->ask(null, 'How is the garden?');
        $system   = self::systemOf($this->sent[0]);
        // Through the API, from another persona than the one that wrote it.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        ApiFixture::$scope = new Scope($userId, null, Scope::CHAT, 'naya');
        ApiFixture::$stop  = null;
        $router = new \Pramnos\Routing\Router(null);
        \Pramnos\Llm\Chat\Api\Routes::register($router, ApiFixture::class);
        $api    = $router->dispatch(\Pramnos\Http\Request::create('/chat/plan', 'GET'));
        ApiFixture::$scope = null;
        unset($_SERVER['REQUEST_METHOD']);
        $closed   = $run('plan_close', ['project' => 'novel']);
        $after    = (new Plan($scope))->projects();

        // Assert
        $this->assertSame('Added Garden to the plan.', $added);
        $this->assertStringStartsWith('Error: the plan has garden already', $twice, 'once');
        $this->assertSame('Changed blockers of Garden.', $changed, 'by its name, whatever the case');
        $this->assertSame('Error: the plan has no project "Gardn". It has: Garden, Novel. Ask which one they mean.', $wrong, 'never a project they did not name');
        $this->assertStringStartsWith('Error: phase is one of', $badPhase);
        $this->assertStringStartsWith('Error: a field is one of', $badField, 'the date is the plan\'s own');
        $this->assertSame(['Garden', 'Novel'], array_column($fromAsk, 'project'), 'the Ask reads the same plan as every persona');
        $this->assertSame(['rain', 'active', (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Athens')))->format('Y-m-d')], [$fromAsk[0]['blockers'], $fromAsk[0]['phase'], $fromAsk[0]['updated']]);
        $this->assertStringContainsString('- Garden (home) — active; beds dug; blocked by: rain; updated ', $system, 'a line a project in the question');
        $this->assertStringContainsString('When they tell you news about a project listed here, update it with plan_update', $system, 'news is the cue to change it');
        $offered = array_column(array_column((array) ($this->sent[0]['body']['tools'] ?? []), 'function'), 'description', 'name');
        $this->assertArrayHasKey('plan_update', $offered, 'with projects in it, changing one is at hand, not behind its skill');
        $this->assertStringContainsString('that goes to plan_update', (string) ($offered['remember'] ?? ''), 'news is not kept in memory too');
        $this->assertSame([200, ['Garden', 'Novel'], Plan::COLUMNS], [$api['status'], array_column($api['data']['projects'], 'project'), $api['data']['columns']], 'the same plan through the API, from any persona');
        $this->assertIsInt($api['data']['artifact_id']);
        $this->assertSame(['type' => 'board', 'by' => 'phase'], (new \Pramnos\Llm\Chat\Artifacts(new Scope($userId, null)))->find($api['data']['artifact_id'])['content']['view'] ?? null, 'a board by phase at first');
        $this->assertSame('Closed Novel; it is out of the plan.', $closed);
        $this->assertSame(['Garden'], array_column($after, 'project'), 'nothing of it kept');
    }

    /**
     * Only where it is turned on, and in a person's own conversations or the Ask; its routines —
     * a weekday morning and a Sunday evening — offered beside the application's own; a weekday
     * routine skips the weekend.
     */
    public function testWhereItIsOnAndWhatItOffers(): void
    {
        // Arrange
        $friday = (new \DateTimeImmutable('2026-11-13 11:00', new \DateTimeZone('UTC')))->getTimestamp();

        // Act
        $on        = [Plan::on(new Scope(5, null, Scope::CHAT, 'naya')), Plan::on(new Scope(5, null, Scope::ADMIN)), Plan::on(new Scope(5, null, 'support')), Plan::on(new Scope(0, null))];
        $templates = Routines::templates();
        $this->configure(['chat' => []]);
        $off       = [Plan::on(new Scope(5, null)), Routines::templates()];
        $next      = Routines::nextRun('weekdays', '10:00', null, 'UTC', $friday);

        // Assert
        $this->assertSame([true, true, false, false], $on, 'a person\'s conversations and the Ask; not another space, nor nobody');
        $this->assertSame(['weekdays', '10:00'], [$templates['plan_morning']['cadence'], $templates['plan_morning']['at']]);
        $this->assertSame(['weekly', 7, '20:00'], [$templates['plan_week']['cadence'], $templates['plan_week']['day'], $templates['plan_week']['at']]);
        $this->assertSame([false, []], [$off[0], array_intersect_key($off[1], Plan::TEMPLATES)], 'off: nothing');
        $this->assertSame('2026-11-16 10:00', gmdate('Y-m-d H:i', $next), 'after Friday\'s, Monday\'s: the weekend skipped');
    }

    /**
     * A check a few hours later (FW-471): one for a thing, never in the person's quiet hours —
     * moved to when they end — a few a day at most, counted by the day each is asked on, and taken
     * away when the person speaks of it first; on only in a person's own conversations.
     */
    #[DataProvider('databases')]
    public function testAFollowUpIsOneAThingOutOfQuietHoursAndForgottenWhenSaidFirst(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['follow_up' => ['daily' => 2], 'memory' => ['learn' => false]]]);
        $userId = $this->user();
        \Pramnos\Llm\Chat\Settings::change(new \Pramnos\User\User($userId), null, ['quiet' => ['from' => '22:00', 'to' => '07:00']]);
        \Pramnos\User\User::clearUserCache();
        $scope    = new Scope($userId, null, Scope::CHAT);
        $routines = new Routines(new Conversations(new BaseAssistant()));
        $follow   = new \Pramnos\Llm\Chat\FollowUps($routines, $scope, 'UTC');
        // Tomorrow at 21:00: two hours on is in the quiet hours.
        $evening  = (new \DateTimeImmutable('tomorrow 21:00', new \DateTimeZone('UTC')))->getTimestamp();
        $morning  = gmdate('Y-m-d', $evening + 86400) . ' 07:00';

        // Act
        $first    = $follow->schedule('the accounts', 2, $evening);
        $again    = $follow->schedule('The Accounts', 3, $evening);
        $second   = $follow->schedule('the shopping', 1, $evening);
        $third    = $follow->schedule('the garden', 1, $evening);
        $pending  = array_column(array_filter($routines->mine($scope), static fn (array $r): bool => str_starts_with($r['title'], 'Follow-up: ')), 'run_at', 'title');
        $context  = $follow->context();
        $taken    = $follow->heard('I finished the accounts already, by the way');
        $follow->schedule('την αναφορά', 1, $evening + 86400 * 2);
        $dropped  = $follow->cancel('ΤΗΝ αναφορά');
        $none     = $follow->cancel('the car');
        $after    = array_column(array_filter($routines->mine($scope), static fn (array $r): bool => str_starts_with($r['title'], 'Follow-up: ')), 'title');

        // Assert
        $this->assertSame('I will ask about the accounts at 07:00.', $first, 'quiet at 23:00: asked when the quiet hours end');
        $this->assertStringStartsWith('Error: a check on The Accounts is already set, for 07:00', $again, 'one for a thing');
        $this->assertSame('I will ask about the shopping at 07:00.', $second);
        $this->assertStringStartsWith('Error: 2 checks a day are enough', $third, 'a few a day, by the day they are asked on');
        $this->assertStringStartsWith($morning, (string) $pending['Follow-up: the accounts'], 'a one-off, the next morning');
        $this->assertSame("Checks you will make later (if they tell you how one went first, drop it with follow_up_cancel):\n- the accounts (at 07:00)\n- the shopping (at 07:00)", $context, 'the model sees what waits');
        $this->assertSame(1, $taken, 'said first: not asked');
        $this->assertSame('Dropped; it will not be asked.', $dropped, 'dropped by the model, in any words the person used');
        $this->assertSame('Error: there is no pending check on that.', $none);
        $this->assertSame(['Follow-up: the shopping'], $after);
        $this->assertSame([true, false], [\Pramnos\Llm\Chat\FollowUps::on($scope), \Pramnos\Llm\Chat\FollowUps::on(new Scope($userId, null, Scope::ADMIN))]);
        $this->assertSame(['follow_up', 'follow_up_cancel'], array_keys($follow->tools()));
        $this->assertStringContainsString('do not ask whether they want it', $follow->tools()['follow_up']['description']);
    }

    /**
     * Whom an answer is for (FW-450): a child speaking is handed none of an adult's tools — the plan,
     * the follow-ups — in code, and is spoken to by an allowlist; the skill catalogue holds only
     * the skills for that audience and age; an adult, or nobody said, as before; the application's
     * own word wins.
     */
    #[DataProvider('databases')]
    public function testAChildIsHandedNoneOfAnAdultsToolsOrSkills(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $dir = sys_get_temp_dir() . '/pf-audience-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/stories.md', "---\nname: stories\ndescription: Bedtime stories\naudience: child\nage: 3-7\n---\nTell a short story.");
        file_put_contents($dir . '/riddles.md', "---\nname: riddles\ndescription: Riddles for big kids\naudience: child\nage: 8-12\n---\nAsk a riddle.");
        file_put_contents($dir . '/work.md', "---\nname: office\ndescription: The office\naudience: adult\n---\nWork.");
        file_put_contents($dir . '/goodnight.md', "---\nname: goodnight\ndescription: Goodnight\n---\nSay goodnight.");
        $this->configure(['chat' => ['plan' => true, 'follow_up' => true, 'skills_path' => $dir, 'memory' => ['learn' => false]]]);
        $userId = $this->user();
        (new Plan(new Scope($userId, null)))->add(['project' => 'Garden']);
        $this->answer([[200, self::says('Hi!')], [200, self::says('Hi!')]]);
        $skillsOf = static function (array $request): string {
            foreach ((array) $request['body']['tools'] as $tool) {
                if ($tool['function']['name'] === 'use_skill') {
                    return (string) $tool['function']['description'];
                }
            }

            return '';
        };

        try {
            // Act
            (new Conversations(new BaseAssistant()))->as(new Scope($userId, null))->ask(null, 'Hi', null, ['speaker' => ['kind' => 'child', 'name' => 'Niko', 'age' => 5]]);
            (new Conversations(new BaseAssistant()))->as(new Scope($userId, null))->ask(null, 'Hi', null, ['speaker' => ['kind' => 'parent', 'name' => 'Maria']]);
        } finally {
            array_map('unlink', glob($dir . '/*.md') ?: []);
            rmdir($dir);
        }
        $this->configure(['chat' => ['audience' => static fn (Scope $scope, ?array $speaker): string => 'child']]);
        $hooked = \Pramnos\Llm\Chat\Audience::of(new Scope($userId, null), null);

        // Assert
        $child = array_map(static fn (array $t): string => $t['function']['name'], (array) $this->sent[0]['body']['tools']);
        $adult = array_map(static fn (array $t): string => $t['function']['name'], (array) $this->sent[1]['body']['tools']);
        $this->assertNotContains('plan_update', $child, 'not loaded at all for a child');
        $this->assertNotContains('follow_up', $child);
        $this->assertContains('plan_update', $adult, 'an adult as before');
        $this->assertStringContainsString('stories (Bedtime stories)', $skillsOf($this->sent[0]), 'a child\'s skill of their age');
        $this->assertStringNotContainsString('riddles', $skillsOf($this->sent[0]), 'not of another age');
        $this->assertStringNotContainsString('office', $skillsOf($this->sent[0]), 'nor an adult\'s');
        $this->assertStringContainsString('goodnight', $skillsOf($this->sent[0]), 'one for all, too');
        $this->assertStringContainsString('office', $skillsOf($this->sent[1]));
        $this->assertStringNotContainsString('stories', $skillsOf($this->sent[1]), 'a child\'s skill is not an adult\'s');
        $this->assertStringContainsString('You are speaking with a child.', self::systemOf($this->sent[0]), 'what may be said, an allowlist');
        $this->assertStringNotContainsString('You are speaking with a child.', self::systemOf($this->sent[1]));
        $this->assertSame('child', $hooked, 'the application\'s own word');
    }
}
