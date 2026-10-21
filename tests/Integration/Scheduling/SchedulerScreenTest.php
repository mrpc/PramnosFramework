<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Scheduling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Scheduler;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Scheduling\RunLog;
use Pramnos\Scheduling\ScheduledTask;
use Pramnos\Scheduling\ScheduleStatus;
use Pramnos\User\User;

/**
 * The scheduler screen against a real history — on MySQL and on PostgreSQL with TimescaleDB.
 *
 * What holds: a task that did not run in its minute is late, and is not once a run of it
 * started after that minute, nor when its minute fell before the history began; "run now"
 * leaves a `requested` row with who asked, and a flash; a task's page lists its runs with
 * their output escaped, and asked for as a fragment it is that page without the theme.
 */
#[CoversClass(Scheduler::class)]
#[CoversClass(ScheduleStatus::class)]
#[CoversClass(\Pramnos\Dashboard\Widgets\ScheduleStatus::class)]
final class SchedulerScreenTest extends TestCase
{
    private ?Database $db = null;

    private ?Database $previous = null;

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    /** The framework's connection back, the table gone, signed out. */
    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->schema()->dropTableIfExists('scheduleruns');
        }
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        RunLog::reset();
        $_SESSION = [];
        $_GET     = [];
        unset($_SERVER['HTTP_X_PF_FRAGMENT']);
        \Pramnos\Document\Document::reset();
        if (Application::getInstance()) {
            Application::getInstance()->currentUser = null;
        }
    }

    /** A connection made the framework's, with a fresh scheduleruns. */
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
        $db->schema()->dropTableIfExists('scheduleruns');
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $db;
        (new \Pramnos\Framework\Migrations\Core\CreateSchedulerunsTable($app))->up();
        RunLog::reset();
    }

    /** A row of history. */
    private function seedRun(ScheduledTask $task, string $at, string $result = 'ok', ?string $output = null): void
    {
        RunLog::table()->insert(['task' => $task->key(), 'label' => $task->label(), 'started_at' => strtotime($at),
            'duration_ms' => 1500, 'result' => $result, 'host' => 'h', 'output' => $output]);
    }

    /**
     * A task due at :20 with the history begun an hour earlier is late at 03:30 with no run since
     * 03:20, and is not once a run started at 03:20:05; a minute before the history began is not
     * missed.
     */
    #[DataProvider('databases')]
    public function testATaskThatDidNotRunInItsMinuteIsLate(string $type, string $host, int $port): void
    {
        // Arrange — the history begins at 02:30, with another task's run
        $this->connect($type, $host, $port);
        $task  = (new ScheduledTask('payments:collect', 'command'))->cron('20 * * * *')->description('payments');
        $other = (new ScheduledTask('other', 'command'))->cron('30 2 * * *');
        $this->seedRun($other, '2026-10-21 02:30:00');
        $now = new \DateTimeImmutable('2026-10-21 03:30:00');

        // Act
        $missing = ScheduleStatus::of([$task], $now)[0];
        $this->seedRun($task, '2026-10-21 03:20:05');
        $ran = ScheduleStatus::of([$task], $now)[0];
        $beforeHistory = ScheduleStatus::of([$task], new \DateTimeImmutable('2026-10-21 02:25:00'))[0];

        // Assert
        $this->assertTrue($missing['late'], 'a missed 03:20 was not late');
        $this->assertSame('2026-10-21 03:20', $missing['expected']->format('Y-m-d H:i'));
        $this->assertFalse($ran['late'], 'a task that ran was still late');
        $this->assertSame('ok', $ran['last']['result']);
        $this->assertFalse($beforeHistory['late'], 'a minute nothing was recording was called missed');
        $this->assertSame(['late' => 1, 'failed' => 0, 'running' => 0, 'tasks' => 2],
            ScheduleStatus::counts([$missing, ScheduleStatus::of([$other], $now)[0]]));
    }

    /**
     * "Run now" leaves a `requested` row with who asked, the reader is sent back with a
     * message, and the list then shows the task requested.
     */
    #[DataProvider('databases')]
    public function testRunNowLeavesARequest(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $task = (new ScheduledTask('payments:collect', 'command'))->cron('20 * * * *');
        $controller = $this->controller([$task]);
        $this->signIn();
        $_GET['_option'] = $task->key();

        // Act
        try {
            $controller->runnow();
        } catch (\RuntimeException $e) {
            $redirect = $e->getMessage();
        }
        $rows = RunLog::table()->getAll();

        // Assert
        $this->assertSame('redirect_quit', $redirect ?? null);
        $this->assertCount(1, $rows);
        $this->assertSame(['requested', 'admin'], [$rows[0]['result'], $rows[0]['requested_by']]);
        $this->assertContains('Requested. The scheduler runs it on its next pass.', $_SESSION['_messages'] ?? []);
        $this->assertTrue(ScheduleStatus::of([$task], new \DateTimeImmutable())[0]['requested']);
    }

    /**
     * A task's page lists its runs newest first with their output escaped; asked for as a
     * fragment, the page is that and no theme. The dashboard card counts the failed run.
     */
    #[DataProvider('databases')]
    public function testATasksPageListsItsRunsEscaped(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $task = (new ScheduledTask('payments:collect', 'command'))->cron('20 * * * *')->description('payments');
        $this->seedRun($task, '2026-10-21 02:20:01');
        $this->seedRun($task, '2026-10-21 03:20:01', 'failed', '<script>alert(1)</script> gateway refused');
        $controller = $this->controller([$task]);
        $this->signIn();
        $_GET['_option'] = $task->key();
        $_SERVER['HTTP_X_PF_FRAGMENT'] = '1';

        // Act
        $view = $controller->show();
        $html = $this->render('show', $controller->lastView);
        $document = \Pramnos\Document\Document::getInstance();
        $document->setContent($html);
        $fragment = $document->render();
        $card = new \Pramnos\Dashboard\Widgets\ScheduleStatus();

        // Assert
        $this->assertSame('show', $view);
        $this->assertSame(['failed', 'ok'], array_column($controller->lastView->runs, 'result'), 'not newest first');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; gateway refused', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html, 'a run\'s output reached the page as markup');
        $this->assertStringNotContainsString('<html', $fragment, 'the fragment carried the theme');
        $this->assertStringContainsString('gateway refused', $fragment);
        $this->assertSame(1, ScheduleStatus::counts(ScheduleStatus::of([$task], new \DateTimeImmutable('2026-10-21 03:30')))['failed']);
        $this->assertSame('scheduler', $card->id());
        $this->assertStringContainsString('Scheduler', (string) $card->link());
    }

    /** The tailwind template with what the controller published. */
    private function render(string $template, object $published): string
    {
        $file = dirname(__DIR__, 3) . '/scaffolding/themes/tailwind/views/scheduler/' . $template . '.html.php';
        $run  = \Closure::bind(function () use ($file): void {
            include $file;
        }, $published, null);
        ob_start();
        $run();

        return (string) ob_get_clean();
    }

    /** Signed in at the administration floor, as `admin`. */
    private function signIn(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = ['logged' => true, 'login' => true, 'userid' => 2, 'uid' => 2, 'usertype' => 98];
        $user = new User(0);
        $user->userid   = 2;
        $user->usertype = 98;
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
