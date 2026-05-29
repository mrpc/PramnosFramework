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
     */
    public function withoutOverlapping(string $lockDir = ''): static
    {
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
     * @throws \RuntimeException When the task type is unsupported.
     */
    public function run(): void
    {
        if ($this->noOverlap && $this->isLocked()) {
            return;
        }

        if ($this->noOverlap) {
            $this->acquireLock();
        }

        try {
            $this->execute();
        } finally {
            if ($this->noOverlap) {
                $this->releaseLock();
            }
        }
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
            // Shell out to the framework's bin/pramnos entry point
            $bin     = defined('PRAMNOS_BIN') ? PRAMNOS_BIN : 'php pramnos';
            $command = escapeshellcmd((string) $this->handler);
            passthru("{$bin} {$command}");
            return;
        }

        throw new \RuntimeException("Unknown scheduled task type '{$this->type}'.");
    }

    private function lockFile(): string
    {
        return $this->lockDir . DIRECTORY_SEPARATOR
            . 'pramnos_sched_' . md5($this->type . ':' . $this->describeHandler()) . '.lock';
    }

    private function isLocked(): bool
    {
        $file = $this->lockFile();
        if (!file_exists($file)) {
            return false;
        }
        // Lock is valid only if the PID inside is still running
        $pid = (int) file_get_contents($file);
        if ($pid > 0 && function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        // Can't verify PID — treat as locked to be safe
        return true;
    }

    private function acquireLock(): void
    {
        file_put_contents($this->lockFile(), getmypid());
    }

    private function releaseLock(): void
    {
        $file = $this->lockFile();
        if (file_exists($file)) {
            unlink($file);
        }
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
