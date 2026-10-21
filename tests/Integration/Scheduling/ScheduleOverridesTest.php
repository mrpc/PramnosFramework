<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Scheduler;
use Pramnos\Auth\AuditLog;
use Pramnos\Auth\Gate;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Scheduling\RunLog;
use Pramnos\Scheduling\ScheduledTask;
use Pramnos\Scheduling\ScheduleOverrides;
use Pramnos\Scheduling\ScheduleStatus;
use Pramnos\User\User;

/**
 * What an operator changes about an adjustable task, against a real `scheduleoverrides` — on MySQL
 * and on PostgreSQL with TimescaleDB.
 *
 * What holds: a saved frequency, hours window and pause decide when the task is due, and the reset
 * gives the code's schedule back; a paused task, or one outside its hours, is never late; the
 * screen validates what it is sent against what the task offers and audits what it saves; and an
 * account that holds only `scheduler.adjust` sees the adjustable tasks alone, without "run now".
 */
#[CoversClass(ScheduleOverrides::class)]
#[CoversClass(ScheduledTask::class)]
#[CoversClass(ScheduleStatus::class)]
#[CoversClass(Scheduler::class)]
final class ScheduleOverridesTest extends TestCase
{
    private ?Database $db = null;

    private ?Database $previous = null;

    /** The adjustable task's id, unique to the test, so its audit rows are its own. */
    private string $id = '';

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    /** The framework's connection back, the tables gone, signed out, no rules. */
    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->schema()->dropTableIfExists('scheduleoverrides');
            $this->db->schema()->dropTableIfExists('scheduleruns');
            $this->db->queryBuilder()->table(AuditLog::TABLE)->where('object_id', $this->id)->delete();
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        ScheduleOverrides::reset();
        RunLog::reset();
        AuditLog::resetTableCache();
        Gate::reset();
        $_SESSION = [];
        $_GET     = [];
        $_POST    = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        if (Application::getInstance()) {
            Application::getInstance()->currentUser = null;
        }
    }

    /** A connection made the framework's, with fresh tables. */
    private function connect(string $type, string $host, int $port): void
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;
        $this->id       = 'sends.' . bin2hex(random_bytes(4));
        $db->schema()->dropTableIfExists('scheduleoverrides');
        $db->schema()->dropTableIfExists('scheduleruns');
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $db;
        (new \Pramnos\Framework\Migrations\Core\CreateSchedulerunsTable($app))->up();
        require_once ROOT . '/database/migrations/framework/core/2026_10_21_000008_create_scheduleoverrides_table.php';
        (new \Pramnos\Framework\Migrations\Core\CreateScheduleoverridesTable($app))->up();
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\AuthServer\CreateAuthserverAuditLogTable::class], $db);
        ScheduleOverrides::reset();
        RunLog::reset();
        AuditLog::resetTableCache();
    }

    /** An every-minute digest the application lets an operator adjust, with a limit of 50 to 600. */
    private function digest(): ScheduledTask
    {
        return (new ScheduledTask('digest:send', 'command'))->everyMinute()
            ->adjustable($this->id, 'Digest', 'Sends', limit: [50, 600]);
    }

    /** Save `$values` for the task as `admin`, at a fixed moment. */
    private function save(ScheduledTask $task, array $values, int $at = 1761030000): void
    {
        $this->assertTrue(ScheduleOverrides::save($task, $values + ['enabled' => true, 'frequency' => null, 'hoursfrom' => null, 'hoursto' => null, 'runlimit' => null], 2, 'admin', $at));
        ScheduleOverrides::apply([$task]);
    }

    /**
     * A saved frequency and hours window decide when the task is due; a pause stops it; the reset
     * gives back the code's every-minute schedule. Each read by `apply()`, as a pass reads them.
     */
    #[DataProvider('databases')]
    public function testSavedSettingsDecideWhenTheTaskIsDueAndTheResetUndoesThem(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $task = $this->digest();
        $at   = static fn (string $time): \DateTimeImmutable => new \DateTimeImmutable('2026-10-21 ' . $time);

        // Act — every 15 minutes, 09:00 to 17:00
        $this->save($task, ['frequency' => 'every:15', 'hoursfrom' => '09:00', 'hoursto' => '17:00']);
        $windowed = [$task->isDue($at('10:15')), $task->isDue($at('10:16')), $task->isDue($at('08:45')), $task->isDue($at('17:00'))];
        $next     = $task->nextDue($at('17:05'));
        $this->save($task, ['enabled' => false, 'frequency' => 'every:15']);
        $paused = $task->isDue($at('10:15'));
        ScheduleOverrides::forget($task, 2);
        ScheduleOverrides::apply([$task]);

        // Assert
        $this->assertSame([true, false, false, false], $windowed, 'the frequency or the window was not applied');
        $this->assertSame('2026-10-22 09:00', $next?->format('Y-m-d H:i'), 'the next run is not the window\'s opening');
        $this->assertFalse($paused, 'a paused task ran');
        $this->assertNull($task->override());
        $this->assertTrue($task->isDue($at('10:16')), 'the reset did not give the code\'s schedule back');
        $this->assertSame([], ScheduleOverrides::all());
    }

    /**
     * Paused, or outside its hours, a task is not late — nannuka's to-do opens an item for every
     * late task, and it would open one every night. Inside its window, a missed run still is.
     */
    #[DataProvider('databases')]
    public function testAPausedOrClosedTaskIsNotLate(string $type, string $host, int $port): void
    {
        // Arrange — the history began at 02:00, and the task runs at :20
        $this->connect($type, $host, $port);
        $task = (new ScheduledTask('digest:send', 'command'))->cron('20 * * * *')->adjustable($this->id);
        RunLog::table()->insert(['task' => 'other', 'label' => 'other', 'started_at' => strtotime('2026-10-21 02:00:00'),
            'duration_ms' => 1, 'result' => 'ok', 'host' => 'h']);

        // Act
        $this->save($task, ['hoursfrom' => '09:00', 'hoursto' => '17:00']);
        $night = ScheduleStatus::of([$task], new \DateTimeImmutable('2026-10-21 20:30'))[0];
        $day   = ScheduleStatus::of([$task], new \DateTimeImmutable('2026-10-21 10:30'))[0];
        $this->save($task, ['enabled' => false]);
        $paused = ScheduleStatus::of([$task], new \DateTimeImmutable('2026-10-21 10:30'))[0];

        // Assert
        $this->assertFalse($night['late'], 'a run outside the window was called missed');
        $this->assertTrue($night['outside']);
        $this->assertSame('2026-10-22 09:20', $night['next']->format('Y-m-d H:i'));
        $this->assertTrue($day['late'], 'a missed run inside the window was not late');
        $this->assertFalse($paused['late'], 'a paused task was late');
        $this->assertTrue($paused['paused']);
        $this->assertNull($paused['next']);
        $this->assertSame(1761030000, (int) $paused['override']['pausedat']);
    }

    /**
     * The screen saves what the task offers and refuses what it does not — a frequency off its
     * list, half an hours window, a limit outside its range — and every save and reset is in the
     * audit log with what it was and what it became.
     */
    #[DataProvider('databases')]
    public function testTheScreenValidatesSavesAndAudits(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $task = $this->digest();
        $controller = $this->controller([$task]);
        $this->signIn(98);
        $_GET['_option'] = $task->key();
        $post = function (string $action, array $form) use ($controller): void {
            $_POST = $form;
            try {
                $controller->{$action}();
            } catch (\RuntimeException) {
                // the redirect back to the task's page
            }
        };

        // Act
        $post('adjust', ['frequency' => 'every:7', 'enabled' => '1']);
        $post('adjust', ['frequency' => '', 'hoursfrom' => '09:00', 'enabled' => '1']);
        $post('adjust', ['frequency' => '', 'runlimit' => '9000', 'enabled' => '1']);
        $refused = ScheduleOverrides::all();
        $post('adjust', ['frequency' => 'weekly', 'weekday' => '1', 'time' => '11:15', 'hoursfrom' => '08:00', 'hoursto' => '20:00', 'runlimit' => '600', 'enabled' => '1']);
        $saved = ScheduleOverrides::all()[$this->id] ?? [];
        $post('reset', []);
        $audit = AuditLog::query(['object_type' => 'scheduled_task', 'object_id' => $this->id]);

        // Assert
        $this->assertSame([], $refused, 'an invalid form was saved');
        $this->assertSame(['weekly:1:11:15', '08:00', '20:00', 600, 'admin'],
            [$saved['frequency'] ?? null, $saved['hoursfrom'] ?? null, $saved['hoursto'] ?? null, (int) ($saved['runlimit'] ?? 0), $saved['updatedby'] ?? null]);
        $this->assertSame([], ScheduleOverrides::all(), 'the reset left the row');
        $this->assertSame(['scheduler.reset', 'scheduler.adjusted'], array_column($audit, 'event_type'));
        $this->assertContains('The per-run limit is between 50 and 600.', $_SESSION['_errors'] ?? []);
    }

    /**
     * An account with `scheduler.adjust` and below the screen's floor sees the adjustable tasks
     * alone and changes them, and may not "run now"; without the ability it is refused the screen.
     */
    #[DataProvider('databases')]
    public function testAnAdjusterSeesOnlyTheAdjustableTasks(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $task  = $this->digest();
        $other = (new ScheduledTask('payments:collect', 'command'))->cron('20 * * * *');
        $controller = $this->controller([$task, $other]);
        $this->signIn(90);
        Gate::resolveUserUsing(static fn (): ?object => Application::getInstance()->currentUser);
        Gate::define(Scheduler::ADJUST_ABILITY, static fn ($user): bool => (int) ($user->usertype ?? 0) >= 90);

        // Act
        $controller->display();
        $listed = array_column($controller->lastView->tasks, 'key');
        $full   = $controller->lastView->full;
        $_GET['_option'] = $other->key();
        try {
            $controller->runnow();
        } catch (\RuntimeException) {
            // refused: sent away
        }
        $requests = RunLog::table()->count();
        $_GET['_option'] = $task->key();
        $_POST = ['frequency' => 'every:5', 'enabled' => '1'];
        try {
            $controller->adjust();
        } catch (\RuntimeException) {
            // the redirect back
        }
        Gate::reset();
        $refused = null;
        try {
            $refused = $controller->display();
        } catch (\RuntimeException) {
            // sent away
        }

        // Assert
        $this->assertSame([$task->key()], $listed, 'an adjuster saw the other tasks');
        $this->assertFalse($full);
        $this->assertSame(0, $requests, 'an adjuster ran a task');
        $this->assertSame('every:5', ScheduleOverrides::all()[$this->id]['frequency'] ?? null);
        $this->assertNull($refused, 'below the floor and without the ability, the screen opened');
    }

    /**
     * The ways a change is refused or cannot be kept, each with what the operator is told: an
     * account the application's rule denies, a task that is not adjustable, a limit for a task that
     * takes none, a store that is not there — and nothing is saved by any of them. A daily time is
     * kept as the form sent it, and a reset of a task with nothing saved is no error.
     */
    #[DataProvider('databases')]
    public function testRefusalsAndFailuresSaveNothingAndSaySo(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $task   = $this->digest();
        $nolim  = (new ScheduledTask('report:send', 'command'))->daily()->adjustable($this->id . 'x');
        $plain  = (new ScheduledTask('payments:collect', 'command'))->cron('20 * * * *');
        $controller = $this->controller([$task, $nolim, $plain]);
        $this->signIn(98);
        $call = function (string $action, string $key, array $form = []) use ($controller): void {
            $_GET['_option'] = $key;
            $_POST = $form;
            try {
                $controller->{$action}();
            } catch (\RuntimeException) {
                // a redirect
            }
        };

        // Act
        Gate::resolveUserUsing(static fn (): ?object => Application::getInstance()->currentUser);
        Gate::define(Scheduler::ADJUST_ABILITY, static fn (): bool => false);
        $call('adjust', $task->key(), ['frequency' => 'every:5', 'enabled' => '1']);
        $denied = ScheduleOverrides::all();
        Gate::reset();
        $call('adjust', $plain->key(), ['frequency' => 'every:5', 'enabled' => '1']);
        $call('adjust', $nolim->key(), ['frequency' => '', 'runlimit' => '5', 'enabled' => '1']);
        $call('reset', $nolim->key());
        $call('adjust', $task->key(), ['frequency' => 'daily', 'time' => '07:30', 'enabled' => '1']);
        $daily = ScheduleOverrides::all()[$this->id]['frequency'] ?? null;
        $errors = $_SESSION['_errors'] ?? [];
        $this->db->schema()->dropTableIfExists('scheduleoverrides');
        $remembered = ScheduleOverrides::all();
        ScheduleOverrides::reset();
        $call('adjust', $task->key(), ['frequency' => '', 'enabled' => '1']);
        $call('reset', $task->key());
        $missing = $_SESSION['_errors'] ?? [];
        $this->signIn(10);
        $call('adjust', $task->key(), ['frequency' => '', 'enabled' => '1']);
        $call('reset', $task->key());

        // Assert
        $this->assertSame([], $denied, 'the application\'s rule was ignored');
        $this->assertSame([
            'You may not change the schedule.',
            'That task is not in the schedule.',
            'This task takes no per-run limit.',
        ], $errors);
        $this->assertSame('daily:07:30', $daily);
        $this->assertSame([], $remembered, 'a table gone mid-process broke the read');
        $this->assertSame(['The settings could not be saved. Run migrate: the scheduleoverrides table may not exist yet.'],
            array_values(array_unique(array_slice($missing, -2))), 'a save or a reset without the table said nothing');
        $this->assertSame([], ScheduleStatus::grouped([]));
    }

    /**
     * Every theme lists the adjustable tasks under their group with the state an operator set, and
     * draws no "run now" for an account that only adjusts; the task's page carries the settings
     * form with what was saved and who saved it.
     */
    #[DataProvider('databases')]
    public function testEveryThemeShowsTheGroupTheStateAndTheForm(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $task  = $this->digest();
        $other = (new ScheduledTask('payments:collect', 'command'))->cron('20 * * * *');
        $this->save($task, ['enabled' => false, 'frequency' => 'weekly:2:11:15', 'runlimit' => 600]);
        $rows = ScheduleStatus::of([$task, $other], new \DateTimeImmutable('2026-10-21 10:30'));
        $list = (object) ['tasks' => $rows, 'counts' => ScheduleStatus::counts($rows), 'working' => true, 'history' => true, 'full' => false];
        $page = (object) ['status' => $rows[0], 'runs' => [], 'page' => 1, 'pages' => 1, 'adjust' => true, 'full' => false];

        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            // Act
            $listed = $this->render($theme, 'scheduler', $list);
            $shown  = $this->render($theme, 'show', $page);

            // Assert
            $this->assertStringContainsString('data-group="Sends"', $listed, $theme . ': the group');
            $this->assertStringContainsString('data-group="Other tasks"', $listed, $theme);
            $this->assertStringContainsString('Paused since', $listed, $theme . ': the pause');
            $this->assertStringNotContainsString('Scheduler/runnow', $listed, $theme . ': run now for an adjuster');
            $this->assertStringContainsString('Scheduler/adjust/' . $task->key(), $shown, $theme . ': the form');
            $this->assertMatchesRegularExpression('~<option value="weekly" selected>~', $shown, $theme);
            $this->assertStringContainsString('value="11:15"', $shown, $theme);
            $this->assertStringContainsString('value="600"', $shown, $theme);
            $this->assertStringContainsString('Changed by admin', $shown, $theme);
            $this->assertStringContainsString('Scheduler/reset/' . $task->key(), $shown, $theme);
        }
    }

    /** A theme's template with what the controller would publish. */
    private function render(string $theme, string $template, object $published): string
    {
        $file = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/scheduler/' . $template . '.html.php';
        $run  = \Closure::bind(function () use ($file): void {
            include $file;
        }, $published, null);
        ob_start();
        $run();

        return (string) ob_get_clean();
    }

    /** Signed in as `admin` at `$usertype`. */
    private function signIn(int $usertype): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = ['logged' => true, 'login' => true, 'userid' => 2, 'uid' => 2, 'usertype' => $usertype];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = \Pramnos\Http\Session::getInstance()->getCsrfToken();
        $user = new User(0);
        $user->userid   = 2;
        $user->usertype = $usertype;
        $user->username = 'admin';
        Application::getInstance()->currentUser = $user;
    }

    /**
     * A controller with this schedule, whose views keep what they were given and whose redirect
     * ends the action.
     *
     * @param list<ScheduledTask> $tasks
     */
    private function controller(array $tasks): Scheduler
    {
        return new class (Application::getInstance(), $tasks) extends Scheduler {
            /** The last view it filled. */
            public ?object $lastView = null;

            /** @param list<ScheduledTask> $fixedTasks */
            public function __construct(?Application $application, private array $fixedTasks)
            {
                parent::__construct($application);
            }

            /** The test's schedule. */
            protected function tasks(): array
            {
                return $this->fixedTasks;
            }

            /** The request ends at a redirect. */
            public function redirect($url = null, $quit = true, $code = '302')
            {
                throw new \RuntimeException('redirect_quit');
            }

            /** A plain object to publish on; display() answers the template's name. */
            public function &getView($name = '', $type = '', $args = [])
            {
                $view = new class extends \stdClass {
                    public function display($template = ''): string
                    {
                        return (string) $template;
                    }
                };
                $this->lastView = $view;

                return $view;
            }
        };
    }
}
