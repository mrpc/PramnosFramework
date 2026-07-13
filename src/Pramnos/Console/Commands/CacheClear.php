<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Cache\Cache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * cache:clear — flush the application cache.
 *
 * Clears the whole cache store, or a single category with `--category=`.
 * Delegates to the configured cache adapter via Cache::clear().
 *
 * Examples:
 *   ./pramnos cache:clear
 *   ./pramnos cache:clear --category=views
 */
class CacheClear extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('cache:clear')
            ->setDescription('Flush the application cache (all categories, or one with --category)')
            ->addOption('category', null, InputOption::VALUE_OPTIONAL, 'Only clear this cache category (default: everything)', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $category = (string) ($input->getOption('category') ?? '');

        try {
            $ok = Cache::getInstance()->clear($category);
        } catch (\Throwable $e) {
            $output->writeln('<error>Cache clear failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($ok === false) {
            $output->writeln('<comment>Nothing cleared (cache disabled or adapter returned false).</comment>');
            return Command::SUCCESS;
        }

        $output->writeln($category === ''
            ? '<info>Cache cleared.</info>'
            : "<info>Cache category '{$category}' cleared.</info>");

        return Command::SUCCESS;
    }
}
