<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\AskTask;
use Pramnos\Llm\Chat\Asks;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Chat;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\RoutineReady;
use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Policy\StoredConsent;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * Scheduled questions, queued questions and stored consent, on MySQL and PostgreSQL.
 */
#[CoversClass(Routines::class)]
#[CoversClass(RoutineReady::class)]
#[CoversClass(Asks::class)]
#[CoversClass(Conversations::class)]
#[CoversClass(AskTask::class)]
#[CoversClass(Chat::class)]
#[CoversClass(StoredConsent::class)]
#[CoversClass(\Pramnos\Console\Commands\LlmRoutines::class)]
class RoutinesAsksConsentTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        Asks::reset();
    }

    protected function tearDown(): void
    {
        Asks::reset();
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** Routines over a conversation service, with deliveries recorded rather than sent. */
    private function routines(?BaseAssistant $assistant = null): RecordingRoutines
    {
        return new RecordingRoutines(new Conversations($assistant ?? new BaseAssistant()));
    }

    /**
     * A routine is created from words or a template, checked, and listed with its next run on
     * its owner's clock; only its owner can pause, resume or delete it.
     */
    #[DataProvider('databases')]
    public function testARoutineIsScheduled(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['routine_templates' => ['weekly' => ['title' => 'Weekly', 'prompt' => 'Sum up', 'cadence' => 'weekly', 'at' => '09:00', 'day' => 1]]]]);
        $routines = $this->routines();
        $scope    = new Scope(7, 3);

        // Act / Assert — refusals, each with a reason
        $this->assertSame('A scheduled task needs a title and what to ask.', $routines->create($scope, ['cadence' => 'daily']));
        $this->assertSame('There is no such cadence. How often: daily, weekly, monthly, monthly_weekday (the 2nd Tuesday, the last Friday) or once.', $routines->create($scope, ['title' => 't', 'prompt' => 'p', 'cadence' => 'hourly']));
        $this->assertSame('The time is HH:MM, for example 08:00.', $routines->create($scope, ['title' => 't', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '25:00']));
        $this->assertSame('Weekly needs a day, 1 (Monday) to 7 (Sunday).', $routines->create($scope, ['title' => 't', 'prompt' => 'p', 'cadence' => 'weekly', 'at' => '08:00']));
        $this->assertSame('Monthly needs a day of the month, 1 to 28.', $routines->create($scope, ['title' => 't', 'prompt' => 'p', 'cadence' => 'monthly', 'at' => '08:00', 'day' => 31]));

        // From a template, in an unknown zone (UTC), and from words
        $weekly = $routines->create($scope, ['template' => 'weekly', 'timezone' => 'Mars/Olympus']);
        $daily  = $routines->create($scope, ['title' => 'Morning', 'prompt' => 'Report', 'cadence' => 'daily', 'at' => '8:05', 'timezone' => 'Europe/Athens']);
        $this->assertSame(['weekly', 'UTC', 1], [$weekly['template'], $weekly['timezone'], $weekly['day']]);
        $this->assertSame(['08:05', null, true], [$daily['at'], $daily['day'], $daily['active']]);
        $this->assertStringEndsWith('08:05', (string) $daily['next_run_local']);
        $this->assertSame(['Weekly', 'Morning'], array_column($routines->mine($scope), 'title'));
        $this->assertSame([], $routines->mine(new Scope(7, null)), 'another scope sees none');

        // Pause, resume, delete — the owner only
        $this->assertFalse($routines->setActive(new Scope(8, 3), $daily['routine_id'], false));
        $this->assertTrue($routines->setActive($scope, $daily['routine_id'], false));
        $this->assertFalse($routines->find($scope, $daily['routine_id'])['active']);
        $this->assertFalse($routines->delete(new Scope(8, 3), $daily['routine_id']));
        $this->assertTrue($routines->delete($scope, $daily['routine_id']));
        $this->assertNull($routines->find($scope, $daily['routine_id']));
    }

    /**
     * The next run is on the owner's clock, across summer time, on the right weekday and day.
     */
    public function testTheNextRunIsOnTheOwnersClock(): void
    {
        // Arrange — Saturday 24 October 2026, 12:00 UTC; Athens leaves summer time on the 25th
        $after = (int) strtotime('2026-10-24 12:00:00 UTC');

        // Act / Assert
        $this->assertSame('2026-10-25 08:00', date_create('@' . Routines::nextRun('daily', '08:00', null, 'Europe/Athens', $after))->setTimezone(new \DateTimeZone('Europe/Athens'))->format('Y-m-d H:i'));
        $this->assertSame('2026-10-26 09:00 Mon', date_create('@' . Routines::nextRun('weekly', '09:00', 1, 'UTC', $after))->format('Y-m-d H:i D'));
        $this->assertSame('2026-11-01 07:30', date_create('@' . Routines::nextRun('monthly', '07:30', 1, 'UTC', $after))->format('Y-m-d H:i'));
        $this->assertSame('2026-10-24 13:00', date_create('@' . Routines::nextRun('daily', '13:00', null, 'UTC', $after))->format('Y-m-d H:i'), 'later today');
    }

    /**
     * What is due runs as its owner into its own conversation, moves its next run on, and
     * tells the owner; a deleted conversation is started again, and a refusal is recorded.
     */
    #[DataProvider('databases')]
    public function testDueRoutinesRunAndTell(string $type, string $host, int $port): void
    {
        // Arrange — a routine due a minute ago
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['routine_notification' => CapturedNotice::class]]);
        $this->answer([[200, self::says('Report ready')]]);
        $routines = $this->routines();
        $scope    = new Scope(7, 3);
        $made     = $routines->create($scope, ['title' => 'Morning', 'prompt' => 'Report', 'cadence' => 'daily', 'at' => '08:00']);
        $this->db->queryBuilder()->table('ai_routines')->where('routine_id', $made['routine_id'])
            ->update(['next_run_at' => \Pramnos\Llm\CallLog::instant(time() - 60)]);

        // Act
        $ran = $routines->runDue(time());

        // Assert — asked as the owner, scheduled, into a conversation of its own
        $this->assertSame(1, $ran);
        $row = $routines->find($scope, $made['routine_id']);
        $this->assertNotNull($row['conversation_id']);
        $this->assertNull($row['last_error']);
        $this->assertGreaterThan(time(), strtotime((string) $row['next_run_at']));
        $this->assertStringContainsString('Scheduled task "Morning"', $this->sent[0]['body']['messages'][1]['content']);
        $this->assertNotContains('schedule_task', array_column(array_column($this->sent[0]['body']['tools'], 'function'), 'name'));
        $this->assertSame([[7, 'Morning', true, '']], array_map(static fn (array $d): array => [$d[0], $d[1]->title, $d[1]->ok, $d[1]->why], $routines->delivered));
        $this->assertStringEndsWith('chat?c=' . $row['conversation_id'], $routines->delivered[0][1]->link);
        $this->assertSame('Report ready', $routines->mine($scope)[0]['latest']['content']);

        // The owner deleted the conversation: the next run starts another
        (new Conversations(new BaseAssistant()))->as($scope)->delete($row['conversation_id']);
        $again = $routines->run($routines->find($scope, $made['routine_id']), time());
        $this->assertTrue($again['ok']);
        $this->assertNotSame($row['conversation_id'], $again['conversation_id']);

        // A refusal is recorded and told, and the run still moves on
        $refused = $this->routines(new NoRoutines())->run($routines->find($scope, $made['routine_id']), time());
        $this->assertSame(['ok' => false, 'says' => 'Not on this plan.'], array_intersect_key($refused, ['ok' => 1, 'says' => 1]));
        $this->assertSame('Not on this plan.', $routines->find($scope, $made['routine_id'])['last_error']);
    }

    /**
     * A limit on active routines stops adding one — created or resumed — and never a run or
     * the tools.
     */
    #[DataProvider('databases')]
    public function testALimitStopsOnlyAdding(string $type, string $host, int $port): void
    {
        // Arrange — one routine, made while there was room, now paused
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('ran')]]);
        $scope = new Scope(7, null);
        $made  = $this->routines()->create($scope, ['title' => 'Daily', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $full  = $this->routines(new NoRoomForMore());
        $full->setActive($scope, $made['routine_id'], false);

        // Act / Assert
        $this->assertSame('No room for another.', $full->create($scope, ['title' => 'x', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']));
        $this->assertFalse($full->setActive($scope, $made['routine_id'], true), 'resuming is adding');
        $this->assertFalse($full->find($scope, $made['routine_id'])['active']);
        $this->assertTrue($full->setActive($scope, $made['routine_id'], false), 'pausing is never refused');
        $this->assertTrue($full->run($full->find($scope, $made['routine_id']), time())['ok'], 'a run is not adding');
        $this->assertArrayHasKey('schedule_task', $full->tools($scope, 'UTC'), 'the tool stays, and says why when used');
        $this->assertSame('Error: No room for another.', ($full->tools($scope, 'UTC')['schedule_task']['run'])(['kind' => 'ask', 'title' => 'x', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']));
    }

    /**
     * The model schedules, lists and cancels through its tools; without routines, there are none.
     */
    #[DataProvider('databases')]
    public function testTheScheduleTools(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $routines = $this->routines();
        $scope    = new Scope(7, null);
        $tools    = $routines->tools($scope, 'Europe/Athens');

        // Act
        $made   = ($tools['schedule_task']['run'])(['kind' => 'ask', 'title' => 'Daily', 'prompt' => 'Report', 'cadence' => 'daily', 'at' => '08:00']);
        $bad    = ($tools['schedule_task']['run'])(['kind' => 'ask', 'title' => 'x', 'prompt' => 'y', 'cadence' => 'never', 'at' => '08:00']);
        $listed = json_decode(($tools['list_scheduled_tasks']['run'])([]), true);

        // Assert
        $this->assertMatchesRegularExpression('/^Scheduled as task \d+\. Next run: .+ \(Europe\/Athens\)\.$/', $made);
        $this->assertSame('Error: There is no such cadence. How often: daily, weekly, monthly, monthly_weekday (the 2nd Tuesday, the last Friday) or once.', $bad);
        $this->assertSame(['Daily'], array_column($listed, 'title'));
        $this->assertSame('Deleted.', ($tools['cancel_scheduled_task']['run'])(['routine_id' => $listed[0]['routine_id']]));
        $this->assertSame('Error: there is no such task.', ($tools['cancel_scheduled_task']['run'])(['routine_id' => 999]));
        $this->assertSame([], $this->routines(new NoRoutines())->tools($scope, 'UTC'));
        $this->assertSame('Not on this plan.', $this->routines(new NoRoutines())->create($scope, ['title' => 't']));
    }

    /**
     * A queued question: a waiting row and a task, then the worker answers it as its author,
     * marks the row, and tells the listeners with what the caller put in `extra`.
     */
    #[DataProvider('databases')]
    public function testAQueuedQuestion(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('Answered later')]]);
        $queue = new RecordingQueue();
        $asks  = new Asks(new Conversations(new BaseAssistant()), $queue);
        $scope = new Scope(7, 3);
        $heard = [];
        Asks::onAnswered(static function (array $job, array $answer) use (&$heard): void {
            $heard[] = [$job['extra']['reply_to'] ?? null, $answer['ok']];
        });
        Asks::onAnswered(static fn () => throw new \RuntimeException('a listener that fails'));

        // Act
        $askId = $asks->start($scope, null, 'Later, please', null, ['reply_to' => 'discord:1']);
        $waiting = $asks->find($scope, $askId);
        $result  = $asks->run($queue->tasks[0][1]);

        // Assert
        $this->assertSame('waiting', $waiting['status']);
        $this->assertSame([Asks::TASK, 5, 1], [$queue->tasks[0][0], $queue->tasks[0][2], $queue->tasks[0][3]]);
        $this->assertTrue($result['ok']);
        $done = $asks->find($scope, $askId);
        $this->assertSame(['answered', $result['conversation_id']], [$done['status'], $done['conversation_id']]);
        $this->assertSame([['discord:1', true]], $heard);
        $this->assertNull($asks->find(new Scope(8, 3), $askId), 'another person cannot follow it');

        // A failure is marked with its reason
        $this->answer([[500, []]]);
        $failedId = $asks->start($scope, 999, 'again');
        $failed   = $asks->run($queue->tasks[1][1]);
        $this->assertSame(['ok' => false, 'why' => 'missing', 'says' => 'There is no such conversation.'], $failed);
        $this->assertSame('failed', $asks->find($scope, $failedId)['status']);
    }

    /**
     * A question can be stopped while it waits or while it is answered: before the worker
     * reaches it, nothing is asked at all; mid-answer, the worker stops before its next round.
     * Either way the ask says `cancelled` and nothing is kept in a conversation.
     *
     * A Stop that only hid the answer on the screen would leave the worker spending rounds
     * nobody will read; one that could stop somebody else's question, or one already answered,
     * would lose an answer.
     */
    #[DataProvider('databases')]
    public function testAQuestionCanBeStopped(string $type, string $host, int $port): void
    {
        // Arrange — a model that calls a tool every round; the tool is where the person presses Stop
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::calls([['look', []]])]]);
        $queue = new RecordingQueue();
        $asks  = new Asks(new Conversations(new StoppableAssistant()), $queue);
        $scope = new Scope(7, 3);

        // Act — one stopped before the worker reaches it, one stopped while answered
        $early   = $asks->start($scope, null, 'Never mind');
        $refused = $asks->cancel(new Scope(8, 3), $early);
        $asks->cancel($scope, $early);
        $before  = $asks->run($queue->tasks[0][1]);
        $asked   = count($this->sent);

        $later = $asks->start($scope, null, 'Dig deep');
        StoppableAssistant::$onLook = static function () use ($asks, $scope, $later): string {
            $asks->cancel($scope, $later);

            return 'found something';
        };
        $during = $asks->run($queue->tasks[1][1]);

        // Assert — stopped before: no call to the provider at all
        $this->assertFalse($refused, 'another person cannot stop it');
        $this->assertSame(['ok' => false, 'why' => 'cancelled', 'says' => \Pramnos\Llm\Client::STOPPED], $before);
        $this->assertSame(0, $asked, 'nothing was sent for a question stopped while it waited');
        $this->assertSame('cancelled', $asks->find($scope, $early)['status']);

        // Stopped during: one round, then no more, and no conversation kept
        $this->assertFalse($during['ok']);
        $this->assertSame('cancelled', $during['why']);
        $this->assertCount(1, $this->sent, 'the round under way was the last');
        $this->assertSame(['cancelled', null], [$asks->find($scope, $later)['status'], $asks->find($scope, $later)['conversation_id']]);
        $this->assertSame([], (new Conversations(new BaseAssistant()))->as($scope)->list(), 'nothing kept');

        // Already over: there is nothing left to stop
        $this->assertFalse($asks->cancel($scope, $later));
    }

    /**
     * The worker writes the answer's text so far on the ask while the model writes it, for the
     * screen following the question, and empties it once the ask is answered.
     *
     * Followed without it, a long answer is a minute of "thinking" and then a wall of text; with
     * it, the person reads as it arrives. Left behind after the answer, it would be a second copy
     * of the answer kept beside the conversation.
     */
    #[DataProvider('databases')]
    public function testAQueuedAnswerIsSeenWhileItIsWritten(string $type, string $host, int $port): void
    {
        // Arrange — a transport that writes half the answer and looks at the ask before the rest
        $this->connectDatabase($type, $host, $port);
        $this->configure(['providers' => ['openai' => ['transport' => PeekingTransport::class]]]);
        $queue = new RecordingQueue();
        $asks  = new Asks(new Conversations(new BaseAssistant()), $queue);
        $scope = new Scope(7, 3);
        $askId = $asks->start($scope, null, 'Tell me slowly');
        PeekingTransport::$peek = static fn (): ?array => $asks->find($scope, $askId);

        // Act
        $result = $asks->run($queue->tasks[0][1]);

        // Assert
        $this->assertTrue($result['ok']);
        $this->assertSame(['waiting', 'The first half'], [PeekingTransport::$seen['status'], PeekingTransport::$seen['partial']], 'seen while written');
        $done = $asks->find($scope, $askId);
        $this->assertSame(['answered', null], [$done['status'], $done['partial']], 'emptied once answered');
    }

    /**
     * An installation that has not run the migration adding `partial` still gets its answers:
     * the text so far is not written, and the ask is answered whole, as before.
     *
     * Streaming is for the screen; a missing column must cost the screen its preview, never the
     * person their answer.
     */
    #[DataProvider('databases')]
    public function testAQueuedAnswerWithoutThePartialColumnStillComes(string $type, string $host, int $port): void
    {
        // Arrange — the column taken away for this test, and put back after
        $this->connectDatabase($type, $host, $port);
        $this->configure(['providers' => ['openai' => ['transport' => PeekingTransport::class]]]);
        $this->db->schema()->alterTable('chat_asks', static function ($table): void {
            $table->dropColumn('partial');
        });
        $queue = new RecordingQueue();
        $asks  = new Asks(new Conversations(new BaseAssistant()), $queue);
        $scope = new Scope(7, 3);
        $askId = $asks->start($scope, null, 'Tell me slowly');
        PeekingTransport::$peek = static fn (): ?array => $asks->find($scope, $askId);

        try {
            // Act
            $result = $asks->run($queue->tasks[0][1]);

            // Assert
            $this->assertTrue($result['ok']);
            $this->assertNull(PeekingTransport::$seen['partial'], 'not streamed, and find() reads the absent column as nothing');
            $this->assertSame('answered', $asks->find($scope, $askId)['status']);
        } finally {
            $this->migration(\Pramnos\Framework\Migrations\Llm\AddPartialToChatAsks::class)->up();
        }
    }

    /**
     * An answer that throws in the worker is a failed ask with the reason, not a lost one.
     */
    #[DataProvider('databases')]
    public function testAnAnswerThatThrowsIsAFailedAsk(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $queue = new RecordingQueue();
        $asks  = new Asks(new Conversations(new class extends BaseAssistant {
            public function acting(Scope $scope, callable $run): mixed
            {
                throw new \RuntimeException('tenant gone');
            }
        }), $queue);
        $id = $asks->start(new Scope(7, null), null, 'x');

        // Act
        $result = $asks->run($queue->tasks[0][1]);

        // Assert
        $this->assertSame(['ok' => false, 'why' => 'provider', 'says' => 'The AI stopped: tenant gone'], $result);
        $this->assertSame('failed', $asks->find(new Scope(7, null), $id)['status']);
    }

    /**
     * The queue answers `chat_ask` without being told how, with the configured assistant.
     */
    #[DataProvider('databases')]
    public function testTheWorkerKnowsTheTask(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->answer([[200, self::says('ok')]]);
        $queue = new RecordingQueue();
        (new Asks(new Conversations(Chat::assistant()), $queue))->start(new Scope(7, null), null, 'From the worker');
        $item = $this->getMockBuilder(\Pramnos\Queue\QueueItem::class)->disableOriginalConstructor()->getMock();
        $item->payload = json_encode($queue->tasks[0][1]);
        $task = (new \ReflectionClass(AskTask::class))->newInstanceWithoutConstructor();

        // Act
        $result = $task->execute($item);

        // Assert
        $this->assertTrue($result);
        $this->assertSame('Answer a queued question ' . $queue->tasks[0][1]['ask_id'], $task->getDescription($item));
        $this->assertSame(AskTask::class, \Pramnos\Queue\Worker::FRAMEWORK_HANDLERS[Asks::TASK]);
        $this->assertInstanceOf(BaseAssistant::class, Chat::assistant());
        $this->configure(['chat' => ['assistant' => \stdClass::class]]);
        $this->assertInstanceOf(BaseAssistant::class, Chat::assistant(), 'a class that is not one falls back');
    }

    /**
     * Stored consent: only a provider that asks for it is checked; a person agrees for themselves,
     * a background call needs anybody in the organisation, a call with neither is refused unless
     * the application says calls outside an organisation carry nobody's data.
     */
    #[DataProvider('databases')]
    public function testStoredConsent(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $consent = new StoredConsent();

        // Act / Assert — a provider that does not ask
        $this->assertNull($consent->refusal(['userid' => 7], 'openai'));

        // A person, in an organisation and outside one
        $this->assertSame('Nothing was sent: the AI features use DeepSeek, and you have not agreed to that yet.',
            $consent->refusal(['userid' => 7, 'organization_id' => 3], 'deepseek'));
        $consent->give(3, 7, 'deepseek');
        $consent->give(3, 7, 'deepseek');
        $this->assertCount(1, $this->rows('ai_consents'), 'giving twice is giving once');
        $this->assertNull($consent->refusal(['userid' => 7, 'organization_id' => 3], 'deepseek'));
        $this->assertNotNull($consent->refusal(['userid' => 7], 'deepseek'), 'agreeing inside an organisation is not agreeing outside it');
        $consent->give(null, 7, 'deepseek');
        $this->assertNull($consent->refusal(['userid' => 7], 'deepseek'));
        $this->assertNotNull($consent->refusal(['userid' => 8, 'organization_id' => 3], 'deepseek'), 'each person agrees for themselves');

        // A background call
        $this->assertNull($consent->refusal(['organization_id' => 3], 'deepseek'), 'anybody in the organisation');
        $this->assertStringContainsString('nobody in this organisation has', (string) $consent->refusal(['organization_id' => 4], 'deepseek'));
        $this->assertStringContainsString('nobody has', (string) $consent->refusal([], 'deepseek'));
        $this->configure(['consent_options' => ['allow_without_organization' => true]]);
        $this->assertNull($consent->refusal([], 'deepseek'));

        // Withdrawn
        $consent->withdraw(3, 7, 'deepseek');
        $this->assertFalse($consent->has(3, 7, 'deepseek'));
        $this->assertTrue($consent->has(null, 7, 'deepseek'));
        $this->assertTrue(StoredConsent::required('deepseek'));
    }

    /**
     * Tables that existed before keep their rows and gain `tags` and `extra`; the creating
     * migrations leave them alone.
     */
    #[DataProvider('databases')]
    public function testExistingTablesGainTheirColumns(string $type, string $host, int $port): void
    {
        // Arrange — the tables as an application had them
        $this->connectDatabase($type, $host, $port);
        $this->llmSchemaChanged();
        $this->db->schema()->alterTable('chat_conversations', fn ($t) => $t->dropColumn('tags'));
        $this->db->schema()->alterTable('chat_messages', fn ($t) => $t->dropColumn('extra'));

        // Act
        $migrations = $this->migrations();
        $this->assertNotEmpty($migrations, 'the sweep found nothing to check');
        foreach ($migrations as $class) {
            $this->migration($class)->up();
        }

        // Assert
        $this->assertTrue($this->db->schema()->hasColumn('chat_conversations', 'tags'));
        $this->assertTrue($this->db->schema()->hasColumn('chat_messages', 'extra'));
        foreach (array_reverse($migrations) as $class) {
            $this->migration($class)->down();
        }
        $this->assertFalse($this->db->schema()->hasTable('ai_routines'));
    }

    /**
     * llm:routines runs what is due, and succeeds on an installation without the table.
     */
    #[DataProvider('databases')]
    public function testTheRoutinesCommand(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\LlmRoutines());

        // Act
        $nothing = $tester->execute([]);
        $said    = $tester->getDisplay();
        $this->llmSchemaChanged();
        $this->db->schema()->dropTableIfExists('ai_routines');
        $absent = $tester->execute([]);

        // Assert
        $this->assertSame([0, 0], [$nothing, $absent]);
        $this->assertStringContainsString('Nothing was due.', $said);
        $this->assertStringContainsString('No scheduled questions here.', $tester->getDisplay());
    }

    /**
     * The framework's notification: in the person's notifications, and as a push.
     */
    public function testTheNotification(): void
    {
        // Arrange
        $ok     = new RoutineReady('Morning', true, '', 'https://x/chat?c=1');
        $failed = new RoutineReady('Morning', false, 'No credit.', 'https://x/chat');

        // Assert
        $this->assertSame(['message' => 'Morning is ready.', 'url' => 'https://x/chat?c=1'], $ok->toDatabase(null));
        $this->assertSame('Morning could not be made: No credit.', $failed->toDatabase(null)['message']);
        $this->assertSame('Your scheduled report is ready.', $ok->toPush(null)['body']);
        $this->assertStringStartsWith('routine-', $failed->toPush(null)['tag']);
        $this->assertContains('database', $ok->via((object) ['userid' => 0]));
    }
}

/** Routines whose notifications are recorded rather than sent. */
final class RecordingRoutines extends Routines
{
    /** @var list<array{0: int, 1: object}> */
    public array $delivered = [];

    protected function deliver(int $userId, object $notification): void
    {
        $this->delivered[] = [$userId, $notification];
    }
}

/** A notification with its constructor's arguments readable. */
final class CapturedNotice implements \Pramnos\Notification\NotificationInterface
{
    public function __construct(public string $title, public bool $ok, public string $why, public string $link)
    {
    }

    public function via(mixed $notifiable): array
    {
        return [];
    }
}

/** An assistant whose plan has no routines. */
final class NoRoutines extends BaseAssistant
{
    public function routineRefusal(Scope $scope, bool $adding = false): ?string
    {
        return 'Not on this plan.';
    }
}

/** An assistant whose plan allows routines but no more active ones. */
final class NoRoomForMore extends BaseAssistant
{
    public function routineRefusal(Scope $scope, bool $adding = false): ?string
    {
        return $adding ? 'No room for another.' : null;
    }
}

/** A queue that records its tasks. */
final class RecordingQueue extends \Pramnos\Queue\QueueManager
{
    /** @var list<array{0: string, 1: array<string, mixed>, 2: int, 3: int}> */
    public array $tasks = [];

    public function __construct()
    {
    }

    public function addTask(string $taskType, mixed $data, int $priority = 10, int $maxAttempts = 3, bool $unique = false): ?int
    {
        $this->tasks[] = [$taskType, $data, $priority, $maxAttempts];

        return count($this->tasks);
    }
}

/** A transport that streams half an answer, looks at the ask, then finishes it — or, unstreamed, looks and answers. */
final class PeekingTransport implements \Pramnos\Llm\Transport\Transport
{
    /** @var (callable(): ?array<string, mixed>)|null What to look at between the halves */
    public static $peek = null;

    /** @var array<string, mixed>|null What it saw */
    public static ?array $seen = null;

    /**
     * Unstreamed — nobody listening: a look, and the answer whole.
     *
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function chat(\Pramnos\Llm\Connection $connection, array $payload): array
    {
        self::$seen = (self::$peek)();

        return [200, ['choices' => [['message' => ['role' => 'assistant', 'content' => 'Whole at once.'], 'finish_reason' => 'stop']]]];
    }

    /**
     * Half the text, a look, the rest.
     *
     * @param array<string, mixed>   $payload
     * @param callable(string): void $onText
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function chatStream(\Pramnos\Llm\Connection $connection, array $payload, callable $onText): array
    {
        $onText('The first half');
        self::$seen = (self::$peek)();
        $onText(', and the rest.');

        return [200, ['choices' => [['message' => ['role' => 'assistant', 'content' => 'The first half, and the rest.'], 'finish_reason' => 'stop']]]];
    }

    /** Not used. */
    public function get(\Pramnos\Llm\Connection $connection, string $path): array
    {
        return [404, null];
    }
}

/** An assistant with one tool, `look`, which runs what the test gives it. */
final class StoppableAssistant extends BaseAssistant
{
    /** @var (callable(): string)|null */
    public static $onLook = null;

    /** @return array<string, array<string, mixed>> */
    public function tools(\Pramnos\Llm\Chat\Turn $turn): array
    {
        return ['look' => ['description' => 'Looks', 'parameters' => [], 'run' => static fn (): string => self::$onLook !== null ? (self::$onLook)() : 'nothing']];
    }
}
