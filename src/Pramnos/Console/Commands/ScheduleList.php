<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Pramnos\Scheduling\Scheduler;

/**
 * Lists all registered scheduled tasks.
 *
 * Displays a table of each task's type, cron expression, handler/description,
 * and overlap-prevention flag.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class ScheduleList extends Command
{
    protected static $defaultName = 'schedule:list';

    /** Path to the schedule definition file. Overridable for testing (default: ROOT/app/schedule.php). */
    public ?string $scheduleFile = null;

    protected function configure(): void
    {
        $this
            ->setName('schedule:list')
            ->setDescription('List all registered scheduled tasks');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Scheduler::loadDefinitions($this->scheduleFile);
        $tasks = Scheduler::all();

        if (empty($tasks)) {
            $output->writeln('<comment>No scheduled tasks registered.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Type', 'Expression', 'Handler / Description', 'No Overlap']);

        foreach ($tasks as $task) {
            $s       = $task->getSummary();
            $handler = $s['description'] ?: $s['handler'];
            $table->addRow([
                $s['type'],
                $s['expression'],
                $handler,
                $s['no_overlap'] ? '<info>yes</info>' : 'no',
            ]);
        }

        $table->render();

        $this->reportWhetherAnythingRunsThis($output, count($tasks));

        return Command::SUCCESS;
    }

    /**
     * Say whether anything is actually executing this schedule.
     *
     * **Listing reads definitions; running needs a process, and nothing checked for one.**
     * `schedule:list` printed six due jobs on an installation where none of them could run:
     * the application had forked `DaemonOrchestrator` without extending the framework's, so
     * `includeScheduler()` and `schedulerProcess()` never ran, there was no `work` process
     * and no `schedule:run` in the crontab. Six registered framework jobs had **never
     * executed** — `spool:drain` every minute, `timescale:drain` hourly,
     * `auth:token-cleanup`, `auth:twofactor-cleanup`, `mail:prune` and
     * `auth:webhook-deliver` every five minutes.
     *
     * That took reading `ps` to find. One line here would have found it in seconds, which
     * is the whole argument for putting it here: this is the command somebody runs when
     * they wonder about the schedule.
     *
     * A `work` process is detectable — it holds `pramnos-work.lock` — and a `schedule:run`
     * crontab line is not, so the wording says what is known rather than asserting the
     * negative.
     */
    private function reportWhetherAnythingRunsThis(OutputInterface $output, int $tasks): void
    {
        $lock = new \Pramnos\Console\WorkerLock('pramnos-work.lock');

        $output->writeln('');

        /*
         * `isHeldByAnother()`, not `isHeld()`.
         *
         * `isHeld()` answers "did **this object** take the lock", which for an object this
         * command just constructed is always false — so the first version reported "no
         * `work` process" on an installation where one was running, which is the same class
         * of wrong answer this line exists to prevent. Found by the test for the held case.
         *
         * `isHeldByAnother()` reads the lock file and checks the recorded pid is alive,
         * which is the question actually being asked.
         */
        if ($lock->isHeldByAnother()) {
            $age = $lock->heartbeatAge();

            $output->writeln(
                '<info>A `work` process holds ' . $lock->getPath() . '</info>'
                . ($age === null ? '' : ', last heartbeat ' . $age . 's ago')
                . ' — this schedule is being executed.'
            );

            if ($lock->holderIsWedged()) {
                $output->writeln(
                    '<comment>Its process is alive but has stopped writing '
                    . 'heartbeats</comment> — alive and stuck, which a plain pid check '
                    . 'would call healthy.'
                );
            }

            return;
        }

        $output->writeln(
            '<comment>No `work` process is running.</comment> These ' . $tasks . ' task(s) '
            . 'are registered, not executing — unless something calls `schedule:run` from '
            . 'cron, which cannot be detected from here. Listing reads definitions; running '
            . 'needs a process.'
        );
        $output->writeln(
            '  Start one with <info>php pramnos work</info>, or let '
            . '`DaemonOrchestrator` supervise it — it adopts the scheduler by default. An '
            . 'application that has forked the orchestrator without extending the '
            . "framework's loses the whole schedule silently, which is how this line came "
            . 'to exist.'
        );
    }
}
