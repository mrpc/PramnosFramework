<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Conversations\Scheduled;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `conversations:send-scheduled` — send the conversations' scheduled messages that are due, each
 * as its writer ({@see Scheduled::due()}), and run their automations that are due
 * ({@see \Pramnos\Conversations\Automations::due()}). Scheduled every minute by the framework; a
 * no-op without the tables.
 *
 * ```bash
 * php bin/pramnos conversations:send-scheduled
 * ```
 */
class ConversationsSendScheduled extends Command
{
    /** Name and description. */
    protected function configure(): void
    {
        $this->setName('conversations:send-scheduled')
            ->setDescription('Send the scheduled conversation messages that are due');
    }

    /** Send what is due, and say how many. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!\Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('chat.conversation_scheduled')) {
            $output->writeln('There is no conversation_scheduled table.');

            return Command::SUCCESS;
        }
        $output->writeln(sprintf('%d scheduled message(s) sent.', Scheduled::due()));
        // The conversations' own automations: a message again, a question to the analyst.
        $output->writeln(sprintf('%d automation(s) ran.', \Pramnos\Conversations\Automations::due()));

        return Command::SUCCESS;
    }
}
