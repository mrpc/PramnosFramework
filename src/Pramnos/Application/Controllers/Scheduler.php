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
 * Actions: display, show (one task's runs and its settings), runnow (POST), adjust (POST),
 * reset (POST).
 *
 * Two abilities. `admin.scheduler` opens everything. {@see ADJUST_ABILITY} changes the tasks the
 * application declared `->adjustable()`; somebody who holds only that sees those tasks alone,
 * with no "run now". Where the application defines no `scheduler.adjust` rule, the screen's own
 * ability adjusts too.
 */
class Scheduler extends Controller
{
    /** The administration ability that opens this screen — its menu item's id. */
    protected string $adminAbility = 'admin.scheduler';

    /** Runs per page of a task's history. */
    public const PER_PAGE = 25;

    /** The ability that changes an adjustable task: pause, frequency, hours, per-run limit. */
    public const ADJUST_ABILITY = 'scheduler.adjust';

    /**
     * Every action decides for itself ({@see refused()}): the screen opens for `admin.scheduler`
     * *or* {@see ADJUST_ABILITY}, which the automatic check of one ability cannot express.
     *
     * @var list<string>
     */
    protected array $adminPublicActions = ['display', 'show', 'runnow', 'adjust', 'reset'];

    /** Registers the actions: all behind sign-in, the run a POST with the session's token. */
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        $this->addAuthAction(['display', 'show', 'runnow', 'adjust', 'reset']);
        // A link that runs a task is one anybody can make a signed-in administrator follow.
        $this->addWriteAction(['runnow', 'adjust', 'reset']);
        parent::__construct($application);
    }

    /** Every task with its next run, its last run, and whether it is late, failed or running. */
    public function display(): mixed
    {
        if ($this->refused(false)) {
            return null;
        }

        \Pramnos\Framework\Factory::getDocument()->title = t('Scheduler');
        $view = $this->getView('scheduler');
        $view->tasks   = ScheduleStatus::of($this->visibleTasks(), $this->now());
        $view->full    = $this->seesAll();
        $view->adjust  = $this->mayAdjust();
        $view->counts  = ScheduleStatus::counts($view->tasks);
        $view->working = ScheduleStatus::workerRunning();
        $view->history = RunLog::available();

        return $view->display();
    }

    /** One task's runs, newest first, a page at a time, each with what it printed. */
    public function show(mixed $key = null): mixed
    {
        if ($this->refused(false)) {
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
        $view->full   = $this->seesAll();
        $view->adjust = $task->adjustment() !== null && $this->mayAdjust();

        return $view->display('show');
    }

    /**
     * Save an operator's settings for an adjustable task: enabled, a frequency from its list, an
     * hours window, a per-run limit within its range. Applies on the scheduler's next pass.
     */
    public function adjust(mixed $key = null): void
    {
        $task = $this->adjustableTask();
        if ($task === null) {
            return;
        }
        $values = $this->submitted($task);
        if (is_string($values)) {
            $this->addError($values);
        } elseif (!\Pramnos\Scheduling\ScheduleOverrides::save($task, $values, $this->me(), $this->who(), $this->now()->getTimestamp())) {
            $this->addError(t('The settings could not be saved. Run migrate: the scheduleoverrides table may not exist yet.'));
        } else {
            $this->addMessage(t('Saved. The scheduler applies it on its next pass.'));
        }
        $this->redirect(adminUrl('Scheduler/show/') . $task->key());
    }

    /** Back to the code's own schedule for an adjustable task. */
    public function reset(mixed $key = null): void
    {
        $task = $this->adjustableTask();
        if ($task === null) {
            return;
        }
        if (\Pramnos\Scheduling\ScheduleOverrides::forget($task, $this->me())) {
            $this->addMessage(t('Back to the schedule in the code.'));
        } else {
            $this->addError(t('The settings could not be saved. Run migrate: the scheduleoverrides table may not exist yet.'));
        }
        $this->redirect(adminUrl('Scheduler/show/') . $task->key());
    }

    /**
     * The adjustable task an `adjust` or `reset` names, when this account may change it; null
     * after refusing or redirecting.
     */
    private function adjustableTask(): ?\Pramnos\Scheduling\ScheduledTask
    {
        if ($this->refused(false)) {
            return null;
        }
        if (!$this->mayAdjust()) {
            $this->addError(t('You may not change the schedule.'));
            $this->redirect(adminUrl('Scheduler'));

            return null;
        }
        $task = $this->find((string) \Pramnos\Http\Request::staticGetOption());
        if ($task === null || $task->adjustment() === null) {
            $this->addError(t('That task is not in the schedule.'));
            $this->redirect(adminUrl('Scheduler'));

            return null;
        }

        return $task;
    }

    /**
     * The form, validated against what the task offers: its settings, or why they are refused.
     *
     * @return array{enabled: bool, frequency: ?string, hoursfrom: ?string, hoursto: ?string, runlimit: ?int}|string
     */
    private function submitted(\Pramnos\Scheduling\ScheduledTask $task): array|string
    {
        $offer = $task->adjustment();
        $kind  = trim((string) ($_POST['frequency'] ?? ''));
        $time  = trim((string) ($_POST['time'] ?? ''));
        $frequency = match ($kind) {
            ''      => null,
            'daily' => 'daily:' . $time,
            'weekly' => 'weekly:' . (int) ($_POST['weekday'] ?? 1) . ':' . $time,
            default => $kind,
        };
        if ($frequency !== null && !\Pramnos\Scheduling\ScheduleOverrides::offers($offer['presets'], $frequency)) {
            return t('That frequency is not one this task offers.');
        }

        $from = trim((string) ($_POST['hoursfrom'] ?? ''));
        $to   = trim((string) ($_POST['hoursto'] ?? ''));
        if (($from === '') !== ($to === '')
            || ($from !== '' && (\Pramnos\Scheduling\ScheduleOverrides::minuteOfDay($from) === null || \Pramnos\Scheduling\ScheduleOverrides::minuteOfDay($to) === null || $from === $to))) {
            return t('The hours need a start and an end, as HH:MM, and they cannot be the same.');
        }

        $limit = trim((string) ($_POST['runlimit'] ?? ''));
        $range = $offer['limit'];
        if ($limit !== '' && ($range === null || !ctype_digit($limit) || (int) $limit < $range[0] || (int) $limit > $range[1])) {
            return $range === null
                ? t('This task takes no per-run limit.')
                : sprintf(t('The per-run limit is between %d and %d.'), $range[0], $range[1]);
        }

        return [
            'enabled'   => !empty($_POST['enabled']),
            'frequency' => $frequency,
            'hoursfrom' => $from === '' ? null : $from,
            'hoursto'   => $to === '' ? null : $to,
            'runlimit'  => $limit === '' ? null : (int) $limit,
        ];
    }

    /** Whether this account opens the whole screen: `admin.scheduler`. */
    protected function seesAll(): bool
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return \Pramnos\Auth\AdminAccess::allows($user === false ? null : $user, $this->adminAbility, $this->adminFloor());
    }

    /** Whether this account changes adjustable tasks: {@see ADJUST_ABILITY}, or the screen's own where no rule defines it. */
    protected function mayAdjust(): bool
    {
        return \Pramnos\Auth\Gate::has(self::ADJUST_ABILITY) ? $this->can(self::ADJUST_ABILITY) : $this->seesAll();
    }

    /**
     * Refuse, the way every administration screen refuses, unless this account opens the whole
     * screen — or, where `$needsAll` is false, adjusts tasks.
     */
    private function refused(bool $needsAll): bool
    {
        if ($this->seesAll() || (!$needsAll && $this->mayAdjust())) {
            return false;
        }

        return $this->requireMinUserType($this->adminFloor());
    }

    /** The tasks this account sees: all of them, or the adjustable ones alone. @return array<int, \Pramnos\Scheduling\ScheduledTask> */
    private function visibleTasks(): array
    {
        $tasks = $this->tasks();

        return $this->seesAll() ? $tasks : array_values(array_filter($tasks, static fn ($task): bool => $task->adjustment() !== null));
    }

    /**
     * Ask for a task to run once: the next pass of `work` or `schedule:run` takes it. Nothing
     * runs in this request.
     */
    public function runnow(mixed $key = null): void
    {
        if ($this->refused(true)) {
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

    /** The task with this key, among those this account sees, or null. */
    private function find(string $key): ?\Pramnos\Scheduling\ScheduledTask
    {
        foreach ($this->visibleTasks() as $task) {
            if ($task->key() === $key) {
                return $task;
            }
        }

        return null;
    }

    /** The signed-in account's id, for the audit log; null when there is none. */
    private function me(): ?int
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return is_object($user) && (int) ($user->userid ?? 0) > 0 ? (int) $user->userid : null;
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
