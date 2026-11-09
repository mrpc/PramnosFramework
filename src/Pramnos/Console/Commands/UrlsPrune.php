<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\User\UrlRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `urls:prune` — delete the addresses in `urls` that nothing refers to ({@see UrlRegistry::prune()}),
 * a range of ids at a time. Not scheduled: the first run on a large table is long, so the
 * application runs it when it chooses, then as often as it likes.
 */
class UrlsPrune extends Command
{
    /** Name, description and options. */
    protected function configure(): void
    {
        $this->setName('urls:prune')
            ->setDescription('Delete the addresses in urls that nothing refers to')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Ids per range', '10000')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count them, delete nothing');
    }

    /** Prune, saying how far it got. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dry  = (bool) $input->getOption('dry-run');
        try {
            $gone = UrlRegistry::prune(max(100, (int) $input->getOption('batch')), null,
                static fn (int $at, int $gone) => $output->writeln(sprintf('… to id %d: %d', $at, $gone), OutputInterface::VERBOSITY_VERBOSE), $dry);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf($dry ? '%d would go.' : '%d went.', $gone));

        return Command::SUCCESS;
    }
}
