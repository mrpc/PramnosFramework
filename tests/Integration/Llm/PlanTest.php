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
}
