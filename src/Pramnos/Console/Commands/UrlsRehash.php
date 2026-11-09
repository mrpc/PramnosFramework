<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\User\UrlRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `urls:rehash` — fill `urls.sha`, merge the addresses kept twice, and make its unique index
 * ({@see UrlRegistry::rehash()}). Resumable: a second run fills what the first did not. Run
 * `urls:prune` first on a large table.
 */
class UrlsRehash extends Command
{
    /** Name, description and options. */
    protected function configure(): void
    {
        $this->setName('urls:rehash')
            ->setDescription('Fill urls.sha, merge the addresses kept twice, and make its unique index')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per batch', '5000');
    }

    /** Rehash, saying how far it got. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $done = UrlRegistry::rehash(max(100, (int) $input->getOption('batch')),
                static fn (string $what, int $count) => $output->writeln(sprintf('… %s %d', $what, $count), OutputInterface::VERBOSITY_VERBOSE));
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('%d filled, %d merged, unique index %s.', $done['filled'], $done['merged'], $done['indexed'] ? 'made' : 'there already'));

        return Command::SUCCESS;
    }
}
