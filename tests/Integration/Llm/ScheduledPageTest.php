<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Llm\Chat\Routines;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Web\ChatPageController;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * `chat/scheduled` — a person's scheduled tasks, the reports shared with them, one task's runs,
 * and the form and buttons that change them — drawn through each theme's real view, on MySQL and
 * PostgreSQL.
 *
 * What holds: every task is listed with what it is and when it runs, a paused one and its error
 * said; a shared report offers to be received or not; a task's runs are shown, a report's with a
 * way to ask about it, its words escaped; the "for" choice appears only to whom the policy lets
 * share; and each action — schedule (from a template, a weekday or a day of the month, a moment on
 * the clock), pause and resume, delete, run now, receive — does what it says or says why not.
 */
#[CoversClass(ChatPageController::class)]
#[CoversClass(Routines::class)]
class ScheduledPageTest extends LlmTestCase
{
    use ChatDatabase;

    private const THEMES = ['tailwind', 'bootstrap', 'plain-css'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        ReportPolicy::$types   = [7 => 80, 8 => 80, 9 => 20];
        ReportPolicy::$members = [3 => [7, 8, 9]];
        ReportPolicy::$exists  = true;
        $this->configure(['chat' => ['task_policy' => ReportPolicy::class, 'routine_notification' => CapturedNotice::class,
            'routine_templates' => ['monday' => ['title' => 'Monday numbers', 'prompt' => 'The week\'s figures', 'cadence' => 'weekly', 'day' => 1, 'at' => '09:00']]]]);
        $_POST = [];
        $_GET  = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** The page as a person in a theme, with its redirects and flash recorded. */
    private function page(?Scope $scope = new Scope(7, 3), string $theme = 'tailwind'): ScheduledPage
    {
        return new ScheduledPage($scope, $theme);
    }

    /** The tasks, as the page's actions reach them. */
    private function tasks(): Routines
    {
        return new Routines((new ScheduledPage(new Scope(7, 3), 'tailwind'))->exposed());
    }

    /**
     * Each theme lists the person's tasks with what each is and when it runs, a paused one and
     * its error, the reports shared with them and whether they receive each, and the form — with
     * the "for" choice for somebody the policy lets share.
     */
    #[DataProvider('databases')]
    public function testThePageInEachTheme(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tasks = $this->tasks();
        $tasks->create(new Scope(7, 3), ['kind' => 'reminder', 'title' => 'Call <Maria>', 'prompt' => 'x', 'cadence' => 'monthly_weekday', 'nth' => 5, 'day' => 5, 'at' => '17:00']);
        $paused = $tasks->create(new Scope(7, 3), ['title' => 'Daily', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $tasks->update(new Scope(7, 3), $paused['routine_id'], ['active' => false]);
        $this->db->queryBuilder()->table('ai_routines')->where('routine_id', $paused['routine_id'])->update(['last_error' => 'Out of <credit>']);
        $tasks->create(new Scope(8, 3), ['kind' => 'report', 'owner' => 'organization', 'title' => 'Team week', 'prompt' => 'p', 'cadence' => 'weekly', 'day' => 1, 'at' => '08:00']);

        foreach (self::THEMES as $theme) {
            // Act
            $html  = (string) $this->page(new Scope(7, 3), $theme)->scheduled();
            $lower = (string) $this->page(new Scope(9, 3), $theme)->scheduled();

            // Assert
            $this->assertStringContainsString('Call &lt;Maria&gt;', $html, $theme);
            $this->assertStringContainsString('The last Friday of each month at 17:00', $html, $theme);
            $this->assertStringContainsString('Paused', $html, $theme);
            $this->assertStringContainsString('Out of &lt;credit&gt;', $html, $theme);
            $this->assertStringContainsString('Team week', $html, $theme . ': a report shared with them');
            $this->assertStringContainsString('Receive it', $html, $theme);
            $this->assertStringContainsString('name="owner"', $html, $theme . ': 7 may share');
            $this->assertStringContainsString('Monday numbers', $html, $theme . ': a template');
            $this->assertStringContainsString('name="_csrf_token"', $html . \Pramnos\Http\Middleware\CsrfMiddleware::tokenField(), $theme);
            $this->assertStringNotContainsString('name="owner"', $lower, $theme . ': 9 may not');
            $this->assertStringNotContainsString('Team week', $lower, $theme . ': nor read a report made above them');
        }
    }

    /**
     * A task's runs: a report's with a way to ask about it, its words drawn safely; a run that
     * failed says why; a task nobody may read here is not shown.
     */
    #[DataProvider('databases')]
    public function testATasksRuns(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tasks = $this->tasks();
        $made  = $tasks->create(new Scope(7, 3), ['kind' => 'report', 'owner' => 'organization', 'title' => 'Team week', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $this->answer([[200, self::says('**12** bookings <script>x</script>')]]);
        $tasks->run($tasks->byId($made['routine_id']), time());
        $this->answer([[500, []]]);
        $tasks->run($tasks->byId($made['routine_id']), time());
        $runId = $tasks->runs($made['routine_id'])[1]['run_id'];

        foreach (self::THEMES as $theme) {
            // Act
            $_GET  = ['task' => (string) $made['routine_id']];
            $html  = (string) $this->page(new Scope(8, 3), $theme)->scheduled();
            $other = (string) $this->page(new Scope(9, 3), $theme)->scheduled();

            // Assert
            $this->assertStringContainsString('<strong>12</strong> bookings', $html, $theme);
            $this->assertStringNotContainsString('<script>x', $html, $theme);
            $this->assertStringContainsString('chat/report/' . $runId, $html, $theme . ': ask about it');
            $this->assertStringContainsString('Failed', $html, $theme);
            $this->assertStringNotContainsString('data-pf-scheduled-runs', $other, $theme . ': not theirs to read');
            // Only the fields the cadence uses, where the script runs; one's own report can be emailed
            $this->assertStringContainsString('data-pf-show-when="cadence:weekly monthly_weekday"', $html, $theme);
            $this->assertStringContainsString('data-pf-show-when="cadence:once"', $html, $theme);
            // What it cost, the framework's own: both runs counted, each run listed
            $this->assertStringContainsString('data-pf-task-cost="' . $made['routine_id'] . '">Times run: 2', $html, $theme);
            $this->assertStringContainsString('data-pf-run-cost="' . $runId . '"', $html, $theme);
        }
    }

    /**
     * Scheduling from the form: a template fills what was left empty; a monthly task takes the
     * day of the month, a weekly one the weekday; a moment on the clock as a datetime field sends
     * it, and one too far ahead is refused rather than failing the insert; and a refusal is said.
     */
    #[DataProvider('databases')]
    public function testScheduling(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $forms = [
            ['template' => 'monday', 'title' => '', 'prompt' => '', 'weekday' => '3', 'monthday' => '9'],
            ['title' => 'Rent', 'prompt' => 'Pay the rent', 'kind' => 'reminder', 'cadence' => 'monthly', 'at' => '09:00', 'weekday' => '2', 'monthday' => '27'],
            ['title' => 'Call', 'prompt' => 'Call Maria', 'kind' => 'reminder', 'cadence' => 'once', 'run_at' => date('Y', time() + 400 * 86400) . '-01-02T10:30'],
            ['title' => 'Far', 'prompt' => 'x', 'kind' => 'reminder', 'cadence' => 'once', 'run_at' => '2099-01-02T10:30'],
            ['title' => 'Shared', 'prompt' => 'x', 'kind' => 'ask', 'owner' => 'organization', 'cadence' => 'daily'],
        ];
        $said = [];

        // Act
        foreach ($forms as $form) {
            $_POST = $form;
            $page  = $this->page();
            $page->schedule();
            $said[] = [$page->messages, $page->errors, $page->redirectedTo[0] ?? ''];
        }
        $made = array_column($this->tasks()->mine(new Scope(7, 3)), null, 'title');

        // Assert
        $this->assertSame(['weekly', 3, '09:00'], [$made['Monday numbers']['cadence'], $made['Monday numbers']['day'], $made['Monday numbers']['at']], 'the template, on the weekday chosen');
        $this->assertSame(['monthly', 27], [$made['Rent']['cadence'], $made['Rent']['day']], 'the day of the month');
        $this->assertStringStartsWith(date('Y', time() + 400 * 86400) . '-01-02', (string) $made['Call']['run_at']);
        $this->assertSame(['That is too far ahead: give a time within ten years.'], $said[3][1], 'refused, not a database error');
        // The time goes into the words as a placeholder, so a translation catches the whole message;
        // and it is written in figures, which read the same in every language.
        $this->assertMatchesRegularExpression('#^Scheduled\. Next: \d{2}/\d{2}/\d{4} \d{2}:\d{2}\.$#', $said[0][0][0]);
        $this->assertStringStartsWith('Only a report is shared', $said[4][1][0]);
        $this->assertStringEndsWith('chat/scheduled', $said[0][2]);
    }

    /**
     * Pausing and resuming, deleting, running now and receiving a shared report — each said, and
     * each refusal too: a task that is not theirs, a report they may not read, one they do not
     * receive.
     */
    #[DataProvider('databases')]
    public function testTheButtons(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $tasks  = $this->tasks();
        $mine   = $tasks->create(new Scope(7, 3), ['kind' => 'reminder', 'title' => 'Mine', 'prompt' => 'Call Maria', 'cadence' => 'daily', 'at' => '08:00']);
        $report = $tasks->create(new Scope(8, 3), ['kind' => 'report', 'owner' => 'organization', 'title' => 'Team', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $press  = function (string $action, array $post, ?Scope $scope = new Scope(7, 3)): ScheduledPage {
            $_POST = $post;
            $page  = $this->page($scope);
            $page->$action();

            return $page;
        };

        // Act
        $paused   = $press('scheduledupdate', ['routine_id' => $mine['routine_id'], 'active' => '']);
        $resumed  = $press('scheduledupdate', ['routine_id' => $mine['routine_id'], 'active' => '1']);
        $ran      = $press('scheduledrun', ['routine_id' => $mine['routine_id']]);
        $notMine  = $press('scheduledrun', ['routine_id' => $report['routine_id']]);
        $on       = $press('scheduledsubscribe', ['routine_id' => $report['routine_id'], 'on' => '1']);
        $off      = $press('scheduledsubscribe', ['routine_id' => $report['routine_id'], 'on' => '']);
        $offAgain = $press('scheduledsubscribe', ['routine_id' => $report['routine_id'], 'on' => '']);
        $lower    = $press('scheduledsubscribe', ['routine_id' => $report['routine_id'], 'on' => '1'], new Scope(9, 3));
        $deleted  = $press('scheduleddelete', ['routine_id' => $mine['routine_id']]);
        $gone     = $press('scheduleddelete', ['routine_id' => $mine['routine_id']]);
        $nobody   = $press('scheduledsubscribe', ['routine_id' => $report['routine_id'], 'on' => '1'], null);
        $noUpdate = $press('scheduledupdate', ['routine_id' => $report['routine_id']], null);
        $noScheduling = $press('schedule', ['title' => 'x'], null);

        // Assert
        $this->assertSame(['Paused.'], $paused->messages);
        $this->assertSame(['Resumed.'], $resumed->messages);
        $this->assertSame(['It ran.'], $ran->messages);
        $this->assertStringEndsWith('chat/scheduled?task=' . $mine['routine_id'], $ran->redirectedTo[0], 'to its runs');
        $this->assertSame(['There is no such task.'], $notMine->errors);
        $this->assertSame(['You will receive it.'], $on->messages);
        $this->assertSame(['You will no longer receive it.'], $off->messages);
        $this->assertSame(['You do not receive it.'], $offAgain->errors);
        $this->assertSame(['There is no such report you may read.'], $lower->errors);
        $this->assertSame(['Deleted.'], $deleted->messages);
        $this->assertSame(['There is no such task.'], $gone->errors);
        $this->assertSame(['This needs somebody signed in.'], $nobody->errors);
        $this->assertSame(['This needs somebody signed in.'], $noUpdate->errors);
        $this->assertSame(['This needs somebody signed in.'], $noScheduling->errors);
    }

    /**
     * A controller's own part of the page: its fields printed inside the form, before the button,
     * and its markup on the open task's card, in every theme; and once a task is made, it is
     * handed the task and the whole form, its own fields among it — and is not called when the
     * form was refused.
     */
    #[DataProvider('databases')]
    public function testTheControllersOwnFieldsAndWhatItMade(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $made  = $this->tasks()->create(new Scope(7, 3), ['kind' => 'reminder', 'title' => 'Open', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $pages = [];

        // Act — drawn with a task open, in each theme
        foreach (self::THEMES as $theme) {
            $page         = $this->page(new Scope(7, 3), $theme);
            $page->extras = ['form' => '<input name="audience" data-own-field>', 'task' => '<p data-own-card>cost</p>'];
            $_GET         = ['task' => (string) $made['routine_id']];
            $pages[$theme] = (string) $page->scheduled();
        }
        $_GET  = [];
        $_POST = ['title' => 'Mine', 'prompt' => 'Call Maria', 'kind' => 'reminder', 'cadence' => 'daily', 'at' => '09:00', 'audience' => 'admins'];
        $good  = $this->page();
        $good->schedule();
        $_POST = ['title' => 'Far', 'prompt' => 'x', 'kind' => 'reminder', 'cadence' => 'once', 'run_at' => '2099-01-02T10:30'];
        $bad   = $this->page();
        $bad->schedule();

        // Assert
        foreach ($pages as $theme => $html) {
            $form = substr($html, (int) strpos($html, 'data-pf-scheduled-form'));
            $this->assertLessThan(strpos($form, '</form>'), strpos($form, 'data-own-field'), $theme . ': inside the form');
            $this->assertLessThan(strpos($form, 'type="submit"'), strpos($form, 'data-own-field'), $theme . ': before its button');
            $card = substr($html, (int) strpos($html, 'data-pf-scheduled-runs'));
            $this->assertLessThan(strpos($card, '</section>'), strpos($card, 'data-own-card'), $theme . ': on the open task\'s card');
            // An application's card replaces the framework's cost summary rather than sitting beside it
            $this->assertStringNotContainsString('data-pf-task-costs', $html, $theme);
        }
        $this->assertCount(1, $good->scheduledWith);
        $this->assertSame('admins', $good->scheduledWith[0][1]['audience'], 'its own field reaches it');
        $this->assertSame('Mine', $good->scheduledWith[0][0]);
        $this->assertSame([], $bad->scheduledWith, 'refused: nothing was made');
    }

    /**
     * Choosing a shared report's readers from the page: the form offers them beside "For", in
     * every theme; the open report's card, for its maker, has them filled in and saves them through
     * `scheduledupdate` — not for somebody else's report, and a refusal is said.
     */
    #[DataProvider('databases')]
    public function testChoosingTheReaders(string $type, string $host, int $port): void
    {
        // Arrange — 8 makes an organisation report; 7 may see it but did not make it
        $this->connectDatabase($type, $host, $port);
        $report = $this->tasks()->create(new Scope(8, 3), ['kind' => 'report', 'owner' => 'organization', 'title' => 'Team', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00', 'audience_role' => 'manager']);
        $draw   = function (Scope $scope, string $theme) use ($report): string {
            $_GET = ['task' => (string) $report['routine_id']];
            $html = (string) $this->page($scope, $theme)->scheduled();
            $_GET = [];

            return $html;
        };

        // Act
        $pages  = array_map(fn (string $theme): string => $draw(new Scope(8, 3), $theme), array_combine(self::THEMES, self::THEMES));
        $other  = $draw(new Scope(7, 3), 'tailwind');
        $_POST  = ['routine_id' => $report['routine_id'], 'audience' => '1', 'audience_role' => 'admin', 'audience_users' => ''];
        $saved  = $this->page(new Scope(8, 3));
        $saved->scheduledupdate();
        $_POST  = ['routine_id' => $report['routine_id'], 'audience' => '1', 'audience_role' => 'emperor'];
        $wrong  = $this->page(new Scope(8, 3));
        $wrong->scheduledupdate();
        $_POST  = [];
        $now    = $this->tasks()->byId($report['routine_id']);

        // Assert
        foreach ($pages as $theme => $html) {
            $form = substr($html, (int) strpos($html, 'data-pf-scheduled-form'));
            $this->assertStringContainsString('name="audience_role"', $form, $theme . ': offered when scheduling');
            $card = substr($html, (int) strpos($html, 'data-pf-scheduled-audience'));
            $this->assertMatchesRegularExpression('#<option value="manager" selected>#', $card, $theme . ': the maker\'s choice filled in');
        }
        $this->assertStringContainsString('data-pf-scheduled-runs', $other, '7, a manager, may read it');
        $this->assertStringNotContainsString('data-pf-scheduled-audience', $other, 'but not choose its readers: 8 made it');
        $this->assertSame(['Its readers are saved.'], $saved->messages);
        $this->assertStringEndsWith('?task=' . $report['routine_id'], $saved->redirectedTo[0], 'back to the report');
        $this->assertSame(90, $now['audience']['min_usertype']);
        $this->assertSame(['There is no role called emperor.'], $wrong->errors);
        // The application's role list, read through static:: — its override, not the framework's
        foreach ($pages as $theme => $html) {
            $this->assertStringNotContainsString('<option value="admin"', $html, $theme);
            $this->assertStringContainsString('<option value="super"', $html, $theme);
        }
        // Told what changed, as it was and as it is; not told when the change was refused
        $this->assertSame([[['min_usertype' => 80, 'users' => []], ['min_usertype' => 90, 'users' => []]]], $saved->readersChanged);
        $this->assertSame([], $wrong->readersChanged);
    }

    /**
     * A shared report is offered to receive by email too, in every theme, and that is what the
     * subscription keeps; once received, only "stop" is offered.
     */
    #[DataProvider('databases')]
    public function testReceivingByEmail(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $report = $this->tasks()->create(new Scope(8, 3), ['kind' => 'report', 'owner' => 'organization', 'title' => 'Team', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00']);
        $offered = array_map(fn (string $theme): string => (string) $this->page(new Scope(7, 3), $theme)->scheduled(), self::THEMES);

        // Act
        $_POST = ['routine_id' => $report['routine_id'], 'on' => '1', 'email' => '1'];
        $page  = $this->page(new Scope(7, 3));
        $page->scheduledsubscribe();
        $_POST = [];
        $after = (string) $this->page(new Scope(7, 3))->scheduled();

        // Assert
        foreach ($offered as $html) {
            $this->assertStringContainsString('Receive it by email too', $html);
            $this->assertStringContainsString('name="email" value="1"', $html);
        }
        $this->assertSame(['You will receive it, by email too.'], $page->messages);
        $this->assertSame([7], $this->tasks()->emailSubscribers($report['routine_id']));
        $this->assertStringNotContainsString('Receive it by email too', $after, 'received: only stop is offered');

        // One's own report: emailed on asking, and the button says so either way
        $mine     = (string) $this->page(new Scope(8, 3))->scheduled();
        $_POST    = ['routine_id' => $report['routine_id'], 'on' => '1', 'email' => '1'];
        $this->page(new Scope(8, 3))->scheduledsubscribe();
        $_POST    = [];
        $emailed  = (string) $this->page(new Scope(8, 3))->scheduled();
        $this->assertStringContainsString('Email it to me', $mine);
        $this->assertStringContainsString('Stop the email', $emailed);
        $this->assertTrue($this->tasks()->mine(new Scope(8, 3))[0]['email']);
    }

    /**
     * A report made with "Email the report to me too" ticked — on the form or asked of the model —
     * is emailed to its maker; one made without it is not; and the form shows the report's own
     * fields only for a report, its readers only for a shared one.
     */
    #[DataProvider('databases')]
    public function testAReportEmailedFromTheStart(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $html = (string) $this->page(new Scope(7, 3))->scheduled();

        // Act — the form, ticked and not; the model's tool
        $_POST = ['kind' => 'report', 'title' => 'Mailed', 'prompt' => 'p', 'cadence' => 'daily', 'at' => '08:00', 'email' => '1'];
        $this->page(new Scope(7, 3))->schedule();
        $_POST = ['kind' => 'report', 'title' => 'Not mailed', 'prompt' => 'q', 'cadence' => 'daily', 'at' => '09:00'];
        $this->page(new Scope(7, 3))->schedule();
        $_POST = [];
        $tools = $this->tasks()->toolsFor(new Scope(7, 3), 'Europe/Athens', null);
        $tools['schedule_task']['run'](['kind' => 'report', 'title' => 'Asked', 'prompt' => 'r', 'cadence' => 'daily', 'at' => '10:00', 'email' => true]);
        $byTitle = array_column($this->tasks()->mine(new Scope(7, 3)), 'email', 'title');

        // Assert
        $this->assertSame(['Mailed' => true, 'Not mailed' => false, 'Asked' => true], $byTitle);
        $this->assertStringContainsString('name="email" value="1" checked', $html);
        $this->assertSame(3, substr_count($html, 'data-pf-show-when="kind:report"'), 'For, Tell readers, and the email box');
        $this->assertStringContainsString('data-pf-show-when="owner:organization global"', $html);
    }

    /** Nobody signed in is sent to sign in, back to this page. */
    #[DataProvider('databases')]
    public function testSignedOut(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $page = $this->page(null);

        // Act
        $page->scheduled();

        // Assert
        $this->assertStringEndsWith('login?return=chat%2Fscheduled', $page->redirectedTo[0]);
    }

    /** The conversations page links here; a page with addresses of its own (the analyst's) does not. */
    public function testTheConversationsPageLinksHere(): void
    {
        foreach (self::THEMES as $theme) {
            // Act
            $view = (string) file_get_contents(ROOT . '/scaffolding/themes/' . $theme . '/views/chat/chat.html.php');

            // Assert
            $this->assertStringContainsString("'scheduled' => is_array(\$this->urls ?? null) ? '' : \$url('chat/scheduled')", $view, $theme);
            $this->assertStringContainsString("\$u['scheduled'] !== ''", $view, $theme);
        }
    }
}

/** The page fixture, drawing the template asked for rather than always the conversations. */
final class ScheduledPage extends PageFixture
{
    /** @var array{form?: string, task?: string} The controller's own markup */
    public array $extras = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> Tasks made, by title, with the form */
    public array $scheduledWith = [];

    /** The markup given. */
    protected function scheduledExtras(?array $task): array
    {
        return $this->extras;
    }

    /** Record what was made, and the whole form. */
    protected function afterScheduled(array $task, array $input): void
    {
        $this->scheduledWith[] = [(string) $task['title'], $input];
    }

    /** @var list<array{0: mixed, 1: mixed}> Readers changed: before, after */
    public array $readersChanged = [];

    /** Record the readers as they were and as they are. */
    protected function afterAudienceChanged(array $task, array $before): void
    {
        $this->readersChanged[] = [$before['audience'] ?? null, $task['audience'] ?? null];
    }

    /** The application's own roles: no "admin" between manager and super. */
    protected static function audienceRoles(): array
    {
        return ['manager' => 80, 'super' => 98];
    }

    /** The view, drawing `$tpl` from the theme's chat views. */
    public function &getView($name = '', $type = '', $args = array())
    {
        $dir  = ROOT . '/scaffolding/themes/' . $this->themeName() . '/views/' . $name;
        $view = new class (new \Pramnos\Application\Controller(), $dir, $name) extends \Pramnos\Application\View {
            /** Draw the template asked for. */
            public function display($tpl = '', $render = false)
            {
                $file = $this->path . '/' . ($tpl !== '' ? $tpl : $this->name) . '.html.php';
                ob_start();
                \Closure::bind(function () use ($file): void {
                    include $file;
                }, $this, \Pramnos\Application\View::class)();

                return (string) ob_get_clean();
            }
        };

        return $view;
    }
}
