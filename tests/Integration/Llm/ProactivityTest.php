<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\CallLog;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Companion\Calendar;
use Pramnos\Llm\Companion\Companion;
use Pramnos\Llm\Llm;
use Pramnos\Llm\Policy\Proactivity;
use Pramnos\Llm\Policy\QuietProactivity;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * The companion's manners — when it may reach out on its own — on MySQL and PostgreSQL.
 *
 * What holds: nothing in quiet hours, held until they end; no more than the day's pings; nothing
 * while the person is in an appointment or said they are busy; an alarm whatever the rules; a
 * scheduled question that is held costs no model call and is asked later; an appointment's
 * reminder goes once, or is passed without counting; and none of it touches an application that
 * has not turned the companion on.
 */
#[CoversClass(QuietProactivity::class)]
#[CoversClass(Companion::class)]
#[CoversClass(\Pramnos\Llm\Chat\Routines::class)]
class ProactivityTest extends LlmTestCase
{
    use ChatDatabase;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
    }

    protected function tearDown(): void
    {
        if ($this->users !== []) {
            $this->db->queryBuilder()->table('#PREFIX#usersettings')->whereIn('userid', $this->users)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $this->users)->delete();
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** Connect, and a person whose settings can be kept. */
    private function person(string $type, string $host, int $port): int
    {
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Core\CreateUsersettingsTable::class], $this->db);
        $mark = 'pr' . bin2hex(random_bytes(4));
        $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark, 'email' => $mark . '@example.com', 'password' => 'x', 'regdate' => time()]);
        $id = (int) $this->db->getInsertId();
        $this->users[] = $id;
        \Pramnos\User\User::clearUserCache();

        return $id;
    }

    /** A moment on Athens' clock. */
    private static function athens(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Europe/Athens')))->getTimestamp();
    }

    /**
     * Quiet hours, across midnight: at 23:00 and at 06:00 a ping waits for 07:30; at noon it goes.
     * Configured hours are read, an empty span turns them off, and an alarm goes regardless.
     */
    #[DataProvider('databases')]
    public function testQuietHours(string $type, string $host, int $port): void
    {
        // Arrange
        $userId = $this->person($type, $host, $port);
        $policy = new QuietProactivity();

        // Act / Assert
        $this->assertSame(self::athens('2026-10-21 07:30'), $policy->when($userId, 'Europe/Athens', self::athens('2026-10-20 23:00')));
        $this->assertSame(self::athens('2026-10-21 07:30'), $policy->when($userId, 'Europe/Athens', self::athens('2026-10-21 06:00')));
        $this->assertSame(self::athens('2026-10-21 12:00'), $policy->when($userId, 'Europe/Athens', self::athens('2026-10-21 12:00')));
        $this->assertSame(self::athens('2026-10-20 23:00'), $policy->when($userId, 'Europe/Athens', self::athens('2026-10-20 23:00'), critical: true), 'an alarm');
        $this->configure(['proactivity' => ['quiet' => '13:00-14:00']]);
        $this->assertSame(self::athens('2026-10-21 14:00'), $policy->when($userId, 'Europe/Athens', self::athens('2026-10-21 13:30')), 'within a day');
        $this->assertSame(self::athens('2026-10-20 23:00'), $policy->when($userId, 'Europe/Athens', self::athens('2026-10-20 23:00')), 'outside them');
        $this->configure(['proactivity' => ['quiet' => '']]);
        $this->assertSame(self::athens('2026-10-21 03:00'), $policy->when($userId, 'Europe/Athens', self::athens('2026-10-21 03:00')), 'none');
    }

    /**
     * The day's limit counts the questions it asked today and the reminders sent today — not a
     * reminder that was passed — and a higher limit lets one more go.
     */
    #[DataProvider('databases')]
    public function testTheDaysLimit(string $type, string $host, int $port): void
    {
        // Arrange — two questions asked today, one reminder sent, one passed
        $userId = $this->person($type, $host, $port);
        $now    = time();
        $zone   = 'UTC';
        $this->configure(['proactivity' => ['quiet' => '']]);
        $this->db->queryBuilder()->table('ai_routines')->insert(['userid' => $userId, 'space' => 'chat', 'kind' => 'ask', 'title' => 'Morning', 'prompt' => 'p',
            'cadence' => 'daily', 'at' => '08:00', 'timezone' => 'UTC', 'is_active' => true, 'created_at' => CallLog::instant($now), 'updated_at' => CallLog::instant($now)]);
        $routineId = (int) $this->db->getInsertId();
        foreach (['ok', 'ok', 'failed'] as $status) {
            $this->db->queryBuilder()->table('ai_task_runs')->insert(['routine_id' => $routineId, 'kind' => 'ask', 'status' => $status, 'started_at' => CallLog::instant($now - 60)]);
        }
        $calendar = new Calendar(new Scope($userId, null), $zone);
        $sent     = $calendar->create('A', gmdate('Y-m-d H:i', $now + 7200))['created']['id'];
        $passed   = $calendar->create('B', gmdate('Y-m-d H:i', $now + 14400))['created']['id'];
        Calendar::alerted($sent);
        Calendar::alerted($passed, false);

        // Act
        $three = (new QuietProactivity())->when($userId, $zone, $now);
        $this->configure(['proactivity' => ['quiet' => '', 'daily' => 4]]);
        $four  = (new QuietProactivity())->when($userId, $zone, $now);

        // Assert
        $this->assertSame(0, $three, 'two asked and one reminded make three');
        $this->assertSame($now, $four);
    }

    /**
     * Busy: in an appointment now, or by the person's word through set_busy until a time — and
     * not once they say they no longer are. A bad time is refused.
     */
    #[DataProvider('databases')]
    public function testBusy(string $type, string $host, int $port): void
    {
        // Arrange
        $userId = $this->person($type, $host, $port);
        $this->configure(['proactivity' => ['quiet' => ''], 'chat' => ['companion' => true]]);
        $scope  = new Scope($userId, null);
        $tools  = (new Companion($scope, 'UTC'))->tools();
        $now    = time();
        $policy = new QuietProactivity();

        // Act / Assert — by their word
        $this->assertSame('Busy until ' . gmdate('Y-m-d H:i', $now + 3600) . '.', ($tools['set_busy']['run'])(['until' => gmdate('Y-m-d H:i', $now + 3600)]));
        \Pramnos\User\User::clearUserCache();
        $this->assertSame(0, $policy->when($userId, 'UTC', $now));
        $this->assertSame('No longer busy.', ($tools['set_busy']['run'])([]));
        \Pramnos\User\User::clearUserCache();
        $this->assertSame($now, $policy->when($userId, 'UTC', $now));
        $this->assertSame('Error: a time is Y-m-d H:i.', ($tools['set_busy']['run'])(['until' => 'later']));

        // In an appointment
        (new Calendar($scope, 'UTC'))->create('Meeting', gmdate('Y-m-d H:i', $now - 600), gmdate('Y-m-d H:i', $now + 600));
        $this->assertSame(0, $policy->when($userId, 'UTC', $now));
    }

    /**
     * A scheduled question the policy holds is not asked — no model call — and waits: until the
     * moment given, or its next turn; a one-off is tried in an hour. Without the companion the
     * policy is never asked.
     */
    #[DataProvider('databases')]
    public function testAHeldQuestionIsNotAsked(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $now     = time();
        $routines = new RecordingRoutines(new Conversations(new BaseAssistant()));
        $scope   = new Scope(7, null);
        $daily   = $routines->create($scope, ['title' => 'Morning', 'prompt' => 'How did you sleep?', 'cadence' => 'daily', 'at' => '08:00']);
        $once    = $routines->create($scope, ['title' => 'Once', 'prompt' => 'Check in', 'cadence' => 'once', 'in_minutes' => 60]);
        $due     = fn (int $id) => $this->db->queryBuilder()->table('ai_routines')->where('routine_id', $id)->update(['next_run_at' => CallLog::instant($now - 60)]);
        $next    = fn (int $id): int => (int) strtotime((string) $routines->find($scope, $id)['next_run_at'] . (str_contains((string) $routines->find($scope, $id)['next_run_at'], '+') ? '' : ' UTC'));
        $policy  = new class implements Proactivity {
            public int $answer = 0;
            public int $asked = 0;
            public function when(int $userId, string $timezone, int $now, bool $critical = false): int
            {
                $this->asked++;

                return $this->answer;
            }
        };
        Llm::use('proactivity', $policy);
        $this->configure(['chat' => ['companion' => true]]);
        Llm::use('proactivity', $policy);

        // Act — not this time
        $due($daily['routine_id']);
        $due($once['routine_id']);
        $ranDropped = $routines->runDue($now);
        $dailyNext  = $next($daily['routine_id']);
        $onceNext   = $next($once['routine_id']);
        // Held until a moment
        $policy->answer = $now + 5000;
        $due($daily['routine_id']);
        $this->db->queryBuilder()->table('ai_routines')->where('routine_id', $once['routine_id'])->update(['is_active' => false]);
        $routines->runDue($now);
        $heldNext = $next($daily['routine_id']);

        // Assert
        $this->assertSame(0, $ranDropped);
        $this->assertSame([], $this->sent, 'no model call for a held question');
        $this->assertGreaterThan($now + 3600, $dailyNext, 'its next turn, tomorrow\'s 08:00 or later today');
        $this->assertSame($now + 3600, $onceNext, 'a one-off in an hour');
        $this->assertSame($now + 5000, $heldNext);
        $this->assertNull($this->db->queryBuilder()->table('ai_routines')->where('routine_id', $daily['routine_id'])->first()->fields['locked_until'], 'let go of');

        // Without the companion the policy is not asked, and the question is
        $this->configure([]);
        Llm::use('proactivity', $policy);
        $asked = $policy->asked;
        $this->answer([[200, self::says('Good morning')]]);
        $due($daily['routine_id']);
        $this->assertSame(1, $routines->runDue($now));
        $this->assertSame($asked, $policy->asked);
    }

    /**
     * A policy that throws does not silence the person's own schedule: the question is asked.
     */
    #[DataProvider('databases')]
    public function testAPolicyThatFailsLetsTheQuestionGo(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $routines = new RecordingRoutines(new Conversations(new BaseAssistant()));
        $made     = $routines->create(new Scope(7, null), ['title' => 'Morning', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $this->db->queryBuilder()->table('ai_routines')->where('routine_id', $made['routine_id'])->update(['next_run_at' => CallLog::instant(time() - 60)]);
        $this->configure(['chat' => ['companion' => true]]);
        Llm::use('proactivity', new class implements Proactivity {
            public function when(int $userId, string $timezone, int $now, bool $critical = false): int
            {
                throw new \RuntimeException('no settings');
            }
        });
        $this->answer([[200, self::says('Hello')]]);

        // Act
        $ran = $routines->runDue(time());

        // Assert
        $this->assertSame(1, $ran);
    }

    /**
     * An appointment's reminder goes once, with the minutes left; one the policy holds is passed —
     * marked, never sent, not counted — and without the companion nothing is sent.
     */
    #[DataProvider('databases')]
    public function testRemindersBeforeAppointments(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['companion' => true], 'proactivity' => ['quiet' => '']]);
        // On the minute, so "in 20 minutes" is exact.
        $now  = time() - time() % 60;
        $soon = (new Calendar(new Scope(7, null), 'UTC'))->create('Giorgos', gmdate('Y-m-d H:i', $now + 20 * 60))['created']['id'];
        $told = [];
        $tell = static function (int $userId, object $notice) use (&$told): void {
            $told[] = [$userId, (new \ReflectionProperty($notice, 'words'))->getValue($notice)];
        };

        // Act
        $first  = Companion::remind($now, $tell);
        $second = Companion::remind($now, $tell);
        $held   = (new Calendar(new Scope(8, null), 'UTC'))->create('Held', gmdate('Y-m-d H:i', $now + 10 * 60))['created']['id'];
        Llm::use('proactivity', new class implements Proactivity {
            public function when(int $userId, string $timezone, int $now, bool $critical = false): int
            {
                return 0;
            }
        });
        $passed = Companion::remind($now, $tell);
        $this->configure([]);
        $off    = (new Calendar(new Scope(9, null), 'UTC'))->create('Off', gmdate('Y-m-d H:i', $now + 5 * 60))['created']['id'];
        $none   = Companion::remind($now, $tell);

        // Assert
        $this->assertSame([1, 0, 0, 0], [$first, $second, $passed, $none]);
        $this->assertSame([[7, 'In 20 minutes: Giorgos.']], $told);
        $alerted = fn (int $id): string => (string) $this->db->queryBuilder()->table('chat_calendar')->where('item_id', $id)->first()->fields['alerted_at'];
        $this->assertStringStartsWith('1970-01-01', $alerted($held), 'passed, at the first moment there is');
        $this->assertStringStartsWith('1970-01-01', $alerted($off));
        $this->assertStringStartsWith(gmdate('Y-m-d'), $alerted($soon));

        // One that cannot be told is not counted, and does not stop the rest
        $this->configure(['chat' => ['companion' => true], 'proactivity' => ['quiet' => '']]);
        (new Calendar(new Scope(10, null), 'UTC'))->create('Broken', gmdate('Y-m-d H:i', $now + 15 * 60));
        $this->assertSame(0, Companion::remind($now, static function (): void {
            throw new \RuntimeException('no push endpoint');
        }));
    }
}
