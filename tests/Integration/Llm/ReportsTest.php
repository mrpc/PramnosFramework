<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Http\Request;
use Pramnos\Llm\Chat\Api\ChatController;
use Pramnos\Llm\Chat\Api\Routes;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\TaskPolicy;
use Pramnos\Llm\Chat\Turn;
use Pramnos\Routing\Router;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * Shared AI reports: who may make one for an organisation or the installation, a run asked
 * headless with read-only tools and kept, its readers told — only those who may still read it,
 * and only when it matters if so asked — subscriptions, a report opened as a reader's own
 * conversation, and the JSON API over all of it; on MySQL and PostgreSQL.
 */
#[CoversClass(Routines::class)]
#[CoversClass(TaskPolicy::class)]
#[CoversClass(Conversations::class)]
#[CoversClass(ChatController::class)]
#[CoversClass(Routes::class)]
#[CoversClass(\Pramnos\Llm\Chat\Web\ChatPageController::class)]
class ReportsTest extends LlmTestCase
{
    use ChatDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        ReportPolicy::$types   = [7 => 80, 8 => 80, 9 => 20, 10 => 99, 11 => 90];
        ReportPolicy::$members = [3 => [7, 8, 9]];
        ReportPolicy::$exists  = true;
        ApiFixture::$scope     = new Scope(8, 3);
        ApiFixture::$input     = [];
        ApiFixture::$stop      = null;
    }

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** Reports over an assistant with a read tool and a write tool, deliveries recorded. */
    private function tasks(?BaseAssistant $assistant = null): RecordingTasks
    {
        return new RecordingTasks(new Conversations($assistant ?? new ReadingAssistant()));
    }

    /** The policy of this test, with notifications captured. */
    private function policy(): void
    {
        $this->configure(['chat' => ['task_policy' => ReportPolicy::class, 'routine_notification' => CapturedNotice::class]]);
    }

    /** An organisation's report, made by 7. */
    private function report(RecordingTasks $tasks, array $more = []): array
    {
        $made = $tasks->create(new Scope(7, 3), $more + ['kind' => 'report', 'owner' => 'organization', 'title' => 'Weekly',
            'prompt' => 'Bookings this week', 'cadence' => 'weekly', 'day' => 1, 'at' => '08:00', 'resource_type' => 'property', 'resource_id' => 42]);
        $this->assertIsArray($made, is_string($made) ? $made : '');

        return $made;
    }

    /** One request through the routes. */
    private function call(string $method, string $uri, array $input = []): mixed
    {
        ApiFixture::$input = $input;
        $router = new Router(null);
        Routes::register($router, ApiFixture::class);

        return $router->dispatch(Request::create($uri, $method));
    }

    /**
     * Only a report is shared, and only by those the policy lets: an organisation's at the
     * administration level inside one, the installation's by its administrators; an unknown owner
     * and a shared question are refused.
     */
    #[DataProvider('databases')]
    public function testWhoMayShareAReport(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $tasks = $this->tasks();
        $base  = ['kind' => 'report', 'title' => 'R', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00'];

        // Act
        $low        = $tasks->create(new Scope(9, 3), $base + ['owner' => 'organization']);
        $noOrg      = $tasks->create(new Scope(7, null), $base + ['owner' => 'organization']);
        $orgOk      = $tasks->create(new Scope(7, 3), $base + ['owner' => 'organization', 'notify' => 'relevant']);
        $globalLow  = $tasks->create(new Scope(7, 3), $base + ['owner' => 'global']);
        $globalOk   = $tasks->create(new Scope(11, null), $base + ['owner' => 'global']);
        $unknown    = $tasks->create(new Scope(7, 3), $base + ['owner' => 'team']);
        $sharedAsk  = $tasks->create(new Scope(7, 3), ['kind' => 'ask', 'owner' => 'organization'] + $base);
        $badKind    = $tasks->create(new Scope(7, 3), ['kind' => 'poem'] + $base);

        // Assert
        $this->assertSame('You may not make a report for the whole organisation.', $low);
        $this->assertSame('You may not make a report for the whole organisation.', $noOrg, 'an organisation report needs an organisation');
        $this->assertSame(['organization', 'relevant'], [$orgOk['owner'], $orgOk['notify']]);
        $this->assertSame('You may not make a report for the whole installation.', $globalLow);
        $this->assertSame('global', $globalOk['owner']);
        $this->assertStringStartsWith('Only a report is shared', $unknown);
        $this->assertStringStartsWith('Only a report is shared', $sharedAsk);
        $this->assertStringContainsString('a report (report)', $badKind);
    }

    /**
     * A report runs headless: no conversation is kept, the assistant's write tools, the memory's
     * and the scheduling tools are not offered, the maker's memory is not in the prompt; the run
     * is kept; its maker and each subscriber who may still read it are told, with a link that
     * opens the run, under a title naming what it is about.
     */
    #[DataProvider('databases')]
    public function testAReportRunsHeadlessAndReachesItsReaders(string $type, string $host, int $port): void
    {
        // Arrange — 8 may read it, 9 is of lower standing, 10 is not a member
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $this->answer([[200, self::says('12 bookings, 3 more than last week.')]]);
        $tasks = $this->tasks();
        $made  = $this->report($tasks);
        (new \Pramnos\Llm\Chat\Memory(new Scope(7, 3)))->remember('preference', 'My wife is called Eleni');
        foreach ([8, 9, 10] as $userId) {
            $this->db->queryBuilder()->table('ai_task_subscriptions')->insert(['routine_id' => $made['routine_id'], 'userid' => $userId, 'email' => false]);
        }

        // Act
        $ran = $tasks->run($tasks->byId($made['routine_id']), time());
        $run = $tasks->runs($made['routine_id'])[0];

        // Assert — asked once, read-only, without the memory
        $this->assertTrue($ran['ok']);
        $offered = array_column(array_column($this->sent[0]['body']['tools'], 'function'), 'name');
        $this->assertSame(['read_figures'], $offered, 'no write, memory or scheduling tool');
        $this->assertStringNotContainsString('Eleni', $this->sent[0]['raw'], 'the maker\'s memory stays theirs');
        $this->assertStringStartsWith('Scheduled report "Weekly" about Villa 42', $this->sent[0]['body']['messages'][1]['content']);
        $this->assertSame([], $this->rows('chat_conversations'), 'nothing is kept as a conversation');
        $this->assertSame(['ok', '12 bookings, 3 more than last week.', [7, 8]], [$run['status'], $run['content'], $run['extra']['notified']]);
        $this->assertSame([7, 8], array_column($tasks->delivered, 0), 'the maker, and the reader who may still read it');
        $this->assertSame('Weekly — Villa 42', $tasks->delivered[0][1]->title);
        $this->assertStringEndsWith('chat/report/' . $run['run_id'], $tasks->delivered[1][1]->link);
        $this->assertNull($ran['conversation_id']);
    }

    /**
     * A reader whose subscription asks for email gets the report itself by email — its title, its
     * words drawn from Markdown, the link to ask about it — beside the bell; the others get the
     * notice only; a failed run sends no email; and an application's own notification class,
     * written for four arguments, still works.
     */
    #[DataProvider('databases')]
    public function testAReportByEmail(string $type, string $host, int $port): void
    {
        // Arrange — the framework's own notification; 8 receives it by email, 7 made it
        $this->connectDatabase($type, $host, $port);
        $this->configure(['chat' => ['task_policy' => ReportPolicy::class]]);
        $this->answer([[200, self::says("**12** bookings <script>x</script>")], [500, ['error' => 'down']], [500, ['error' => 'down']], [500, ['error' => 'down']]]);
        $tasks = $this->tasks();
        $made  = $this->report($tasks);
        $tasks->subscribe(8, $made['routine_id'], true);

        // Act
        $tasks->run($tasks->byId($made['routine_id']), time());
        $sent = $tasks->delivered;
        $tasks->delivered = [];
        $tasks->run($tasks->byId($made['routine_id']), time());
        $failed = $tasks->delivered;

        // Assert
        $this->assertSame([7, 8], array_column($sent, 0));
        [$maker, $reader] = [$sent[0][1], $sent[1][1]];
        $this->assertNotContains('mail', $maker->via((object) ['userid' => 7]), 'the maker did not ask for email');
        $this->assertContains('mail', $reader->via((object) ['userid' => 8]));
        $mail = $reader->toMail(null);
        $this->assertSame('Weekly — Villa 42', $mail['subject']);
        $this->assertStringContainsString('<strong>12</strong>', $mail['body']);
        $this->assertStringNotContainsString('<script>', $mail['body'], 'escaped before it is drawn');
        $this->assertStringContainsString('chat/report/', $mail['body']);
        $this->assertSame([7], array_column($failed, 0), 'a failure is the maker\'s alone');
        $this->assertNotContains('mail', $failed[0][1]->via((object) ['userid' => 7]));
        $this->assertSame([8], $tasks->emailSubscribers($made['routine_id']));
    }

    /**
     * A scheduled run's calls say which task they were made for — `llm_calls.routine_id` — and a
     * conversation's do not; the purpose stays the space's, since it is what routes the call. The
     * analyst's spend shows the tasks' runs apart, by task.
     */
    #[DataProvider('databases')]
    public function testARunsCallsSayWhichTask(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $this->answer([[200, self::says('12 bookings.')], [200, self::says('Hello.')]]);
        $tasks = $this->tasks();
        $made  = $this->report($tasks);

        // Act — the report runs, then the maker asks in a conversation
        $tasks->run($tasks->byId($made['routine_id']), time());
        (new Conversations(new ReadingAssistant()))->as(new Scope(7, 3))->ask(null, 'Hi');
        $calls = $this->rows('llm_calls');
        \Pramnos\Http\RequestIdentity::seal((object) ['userid' => 7, 'usertype' => 99], 'test');
        $spend = (new \Pramnos\Llm\Analyst\Tools\LlmSpendTool())->execute(['days' => 1]);
        // Before the migration the log cannot say which task: the spend says nothing about tasks
        $this->llmSchemaChanged();
        $this->migration(\Pramnos\Framework\Migrations\Llm\AddRoutineIdToLlmCalls::class)->down();
        $before = (new \Pramnos\Llm\Analyst\Tools\LlmSpendTool())->execute(['days' => 1]);
        $this->migration(\Pramnos\Framework\Migrations\Llm\AddRoutineIdToLlmCalls::class)->up();
        \Pramnos\Http\RequestIdentity::reset();

        // Assert
        $this->assertCount(2, $calls);
        $this->assertSame([$made['routine_id'], null], array_map(static fn (array $c): ?int => $c['routine_id'] === null ? null : (int) $c['routine_id'], $calls));
        $this->assertSame(['chat', 'chat'], array_column($calls, 'purpose'), 'the purpose still routes');
        $this->assertSame(1, $spend['scheduled']['calls']);
        $this->assertSame([(string) $made['routine_id']], array_map('strval', array_keys($spend['scheduled']['by_task'])));
        $this->assertNull($before['scheduled']);
    }

    /**
     * Asked to tell only what matters, a report that decides there is nothing new tells nobody
     * and is still kept; one that never decides tells, rather than staying silent by omission.
     */
    #[DataProvider('databases')]
    public function testARelevantReportTellsOnlyWhenItMatters(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $tasks = $this->tasks();
        $made  = $this->report($tasks, ['notify' => 'relevant']);

        // Act — decided not relevant; then answered without deciding
        $this->answer([[200, self::calls([['report_relevance', ['relevant' => false, 'why' => 'Same as last week.']]])], [200, self::says('Nothing new.')]]);
        $tasks->run($tasks->byId($made['routine_id']), time());
        $quiet = $tasks->delivered;
        $offered = array_column(array_column($this->sent[0]['body']['tools'], 'function'), 'name');
        $this->answer([[200, self::says('Something new.')]]);
        $tasks->run($tasks->byId($made['routine_id']), time());
        $runs = $tasks->runs($made['routine_id']);

        // Assert
        $this->assertContains('report_relevance', $offered);
        $this->assertSame([], $quiet, 'nothing new, nobody told');
        $this->assertSame(['Nothing new.', false], [$runs[1]['content'], $runs[1]['extra']['relevant']], 'kept all the same');
        $this->assertArrayNotHasKey('notified', $runs[1]['extra']);
        $this->assertSame([7], array_column($tasks->delivered, 0), 'no decision: told');
    }

    /**
     * A report that fails tells its maker only — readers get reports, not errors — and a report
     * about something gone is paused without counting a failure, its maker told why.
     */
    #[DataProvider('databases')]
    public function testFailuresAndWhatIsGone(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $this->answer([[500, ['error' => ['message' => 'Overloaded']]]]);
        $tasks = $this->tasks();
        $made  = $this->report($tasks);
        $this->db->queryBuilder()->table('ai_task_subscriptions')->insert(['routine_id' => $made['routine_id'], 'userid' => 8, 'email' => false]);

        // Act
        $failed = $tasks->run($tasks->byId($made['routine_id']), time());
        $told   = $tasks->delivered;
        ReportPolicy::$exists = false;
        $gone   = $tasks->run($tasks->byId($made['routine_id']), time());
        $after  = $tasks->byId($made['routine_id']);

        // Assert
        $this->assertFalse($failed['ok']);
        $this->assertSame([7], array_column($told, 0));
        $this->assertFalse($told[0][1]->ok);
        $this->assertSame('Paused: what it is about no longer exists.', $gone['says']);
        $this->assertSame([false, 1, 'What it is about no longer exists.'], [$after['active'], $after['failures'], $after['last_error']], 'one failure, from the run before');
        $this->assertSame('It was paused: what it is about no longer exists.', end($tasks->delivered)[1]->why);
        $this->assertCount(1, $tasks->runs($made['routine_id']), 'nothing ran on what is gone');
    }

    /**
     * A reader subscribes to a report they may see — again, to change the email choice — and not
     * to one they may not, nor to anything but a report; the shared reports here are listed with
     * whether they read each; they unsubscribe; an export holds what a person made and reads.
     */
    #[DataProvider('databases')]
    public function testSubscriptions(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $tasks  = $this->tasks();
        $made   = $this->report($tasks);
        $mine   = $tasks->create(new Scope(8, 3), ['title' => 'Mine', 'prompt' => 'x', 'cadence' => 'daily', 'at' => '08:00']);
        $global = $tasks->create(new Scope(11, null), ['kind' => 'report', 'owner' => 'global', 'title' => 'Everywhere', 'prompt' => 'x', 'cadence' => 'daily', 'at' => '08:00']);
        $id     = $made['routine_id'];

        // Act
        $first    = $tasks->subscribe(8, $id);
        $again    = $tasks->subscribe(8, $id, true);
        $lower    = $tasks->subscribe(9, $id);
        $outsider = $tasks->subscribe(10, $id);
        $notOne   = $tasks->subscribe(7, $mine['routine_id']);
        $listed   = $tasks->reports(new Scope(8, 3));
        $email    = $this->rows('ai_task_subscriptions')[0]['email'];
        $off      = $tasks->unsubscribe(8, $id);
        $offAgain = $tasks->unsubscribe(8, $id);
        $tasks->subscribe(7, $id);
        $export   = $tasks->forUser(7);
        $above    = $tasks->subscribe(7, $global['routine_id']);

        // Assert
        $this->assertNull($first);
        $this->assertNull($again);
        $this->assertTrue(in_array($email, [true, 1, '1', 't'], true), 'subscribing again changed the email choice');
        $this->assertSame('There is no such report you may read.', $lower, 'a reader of lower standing than its maker');
        $this->assertSame('There is no such report you may read.', $outsider, 'not a member of its organisation');
        $this->assertSame('There is no such report you may read.', $notOne, 'only a report has readers');
        $this->assertSame([$id], array_column($listed, 'routine_id'), 'the installation\'s is above 8\'s standing');
        $this->assertTrue($listed[0]['subscribed']);
        $this->assertNull($listed[0]['latest_run']);
        $this->assertTrue($off);
        $this->assertFalse($offAgain);
        $this->assertSame([7], $tasks->subscribers($id), 'only the maker, who subscribed after');
        $this->assertSame([$id], array_column($export['tasks'], 'routine_id'));
        $this->assertSame([$id], $export['subscriptions'], 'a maker may read their own');
        $this->assertSame('There is no such report you may read.', $above, 'the installation\'s, made above 7\'s standing');
    }

    /**
     * A shared report's maker chooses its readers: everyone from a role up, people by name, or
     * both — kept on the report, read by the default rule in place of "the maker's standing and
     * up", still only inside the organisation; changed later by the maker, and cleared back to
     * the default; refused for a role or a person that does not exist, and for one person's task.
     */
    #[DataProvider('databases')]
    public function testTheMakerChoosesTheReaders(string $type, string $host, int $port): void
    {
        // Arrange — 9 is of lower standing than 7, the maker; 10 is not a member
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $tasks = $this->tasks();

        // Act
        $byRole   = $this->report($tasks, ['audience_role' => 'manager']);
        $byName   = $this->report($tasks, ['audience' => ['users' => [9, 10]]]);
        $plain    = $this->report($tasks);
        $roleSees = [$tasks->subscribe(8, $byRole['routine_id']), $tasks->subscribe(9, $byRole['routine_id'])];
        $nameSees = [$tasks->subscribe(9, $byName['routine_id']), $tasks->subscribe(10, $byName['routine_id']), $tasks->subscribe(8, $byName['routine_id'])];
        $noRole   = $tasks->create(new Scope(7, 3), ['kind' => 'report', 'owner' => 'organization', 'title' => 'x', 'prompt' => 'x', 'cadence' => 'daily', 'at' => '08:00', 'audience_role' => 'emperor']);
        $tooLow   = $tasks->create(new Scope(7, 3), ['kind' => 'report', 'owner' => 'organization', 'title' => 'x', 'prompt' => 'x', 'cadence' => 'daily', 'at' => '08:00', 'audience' => ['min_usertype' => 0]]);
        $changed  = $tasks->update(new Scope(7, 3), $plain['routine_id'], ['audience_role' => '', 'audience' => ['users' => [9]]]);
        $cleared  = $tasks->update(new Scope(7, 3), $byName['routine_id'], ['audience_role' => '', 'audience_users' => '']);
        $mine     = $tasks->create(new Scope(7, 3), ['title' => 'Mine', 'prompt' => 'x', 'cadence' => 'daily', 'at' => '08:00']);
        $notShared = $tasks->update(new Scope(7, 3), $mine['routine_id'], ['audience_role' => 'manager']);

        // Assert
        $this->assertSame(['min_usertype' => 80, 'users' => []], $byRole['audience'], 'the role, as its usertype');
        $this->assertSame([null, 'There is no such report you may read.'], $roleSees, 'a manager may; 9 is below');
        $this->assertSame([null, 'There is no such report you may read.', 'There is no such report you may read.'], $nameSees,
            '9 by name although below the maker; 10 named but outside the organisation; 8 not named');
        $this->assertNull($plain['audience'], 'nothing chosen: the default rule');
        $this->assertSame('There is no role called emperor.', $noRole);
        $this->assertSame('A usertype is between 1 and 99.', $tooLow);
        $this->assertSame(['min_usertype' => null, 'users' => [9]], $changed['audience']);
        $this->assertNull($cleared['audience'], 'cleared back to the default');
        $this->assertSame('Only a shared report has readers to choose.', $notShared);
    }

    /**
     * A refusal that names something — the role, the people — is in the page's language: the
     * name is a placeholder, so a translation of the whole sentence applies.
     */
    #[DataProvider('databases')]
    public function testTheReadersRefusalsAreTranslated(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Auth\CreateUsersTable::class], $this->db);
        $this->policy();
        \Pramnos\Translator\Language::setInstance(new class extends \Pramnos\Translator\Language {
            public function __construct()
            {
            }

            /** Two sentences in Greek, formatted with their values. */
            public function _($string = '', $args = '')
            {
                $greek = ['There is no role called %s.' => 'Δεν υπάρχει ρόλος %s.', 'There is nobody called %s.' => 'Δεν υπάρχει κανείς με όνομα %s.'];
                $values = array_slice(func_get_args(), 1);

                return vsprintf($greek[$string] ?? $string, $values);
            }
        });
        $shared = ['kind' => 'report', 'owner' => 'organization', 'title' => 'x', 'prompt' => 'x', 'cadence' => 'daily', 'at' => '08:00'];

        try {
            // Act
            $role   = $this->tasks()->create(new Scope(7, 3), $shared + ['audience_role' => 'emperor']);
            $nobody = $this->tasks()->create(new Scope(7, 3), $shared + ['audience' => [], 'audience_users' => 'nobody-at-all']);
        } finally {
            \Pramnos\Translator\Language::setInstance(null);
        }

        // Assert
        $this->assertSame('Δεν υπάρχει ρόλος emperor.', $role);
        $this->assertSame('Δεν υπάρχει κανείς με όνομα nobody-at-all.', $nobody);
    }

    /**
     * The migration adds `ai_routines.audience` and takes it away again; run twice, it does
     * nothing the second time; and a report made while the column is missing — an installation
     * that has not migrated — still schedules when it names no readers.
     */
    #[DataProvider('databases')]
    public function testTheAudienceColumnComesAndGoes(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $schema    = $this->db->schema();
        $migration = $this->migration(\Pramnos\Framework\Migrations\Llm\AddAudienceToAiRoutines::class);

        // Act
        $this->llmSchemaChanged();
        $migration->down();
        $gone    = $schema->hasColumn('ai_routines', 'audience');
        $without = $this->report($this->tasks());
        $migration->up();
        $migration->up();
        $back = $schema->hasColumn('ai_routines', 'audience');

        // Assert
        $this->assertFalse($gone);
        $this->assertNull($without['audience'], 'scheduled without the column');
        $this->assertTrue($back);
    }

    /**
     * The default policy finds a report's named readers by username or email, says who it did not
     * find, and shows them back by username; the form names them so.
     */
    #[DataProvider('databases')]
    public function testReadersAreNamedByUsernameOrEmail(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Auth\CreateUsersTable::class], $this->db);
        $mark  = 'rd' . bin2hex(random_bytes(3));
        $users = $this->db->queryBuilder()->table('#PREFIX#users');
        foreach (['a', 'b'] as $who) {
            $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark . $who, 'email' => $mark . $who . '@example.com', 'password' => 'x', 'regdate' => time()]);
        }
        $policy = new TaskPolicy(new ReadingAssistant());
        // The test's standing for 11 — an administrator — and the default lookup of names
        $this->policy();
        $tasks  = $this->tasks();

        try {
            // Act
            $found   = $policy->audienceUsers([$mark . 'a', $mark . 'b@example.com', 'nobody-' . $mark]);
            $names   = $policy->audienceNames(array_values($found));
            $none    = $policy->audienceNames([]);
            $shared  = ['kind' => 'report', 'owner' => 'global', 'title' => 'x', 'prompt' => 'x', 'cadence' => 'daily', 'at' => '08:00'];
            $refused = $tasks->create(new Scope(11, null), $shared + ['audience_users' => $mark . 'a, nobody-' . $mark]);
            $made    = $tasks->create(new Scope(11, null), $shared + ['audience_users' => $mark . 'a, ' . $mark . 'b@example.com']);

            // Assert
            $this->assertSame([$mark . 'a', $mark . 'b@example.com'], array_keys($found));
            $this->assertSame([$mark . 'a', $mark . 'b'], array_values($names));
            $this->assertSame([], $none);
            $this->assertSame('There is nobody called nobody-' . $mark . '.', $refused);
            $this->assertSame(array_values($found), $made['audience']['users'], 'kept by id');
        } finally {
            $users->where('username', 'like', $mark . '%')->delete();
        }
    }

    /**
     * A run opens as a conversation of the reader's own, holding the report: the same one when
     * opened again, a new one when that was deleted; nothing for a reader who may not see it, a
     * failed run, or no run at all.
     */
    #[DataProvider('databases')]
    public function testAReportContinuesInAsk(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $this->answer([[200, self::says('12 bookings.')]]);
        $tasks = $this->tasks();
        $made  = $this->report($tasks);
        $tasks->run($tasks->byId($made['routine_id']), time());
        $runId = $tasks->runs($made['routine_id'])[0]['run_id'];
        $this->answer([[500, []]]);
        $tasks->run($tasks->byId($made['routine_id']), time());
        $failedId = $tasks->runs($made['routine_id'])[0]['run_id'];
        $reader   = new Scope(8, 3);

        // Act
        $opened = $tasks->continueRun($reader, $runId);
        $again  = $tasks->continueRun($reader, $runId);
        (new Conversations(new ReadingAssistant()))->as($reader)->delete($opened);
        $fresh  = $tasks->continueRun($reader, $runId);

        // Assert
        $held = (new Conversations(new ReadingAssistant()))->as($reader)->find($fresh);
        $this->assertSame($opened, $again, 'opened again, the same conversation');
        $this->assertNotSame($opened, $fresh, 'deleted, a new one');
        $this->assertSame(['user', 'assistant'], array_column($held['messages'], 'role'));
        $this->assertSame('12 bookings.', $held['messages'][1]['content']);
        $this->assertSame($fresh, $tasks->opened($runId, 8));
        $this->assertNull($tasks->continueRun(new Scope(9, 3), $runId), 'may not see it');
        $this->assertNull($tasks->continueRun($reader, $failedId), 'a failure is nothing to ask about');
        $this->assertNull($tasks->continueRun($reader, 999999));
        $this->assertNull($tasks->opened(999999, 8));
    }

    /**
     * The model shares a report when asked — refused for someone the policy does not let — lists
     * the reports here, and subscribes and unsubscribes a person by id.
     */
    #[DataProvider('databases')]
    public function testTheModelSharesAndSubscribes(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $tasks  = $this->tasks();
        $maker  = $tasks->toolsFor(new Scope(7, 3), 'Europe/Athens', null);
        $reader = $tasks->toolsFor(new Scope(8, 3), 'Europe/Athens', null);
        $lower  = $tasks->toolsFor(new Scope(9, 3), 'Europe/Athens', null);

        // Act
        $shared  = $maker['schedule_task']['run'](['kind' => 'report', 'owner' => 'organization', 'title' => 'Weekly', 'prompt' => 'Bookings', 'cadence' => 'daily', 'at' => '08:00']);
        $refused = $lower['schedule_task']['run'](['kind' => 'report', 'owner' => 'organization', 'title' => 'Other', 'prompt' => 'x', 'cadence' => 'daily', 'at' => '08:00']);
        $id      = (int) preg_replace('/\D.*$/s', '', substr($shared, strlen('Scheduled as task ')));
        $list    = json_decode($reader['list_reports']['run']([]), true);
        $on      = $reader['subscribe_report']['run'](['routine_id' => $id]);
        $no      = $lower['subscribe_report']['run'](['routine_id' => $id]);
        $off     = $reader['unsubscribe_report']['run'](['routine_id' => $id]);
        $offNo   = $reader['unsubscribe_report']['run'](['routine_id' => $id]);

        // Assert
        $this->assertStringStartsWith('Scheduled as task ', $shared);
        $this->assertSame('Error: You may not make a report for the whole organisation.', $refused);
        $this->assertSame([[$id, 'Weekly', false]], array_map(static fn (array $r): array => [$r['routine_id'], $r['title'], $r['subscribed']], $list));
        $this->assertSame('Subscribed.', $on);
        $this->assertSame('Error: There is no such report you may read.', $no);
        $this->assertSame('Unsubscribed.', $off);
        $this->assertSame('Error: they do not receive it.', $offNo);
    }

    /**
     * Asked in Ask for a report by email, the subscribe tool turns email on — on a new
     * subscription and on one that already exists — so the run emails them the report itself.
     */
    #[DataProvider('databases')]
    public function testTheSubscribeToolTurnsOnEmail(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $tasks  = $this->tasks();
        $maker  = $tasks->toolsFor(new Scope(7, 3), 'Europe/Athens', null);
        $reader = $tasks->toolsFor(new Scope(8, 3), 'Europe/Athens', null);
        $shared = $maker['schedule_task']['run'](['kind' => 'report', 'owner' => 'organization', 'title' => 'Weekly', 'prompt' => 'Bookings', 'cadence' => 'daily', 'at' => '08:00']);
        $id     = (int) preg_replace('/\D.*$/s', '', substr($shared, strlen('Scheduled as task ')));

        // Act
        $plain = $reader['subscribe_report']['run'](['routine_id' => $id]);
        $none  = $tasks->emailSubscribers($id);
        $email = $reader['subscribe_report']['run'](['routine_id' => $id, 'email' => true]);

        // Assert
        $this->assertSame('Subscribed.', $plain);
        $this->assertSame([], $none, 'not asked for, no email');
        $this->assertSame('Subscribed, by email too.', $email);
        $this->assertSame([8], $tasks->emailSubscribers($id), 'asked again, the existing subscription now emails');
    }

    /**
     * Through the API: a reader lists the reports, subscribes and unsubscribes, reads a run and
     * the runs of the report, and opens a run as a conversation; what they may not read is
     * answered as if it did not exist.
     */
    #[DataProvider('databases')]
    public function testTheApi(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $this->answer([[200, self::says('12 bookings.')]]);
        $tasks = $this->tasks();
        $made  = $this->report($tasks);
        $id    = $made['routine_id'];
        $tasks->run($tasks->byId($id), time());
        $runId = $tasks->runs($id)[0]['run_id'];

        // Act
        $reports = $this->call('GET', '/chat/reports');
        $on      = $this->call('POST', '/chat/routines/' . $id . '/subscribe', ['email' => '1']);
        $runs    = $this->call('GET', '/chat/routines/' . $id . '/runs');
        $run     = $this->call('GET', '/chat/runs/' . $runId);
        $opened  = $this->call('POST', '/chat/runs/' . $runId . '/continue');
        $off     = $this->call('DELETE', '/chat/routines/' . $id . '/subscribe');
        $offNo   = $this->call('DELETE', '/chat/routines/' . $id . '/subscribe');
        ApiFixture::$scope = new Scope(9, 3);
        $hidden  = [$this->call('GET', '/chat/runs/' . $runId)['status'], $this->call('GET', '/chat/routines/' . $id . '/runs')['status'],
            $this->call('POST', '/chat/routines/' . $id . '/subscribe')['status'], $this->call('POST', '/chat/runs/' . $runId . '/continue')['status']];
        ApiFixture::$scope = null;
        $nobody  = [$this->call('GET', '/chat/reports')['data']['reports'], $this->call('GET', '/chat/routines/' . $id . '/runs')['status'],
            $this->call('POST', '/chat/routines/' . $id . '/subscribe')['status'], $this->call('DELETE', '/chat/routines/' . $id . '/subscribe')['status']];

        // Assert
        $this->assertSame([$id], array_column($reports['data']['reports'], 'routine_id'));
        $this->assertSame(['subscribed' => true], $on['data']);
        $this->assertSame([$runId], array_column($runs['data']['runs'], 'run_id'));
        $this->assertSame($id, $runs['data']['task']['routine_id']);
        $this->assertSame(['12 bookings.', $id], [$run['data']['content'], $run['data']['task']['routine_id']]);
        $this->assertSame(200, $opened['status']);
        $this->assertSame($opened['data']['conversation_id'], $tasks->opened($runId, 8));
        $this->assertSame(['subscribed' => false], $off['data']);
        $this->assertSame(404, $offNo['status']);
        $this->assertSame([404, 404, 404, 404], $hidden);
        $this->assertSame([[], 404, 404, 404], $nobody);
    }

    /**
     * A plan with nothing scheduled refuses a report's run, its maker told; nobody signed in has
     * no report and no conversation to seed.
     */
    #[DataProvider('databases')]
    public function testWhatRefusesARun(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $made    = $this->report($this->tasks());
        $refused = $this->tasks(new NoRoutines());
        $nobody  = new Conversations(new ReadingAssistant());

        // Act
        $ran = $refused->run($refused->byId($made['routine_id']), time());

        // Assert
        $this->assertSame([false, 'Not on this plan.'], [$ran['ok'], $ran['says']]);
        $this->assertSame([7], array_column($refused->delivered, 0));
        $this->assertSame([], $this->sent, 'no model was asked');
        $this->assertSame([false, 'scope'], [($r = $nobody->report('x'))['ok'], $r['why']]);
        $this->assertNull($nobody->seed('x', []));
    }

    /**
     * A notification's link — `chat/report/{run}` — opens the run as the reader's conversation;
     * for a reader who may not see it, it says so and goes to the page.
     */
    #[DataProvider('databases')]
    public function testTheNotificationsLinkOpensTheRun(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->policy();
        $this->configure(['chat' => ['task_policy' => ReportPolicy::class]]);
        $this->answer([[200, self::says('12 bookings.')]]);
        $tasks = $this->tasks();
        $made  = $this->report($tasks);
        $tasks->run($tasks->byId($made['routine_id']), time());
        $runId = $tasks->runs($made['routine_id'])[0]['run_id'];
        $page  = new PageFixture(new Scope(8, 3), 'plain-css');
        $other = new PageFixture(new Scope(9, 3), 'plain-css');

        // Act
        $page->report($runId);
        $other->report($runId);

        // Assert
        $this->assertStringEndsWith('chat?c=' . $tasks->opened($runId, 8), $page->redirectedTo[0]);
        $this->assertSame([], $page->errors);
        $this->assertSame(['There is no such report you may read.'], $other->errors);
        $this->assertStringEndsWith('/chat', $other->redirectedTo[0]);
    }

    /**
     * The default policy, with nobody's standing known to an application: a person's own task is
     * theirs to make; a shared one needs a standing nobody signed out has; a member is anybody;
     * a task is about nothing in particular, which always exists; a non-report links to its
     * conversation.
     */
    #[DataProvider('databases')]
    public function testTheDefaultPolicy(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $policy = new TaskPolicy(new ReadingAssistant());
        $task   = ['routine_id' => 1, 'userid' => 1, 'organization_id' => 3, 'owner' => 'organization', 'kind' => 'report', 'conversation_id' => 4];

        // Act / Assert
        $this->assertTrue($policy->canManage(new Scope(0, 3), 'user'));
        $this->assertFalse($policy->canManage(new Scope(0, 3), 'organization'), 'standing 0');
        $this->assertFalse($policy->canManage(new Scope(0, null), 'global'));
        $this->assertFalse($policy->canManage(new Scope(999999, 3), 'organization'), 'nobody by that id');
        $this->assertTrue($policy->canSee(1, $task), 'its maker');
        $this->assertTrue($policy->canSee(0, $task), 'same standing as a maker nobody knows');
        $this->assertFalse($policy->canSee(5, ['owner' => 'user'] + $task), 'one person\'s task');
        $this->assertTrue($policy->member(5, 3));
        $this->assertSame('', $policy->resourceLabel($task));
        $this->assertTrue($policy->resourceExists($task));
        $this->assertSame([1], $policy->audience($task + ['subscribers' => [1]]), 'the maker once');
        $this->assertStringEndsWith('chat/report/9', $policy->link($task, ['run_id' => 9]));
        $this->assertStringContainsString('c=4', $policy->link(['kind' => 'ask'] + $task, ['conversation_id' => 4]));
    }
}

/** An assistant with a read tool and a write tool. */
final class ReadingAssistant extends BaseAssistant
{
    /** One tool that reads, one that writes. */
    public function tools(Turn $turn): array
    {
        $tool = static fn (string $name): array => ['description' => $name, 'parameters' => ['type' => 'object', 'properties' => new \stdClass()], 'run' => static fn (): string => 'ok'];

        return ['read_figures' => $tool('read_figures'), 'publish' => $tool('publish')];
    }

    /** The write tool, beside the framework's. */
    public function writes(): array
    {
        return [...parent::writes(), 'publish'];
    }

    /** Where conversations are. */
    public function conversationUrl(?int $conversationId): string
    {
        return 'https://app.test/chat' . ($conversationId ? '?c=' . $conversationId : '');
    }
}

/** Standing and membership as the test sets them, and what a report is about. */
final class ReportPolicy extends TaskPolicy
{
    /** @var array<int, int> */
    public static array $types = [];

    /** @var array<int, list<int>> */
    public static array $members = [];

    public static bool $exists = true;

    /** A member of the organisations the test lists. */
    public function member(int $userId, ?int $organizationId): bool
    {
        return in_array($userId, self::$members[(int) $organizationId] ?? [], true);
    }

    /** The property's name. */
    public function resourceLabel(array $task): string
    {
        return $task['resource_id'] === null ? '' : 'Villa ' . $task['resource_id'];
    }

    /** Whether the property is still there. */
    public function resourceExists(array $task): bool
    {
        return self::$exists;
    }

    /** The standing the test gave. */
    protected function usertype(int $userId): int
    {
        return self::$types[$userId] ?? 0;
    }
}
