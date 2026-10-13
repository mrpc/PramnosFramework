<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Llm\Chat\Chat;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Routines;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * llm:routines — ask the scheduled questions that are due, each as its owner.
 *
 * Scheduled every minute by the framework. An installation without the `llm` feature has no
 * table, and the command says so and succeeds.
 */
class LlmRoutines extends Command
{
    protected function configure(): void
    {
        $this->setName('llm:routines')
            ->setDescription('Ask the scheduled questions that are due, each as its owner');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!\Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('#PREFIX#ai_routines')) {
            $output->writeln('No scheduled questions here.');

            return Command::SUCCESS;
        }

        $ran = $this->routines()->runDue(time());
        $output->writeln($ran === 0 ? 'Nothing was due.' : 'Asked ' . $ran . ' scheduled question' . ($ran === 1 ? '' : 's') . '.');

        return Command::SUCCESS;
    }

    /** The routines, with the configured assistant. A seam for tests. */
    protected function routines(): Routines
    {
        return new Routines(new Conversations(Chat::assistant()));
    }
}
