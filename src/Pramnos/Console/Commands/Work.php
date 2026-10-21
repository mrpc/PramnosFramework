<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Console\CommandBase;
use Pramnos\Scheduling\Scheduler;

/**
 * One process that does everything the background needs doing.
 *
 * The framework has several things that must happen periodically — buffered
 * writes have to be written, queued rows have to reach their compressed chunks,
 * finished queue items have to be cleared — and the traditional answer is a
 * crontab line each. That answer has two problems. Every new framework version
 * that needs a periodic job needs every project to edit its crontab, which is a
 * migration nobody runs. And a container does not have cron at all: the usual
 * workarounds are a second process nobody supervises, or an image with a cron
 * daemon bolted into it.
 *
 * So there are two supported shapes, and both run the *same* schedule:
 *
 * **With cron** — one line, forever, whatever the framework adds later:
 *
 * ```
 * * * * * * cd /path/to/app && php pramnos schedule:run >> /dev/null 2>&1
 * ```
 *
 * **Without cron** — one long-running process, under systemd, supervisor, or as
 * a container's command:
 *
 * ```
 * php pramnos work
 * ```
 *
 * This is that process. It wakes every minute, runs whatever is due, and sleeps
 * again — a cron daemon for one application, with the application's own
 * definition of "due". It holds a single-instance lock, so two copies cannot
 * both run the same job, and it stops cooperatively: a SIGTERM during a task
 * lets the task finish rather than cutting it in half.
 *
 * ## Usage
 *
 * ```
 * php pramnos work                    # run until told to stop
 * php pramnos work --once             # one pass, then exit (a cron equivalent)
 * php pramnos work --interval=30      # check for due work every 30 seconds
 * php pramnos work --max-runtime=3600 # exit after an hour, for a supervisor to restart
 * ```
 *
 * ## This is not the queue worker
 *
 * `queue:process` runs background *jobs* — the things an application dispatches
 * and expects to happen within seconds. This runs the *schedule* — the things
 * that happen on the clock. They are separate processes because they want
 * opposite things: a queue worker polls constantly to keep latency low, and a
 * scheduler sleeps a minute at a time because nothing it runs is due more often
 * than that. Running both from one loop would either make jobs wait a minute or
 * make the scheduler spin.
 *
 * A small installation runs both:
 *
 * ```
 * php pramnos work           &   # the schedule
 * php pramnos queue:process --daemon &   # the jobs
 * ```
 *
 * An installation with cron runs the crontab line for the first and keeps the
 * second under systemd or supervisor.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Work extends CommandBase
{
    /** @var string The command name as typed */
    protected static $defaultName = 'work';

    /** @var int How long to sleep between checks, in seconds */
    protected int $interval = 60;

    /** How many minutes back a pass looks for ones the previous pass did not reach. */
    public const CATCH_UP_MINUTES = 15;

    /** @var int|null The last minute a pass asked about, as a timestamp at its :00; null before the first */
    private ?int $lastMinute = null;

    /** @var string|null Schedule definition file; overridable for tests */
    public ?string $scheduleFile = null;

    /**
     * The lock this worker holds.
     *
     * One name for the whole process rather than one per task: the individual
     * tasks take their own locks through `withoutOverlapping()`, and this one
     * exists to stop a second *worker* being started beside the first.
     */
    protected function getJobName(): string
    {
        return 'pramnos-work.lock';
    }

    /**
     * Where a `work` process keeps its lock — for a command that asks whether one is running.
     *
     * The worker's own answer, so the asker reads the file the worker writes: `schedule:list`
     * built a `WorkerLock` by this name, whose default path adds `.lock` once more, and read
     * `pramnos-work.lock.lock` — "No `work` process is running" beside one that was.
     */
    public function lockFilePath(): string
    {
        return $this->resolvedJobLockFilePath();
    }

    protected function configure(): void
    {
        $this
            ->setName('work')
            ->setDescription(
                'Run the framework and application schedule continuously — '
                . 'a cron replacement for environments without one'
            )
            ->addOption(
                'once',
                null,
                InputOption::VALUE_NONE,
                'Run one pass and exit, instead of looping'
            )
            ->addOption(
                'interval',
                'i',
                InputOption::VALUE_REQUIRED,
                'Seconds between checks for due work',
                '60'
            )
            ->addOption(
                'max-runtime',
                null,
                InputOption::VALUE_REQUIRED,
                'Exit after this many seconds, for a supervisor to restart cleanly'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->interval = max(1, (int) $input->getOption('interval'));
        $maxRuntime     = (int) $input->getOption('max-runtime');
        $once           = (bool) $input->getOption('once');

        Scheduler::loadDefinitions($this->scheduleFile);

        if ($once) {
            return $this->runDuePass($output) === 0
                ? self::SUCCESS
                : self::FAILURE;
        }

        if ($this->checkIfRunning()) {
            $output->writeln('<error>Another worker is already running.</error>');

            return self::FAILURE;
        }

        $this->startJob();
        $this->installStopSignals();
        $this->systemd()->ready();

        $output->writeln(
            '<info>Working.</info> Checking every ' . $this->interval . 's. '
            . 'Stop with SIGTERM or Ctrl-C.'
        );

        $started = time();
        $errors  = 0;

        try {
            while (!$this->shouldStop()) {
                $errors += $this->runDuePass($output);

                // A worker whose lock was taken over by a replacement — a deploy
                // started the new one before this one stopped — must not carry on
                // running the same jobs beside it.
                if (!$this->heartbeat(['errors' => $errors])) {
                    $output->writeln('<comment>Lock taken over; stopping.</comment>');
                    break;
                }

                if ($maxRuntime > 0 && (time() - $started) >= $maxRuntime) {
                    $output->writeln('<comment>Reached max runtime; stopping.</comment>');
                    break;
                }

                $this->sleepUnlessStopping();
            }
        } finally {
            $this->endJob();
        }

        return self::SUCCESS;
    }

    /**
     * Run everything due right now.
     *
     * A task that raises is reported and counted, and the pass continues: one
     * broken job must not stop the others from running, this minute or ever.
     *
     * @return int How many tasks failed
     */
    protected function runDuePass(OutputInterface $output): int
    {
        $due = $this->dueSinceLastPass();
        // The console a task marked inProcess() runs through: this one.
        \Pramnos\Scheduling\ScheduledTask::useConsole($this->getApplication());
        // What an operator asked for from the scheduler screen, before the clock's own work.
        $failed = \Pramnos\Scheduling\RunLog::runRequested(Scheduler::all());

        if ($due === []) {
            return $failed;
        }

        foreach ($due as $task) {
            $summary = $task->getSummary();
            $label   = $summary['description'] ?: $summary['handler'];
            $started = microtime(true);

            try {
                $ran = $task->run();
                $ms  = (int) round((microtime(true) - $started) * 1000);

                if ($ran) {
                    $output->writeln('  <info>✓</info> ' . $label . ' (' . $ms . 'ms)');
                    $this->log('ran: ' . $label . ' in ' . $ms . 'ms');
                } else {
                    $output->writeln('  <comment>↷</comment> ' . $label . ' — still running');
                }
            } catch (\Throwable $ex) {
                $failed++;
                $output->writeln('  <error>✗ ' . $label . ': ' . $ex->getMessage() . '</error>');
                $this->log(
                    'failed: ' . $label . ' — ' . $ex->getMessage(),
                    ['level' => 'error']
                );
            }
        }

        return $failed;
    }

    /**
     * The tasks due in any minute since the last pass asked — each once.
     *
     * Every minute, not the current one alone. A pass that runs a nine-second task starts
     * the next one sixty seconds after it *ended*, so the clock walks: passes at 03:19:59
     * and 03:21:09 never ask about 03:20, and a task on `5,20,35,50` silently does not run —
     * a weekly one landing on a skipped minute waits a week. So a pass asks about each
     * minute after the last one it asked about, up to now, at most
     * {@see CATCH_UP_MINUTES} back: a worker that was stopped for an hour does not replay
     * the hour. A minute already asked about is not asked again, so a second pass inside one
     * minute runs nothing twice. A task due in several of the minutes runs once.
     *
     * The last minute asked about is also kept on disk, beside the lock ({@see minuteFile()}),
     * and a new process starts from it. A deploy restarts the worker, and the old one finishes
     * its pass before the new one starts: a minute or two in which nothing asked. Kept in
     * memory alone, those minutes were lost on every restart — a task on `18 7 * * *` that fell
     * in one never ran, and left no row in `scheduleruns`, not even a skipped one.
     *
     * @return list<\Pramnos\Scheduling\ScheduledTask>
     */
    protected function dueSinceLastPass(): array
    {
        $now     = $this->clock();
        $current = $now->getTimestamp() - $now->getTimestamp() % 60;
        $last    = $this->lastMinute ?? $this->storedMinute();
        $first   = $last === null
            ? $current
            : max($last + 60, $current - (self::CATCH_UP_MINUTES - 1) * 60);
        $this->lastMinute = max($last ?? $current, $current);
        @file_put_contents($this->minuteFile(), (string) $this->lastMinute, LOCK_EX);

        $due = [];
        for ($minute = $first; $minute <= $current; $minute += 60) {
            foreach (Scheduler::getDue($now->setTimestamp($minute)) as $task) {
                $due[spl_object_id($task)] = $task;
            }
        }

        return array_values($due);
    }

    /** Where the last minute a pass asked about is kept between processes: beside the lock. */
    protected function minuteFile(): string
    {
        return $this->lockFilePath() . '.minute';
    }

    /** The last minute a previous process asked about, or null when none is recorded. */
    private function storedMinute(): ?int
    {
        $stored = @file_get_contents($this->minuteFile());

        return $stored !== false && ctype_digit(trim($stored)) ? (int) trim($stored) : null;
    }

    /** The time a pass asks about — a seam, so a test can set the clock. */
    protected function clock(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    /**
     * Sleep until the next check, waking early if asked to stop.
     *
     * A plain `sleep($interval)` would make a SIGTERM take up to a minute to be
     * noticed, which turns every deploy into a wait. It wakes at the start of the next
     * minute, sooner than the interval, so a pass is not a minute after the last one
     * *ended* — though {@see dueSinceLastPass()} catches a minute it missed anyway.
     *
     * @return void
     */
    protected function sleepUnlessStopping(): void
    {
        $seconds = min($this->interval, 60 - time() % 60);
        for ($slept = 0; $slept < $seconds; $slept++) {
            if ($this->shouldStop()) {
                return;
            }
            sleep(1);
        }
    }

    /**
     * Record an outcome to the `schedule` log channel.
     *
     * @param  string               $message
     * @param  array<string, mixed> $context
     * @return void
     */
    protected function log(string $message, array $context = []): void
    {
        \Pramnos\Logs\Logger::log($message, 'schedule', 'log', false, $context);
    }
}
