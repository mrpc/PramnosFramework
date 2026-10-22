<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Event\Transport\StreamConsumer;
use Pramnos\Event\Transport\StreamTransport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Read the event stream for its consumer groups — every queued listener set to `stream`, or the
 * ones named — until stopped or `--max-time` is up: a daemon, as `queue:work` is. `--once` makes
 * one pass and stops, for a cron or a test.
 *
 * Each group is held by one consumer at a time; a second one started for the same groups simply
 * waits its turn, so two daemons are a standby, not a double run.
 */
class EventsConsume extends Command
{
    /** Whether a signal asked it to stop. */
    private bool $stop = false;

    protected function configure(): void
    {
        $this->setName('events:consume')
            ->setDescription('Read the event stream for its consumer groups')
            ->addOption('group', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A group (a listener class) to read; all by default')
            ->addOption('once', null, InputOption::VALUE_NONE, 'One pass, then stop')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Entries per group per pass', '100')
            ->addOption('max-time', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds', '3600')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to wait when there was nothing to do', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!(new StreamTransport())->available()) {
            $output->writeln('No event stream here: run migrate (create_event_log_tables).');

            return Command::SUCCESS;
        }
        $groups = (array) $input->getOption('group') ?: StreamConsumer::groups();
        if ($groups === []) {
            $output->writeln('No queued listener goes by the stream: nothing to read.');

            return Command::SUCCESS;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stop = true);
            pcntl_signal(SIGINT, fn () => $this->stop = true);
        }
        // On Redis a pass waits a second for something new rather than sleeping between reads.
        $consumer = new StreamConsumer('', $input->getOption('once') ? 0 : 1000);
        $until    = time() + max(1, (int) $input->getOption('max-time'));
        do {
            $busy = false;
            foreach ($groups as $group) {
                $tally = $consumer->consume($group, max(1, (int) $input->getOption('limit')));
                $busy  = $busy || $tally['ran'] + $tally['failed'] + $tally['retried'] + $tally['dead'] > 0;
                if ($tally['ran'] + $tally['failed'] + $tally['retried'] + $tally['dead'] > 0 || $input->getOption('once')) {
                    $output->writeln(sprintf('%s: %s, ran %d, failed %d, retried %d, dead %d', $group, $tally['held'] ? 'held by another consumer' : 'read',
                        $tally['ran'], $tally['failed'], $tally['retried'], $tally['dead']));
                }
            }
            if ($input->getOption('once')) {
                break;
            }
            if (!$busy && !$this->stop) {
                sleep(max(1, (int) $input->getOption('sleep')));
            }
        } while (!$this->stop && time() < $until);
        foreach ($groups as $group) {
            $consumer->release($group);
        }

        return Command::SUCCESS;
    }
}
