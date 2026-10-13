<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Llm\CallLog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * llm:prune — drop language-model call log rows older than the window worth keeping.
 *
 * ```
 * ./yourapp llm:prune             # keep 90 days
 * ./yourapp llm:prune --days=30
 * ```
 *
 * Scheduled daily by the framework. An installation without the `llm` feature has no table, and
 * the command says so and succeeds: a scheduled job failing every night for a feature nobody
 * enabled is noise that hides the failures that matter.
 */
class LlmPrune extends Command
{
    protected function configure(): void
    {
        $this->setName('llm:prune')
            ->setDescription('Drop language-model call log rows older than the window kept')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Days to keep', (string) CallLog::KEEP_DAYS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $database = \Pramnos\Framework\Factory::getDatabase();

        if (!$database->schema()->hasTable(CallLog::TABLE)) {
            $output->writeln('No language-model call log here; nothing to prune.');

            return Command::SUCCESS;
        }

        $days = (new CallLog())->prune(max(1, (int) $input->getOption('days')));
        $output->writeln('Kept the last ' . $days . ' days of language-model calls.');

        return Command::SUCCESS;
    }
}
