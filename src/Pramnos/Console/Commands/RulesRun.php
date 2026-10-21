<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Audience\Rules\Engine;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Start the scheduled rules due this minute: each takes everybody who matches it, in the queue.
 *
 * Scheduled every minute by the framework; a no-op where rules are not installed, or none has a
 * schedule.
 */
class RulesRun extends Command
{
    protected function configure(): void
    {
        $this->setName('rules:run')->setDescription('Start the scheduled rules due this minute');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!\Pramnos\Audience\Installed::has('rules')) {
            $output->writeln('No rules here: the tables are not installed.');

            return Command::SUCCESS;
        }
        $output->writeln(Engine::due() . ' scheduled rule(s) due.');

        return Command::SUCCESS;
    }
}
