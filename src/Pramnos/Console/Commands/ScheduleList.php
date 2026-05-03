<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Pramnos\Scheduling\Scheduler;

/**
 * Lists all registered scheduled tasks.
 *
 * Displays a table of each task's type, cron expression, handler/description,
 * and overlap-prevention flag.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Console
 */
class ScheduleList extends Command
{
    protected static $defaultName = 'schedule:list';

    protected function configure(): void
    {
        $this
            ->setName('schedule:list')
            ->setDescription('List all registered scheduled tasks');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tasks = Scheduler::all();

        if (empty($tasks)) {
            $output->writeln('<comment>No scheduled tasks registered.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Type', 'Expression', 'Handler / Description', 'No Overlap']);

        foreach ($tasks as $task) {
            $s       = $task->getSummary();
            $handler = $s['description'] ?: $s['handler'];
            $table->addRow([
                $s['type'],
                $s['expression'],
                $handler,
                $s['no_overlap'] ? '<info>yes</info>' : 'no',
            ]);
        }

        $table->render();
        return Command::SUCCESS;
    }
}
