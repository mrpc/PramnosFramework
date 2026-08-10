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
            ->addOption('category', null, InputOption::VALUE_OPTIONAL, 'Only clear this cache category (default: everything)', '')
            // Clearing is scoped to this installation's key prefix. Flushing the
            // whole backend also empties every co-tenant sharing it, so it has
            // to be asked for by name rather than being what "clear" means.
            ->addOption('all', null, InputOption::VALUE_NONE, 'Flush the ENTIRE cache backend, including other installations sharing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $category = (string) ($input->getOption('category') ?? '');
        $everything = (bool) $input->getOption('all');

        if ($everything && $category !== '') {
            $output->writeln('<error>--all and --category are mutually exclusive.</error>');
            return Command::FAILURE;
        }

        try {
            $ok = $everything ? $this->flushEverything() : $this->clearCache($category);
        } catch (\Throwable $e) {
            $output->writeln('<error>Cache clear failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($ok === false) {
            $output->writeln('<comment>Nothing cleared (cache disabled or adapter returned false).</comment>');
            return Command::SUCCESS;
        }

        if ($everything) {
            $output->writeln('<info>Entire cache backend flushed.</info>');
            return Command::SUCCESS;
        }

        $output->writeln($category === ''
            ? '<info>Cache cleared for this installation.</info>'
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

    /**
     * Flush the whole backend, prefix and co-tenants included.
     *
     * Separated for the same reason as clearCache(): overridable in tests
     * without touching the singleton.
     */
    protected function flushEverything(): bool
    {
        return Cache::getInstance()->flushEverything();
    }
}
