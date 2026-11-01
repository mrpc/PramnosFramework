<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Application\Settings;
use Pramnos\Html\Hreflang;
use Pramnos\Html\Sitemap;
use Pramnos\Html\SitemapWriter;
use Pramnos\Routing\Router;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate sitemap.xml (sharded, with an index, past 50,000 URLs) from three sources that
 * need no new declaration and one that does:
 *
 * - Every localized route group in a route file ({@see Router::collectedLocaleGroups()}).
 * - Every hand-declared {@see Hreflang::group()} from app/hreflang.php.
 * - Every provider registered with {@see Sitemap::provider()} in app/sitemap.php — the only
 *   one that needs anything new, because a database-backed page's URLs are the
 *   application's own knowledge to enumerate (docs/Hreflang-And-Sitemap-Design.md §9.2).
 *
 * ## Usage
 *
 * ```
 * php pramnos sitemap:generate                                 # providers + Hreflang groups only
 * php pramnos sitemap:generate --routes=src/Api/routes.php      # + every localized route group in it
 * php pramnos sitemap:generate --output=www/sitemap.xml --base-url=https://example.com
 * ```
 *
 * See {@see SitemapWriter} for sharding, the index, and the §6 reciprocity check.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class SitemapGenerate extends Command
{
    /**
     * Base directory the relative paths resolve against. Defaults to ROOT (or the cwd) at
     * runtime; public so tests can point it at a temporary directory — mirroring ApiDocs.
     */
    public string $targetBaseDir = '';

    protected static $defaultName = 'sitemap:generate';

    private const WEB_ROOT_CANDIDATES = ['www', 'public', 'html', 'web'];

    protected function configure(): void
    {
        $this
            ->setName('sitemap:generate')
            ->setDescription('Generate sitemap.xml from registered providers, localized route groups and Hreflang groups')
            ->addOption('routes', null, InputOption::VALUE_REQUIRED, 'A route file to read for localized route groups, e.g. src/Api/routes.php')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output file for the sitemap. Default: <document root>/sitemap.xml', null)
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Absolute base URL entries are resolved against. Default: the site_url setting, or the sURL constant')
            ->addOption('allow-empty', null, InputOption::VALUE_NONE, 'Write the sitemap even when it describes no URLs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $base = $this->baseDir();

        $baseUrl = $this->resolveBaseUrl($input);
        if ($baseUrl === '') {
            $output->writeln('<error>No base URL. Pass --base-url, or set the site_url setting.</error>');
            return Command::FAILURE;
        }

        Hreflang::loadDefinitions();
        Sitemap::loadDefinitions();

        $routerGroups = [];
        $routesRead   = '';
        $routesOption = $input->getOption('routes');

        if ($routesOption !== null) {
            $routesPath = $this->resolve($base, (string) $routesOption);

            if (!is_file($routesPath)) {
                $output->writeln("<error>Route file not found: {$routesPath}</error>");
                return Command::FAILURE;
            }

            try {
                $routerGroups = $this->collectLocaleGroups($routesPath);
            } catch (\Throwable $ex) {
                $output->writeln("<error>Could not read {$routesPath}: " . $ex->getMessage() . '</error>');
                return Command::FAILURE;
            }

            $routesRead = $routesPath;
        }

        $hreflangGroups = Hreflang::groups();
        $providers      = Sitemap::providers();

        $outputOption = $input->getOption('output');
        $outputPath   = $this->resolve(
            $base,
            $outputOption !== null ? (string) $outputOption : $this->defaultOutputFile($base)
        );
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $writer = new SitemapWriter($outputPath, baseUrl: $baseUrl);

        try {
            $result = $writer->write(
                $this->buildEntries($routerGroups, $hreflangGroups, $providers, $baseUrl),
                (bool) $input->getOption('allow-empty')
            );
        } catch (\RuntimeException $ex) {
            $output->writeln('<error>' . $ex->getMessage() . '</error>');
            if (str_contains($ex->getMessage(), 'empty')) {
                $output->writeln(
                    '<comment>An empty sitemap overwriting a good one is worse than no run at '
                    . 'all. If routes are registered on the router, pass '
                    . '--routes=src/Api/routes.php. If this site really has no pages yet, pass '
                    . '--allow-empty.</comment>'
                );
            }
            return Command::FAILURE;
        }

        $sources = [];
        if ($routesRead !== '') {
            $sources[] = count($routerGroups) . ' locale group(s) from ' . $routesRead;
        }
        if ($hreflangGroups !== []) {
            $sources[] = count($hreflangGroups) . ' Hreflang group(s)';
        }
        if ($providers !== []) {
            $sources[] = 'provider(s): ' . implode(', ', array_keys($providers));
        }

        $output->writeln(sprintf(
            '<info>Wrote %d url(s) across %d shard(s) to %s%s</info>',
            $result['count'],
            $result['shards'],
            $outputPath,
            $sources === [] ? '' : ' (from ' . implode('; ', $sources) . ')'
        ));

        foreach ($result['warnings'] as $warning) {
            $output->writeln('<comment>Warning: ' . $warning . '</comment>');
        }

        return Command::SUCCESS;
    }

    /**
     * @param  list<array<string, \Pramnos\Routing\Route>> $routerGroups    Route objects.
     * @param  list<array<string, string>>                  $hreflangGroups Plain path strings.
     * @param  array<string, callable(): \Generator>         $providers
     * @return \Generator<array<string, mixed>>
     */
    private function buildEntries(array $routerGroups, array $hreflangGroups, array $providers, string $baseUrl): \Generator
    {
        foreach ($routerGroups as $group) {
            yield from $this->groupToEntries($group, $baseUrl, isRoute: true);
        }

        foreach ($hreflangGroups as $group) {
            yield from $this->groupToEntries($group, $baseUrl, isRoute: false);
        }

        foreach ($providers as $provider) {
            yield from $provider();
        }
    }

    /**
     * One entry per locale in a group, each carrying the whole group as `alternates` — the
     * group is already the reciprocal set, the same way it already is in the `<head>`.
     *
     * @param  array<string, mixed> $group  locale => Route, or locale => path string.
     */
    private function groupToEntries(array $group, string $baseUrl, bool $isRoute): \Generator
    {
        $alternates = [];
        foreach ($group as $locale => $pathOrRoute) {
            $path = $isRoute ? $pathOrRoute->uri : $pathOrRoute;
            $alternates[$locale] = $baseUrl . '/' . ltrim((string) $path, '/');
        }

        foreach ($alternates as $loc) {
            yield ['loc' => $loc, 'alternates' => $alternates];
        }
    }

    /**
     * Read a route file without serving a request, keeping only the locale groups it
     * registered — {@see Router::collectedLocaleGroups()} must run before
     * {@see Router::stopCollecting()}, which clears them.
     *
     * Same shape and the same reason as {@see ApiDocs::collectRoutes()}: a route file
     * registers and then dispatches, so including it must stop the dispatch rather than the
     * include.
     *
     * @return list<array<string, \Pramnos\Routing\Route>>
     */
    private function collectLocaleGroups(string $routesPath): array
    {
        Router::beginCollecting();

        try {
            (function () use ($routesPath): void {
                include $routesPath;
            })();
            $groups = Router::collectedLocaleGroups();
        } finally {
            Router::stopCollecting();
        }

        return $groups;
    }

    private function resolveBaseUrl(InputInterface $input): string
    {
        $option = $input->getOption('base-url');
        $url = $option !== null
            ? (string) $option
            : (string) (Settings::getSetting('site_url') ?: (defined('sURL') ? sURL : ''));

        return rtrim(trim($url), '/');
    }

    private function baseDir(): string
    {
        if ($this->targetBaseDir !== '') {
            return rtrim($this->targetBaseDir, '/');
        }
        if (defined('ROOT')) {
            return rtrim((string) ROOT, '/');
        }
        return rtrim((string) getcwd(), '/');
    }

    private function resolve(string $base, string $path): string
    {
        if ($path === '') {
            return $base;
        }
        if ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return $path; // absolute
        }
        return $base . '/' . ltrim($path, '/');
    }

    /**
     * `www/` is only the first candidate — the same reasoning as ApiDocs::defaultOutputFile():
     * a project scaffolded with a different web root must not get a stray `www/` beside it.
     */
    private function defaultOutputFile(string $base): string
    {
        foreach (self::WEB_ROOT_CANDIDATES as $candidate) {
            if (is_file($base . '/' . $candidate . '/index.php')) {
                return $candidate . '/sitemap.xml';
            }
        }

        return self::WEB_ROOT_CANDIDATES[0] . '/sitemap.xml';
    }
}
