<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Analyst\AnalystAssistant;
use Pramnos\Llm\CallLog;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\TaskPolicy;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * Scheduled tasks for the analyst, and what a scheduled task shows and links to wherever it runs
 * — on MySQL and PostgreSQL.
 *
 * What holds: a due analyst task runs with the analyst, not the chat assistant the tick was built
 * with; a next run read back from the database is shown on its own clock whatever PHP's zone; a
 * deleted task takes its readers and runs; a notification's link built with no site address is
 * from the root; the analyst says it may schedule when it may, on its own clock; and the analyst's
 * screen serves the scheduled page and opens a report's run in its own conversations.
 */
#[CoversClass(Routines::class)]
#[CoversClass(TaskPolicy::class)]
#[CoversClass(AnalystAssistant::class)]
#[CoversClass(\Pramnos\Application\Controllers\AiController::class)]
#[CoversClass(\Pramnos\Llm\Chat\Web\SchedulesTasks::class)]
#[CoversClass(\Pramnos\Llm\Chat\RoutineReady::class)]
#[CoversClass(\Pramnos\Llm\Chat\TaskReminder::class)]
class AnalystTasksTest extends LlmTestCase
{
    use ChatDatabase;

    private string $zone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->zone = date_default_timezone_get();
        RelativeLinkPolicy::$link = 'admin/Ai/report/1';
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
        \Pramnos\Http\RequestIdentity::reset();
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** Tasks over the chat assistant, deliveries recorded. */
    private function tasks(): RecordingTasks
    {
        return new RecordingTasks(new Conversations(new BaseAssistant()));
    }

    /**
     * A tick built with the chat assistant runs a due analyst task with the analyst: the
     * analyst's refusal stops it, where the chat assistant's would have let it ask.
     */
    #[DataProvider('databases')]
    public function testAnAnalystTaskRunsWithTheAnalyst(string $type, string $host, int $port): void
    {
        // Arrange — made directly, as the analyst refuses to schedule by default
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('Fine.')]]);
        $stamp = CallLog::instant(time());
        $this->db->queryBuilder()->table('pramnos.ai_routines')->insert([
            'organization_id' => null, 'userid' => 7, 'space' => Scope::ADMIN, 'title' => 'Weekly figures', 'prompt' => 'How many?',
            'kind' => 'ask', 'owner' => 'user', 'cadence' => 'daily', 'at' => '08:00', 'timezone' => 'UTC', 'notify' => 'always',
            'is_active' => true, 'next_run_at' => CallLog::instant(time() - 60), 'created_at' => $stamp, 'updated_at' => $stamp,
        ]);

        // Act
        $claimed = $this->tasks()->runDue(time());
        $task    = $this->tasks()->forUser(7)['tasks'][0];

        // Assert
        $this->assertSame(1, $claimed);
        $this->assertSame('The analyst does not run scheduled reports.', $task['last_error'], 'the analyst\'s refusal, not the chat assistant\'s go-ahead');
        $this->assertSame([], $this->sent, 'no model asked');
    }

    /**
     * A next run is shown on the task's clock whatever PHP's zone: MySQL answers the UTC moment
     * without its offset, which was read in PHP's zone — "09:00 in Athens" shown as 06:00.
     */
    #[DataProvider('databases')]
    public function testTheNextRunIsShownOnItsOwnClock(string $type, string $host, int $port): void
    {
        // Arrange — PHP in Athens, a weekly task at nine on Athens' clock
        $this->connectDatabase($type, $host, $port);
        date_default_timezone_set('Europe/Athens');
        $made = $this->tasks()->create(new Scope(7, null), ['kind' => 'reminder', 'title' => 'Monday', 'prompt' => 'p', 'cadence' => 'weekly', 'day' => 1, 'at' => '09:00', 'timezone' => 'Europe/Athens']);

        // Act
        $read = $this->tasks()->byId($made['routine_id']);

        // Assert
        $this->assertStringEndsWith('09:00', (string) $read['next_run_local']);
        $this->assertSame(0, Routines::utc('1970-01-01 00:00:00'), 'a bare moment is UTC');
        $this->assertSame(0, Routines::utc('1970-01-01 02:00:00+02:00'), 'an offset wins');
        $this->assertNull(Routines::utc('not a moment'));
    }

    /** A deleted task takes its readers and the record of its runs with it. */
    #[DataProvider('databases')]
    public function testADeletedTaskTakesItsReadersAndRuns(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tasks = $this->tasks();
        $made  = $tasks->create(new Scope(7, null), ['kind' => 'reminder', 'title' => 'Mine', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $tasks->run($tasks->byId($made['routine_id']), time());
        $this->db->queryBuilder()->table('pramnos.ai_task_subscriptions')->insert(['routine_id' => $made['routine_id'], 'userid' => 7, 'email' => false]);

        // Act
        $tasks->delete(new Scope(7, null), $made['routine_id']);

        // Assert
        $this->assertSame([], $tasks->forUser(7)['subscriptions']);
        $this->assertSame([], $tasks->runs($made['routine_id']));
    }

    /**
     * A notification's link built with no site address — a cron run with no request — is from the
     * site's root, not from whatever page the bell is on; an absolute one is left alone.
     */
    #[DataProvider('databases')]
    public function testANotificationsLinkIsFromTheRoot(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['task_policy' => RelativeLinkPolicy::class]]);
        $tasks = $this->tasks();
        $made  = $tasks->create(new Scope(7, null), ['kind' => 'reminder', 'title' => 'Mine', 'prompt' => 'Call Maria', 'cadence' => 'daily', 'at' => '08:00']);

        // Act
        $tasks->run($tasks->byId($made['routine_id']), time());
        RelativeLinkPolicy::$link = 'https://app.test/chat';
        $tasks->run($tasks->byId($made['routine_id']), time());

        // Assert
        $this->assertSame('/admin/Ai/report/1', $tasks->delivered[0][1]->toDatabase(null)['url'] ?? $tasks->delivered[0][1]->link ?? null);
        $this->assertSame('https://app.test/chat', $tasks->delivered[1][1]->toDatabase(null)['url'] ?? $tasks->delivered[1][1]->link ?? null);
    }

    /**
     * The analyst says it may change nothing — unless its application lets it schedule, when it
     * says the only tools that write are the scheduling ones; and it keeps the analyst's clock.
     */
    public function testWhatTheAnalystSaysAboutWriting(): void
    {
        // Arrange
        $turn = static fn (\Pramnos\Llm\Chat\Assistant $assistant): \Pramnos\Llm\Chat\Turn => new \Pramnos\Llm\Chat\Turn(new Scope(7, null, Scope::ADMIN), null, false, [], [], [], new Conversations($assistant));
        $closed = new AnalystAssistant();
        $open   = new SchedulingAnalyst();

        // Act
        $closedSays = $closed->systemPrompt($turn($closed));
        $openSays   = $open->systemPrompt($turn($open));

        // Assert
        $this->assertStringContainsString('You cannot change anything: no tool writes.', $closedSays);
        $this->assertStringContainsString('the only tools that write are the ones that schedule your tasks', $openSays);
        $this->assertStringNotContainsString('no tool writes', $openSays);
        $this->assertSame(\Pramnos\Llm\Analyst\Analyst::timezone(), $open->timezone(new Scope(7)));
    }

    /**
     * The analyst's screen: its scheduled page lists the administrator's analyst tasks with the
     * area's addresses; a report's run opens as one of their analyst conversations; and the
     * analyst's page links to the scheduled page only where the analyst may schedule.
     */
    #[DataProvider('databases')]
    public function testTheAnalystsScheduledPage(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('12 sign-ups this week.')]]);
        \Pramnos\Http\RequestIdentity::seal((object) ['userid' => 7, 'usertype' => 99], 'test');
        $tasks = new Routines(new Conversations(new SchedulingAnalyst()));
        $made  = $tasks->create(new Scope(7, null, Scope::ADMIN), ['kind' => 'report', 'title' => 'Sign-ups', 'prompt' => 'How many sign-ups?', 'cadence' => 'daily', 'at' => '08:00']);
        $tasks->run($tasks->byId($made['routine_id']), time());
        $runId  = $tasks->runs($made['routine_id'])[0]['run_id'];
        $screen = new AnalystScreen();

        // Act — the page, open at the report's runs
        $_GET   = ['task' => (string) $made['routine_id']];
        $page   = (string) $screen->scheduled();
        $_GET   = [];
        $screen->report($runId);
        $opened = $screen->redirectedTo[0] ?? '';
        $screen->report(999999);
        $urls   = [(new \ReflectionMethod($screen, 'scheduledUrl'))->invoke($screen, 'schedule'), (new \ReflectionMethod($screen, 'taskReportUrl'))->invoke($screen, 5)];

        // Assert
        $this->assertStringContainsString('Sign-ups', $page);
        $this->assertStringContainsString('Ai/report/' . $runId, $page, 'ask about it, in the area');
        $this->assertStringContainsString('Ai/schedule', $page, 'the form posts to the analyst\'s action');
        $this->assertMatchesRegularExpression('#Ai\?c=\d+$#', $opened, 'opened as an analyst conversation');
        $this->assertSame(['There is no such report you may read.'], $screen->errors);
        $this->assertStringEndsWith('Ai/schedule', $urls[0]);
        $this->assertStringEndsWith('Ai/report/5', $urls[1]);
        $this->assertFalse((new \ReflectionMethod($screen, 'analystSchedules'))->invoke($screen), 'the framework\'s analyst schedules nothing');
        $this->assertStringEndsWith('Ai', (new \ReflectionMethod($screen, 'taskSignInUrl'))->invoke($screen));
        // No area in this process — a site's cron — and the report still leads into the administration.
        $this->assertStringEndsWith('admin/Ai/report/' . $runId, (new TaskPolicy(new SchedulingAnalyst()))->link(['kind' => 'report', 'space' => Scope::ADMIN] + $made, ['run_id' => $runId]));
        $this->assertStringEndsWith('admin/Ai/analyst?c=4', (new SchedulingAnalyst())->conversationUrl(4), 'the conversations by the same rule');
        // An application with an area of its own, under another prefix: the area's address wins.
        \Pramnos\Http\AdminArea::detect('manage');
        $inArea = AnalystAssistant::areaUrl('Ai/report/' . $runId);
        \Pramnos\Http\AdminArea::reset();
        $this->assertStringEndsWith('manage/Ai/report/' . $runId, $inArea);
        // An application whose whole site is the administration says so, and every link follows:
        // the report's, a conversation's, a share's.
        $this->configure(['analyst' => ['area' => '']]);
        $root = AnalystAssistant::areaUrl('Ai/report/' . $runId);
        $this->assertStringEndsWith('Ai/report/' . $runId, $root);
        $this->assertStringNotContainsString('admin/', $root);
        $this->assertStringNotContainsString('admin/', (new \Pramnos\Llm\Chat\SharePolicy())->link(['share_id' => 3, 'space' => Scope::ADMIN]));
        $this->configure(['analyst' => ['area' => 'backoffice/']]);
        $this->assertStringEndsWith('backoffice/Ai/analyst?c=4', (new SchedulingAnalyst())->conversationUrl(4), 'or another place of its own');
        $this->configure([]);
    }

    /**
     * The analyst's screen pauses and resumes a task through the trait's own action — it reads
     * the person through taskScope(), which every controller using the trait has, so the button
     * answers rather than failing on a method only the chat page declares.
     */
    #[DataProvider('databases')]
    public function testTheAnalystsPauseButton(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Http\RequestIdentity::seal((object) ['userid' => 7, 'usertype' => 99], 'test');
        $tasks  = new Routines(new Conversations(new SchedulingAnalyst()));
        $made   = $tasks->create(new Scope(7, null, Scope::ADMIN), ['kind' => 'reminder', 'title' => 'Mine', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $paused = new AnalystScreen();

        // Act
        $_POST = ['routine_id' => (string) $made['routine_id'], 'active' => ''];
        $paused->scheduledupdate();
        $_POST = [];

        // Assert
        $this->assertSame(['Paused.'], $paused->messages);
        $this->assertFalse($tasks->byId($made['routine_id'])['active'], 'it took effect in the database');
        $this->assertStringEndsWith('Ai/scheduled', $paused->redirectedTo[0]);
    }

    /**
     * Where a scheduled task's notifications go: the bell, and a push for somebody with a browser
     * subscribed — unless `llm.chat.notify_via` names the bell only, for both the report's
     * notification and the reminder's.
     */
    #[DataProvider('databases')]
    public function testNotifyVia(string $type, string $host, int $port): void
    {
        // Arrange — person 7 has a browser subscribed
        $this->connectDatabase($type, $host, $port);
        $this->migration(\Pramnos\Framework\Migrations\Notifications\CreatePushSubscriptionsTable::class)->up();
        $person = (object) ['userid' => 7];
        \Pramnos\Push\Subscriptions::store(7, ['endpoint' => 'https://push.example/' . bin2hex(random_bytes(8)), 'keys' => ['p256dh' => 'key', 'auth' => 'secret']], 'Firefox/143.0');

        try {
            // Act
            $both = [(new \Pramnos\Llm\Chat\RoutineReady('t', true, '', '/x'))->via($person), (new \Pramnos\Llm\Chat\TaskReminder('w', '/x'))->via($person)];
            $this->configure(['chat' => ['notify_via' => ['database']]]);
            $bell = [(new \Pramnos\Llm\Chat\RoutineReady('t', true, '', '/x'))->via($person), (new \Pramnos\Llm\Chat\TaskReminder('w', '/x'))->via($person)];
            $this->configure(['chat' => ['notify_via' => ['push']]]);
            $push = \Pramnos\Llm\Chat\RoutineReady::channels($person);
            $none = \Pramnos\Llm\Chat\RoutineReady::channels((object) ['userid' => 8]);

            // Assert
            $this->assertSame([['database', 'push'], ['database', 'push']], $both);
            $this->assertSame([['database'], ['database']], $bell);
            $this->assertSame(['push'], $push, 'a list without the bell leaves it out');
            $this->assertSame([], $none, 'push only, and nobody subscribed: nothing');
        } finally {
            $this->db->queryBuilder()->table('pramnos.pushsubscriptions')->where('userid', 7)->delete();
        }
    }
}

/** An analyst its application lets schedule. */
final class SchedulingAnalyst extends AnalystAssistant
{
    /** It may. */
    public function routineRefusal(Scope $scope, bool $adding = false): ?string
    {
        return null;
    }
}

/** A policy whose link is as a cron run builds it with no site address. */
final class RelativeLinkPolicy extends TaskPolicy
{
    public static string $link = 'admin/Ai/report/1';

    /** The link as given. */
    public function link(array $task, array $run): string
    {
        return self::$link;
    }
}
