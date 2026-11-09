<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Todos\Todos;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `todos:sync` — keep the to-do list in step with the application's sources ({@see Todos::sync()}),
 * for every organisation. Every `todos.sync_every` minutes; nothing to do where no source is declared.
 */
class TodosSync extends Command
{
    protected function configure(): void
    {
        $this->setName('todos:sync')
            ->setDescription('Keep the to-do list in step with the application\'s sources');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // An application that has not adopted the to-dos has no table: nothing to do, not a failure.
        if (!Todos::installed()) {
            return Command::SUCCESS;
        }
        $done = Todos::sync();
        $output->writeln(sprintf('%d opened, %d changed, %d closed.', $done['opened'], $done['changed'], $done['closed']), OutputInterface::VERBOSITY_VERBOSE);
        foreach ($done['failed'] as $kind) {
            $output->writeln('<error>The source ' . $kind . ' failed: see the todos log.</error>');
        }

        return $done['failed'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
