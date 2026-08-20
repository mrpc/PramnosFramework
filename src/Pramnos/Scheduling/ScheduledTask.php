<?php

namespace Pramnos\Scheduling;

/**
 * Represents a single scheduled task with its timing and execution logic.
 *
 * Created via the fluent methods on Scheduler; not instantiated directly.
 *
 * ## Timing
 *
 * ```php
 * Scheduler::command('cleanup:temp')->daily()->at('02:00');
 * Scheduler::call(fn() => Cache::flush())->everyHour();
 * Scheduler::call($fn)->cron('*\/15 * * * *');
 * ```
 *
 * ## Overlap prevention
 *
 * ```php
 * Scheduler::command('slow:job')->hourly()->withoutOverlapping();
 * ```
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class ScheduledTask
{
    /** The underlying cron expression. */
    private CronExpression $cron;

    /** Human-readable description shown in schedule:list. */
    private string $description = '';

    /** When true, the task is skipped if a lock file indicates a previous run is still active. */
    private bool $noOverlap = false;

    /** Base directory for lock files (defaults to sys_get_temp_dir()). */
    private string $lockDir;

    /**
     * Seconds after which a lock whose holder cannot be identified — a different
     * host, a different container — is treated as abandoned.
     *
     * Only reached when the pid cannot be trusted; a holder on this host is
     * checked directly. Raise it for a task that legitimately runs longer than
     * this without finishing.
     */
    private int $lockStaleAfter = \Pramnos\Console\WorkerLock::DEFAULT_STALE_AFTER;

    /**
     * @param string|callable $handler CLI command name or PHP callable.
     * @param string          $type    'command' | 'callable' | 'job'
     */
    public function __construct(
        private readonly mixed  $handler,
        private readonly string $type
    ) {
        $this->cron    = new CronExpression('* * * * *');
        $this->lockDir = sys_get_temp_dir();
    }

    // =========================================================================
    // Fluent timing methods
    // =========================================================================

    /**
     * Sets an arbitrary cron expression.
     *
     * @param string $expression 5-field cron expression, e.g. '0 2 * * *'.
     */
    public function cron(string $expression): static
    {
        $this->cron = new CronExpression($expression);
        return $this;
    }

    /** Runs once per minute. */
    public function everyMinute(): static
    {
        return $this->cron('* * * * *');
    }

    /** Runs every N minutes (1–59). */
    public function everyNMinutes(int $n): static
    {
        return $this->cron("*/{$n} * * * *");
    }

    /** Runs every 5 minutes. */
    public function everyFiveMinutes(): static
    {
        return $this->everyNMinutes(5);
    }

    /** Runs every 10 minutes. */
    public function everyTenMinutes(): static
    {
        return $this->everyNMinutes(10);
    }

    /** Runs every 15 minutes. */
    public function everyFifteenMinutes(): static
    {
        return $this->everyNMinutes(15);
    }

    /** Runs every 30 minutes. */
    public function everyThirtyMinutes(): static
    {
        return $this->everyNMinutes(30);
    }

    /** Runs at the start of every hour. */
    public function hourly(): static
    {
        return $this->cron('0 * * * *');
    }

    /** Runs at midnight each day. */
    public function daily(): static
    {
        return $this->cron('0 0 * * *');
    }

    /**
     * Adjusts the minute and hour of the current expression to run at the
     * specified time.  Typically chained after daily() / weekly():
     *
     * ```php
     * ->daily()->at('14:30')   // runs at 14:30 each day
     * ->weekly()->at('03:00')  // runs at 03:00 every Sunday
     * ```
     *
     * @param string $time 'HH:MM' or 'H:MM'
     */
    public function at(string $time): static
    {
        $this->cron = $this->cron->withTime($time);
        return $this;
    }

    /** Runs at midnight on Sunday. */
    public function weekly(): static
    {
        return $this->cron('0 0 * * 0');
    }

    /** Runs at midnight on the 1st of each month. */
    public function monthly(): static
    {
        return $this->cron('0 0 1 * *');
    }

    /** Runs at midnight on 1 Jan. */
    public function yearly(): static
    {
        return $this->cron('0 0 1 1 *');
    }

    // =========================================================================
    // Options
    // =========================================================================

    /**
     * Skips execution if a previous run of this task is still active (based on
     * a lock file in the system temp directory).
     *
     * @param string $lockDir    Where the lock lives (default: the system temp dir)
     * @param int    $staleAfter Seconds after which a lock whose holder cannot be
     *                           identified is treated as abandoned. 0 keeps the
     *                           default. Only consulted when the holder is on
     *                           another host, so a long task on this host is never
     *                           taken over while it is still running.
     * @return static
     */
    public function withoutOverlapping(string $lockDir = '', int $staleAfter = 0): static
    {
        if ($staleAfter > 0) {
            $this->lockStaleAfter = $staleAfter;
        }

        $this->noOverlap = true;
        if ($lockDir !== '') {
            $this->lockDir = $lockDir;
        }
        return $this;
    }

    /**
     * Sets a human-readable description shown by `schedule:list`.
     */
    public function description(string $desc): static
    {
        $this->description = $desc;
        return $this;
    }

    // =========================================================================
    // Execution
    // =========================================================================

    /**
     * Returns true when this task is due at the given moment.
     */
    public function isDue(\DateTimeInterface $when): bool
    {
        return $this->cron->isDue($when);
    }

    /**
     * Executes the task.
     *
     * @return bool True if the task ran; false if it was skipped because a
     *              previous run is still holding the overlap lock.
     * @throws \RuntimeException When the task type is unsupported.
     */
    public function run(): bool
    {
        $lock = null;

        if ($this->noOverlap) {
            $lock = $this->lock();
            if (!$lock->acquire()) {
                return false;
            }
        }

        try {
            $this->execute();
        } finally {
            if ($lock !== null) {
                $lock->release();
                // WorkerLock keeps the file and marks it stopped, which is how a
                // dashboard reads a daemon's last state. A scheduled task has no
                // dashboard and runs again in a minute, so the file is removed:
                // the next run then takes a plain lock rather than a takeover,
                // and nothing watching the directory sees a lock that is not one.
                // Only ever reached by the process that acquired it — a failed
                // acquire returns before the try.
                $this->removeLockFile();
            }
        }

        return true;
    }

    /**
     * Returns summary information for `schedule:list`.
     *
     * @return array{type: string, handler: string, expression: string, description: string, no_overlap: bool}
     */
    public function getSummary(): array
    {
        return [
            'type'        => $this->type,
            'handler'     => $this->describeHandler(),
            'expression'  => $this->cron->getExpression(),
            'description' => $this->description,
            'no_overlap'  => $this->noOverlap,
        ];
    }

    /**
     * Returns the raw CronExpression object for inspection.
     */
    public function getCronExpression(): CronExpression
    {
        return $this->cron;
    }

    // =========================================================================
    // Internal
    // =========================================================================

    private function execute(): void
    {
        if ($this->type === 'callable') {
            ($this->handler)();
            return;
        }

        if ($this->type === 'job') {
            $job = $this->handler;
            if (is_callable($job)) {
                $job();
            } elseif (is_object($job) && method_exists($job, 'handle')) {
                $job->handle();
            } else {
                throw new \RuntimeException('Job must be callable or have a handle() method.');
            }
            return;
        }

        if ($this->type === 'command') {
            $command = $this->consoleBinary() . ' ' . escapeshellcmd((string) $this->handler);
            $status  = $this->runShellCommand($command);

            // A command that could not run is a task that did not happen, and
            // the callers already know how to report a throw — `schedule:run`
            // prints "✗ Failed" and returns non-zero, `work` counts it. Before
            // this, `passthru()`'s status was discarded: every framework task
            // reported "✓ Done" while the shell answered "Could not open input
            // file", once a minute, for as long as the installation existed.
            if ($status !== 0) {
                throw new \RuntimeException(sprintf(
                    "Scheduled command '%s' exited with status %d (ran: %s).",
                    (string) $this->handler,
                    $status,
                    $command
                ));
            }

            return;
        }

        throw new \RuntimeException("Unknown scheduled task type '{$this->type}'.");
    }

    /**
     * The console this application is actually run with.
     *
     * The default was the literal `php pramnos` — the framework's own entry
     * point, which a scaffolded application does not have: the scaffolder
     * generates `<cliName>.php` in the project root and says so. Every
     * `command` task in such a project answered "Could not open input file:
     * pramnos" and did nothing, which on one installation meant `spool:drain`
     * had never run: 478 rows waiting in a file and a `tokenactions` table that
     * had never had a row in it.
     *
     * The running script is a better assumption than a fixed name, and not a
     * guess: the process running the scheduler is by definition a console that
     * knows the commands the scheduler wants to run. `PRAMNOS_BIN` still wins
     * where an installation needs to say something else.
     *
     * Only in CLI. Under a web SAPI the running script is `index.php`, which
     * would turn a scheduled command into a second HTTP bootstrap.
     *
     * @return string A shell-ready prefix, without a trailing space
     */
    protected function consoleBinary(): string
    {
        if (defined('PRAMNOS_BIN')) {
            return (string) \PRAMNOS_BIN;
        }

        if (PHP_SAPI === 'cli') {
            $script = $_SERVER['SCRIPT_FILENAME'] ?? ($GLOBALS['argv'][0] ?? '');
            if (is_string($script) && $script !== '' && is_file($script)) {
                return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
            }
        }

        return 'php pramnos';
    }

    /**
     * Run a shell command and return its exit status.
     *
     * A seam: the only line in this class that reaches the shell, so a test can
     * assert what would be run without running it.
     *
     * @param  string $command The full command line
     * @return int Exit status, 0 on success
     */
    protected function runShellCommand(string $command): int
    {
        $status = 0;
        passthru($command, $status);

        return $status;
    }

    private function lockFile(): string
    {
        return $this->lockDir . DIRECTORY_SEPARATOR
            . 'pramnos_sched_' . md5($this->type . ':' . $this->describeHandler()) . '.lock';
    }

    /**
     * The overlap lock for this task.
     *
     * {@see \Pramnos\Console\WorkerLock}, rather than the pid file this used to
     * write, for the reason a pid file cannot answer the question it is asked:
     * **a pid is a fact about the process table of whoever is asking.** This
     * wrote its own pid to a file and later called `posix_kill($pid, 0)` on
     * whatever it read back. Two containers sharing a volume — an application
     * container and a daemon container, which is the ordinary shape here — each
     * see the other's recorded pid as alive whenever some unrelated local
     * process happens to hold that number. The task is then skipped for ever,
     * silently, which is indistinguishable from never having scheduled it.
     *
     * `WorkerLock` records the host beside the pid and trusts the pid only when
     * the host matches, falling back to heartbeat age when it does not. It also
     * closes a race this had: `isLocked()` followed by `acquireLock()` is a
     * check and then a write, and two schedulers a millisecond apart both passed
     * the check. `acquire()` is one atomic create.
     *
     * A lock left behind in the old format is a bare pid rather than JSON.
     * `WorkerLock::readState()` recognises that case and reports an unknown
     * holder with the file's own age, so such a lock is honoured while it is
     * fresh and taken over once it is older than the stale threshold. An upgrade
     * therefore neither runs a task twice nor inherits a lock that outlives the
     * process that wrote it.
     *
     * @return \Pramnos\Console\WorkerLock
     */
    /**
     * Delete this task's lock file, if it is still there.
     *
     * @return void
     */
    private function removeLockFile(): void
    {
        $file = $this->lockFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function lock(): \Pramnos\Console\WorkerLock
    {
        return new \Pramnos\Console\WorkerLock(
            'schedule:' . $this->describeHandler(),
            $this->lockFile(),
            $this->lockStaleAfter
        );
    }

    private function describeHandler(): string
    {
        if (is_string($this->handler)) {
            return $this->handler;
        }
        if (is_array($this->handler)) {
            $class = is_object($this->handler[0]) ? get_class($this->handler[0]) : $this->handler[0];
            return $class . '::' . $this->handler[1];
        }
        if ($this->handler instanceof \Closure) {
            return 'Closure';
        }
        if (is_object($this->handler)) {
            return get_class($this->handler);
        }
        return 'unknown';
    }
}
