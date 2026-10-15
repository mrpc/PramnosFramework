<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\CallLog;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\TaskPolicy;
use Pramnos\Llm\Chat\TaskReminder;
use Pramnos\Llm\Chat\TaskRunTask;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * Scheduled AI tasks beyond a question asked again: reminders, one-offs, the nth weekday of a
 * month, runs claimed so none runs twice, runs queued, a history of runs, failures that pause, a
 * maker who may no longer run one, and a member's tasks handed to another — on MySQL and
 * PostgreSQL.
 *
 * What a routine already did is held by RoutinesAsksConsentTest, which runs unchanged.
 */
#[CoversClass(Routines::class)]
#[CoversClass(TaskPolicy::class)]
#[CoversClass(TaskReminder::class)]
#[CoversClass(TaskRunTask::class)]
class TasksTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        RanPolicy::$ran = [];
        RanPolicy::$allowed = true;
    }

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** Tasks over a conversation service, with deliveries recorded rather than sent. */
    private function tasks(?BaseAssistant $assistant = null, ?RecordingQueue $queue = null): RecordingTasks
    {
        return new RecordingTasks(new Conversations($assistant ?? new BaseAssistant()), $queue);
    }

    /** Make a task due a minute ago. */
    private function due(int $routineId): void
    {
        $this->db->queryBuilder()->table('ai_routines')->where('routine_id', $routineId)
            ->update(['next_run_at' => CallLog::instant(time() - 60)]);
    }

    /**
     * A row as an installation holds it from before kinds existed — only the original columns
     * written — runs as it did: asked into its owner's conversation, told, its next run moved on;
     * and now its run is kept in the history.
     */
    #[DataProvider('databases')]
    public function testARoutineOfTheOlderShapeRunsAsItDid(string $type, string $host, int $port): void
    {
        // Arrange — inserted as the older code inserted it
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['routine_notification' => CapturedNotice::class]]);
        $this->answer([[200, self::says('All quiet.')]]);
        $stamp = CallLog::instant(time());
        $this->db->queryBuilder()->table('ai_routines')->insert([
            'organization_id' => 3, 'userid' => 7, 'space' => 'chat', 'title' => 'Morning', 'prompt' => 'Anything new?',
            'template' => null, 'cadence' => 'daily', 'at' => '08:00', 'day' => null, 'timezone' => 'Europe/Athens',
            'is_active' => true, 'next_run_at' => CallLog::instant(time() - 60), 'created_at' => $stamp, 'updated_at' => $stamp,
        ]);
        $tasks = $this->tasks();

        // Act
        $claimed = $tasks->runDue(time());
        $task    = $tasks->ownedBy(3, 7)['tasks'][0];

        // Assert
        $this->assertSame(1, $claimed);
        $this->assertSame('ask', $task['kind'], 'the default kind');
        $this->assertSame('user', $task['owner']);
        $this->assertTrue($task['active']);
        $this->assertSame(0, $task['failures']);
        $this->assertGreaterThan(time(), strtotime((string) $task['next_run_at']));
        $this->assertNull($tasks->byId($task['routine_id'])['run_at']);
        $this->assertSame('Morning', $tasks->delivered[0][1]->title, 'told as before');
        $runs = $tasks->runs($task['routine_id']);
        $this->assertSame(['ok', 'All quiet.'], [$runs[0]['status'], $runs[0]['content']]);
        $this->assertSame($task['conversation_id'], $runs[0]['conversation_id']);
    }

    /**
     * A due task is claimed once: a second tick at the same moment finds nothing, and a task
     * claimed by a run still holding it is not claimed again.
     */
    #[DataProvider('databases')]
    public function testADueTaskIsClaimedOnce(string $type, string $host, int $port): void
    {
        // Arrange — runs go to a queue, so claiming is all a tick does
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['task_queue' => true]]);
        $queue = new RecordingQueue();
        $tasks = $this->tasks(null, $queue);
        $made  = $tasks->create(new Scope(7, null), ['title' => 'Morning', 'prompt' => 'Report', 'cadence' => 'daily', 'at' => '08:00']);
        $this->due($made['routine_id']);

        // Act
        $first  = $tasks->runDue(time());
        $this->due($made['routine_id']);
        $second = $tasks->runDue(time());

        // Assert — queued once, with what the worker needs; the lease still holds it
        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'held by the run that claimed it');
        $this->assertCount(1, $queue->tasks);
        $this->assertSame(Routines::TASK, $queue->tasks[0][0]);
        $this->assertSame($made['routine_id'], $queue->tasks[0][1]['routine_id']);
        $this->assertSame([], $this->sent, 'nothing ran in the tick');
        $this->assertNotNull($tasks->byId($made['routine_id'])['next_run_at']);
    }

    /** The worker runs a queued task by id, and frees it; a task gone since is a warning. */
    #[DataProvider('databases')]
    public function testTheWorkerRunsAQueuedTask(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('Done.')]]);
        $made = $this->tasks()->create(new Scope(7, null), ['title' => 'Morning', 'prompt' => 'Report', 'cadence' => 'daily', 'at' => '08:00']);
        $item = $this->getMockBuilder(\Pramnos\Queue\QueueItem::class)->disableOriginalConstructor()->getMock();
        $item->payload = json_encode(['routine_id' => $made['routine_id'], 'space' => 'chat']);
        $gone = $this->getMockBuilder(\Pramnos\Queue\QueueItem::class)->disableOriginalConstructor()->getMock();
        $gone->payload = json_encode(['routine_id' => 999999]);
        $task = (new \ReflectionClass(TaskRunTask::class))->newInstanceWithoutConstructor();

        // Act
        $result  = $task->execute($item);
        $missing = $task->execute($gone);

        // Assert
        $this->assertTrue($result);
        $this->assertSame(['warning' => 'The scheduled task is gone.'], $missing);
        $this->assertSame('ok', $this->tasks()->runs($made['routine_id'])[0]['status']);
        $this->assertNull($this->tasks()->byId($made['routine_id'])['last_error']);
        $this->assertStringContainsString((string) $made['routine_id'], $task->getDescription($item));
    }

    /**
     * "Remind me in an hour about the invoice": no model is asked; the owner is told in their own
     * words, with a link to the conversation it was asked in; a one-off is done once it ran.
     */
    #[DataProvider('databases')]
    public function testAReminderIsTheOwnersWordsAndNoModel(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tasks = $this->tasks();
        $made  = $tasks->create(new Scope(7, null), ['kind' => 'reminder', 'title' => 'Invoice', 'prompt' => 'Send the invoice to Acme', 'cadence' => 'once', 'in_minutes' => 60, 'conversation_id' => 42]);
        $this->assertEqualsWithDelta(time() + 3600, strtotime((string) $made['next_run_at']), 5);
        $this->due($made['routine_id']);

        // Act
        $tasks->runDue(time());
        $after = $tasks->byId($made['routine_id']);

        // Assert
        $this->assertSame([], $this->sent, 'no model was asked');
        $this->assertInstanceOf(TaskReminder::class, $tasks->delivered[0][1]);
        $this->assertSame(['message' => 'Send the invoice to Acme', 'url' => \Pramnos\Http\SiteUrl::to('chat?c=42')], $tasks->delivered[0][1]->toDatabase(null));
        $this->assertFalse($after['active'], 'a one-off is done');
        $this->assertNull($after['next_run_at']);
        $this->assertSame('Send the invoice to Acme', $tasks->runs($made['routine_id'])[0]['content']);
    }

    /**
     * A one-off at a time is that time on the asker's clock; one already past is refused, and so
     * is a one-off with no time at all.
     */
    #[DataProvider('databases')]
    public function testAOneOffIsOnTheAskersClock(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tasks = $this->tasks();
        $scope = new Scope(7, null);

        // Act
        $athens = $tasks->create($scope, ['kind' => 'reminder', 'title' => 'Call', 'prompt' => 'Call Maria', 'cadence' => 'once', 'run_at' => '2030-01-15 17:00', 'timezone' => 'Europe/Athens']);
        $past   = $tasks->create($scope, ['kind' => 'reminder', 'title' => 'Late', 'prompt' => 'x', 'cadence' => 'once', 'run_at' => '2001-01-01 10:00']);
        $none   = $tasks->create($scope, ['kind' => 'reminder', 'title' => 'When', 'prompt' => 'x', 'cadence' => 'once']);
        $kind   = $tasks->create($scope, ['kind' => 'magic', 'title' => 'x', 'prompt' => 'x', 'cadence' => 'daily']);

        // Assert — 17:00 in Athens in winter is 15:00 UTC
        $this->assertSame('2030-01-15 15:00', gmdate('Y-m-d H:i', (int) strtotime((string) $athens['next_run_at'])));
        $this->assertSame('17:00', $athens['at']);
        $this->assertSame('That time has passed: give one still to come.', $past);
        $this->assertStringStartsWith('A one-off needs when', $none);
        $this->assertStringStartsWith('A task is a question asked again', $kind);
    }

    /** The nth weekday of a month — the second Tuesday, the last Friday — and what it refuses. */
    #[DataProvider('databases')]
    public function testTheNthWeekdayOfAMonth(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $after = (int) strtotime('2026-10-15 12:00 UTC');

        // Act
        $secondTuesday = Routines::nextFor(['cadence' => 'monthly_weekday', 'at' => '09:00', 'day' => 2, 'nth' => 2, 'timezone' => 'UTC'], $after);
        $lastFriday    = Routines::nextFor(['cadence' => 'monthly_weekday', 'at' => '18:30', 'day' => 5, 'nth' => 5, 'timezone' => 'Europe/Athens'], $after);
        $bad           = $this->tasks()->create(new Scope(7, null), ['title' => 'x', 'prompt' => 'x', 'cadence' => 'monthly_weekday', 'at' => '09:00', 'day' => 9, 'nth' => 2]);
        $made          = $this->tasks()->create(new Scope(7, null), ['title' => 'Board', 'prompt' => 'Summarise the month', 'cadence' => 'monthly_weekday', 'at' => '09:00', 'day' => 2, 'nth' => 1]);

        // Assert — the 13th of October has passed, so November's; the 30th is October's last Friday
        $this->assertSame('2026-11-10 09:00 Tue', gmdate('Y-m-d H:i D', (int) $secondTuesday));
        $this->assertSame('2026-10-30 18:30', (new \DateTimeImmutable('@' . $lastFriday))->setTimezone(new \DateTimeZone('Europe/Athens'))->format('Y-m-d H:i'));
        $this->assertStringStartsWith('Monthly on a weekday needs', $bad);
        $this->assertSame([2, 1], [$made['day'], $made['nth']]);
        $this->assertSame('Tue', gmdate('D', (int) strtotime((string) $made['next_run_at'])));
    }

    /**
     * Failures in a row pause a task at the limit and say so; a success in between starts the
     * count again; resuming starts it again too.
     */
    #[DataProvider('databases')]
    public function testFailuresInARowPause(string $type, string $host, int $port): void
    {
        // Arrange — an assistant whose plan refuses every run
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['routine_notification' => CapturedNotice::class]]);
        $refusing = $this->tasks(new NoRoutines());
        $scope    = new Scope(7, null);
        $made     = $this->tasks()->create($scope, ['title' => 'Morning', 'prompt' => 'Report', 'cadence' => 'daily', 'at' => '08:00']);

        // Act
        for ($i = 0; $i < 3; $i++) {
            $refusing->run($refusing->byId($made['routine_id']), time());
        }
        $after   = $refusing->byId($made['routine_id']);
        $resumed = $this->tasks()->setActive($scope, $made['routine_id'], true);

        // Assert
        $this->assertFalse($after['active'], 'paused at the third');
        $this->assertSame(3, $after['failures']);
        $this->assertStringContainsString('paused after 3 failures in a row', $refusing->delivered[2][1]->why);
        $this->assertStringNotContainsString('paused', $refusing->delivered[1][1]->why, 'not before');
        $this->assertTrue($resumed);
        $this->assertSame(0, $this->tasks()->byId($made['routine_id'])['failures']);
        $this->assertCount(3, $this->tasks()->runs($made['routine_id']));
    }

    /**
     * A task whose maker may no longer run it is paused and its maker told, before anything runs;
     * and the policy hears of every run, a hook that throws included.
     */
    #[DataProvider('databases')]
    public function testThePolicyDecidesAndHears(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['task_policy' => RanPolicy::class, 'routine_notification' => CapturedNotice::class]]);
        $this->answer([[200, self::says('Fine.')]]);
        $tasks = $this->tasks();
        $made  = $tasks->create(new Scope(7, 3), ['title' => 'Morning', 'prompt' => 'Report', 'cadence' => 'daily', 'at' => '08:00', 'timezone' => 'Pacific/Auckland']);

        // Act — a run heard; then the maker left the organisation
        $tasks->run($tasks->byId($made['routine_id']), (int) strtotime('2026-10-15 23:30 UTC'));
        RanPolicy::$allowed = false;
        $refused = $tasks->run($tasks->byId($made['routine_id']), time());

        // Assert
        $this->assertSame([$made['routine_id']], array_column(RanPolicy::$ran, 'routine_id'), 'heard once: the refused run never ran');
        $this->assertSame('ok', RanPolicy::$ran[0]['status']);
        $this->assertStringContainsString('run on Friday 16 October 2026', $this->sent[0]['body']['messages'][1]['content'], 'the day on the owner\'s clock, not UTC\'s');
        $this->assertFalse($refused['ok']);
        $this->assertFalse($tasks->byId($made['routine_id'])['active']);
        $this->assertSame('It was paused: you may no longer run it here.', end($tasks->delivered)[1]->why);
    }

    /**
     * A member's tasks in an organisation go to another: each keeps its schedule, not its
     * conversation; one the new owner may not run stays paused, saying why; the leaver's
     * subscriptions go; the new owner is told. Another organisation's are untouched.
     */
    #[DataProvider('databases')]
    public function testAMembersTasksGoToAnother(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tasks = $this->tasks();
        $mine  = $tasks->create(new Scope(7, 3), ['title' => 'Morning', 'prompt' => 'Report', 'cadence' => 'daily', 'at' => '08:00', 'conversation_id' => 5]);
        $other = $tasks->create(new Scope(7, 4), ['title' => 'Elsewhere', 'prompt' => 'x', 'cadence' => 'daily', 'at' => '09:00']);
        $this->db->queryBuilder()->table('ai_task_subscriptions')->insert(['routine_id' => $mine['routine_id'], 'userid' => 7, 'email' => false]);
        $listed = $tasks->ownedBy(3, 7);

        // Act
        $result = $tasks->transfer(3, 7, 8);
        $moved  = $tasks->byId($mine['routine_id']);

        // Assert
        $this->assertSame([$mine['routine_id']], array_column($listed['tasks'], 'routine_id'));
        $this->assertSame([$mine['routine_id']], $listed['subscriptions']);
        $this->assertSame(['moved' => [$mine['routine_id']], 'paused' => []], $result);
        $this->assertSame([8, null, '08:00'], [$moved['userid'], $moved['conversation_id'], $moved['at']]);
        $this->assertSame(7, $tasks->byId($other['routine_id'])['userid'], 'another organisation\'s stays');
        $this->assertSame([], $tasks->ownedBy(3, 7)['subscriptions'], 'subscriptions are not passed on');
        $this->assertSame(8, end($tasks->delivered)[0]);
        $this->assertSame('A scheduled task was passed to you.', end($tasks->delivered)[1]->toDatabase(null)['message']);

        // A new owner the plan refuses: theirs, but paused
        $refusing = $this->tasks(new NoRoutines());
        $back     = $refusing->transfer(3, 8, 7);
        $this->assertSame([$mine['routine_id'] => 'Not on this plan.'], $back['paused']);
        $this->assertFalse($refusing->byId($mine['routine_id'])['active']);
    }

    /**
     * The model schedules a reminder in the conversation it was asked in, once, an hour on; asked
     * the same again, the tool refuses with the id of the one there is; it changes that one's time
     * and pauses and resumes it; it lists what there is; and it cancels by id.
     */
    #[DataProvider('databases')]
    public function testTheModelManagesTasksThroughItsTools(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tasks = $this->tasks();
        $scope = new Scope(7, null);
        $tools = $tasks->toolsFor($scope, 'Europe/Athens', 42);

        // Act — scheduled, then asked for again
        $made  = $tools['schedule_task']['run'](['kind' => 'reminder', 'title' => 'Invoice', 'prompt' => 'Send the invoice to Acme', 'cadence' => 'once', 'in_minutes' => 60]);
        $again = $tools['schedule_task']['run'](['kind' => 'reminder', 'title' => 'invoice', 'prompt' => 'something else', 'cadence' => 'once', 'in_minutes' => 30]);
        $daily = $tools['schedule_task']['run'](['kind' => 'ask', 'title' => 'Morning', 'prompt' => 'Report', 'cadence' => 'daily', 'at' => '8:00']);
        $twice = $tools['schedule_task']['run'](['kind' => 'ask', 'title' => 'Other name', 'prompt' => 'report', 'cadence' => 'daily', 'at' => '08:00']);
        // A reminder asked for with no kind — as one model sent it — is refused, not made a question
        $kindless = $tools['schedule_task']['run'](['title' => 'Test', 'prompt' => 'Δοκιμή reminder', 'cadence' => 'once', 'in_minutes' => 2]);
        $id    = (int) preg_replace('/\D.*$/s', '', substr($made, strlen('Scheduled as task ')));
        $where = $tasks->byId($id)['conversation_id'];
        $moved = $tools['update_scheduled_task']['run'](['routine_id' => $id, 'in_minutes' => 120, 'cadence' => 'once']);
        $pause = $tools['update_scheduled_task']['run'](['routine_id' => $id, 'active' => false]);
        $back  = $tools['update_scheduled_task']['run'](['routine_id' => $id, 'active' => true, 'title' => 'Invoice to Acme']);
        $list  = json_decode($tools['list_scheduled_tasks']['run']([]), true);
        $gone  = $tools['cancel_scheduled_task']['run'](['routine_id' => $id]);

        // Assert
        $this->assertStringStartsWith('Scheduled as task ' . $id . '. Next run: ', $made);
        $this->assertSame(42, $where, 'it leads back to where it was asked');
        $this->assertStringStartsWith('Error: task ' . $id . ' ("Invoice") already does this', $again);
        $this->assertStringStartsWith('Scheduled as task ', $daily);
        $this->assertStringStartsWith('Error: say which kind', $kindless);
        $this->assertSame(['kind', 'title', 'prompt', 'cadence'], $tools['schedule_task']['parameters']['required']);
        $this->assertStringContainsString('already does this', $twice, 'the same words at the same time, under another name');
        $this->assertStringStartsWith('Task ' . $id . ' changed. Next run: ', $moved);
        $this->assertSame('Task ' . $id . ' changed. It is paused.', $pause);
        $this->assertStringStartsWith('Task ' . $id . ' changed. Next run: ', $back);
        $this->assertSame(['reminder', 'Invoice to Acme', true], [$list[0]['kind'], $list[0]['title'], $list[0]['active']]);
        $this->assertArrayHasKey('last_error', $list[0]);
        $this->assertSame('Deleted.', $gone);
        $this->assertSame('Error: there is no such task.', $tools['cancel_scheduled_task']['run'](['routine_id' => $id]));
    }

    /**
     * A reminder asked for in the first question of a conversation leads back to it: the
     * conversation exists only once the question is answered, and the task the model made during
     * the answer is given its id then. A task made elsewhere with no conversation is left alone.
     */
    #[DataProvider('databases')]
    public function testAReminderInANewConversationLeadsBackToIt(string $type, string $host, int $port): void
    {
        // Arrange — a task of another turn, with no conversation, that must stay so
        $this->connectDatabase($type, $host, $port);
        $tasks = $this->tasks();
        $other = $tasks->create(new Scope(7, null), ['kind' => 'reminder', 'title' => 'Other', 'prompt' => 'x', 'cadence' => 'once', 'in_minutes' => 30]);
        $this->answer([
            [200, self::calls([['schedule_task', ['kind' => 'reminder', 'title' => 'Test', 'prompt' => 'Δοκιμή reminder', 'cadence' => 'once', 'in_minutes' => 2]]])],
            [200, self::says('Θα σου το θυμίσω.')],
        ]);

        // Act
        $answer = (new Conversations(new BaseAssistant()))->as(new Scope(7, null))->ask(null, 'Θύμισέ μου σε 2 λεπτά: Δοκιμή reminder');
        $made   = array_values(array_filter($tasks->ownedBy(null, 7)['tasks'], static fn (array $t): bool => $t['title'] === 'Test'));

        // Assert
        $this->assertTrue($answer['ok']);
        $this->assertSame('reminder', $made[0]['kind']);
        $this->assertSame($answer['conversation_id'], $made[0]['conversation_id'], 'linked to the conversation it was asked in');
        $this->assertNull($tasks->byId($other['routine_id'])['conversation_id'], 'another turn\'s task untouched');
        $this->assertSame(0, $tasks->linkMade(5), 'nothing this instance made');
    }

    /** What a change refuses: no such task, an unknown cadence, a bad time, a time already gone, a plan that will not resume it. */
    #[DataProvider('databases')]
    public function testWhatAChangeRefuses(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tasks = $this->tasks();
        $scope = new Scope(7, null);
        $made  = $tasks->create($scope, ['title' => 'Morning', 'prompt' => 'Report', 'cadence' => 'daily', 'at' => '08:00']);
        $tasks->setActive($scope, $made['routine_id'], false);

        // Act & Assert
        $this->assertSame('There is no such task.', $tasks->update($scope, 999999, ['title' => 'x']));
        $this->assertStringStartsWith('There is no such cadence', $tasks->update($scope, $made['routine_id'], ['cadence' => 'hourly']));
        $this->assertSame('The time is HH:MM, for example 08:00.', $tasks->update($scope, $made['routine_id'], ['at' => '25:00']));
        $this->assertSame('That time has passed: give one still to come.', $tasks->update($scope, $made['routine_id'], ['cadence' => 'once', 'run_at' => '2001-01-01 10:00', 'active' => true]));
        $this->assertSame('Not on this plan.', $this->tasks(new NoRoutines())->update($scope, $made['routine_id'], ['active' => true]));
        $this->assertSame([], $this->tasks(new NoRoutines())->toolsFor($scope, 'UTC', null), 'a plan with nothing scheduled offers no tools');
        $kept = $tasks->update($scope, $made['routine_id'], ['prompt' => 'A fuller report']);
        $this->assertSame(['A fuller report', false, null], [$kept['prompt'], $kept['active'], $kept['next_run_at']]);
    }

    /**
     * A reminder's notification: in the person's notifications always, as a push where they have
     * a browser subscribed; the push carries the words, cut to a title, the rest as its body.
     */
    public function testTheReminderNotification(): void
    {
        // Arrange
        $short = new TaskReminder('Call Maria', 'https://app.test/chat?c=4');
        $long  = new TaskReminder(str_repeat('Send the quarterly figures to the board ', 4), 'https://app.test/chat');

        // Act
        $push     = $short->toPush(null);
        $longPush = $long->toPush(null);

        // Assert
        $this->assertSame(['database'], $short->via((object) ['userid' => 0]), 'no browser subscribed: no push');
        $this->assertSame(['title' => 'Call Maria', 'body' => '', 'url' => 'https://app.test/chat?c=4', 'tag' => 'reminder-' . md5('Call Maria' . 'https://app.test/chat?c=4')], $push);
        $this->assertSame(90, mb_strlen($longPush['title']));
        $this->assertNotSame('', $longPush['body'], 'the rest as the body');
    }

    /** The added columns reach a table of the older shape, and go again on the way down. */
    #[DataProvider('databases')]
    public function testTheMigrationExtendsAndShrinks(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $schema    = $this->db->schema();
        $app       = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        $migration = new \Pramnos\Framework\Migrations\Llm\ExtendAiRoutinesForTasks($app);

        // Act
        $migration->down();
        $shrunk = $schema->hasColumn('ai_routines', 'kind');
        $migration->up();
        $migration->up();

        // Assert
        $this->assertFalse($shrunk);
        foreach (['kind', 'owner', 'resource_type', 'resource_id', 'run_at', 'nth', 'notify', 'failures', 'locked_until'] as $column) {
            $this->assertTrue($schema->hasColumn('ai_routines', $column), $column);
        }
    }
}

/** Tasks whose notifications are recorded rather than sent, and whose queue is the test's. */
final class RecordingTasks extends Routines
{
    /** @var list<array{0: int, 1: object}> */
    public array $delivered = [];

    public function __construct(Conversations $chat, private readonly ?RecordingQueue $recording = null)
    {
        parent::__construct($chat, $recording);
    }

    /** Record instead of sending. */
    protected function deliver(int $userId, object $notification): void
    {
        $this->delivered[] = [$userId, $notification];
    }
}

/** A policy that records each run and can refuse the maker — and whose hook throws. */
final class RanPolicy extends TaskPolicy
{
    /** @var list<array<string, mixed>> */
    public static array $ran = [];

    public static bool $allowed = true;

    /** Whether the maker may still run it. */
    public function stillAllowed(array $task): bool
    {
        return self::$allowed;
    }

    /** Record the run, then fail: a hook that throws is logged, not the run's failure. */
    public function onRan(array $task, array $run): void
    {
        self::$ran[] = $run;

        throw new \RuntimeException('a Discord that is down');
    }
}
