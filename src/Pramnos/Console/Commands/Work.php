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
        $due = Scheduler::getDue(new \DateTime());

        if ($due === []) {
            return 0;
        }

        $failed = 0;

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
     * Sleep until the next check, waking early if asked to stop.
     *
     * A plain `sleep($interval)` would make a SIGTERM take up to a minute to be
     * noticed, which turns every deploy into a wait.
     *
     * @return void
     */
    protected function sleepUnlessStopping(): void
    {
        for ($slept = 0; $slept < $this->interval; $slept++) {
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
