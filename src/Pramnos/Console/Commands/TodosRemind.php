<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Todos\Todos;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `todos:remind` — every minute: the to-dos' reminders that are due ({@see Todos::remind()}), and
 * from 08:05 in each organisation's zone the "due today" notices ({@see Todos::dueNotices()}).
 */
class TodosRemind extends Command
{
    /** Name and description. */
    protected function configure(): void
    {
        $this->setName('todos:remind')
            ->setDescription('Send the to-dos\' reminders, and the "due today" notices');
    }

    /** Send them. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reminded = Todos::remind();
        $due      = Todos::dueNotices();
        $output->writeln(sprintf('%d reminded, %d told of their day.', $reminded, $due), OutputInterface::VERBOSITY_VERBOSE);

        return Command::SUCCESS;
    }
}
