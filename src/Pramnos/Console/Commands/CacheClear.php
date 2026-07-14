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
            $ok = $this->clearCache($category);
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

    /**
     * Delegate to the configured cache adapter.
     *
     * Isolated in its own protected method so it can be overridden in tests
     * without touching the static Cache singleton. Production behaviour is
     * identical to calling Cache::getInstance()->clear($category) inline.
     *
     * @param  string $category Cache category to clear ('' clears everything).
     * @return bool             Whether the adapter reported a successful clear.
     */
    protected function clearCache(string $category): bool
    {
        return Cache::getInstance()->clear($category);
    }
}
