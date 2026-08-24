<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Cache\Page\PageCache;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * pagecache:purge — invalidate cached pages.
 *
 * Separate from `cache:clear` rather than a flag on it, because the page cache
 * owns its own store and its own indexes: clearing the application cache must
 * not silently take the site's pages with it, and purging a page must not empty
 * the query cache.
 *
 * Examples:
 *   ./pramnos pagecache:purge /stations/7
 *   ./pramnos pagecache:purge --tag=station:7 --tag=homepage
 *   ./pramnos pagecache:purge --all
 */
class PageCachePurge extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('pagecache:purge')
            ->setDescription('Purge cached pages by URL, by tag, or all of them')
            ->addArgument('url', InputArgument::OPTIONAL, 'URL or path to purge, e.g. /stations/7', '')
            ->addOption('tag', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Purge every page carrying this tag (repeatable)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Purge every cached page');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url  = (string) $input->getArgument('url');
        $tags = (array) $input->getOption('tag');
        $all  = (bool) $input->getOption('all');

        if (!$all && $url === '' && $tags === []) {
            $output->writeln('<error>Give a URL, at least one --tag, or --all.</error>');
            return Command::FAILURE;
        }

        try {
            $cache = $this->pageCache();

            if ($all) {
                $cache->flush();
                $output->writeln('<info>Every cached page purged.</info>');
                return Command::SUCCESS;
            }

            if ($url !== '') {
                $cache->purgeUrl($this->absolute($url));
                $output->writeln("<info>Purged: {$url}</info>");
            }

            if ($tags !== []) {
                $removed = $cache->purgeTag(...array_map('strval', $tags));
                $output->writeln(
                    "<info>Purged {$removed} page(s) for tag(s): "
                    . implode(', ', $tags) . '</info>'
                );
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Purge failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * A bare path becomes a URL against the configured site address.
     *
     * Entries are keyed by absolute URL because one installation can answer on
     * more than one host. Purging `/stations/7` without saying which host would
     * otherwise have to guess, and guessing wrong purges nothing while
     * reporting success.
     */
    protected function absolute(string $url): string
    {
        if (str_contains($url, '://')) {
            return $url;
        }

        $base = rtrim((string) $this->siteUrl(), '/');

        return $base . '/' . ltrim($url, '/');
    }

    /** Overridable so the tests do not need application settings. */
    protected function siteUrl(): string
    {
        if (class_exists('\Pramnos\Application\Settings')) {
            $url = \Pramnos\Application\Settings::getSetting('siteurl');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return 'http://localhost';   // @codeCoverageIgnore
    }

    /**
     * Isolated for the same reason `CacheClear` isolates its clear: so a test
     * can drive the command without a configured backend.
     */
    protected function pageCache(): PageCache
    {
        $config = class_exists('\Pramnos\Application\Settings')
            ? \Pramnos\Application\Settings::getSetting('pagecache')
            : [];

        return new PageCache(is_array($config) ? $config : []);
    }
}
