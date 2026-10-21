<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Audience\PersonEvents;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete person-event log rows past the retention; the per-person summary stays.
 *
 * Scheduled daily by the framework. The retention is `audience.event_log_days` in app.php (365
 * by default; 0 keeps the log whole), so "N times in the last M days" reaches back that far.
 */
class AudiencePruneEvents extends Command
{
    protected function configure(): void
    {
        $this->setName('audience:prune-events')
            ->setDescription('Delete person-event log rows older than the retention')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Days to keep (default: audience.event_log_days, 365)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!\Pramnos\Database\Database::getInstance()->schema()->hasTable('person_event_log')) {
            $output->writeln('No person-event log here; nothing to prune.');

            return Command::SUCCESS;
        }
        $days    = $input->getOption('days') !== null ? (int) $input->getOption('days') : PersonEvents::logDays();
        $deleted = PersonEvents::prune($days);
        $output->writeln($deleted === null
            ? 'The person-event log is kept whole.'
            : 'Kept the last ' . $days . ' days of the person-event log; ' . $deleted . ' older rows deleted.');

        return Command::SUCCESS;
    }
}
