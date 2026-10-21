<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete rule run-log rows past their retention (`audience.rule_log_days`, 90; 0 keeps them), and
 * segment stays that ended past theirs (`audience.segment_history_days`, 365).
 *
 * Scheduled daily by the framework. Each person event is kept in the change log, whose retention
 * is the change log's own; the per-person summary is kept for good.
 */
class AudiencePruneEvents extends Command
{
    protected function configure(): void
    {
        $this->setName('audience:prune-events')
            ->setDescription('Delete rule run-log rows and ended segment stays older than their retention')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Days to keep (default: audience.rule_log_days, 90)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days  = $input->getOption('days') !== null ? (int) $input->getOption('days') : null;
        $rules = \Pramnos\Audience\Rules\Engine::prune($days);
        $stays = \Pramnos\Audience\Segments::pruneHistory();
        if ($rules === null && $stays === null) {
            $output->writeln('Nothing to prune here: no history kept, or kept whole.');

            return Command::SUCCESS;
        }
        $output->writeln($rules === null ? 'Rule log: kept whole, or not installed here.' : 'Rule log: ' . $rules . ' rows past its retention deleted.');
        $output->writeln($stays === null ? 'Segment history: kept whole, or not installed here.' : 'Segment history: ' . $stays . ' stays that ended past its retention deleted.');

        return Command::SUCCESS;
    }
}
