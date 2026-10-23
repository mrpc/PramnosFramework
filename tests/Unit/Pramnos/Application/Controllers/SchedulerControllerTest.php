<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Scheduler;
use Pramnos\Scheduling\ScheduledTask;
use Pramnos\User\User;

/**
 * The scheduler screen's controller, with the schedule and the clock it is given.
 *
 * What holds: the list publishes each task with its next run worked out from its expression at
 * the screen's clock — a list of minutes included — and the counts; a page below the
 * administration floor is refused; a task that is not in the schedule sends the reader back with
 * the reason, from its page and from "run now"; and a task's page publishes its state and its
 * runs.
 */
#[CoversClass(Scheduler::class)]
#[CoversClass(\Pramnos\Scheduling\ScheduleStatus::class)]
#[CoversClass(\Pramnos\Dashboard\Widgets\ScheduleStatus::class)]
final class SchedulerControllerTest extends TestCase
{
    /**
     * A controller whose schedule, clock, views and redirects the test holds.
     *
     * @param list<ScheduledTask> $tasks
     */
    private function controller(array $tasks, string $now = '2026-10-21 03:21:09'): Scheduler
    {
        $app = Application::getInstance();

        return new class ($app, $tasks, $now) extends Scheduler {
            /** @var list<string> Where it sent the reader */
            public array $redirectedTo = [];

            /** The last view it filled. */
            public ?object $lastView = null;

            /**
             * @param list<ScheduledTask> $fixedTasks
             */
            public function __construct(?Application $application, private array $fixedTasks, private string $clock)
            {
                parent::__construct($application);
            }

            /** The test's schedule. */
            protected function tasks(): array
            {
                return $this->fixedTasks;
            }

            /** The test's clock. */
            protected function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->clock);
            }

            /** Whether a redirect ends the action, as a real one ends the request. */
            public bool $quits = true;

            /** Kept, and the request ends there unless the test says otherwise. */
            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirectedTo[] = (string) $url;
                if ($this->quits) {
                    throw new \RuntimeException('redirect_quit');
                }
            }

            /** A view that records what it was given. */
            public function &getView($name = '', $type = '', $args = [])
            {
                $view = new class {
                    /** @var array<string, mixed> */
                    public array $published = [];

                    public function __set(string $key, mixed $value): void
                    {
                        $this->published[$key] = $value;
                    }

                    public function __get(string $key): mixed
                    {
                        return $this->published[$key] ?? null;
                    }

                    public function display($template = ''): string
                    {
                        return 'view:' . $template;
                    }
                };
                $this->lastView = $view;

                return $view;
            }
        };
    }

    /** A signed-in user of this usertype. */
    private function signIn(int $usertype): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = ['logged' => true, 'login' => true, 'userid' => 2, 'uid' => 2, 'usertype' => $usertype];
        $user = new User(0);
        $user->userid   = 2;
        $user->usertype = $usertype;
        $user->username = 'admin';
        Application::getInstance()->currentUser = $user;
    }

    /** Signed out, and no request left over. */
    protected function tearDown(): void
    {
        $_SESSION = [];
        $_GET     = [];
        if (Application::getInstance()) {
            Application::getInstance()->currentUser = null;
        }
    }

    /** Run an action that ends in a redirect. */
    private function act(callable $action): void
    {
        try {
            $action();
        } catch (\RuntimeException $exception) {
            $this->assertSame('redirect_quit', $exception->getMessage());
        }
    }

    /**
     * The list shows each task's next run from its expression at the screen's clock: for
     * `5,20,35,50 * * * *` at 03:21:09 that is 03:35, not one interval on.
     */
    public function testTheListShowsTheNextRunOfAListOfMinutes(): void
    {
        // Arrange
        $this->signIn(98);
        $task = (new ScheduledTask('payments:collect', 'command'))->cron('5,20,35,50 * * * *')->description('payments');
        $controller = $this->controller([$task]);

        // Act
        $html = $controller->display();
        $rows = $controller->lastView->published['tasks'];

        // Assert
        $this->assertSame('view:', $html);
        $this->assertCount(1, $rows);
        $this->assertSame('payments', $rows[0]['label']);
        $this->assertSame('2026-10-21 03:35', $rows[0]['next']->format('Y-m-d H:i'));
        $this->assertSame('At :05, :20, :35, :50 every hour', $rows[0]['words']);
        $this->assertSame(1, $controller->lastView->published['counts']['tasks']);
        $this->assertIsBool($controller->lastView->published['working']);
    }

    /**
     * Below the administration floor the screen is refused.
     */
    public function testBelowTheFloorTheScreenIsRefused(): void
    {
        // Arrange
        $this->signIn(97);
        $controller = $this->controller([]);

        // Act
        $this->act(fn () => $controller->display());

        // Assert
        $this->assertCount(1, $controller->redirectedTo, 'the screen was shown below the floor');
        $this->assertNull($controller->lastView);
    }

    /**
     * A task that is not in the schedule sends the reader back with the reason — from its page
     * and from "run now" — and nothing is requested.
     */
    public function testAnUnknownTaskSendsTheReaderBack(): void
    {
        // Arrange
        $this->signIn(98);
        $controller = $this->controller([]);
        $_GET['_option'] = 'deadbeefdeadbeef';

        // Act
        $this->act(fn () => $controller->show());
        $this->act(fn () => $controller->runnow());

        // Assert
        $this->assertSame([adminUrl('Scheduler'), adminUrl('Scheduler')], $controller->redirectedTo);
        $this->assertSame(['That task is not in the schedule.', 'That task is not in the schedule.'], $_SESSION['_errors'] ?? []);
    }

    /**
     * A task's page publishes its state and the first page of its runs.
     */
    public function testATasksPagePublishesItsStateAndRuns(): void
    {
        // Arrange
        $this->signIn(98);
        $task = (new ScheduledTask('reports:nightly', 'command'))->daily()->description('reports');
        $controller = $this->controller([$task]);
        $_GET['_option'] = $task->key();

        // Act
        $html = $controller->show();
        $published = $controller->lastView->published;

        // Assert
        $this->assertSame('view:show', $html);
        $this->assertSame('reports', $published['status']['label']);
        $this->assertIsArray($published['runs']);
        $this->assertSame(1, $published['page']);
        $this->assertGreaterThanOrEqual(1, $published['pages']);
    }

    /**
     * Below the floor a task's page and "run now" are refused too, and nothing is requested.
     */
    public function testBelowTheFloorAPageAndARunAreRefused(): void
    {
        // Arrange
        $this->signIn(97);
        $task = (new ScheduledTask('reports:nightly', 'command'))->daily();
        $controller = $this->controller([$task]);
        $_GET['_option'] = $task->key();

        // Act
        $this->act(fn () => $controller->show());
        $this->act(fn () => $controller->runnow());

        // Assert
        $this->assertCount(2, $controller->redirectedTo);
        $this->assertNull($controller->lastView);
        $this->assertArrayNotHasKey('_messages', $_SESSION);
    }

    /**
     * Where the history cannot take the request — no table yet — "run now" says so and sends
     * the reader back.
     */
    public function testARequestThatCannotBeRecordedSaysWhy(): void
    {
        // Arrange — a database without scheduleruns
        $this->signIn(98);
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }
        $db->schema()->dropTableIfExists('pramnos.scheduleruns');
        \Pramnos\Scheduling\RunLog::reset();
        $task = (new ScheduledTask('reports:nightly', 'command'))->daily();
        $controller = $this->controller([$task]);
        $_GET['_option'] = $task->key();

        // Act
        $this->act(fn () => $controller->runnow());

        // Assert
        $this->assertSame([adminUrl('Scheduler')], $controller->redirectedTo);
        $this->assertSame(['The run could not be requested. Run migrate: the scheduleruns table may not exist yet.'], $_SESSION['_errors'] ?? []);
        \Pramnos\Scheduling\RunLog::reset();
    }

    /**
     * Without a seam the screen reads the schedule itself — the application's definitions and
     * the framework's own — at the real clock.
     */
    public function testTheScreenReadsTheSchedule(): void
    {
        // Arrange
        $this->signIn(98);
        \Pramnos\Scheduling\Scheduler::reset();
        \Pramnos\Scheduling\FrameworkSchedule::reset();
        \Pramnos\Scheduling\Scheduler::call(static function (): void {
        })->hourly()->description('from the application');
        $controller = new class (Application::getInstance()) extends Scheduler {
            /** The last view it filled. */
            public ?object $lastView = null;

            /** A plain object to publish on. */
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

        try {
            // Act
            $controller->display();
            $labels = array_column($controller->lastView->tasks, 'label');

            // Assert
            $this->assertContains('from the application', $labels);
            $this->assertGreaterThan(1, count($labels), 'the framework\'s own tasks are missing');
        } finally {
            \Pramnos\Scheduling\Scheduler::reset();
            \Pramnos\Scheduling\FrameworkSchedule::reset();
        }
    }

    /**
     * The dashboard card: its id, its words, where it leads, and three tiles — the late and
     * the failed marked when there are any.
     */
    public function testTheDashboardCardCountsLateFailedAndRunning(): void
    {
        // Arrange
        $card = new \Pramnos\Dashboard\Widgets\ScheduleStatus();
        \Pramnos\Scheduling\Scheduler::reset();

        // Act
        $html  = $card->present(['late' => 2, 'failed' => 1, 'running' => 3]);
        $quiet = $card->present(['late' => 0, 'failed' => 0, 'running' => 0]);
        $data  = $card->data();
        \Pramnos\Scheduling\Scheduler::reset();
        \Pramnos\Scheduling\FrameworkSchedule::reset();

        // Assert
        $this->assertSame('scheduler', $card->id());
        $this->assertSame('Scheduler', $card->title());
        $this->assertNotSame('', $card->description());
        $this->assertTrue($card->shared());
        $this->assertStringEndsWith('Scheduler', (string) $card->link());
        $this->assertStringContainsString('2', $html);
        $this->assertNotSame($html, $quiet, 'a late task looked like none');
        $this->assertSame(['late', 'failed', 'running', 'tasks'], array_keys($data));
        $this->assertIsBool($card->allowed(null));
    }

    /**
     * An action whose redirect does not end the request — a redirect that only sets a header —
     * still returns at once rather than going on to draw the page or request a run.
     */
    public function testAnActionReturnsAfterItsRedirect(): void
    {
        // Arrange
        $this->signIn(97);
        $controller = $this->controller([]);
        $controller->quits = false;

        // Act
        $display = $controller->display();
        $show    = $controller->show();
        $controller->runnow();
        $this->signIn(98);
        $missing = $controller->show();

        // Assert
        $this->assertNull($display);
        $this->assertNull($show);
        $this->assertNull($missing);
        $this->assertNull($controller->lastView, 'a page was drawn after the redirect');
        $this->assertCount(4, $controller->redirectedTo);
    }
}
