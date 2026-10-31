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
 * llm:routines — ask the scheduled questions that are due, each as its owner, then learn from the
 * conversations that went quiet with something of the person's not yet learned from.
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
        if (!\Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('pramnos.ai_routines')) {
            $output->writeln('No scheduled questions here.');

            return Command::SUCCESS;
        }

        $routines = $this->routines();
        $ran      = $routines->runDue(time());
        $output->writeln($ran === 0 ? 'Nothing was due.' : 'Asked ' . $ran . ' scheduled question' . ($ran === 1 ? '' : 's') . '.');

        // Reminders before appointments in a person's calendar, as the manners allow.
        $reminded = \Pramnos\Llm\Chat\Calendar::remind(time());
        if ($reminded > 0) {
            $output->writeln('Reminded of ' . $reminded . ' appointment' . ($reminded === 1 ? '' : 's') . '.');
        }

        $learned = $routines->conversations()->learnQuiet(time());
        if ($learned > 0) {
            $output->writeln('Learned from ' . $learned . ' quiet conversation' . ($learned === 1 ? '' : 's') . '.');
        }

        return Command::SUCCESS;
    }

    /** The routines, with the configured assistant. A seam for tests. */
    protected function routines(): Routines
    {
        return new Routines(new Conversations(Chat::assistant()));
    }
}
