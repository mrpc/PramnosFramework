<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

use Pramnos\Application\Controller;
use Pramnos\Scheduling\RunLog;
use Pramnos\Scheduling\ScheduleStatus;

/**
 * The schedule, for an administrator: what runs and when, how each task's last run went, what is
 * late, and a "run now".
 *
 * Reachable at `/admin/Scheduler` as the framework's own controller — no wrapper in the
 * application is needed. Reads the schedule the way `schedule:list` does and the history
 * `RunLog` keeps; a "run now" is a request the next pass of `work` or `schedule:run` takes,
 * never a run inside this request.
 *
 * Actions: display, show (one task's runs), runnow (POST).
 */
class Scheduler extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.scheduler';

    /** Runs per page of a task's history. */
    public const PER_PAGE = 25;

    /** Registers the actions: all behind sign-in, the run a POST with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'show', 'runnow']);
        // A link that runs a task is one anybody can make a signed-in administrator follow.
        $this->addWriteAction(['runnow']);
        parent::__construct($application);
    }

    /** Every task with its next run, its last run, and whether it is late, failed or running. */
    public function display(): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        \Pramnos\Framework\Factory::getDocument()->title = t('Scheduler');
        $view = $this->getView('scheduler');
        $view->tasks   = ScheduleStatus::of($this->tasks(), $this->now());
        $view->counts  = ScheduleStatus::counts($view->tasks);
        $view->working = ScheduleStatus::workerRunning();
        $view->history = RunLog::available();

        return $view->display();
    }

    /** One task's runs, newest first, a page at a time, each with what it printed. */
    public function show(mixed $key = null): mixed
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return null;
        }

        $task = $this->find((string) \Pramnos\Http\Request::staticGetOption());
        if ($task === null) {
            $this->addError(t('That task is not in the schedule.'));
            $this->redirect(adminUrl('Scheduler'));
            return null;
        }

        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $runs  = [];
        $total = 0;
        if (RunLog::available()) {
            $runs  = RunLog::table()->where('task', $task->key())->orderBy('id', 'desc')
                ->forPage($page, self::PER_PAGE)->getAll();
            $total = RunLog::table()->where('task', $task->key())->count();
        }

        \Pramnos\Framework\Factory::getDocument()->title = $task->label();
        $view = $this->getView('scheduler');
        $view->status = ScheduleStatus::of([$task], $this->now())[0];
        $view->runs   = $runs;
        $view->page   = $page;
        $view->pages  = max(1, (int) ceil($total / self::PER_PAGE));

        return $view->display('show');
    }

    /**
     * Ask for a task to run once: the next pass of `work` or `schedule:run` takes it. Nothing
     * runs in this request.
     */
    public function runnow(mixed $key = null): void
    {
        if ($this->requireMinUserType($this->adminFloor())) {
            return;
        }

        // The task's key in the address, as the list's buttons send it — or as a `task` field,
        // as the page's one tool for an AI agent (data-pf-tool="run_scheduled_task") sends it.
        $key  = (string) \Pramnos\Http\Request::staticGetOption();
        $key  = $key !== '' ? $key : (string) (new \Pramnos\Http\Request())->get('task', '', 'post');
        $task = $this->find($key);
        if ($task === null) {
            $this->addError(t('That task is not in the schedule.'));
        } elseif (!RunLog::request($task, $this->who())) {
            $this->addError(t('The run could not be requested. Run migrate: the scheduleruns table may not exist yet.'));
        } else {
            \Pramnos\Logs\Logger::log(
                'Scheduler: requested a run of ' . $task->label() . ' by ' . ($this->who() ?: 'an unknown user')
                . ' from ' . (string) ($_SERVER['REMOTE_ADDR'] ?? '?'),
                'auth'
            );
            $this->addMessage(t('Requested. The scheduler runs it on its next pass.'));
        }
        $this->redirect(adminUrl('Scheduler'));
    }

    /** The schedule's tasks — a seam, so a test can hold its own. @return array<int, \Pramnos\Scheduling\ScheduledTask> */
    protected function tasks(): array
    {
        \Pramnos\Scheduling\Scheduler::loadDefinitions();

        return \Pramnos\Scheduling\Scheduler::all();
    }

    /** The moment the screen describes — a seam, so a test can set the clock. */
    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    /** The task with this key, or null. */
    private function find(string $key): ?\Pramnos\Scheduling\ScheduledTask
    {
        foreach ($this->tasks() as $task) {
            if ($task->key() === $key) {
                return $task;
            }
        }

        return null;
    }

    /** Who is asking, for the history and the audit log. */
    private function who(): string
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return is_object($user) && (int) ($user->userid ?? 0) > 0
            ? (string) ($user->username ?? ('user ' . (int) $user->userid))
            : '';
    }
}
