<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Database\WriteSpool;

/**
 * Writes the rows that were buffered out of the request path.
 *
 * {@see WriteSpool} takes a row that is worth keeping and worth nothing
 * individually — an audit entry, an access log, a counter — and puts it
 * somewhere cheap so that the visitor is not kept waiting for a database write
 * nobody will read until tomorrow. This is the other half: it writes what has
 * accumulated, batched.
 *
 * Registered in the framework schedule to run every minute, so an application
 * that has installed the cron line or runs `pramnos work` needs to do nothing
 * at all. Running it by hand is for looking, or for draining before a migration
 * that will change the table it writes to.
 *
 * ```
 * php pramnos spool:drain            # write everything buffered
 * php pramnos spool:drain --status   # how much is waiting, and where
 * ```
 *
 * Exit codes: 0 = everything buffered was written (including "nothing was"),
 * 1 = at least one row could not be written.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class SpoolDrain extends Command
{
    /** @var string The command name as typed */
    protected static $defaultName = 'spool:drain';

    protected function configure(): void
    {
        $this
            ->setName('spool:drain')
            ->setDescription('Write rows buffered out of the request path')
            ->addOption(
                'status',
                's',
                InputOption::VALUE_NONE,
                'Report what is buffered, and write nothing'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('status')) {
            $pending = WriteSpool::pending();

            $output->writeln('Driver:  <info>' . WriteSpool::driver() . '</info>');
            $output->writeln(
                'Waiting: <info>' . number_format($pending) . '</info> row(s)'
            );

            return Command::SUCCESS;
        }

        $started = microtime(true);
        $stats   = WriteSpool::drain(
            static fn(string $message) => $output->writeln($message)
        );

        if ($stats['written'] === 0 && $stats['failed'] === 0) {
            // Silent on the common case: this runs every minute, and a line
            // per minute saying "nothing" is a log nobody reads.
            if ($output->isVerbose()) {
                $output->writeln('<info>Nothing was buffered.</info>');
            }

            return Command::SUCCESS;
        }

        foreach ($stats['tables'] as $table => $count) {
            $output->writeln(
                $table . ': <info>' . number_format($count) . '</info> written'
            );
        }

        $output->writeln('');
        $output->writeln(
            number_format($stats['written']) . ' row(s) written in '
            . round((microtime(true) - $started) * 1000) . 'ms.'
        );

        if ($stats['failed'] > 0) {
            $output->writeln(
                '<error>' . number_format($stats['failed']) . ' row(s) could not be '
                . 'written and were kept for the next run.</error>'
            );

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
