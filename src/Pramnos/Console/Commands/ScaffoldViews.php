<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Application\ScaffoldingHelper;

/**
 * scaffold:views — publish bundled scaffold view templates into a project.
 *
 * Works on both new and existing projects. Skips files that already exist
 * unless --force is passed. Useful when:
 *
 *  - An existing project wants to add a view group it did not scaffold at init
 *    (e.g. the project was created without `authserver` and now needs it)
 *  - A developer wants to customise a view that previously fell back to the
 *    bundled scaffolding copy
 *
 * Examples:
 *
 *   ./pramnos scaffold:views --all
 *   ./pramnos scaffold:views --group=login,device
 *   ./pramnos scaffold:views --group=oauth2 --theme=tailwind --force
 *   ./pramnos scaffold:views --list
 *
 */
class ScaffoldViews extends Command
{
    /** Target project root. Overridable for testing. */
    public string $targetBaseDir = '';

    protected function configure(): void
    {
        $this
            ->setName('scaffold:views')
            ->setDescription('Publish bundled scaffold view templates into an existing project')
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Publish all view groups'
            )
            ->addOption(
                'group',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma-separated list of view groups to publish (e.g. login,device,oauth2)'
            )
            ->addOption(
                'theme',
                null,
                InputOption::VALUE_OPTIONAL,
                'Scaffold theme to use (plain-css, bootstrap, tailwind). Reads from app/app.php if omitted.'
            )
            ->addOption(
                'dest',
                null,
                InputOption::VALUE_OPTIONAL,
                'Destination directory relative to the project root (default: src/Views)',
                'src/Views'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite existing view files'
            )
            ->addOption(
                'list',
                null,
                InputOption::VALUE_NONE,
                'List available view groups without writing any files'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baseDir = $this->targetBaseDir ?: (defined('ROOT') ? ROOT : getcwd());

        // ── Resolve scaffold theme ────────────────────────────────────────────
        $theme = $input->getOption('theme');
        if (!$theme) {
            $appConfig = $this->loadAppConfig($baseDir);
            $theme     = ScaffoldingHelper::getScaffoldTheme($appConfig ?? []);
        }
        if (!$theme) {
            $output->writeln('<error>Cannot determine scaffold theme.</error>');
            $output->writeln(
                'Set scaffold_theme in app/app.php or pass --theme=(plain-css|bootstrap|tailwind)'
            );
            return Command::FAILURE;
        }
        if (!in_array($theme, ScaffoldingHelper::THEMES, true)) {
            $output->writeln("<error>Unknown theme '$theme'. Valid: " . implode(', ', ScaffoldingHelper::THEMES) . '</error>');
            return Command::FAILURE;
        }

        $themeDir = ScaffoldingHelper::getThemeDir($theme);
        if (!is_dir($themeDir . DIRECTORY_SEPARATOR . 'views')) {
            $output->writeln("<error>No views found for theme '$theme' in bundled scaffolding.</error>");
            return Command::FAILURE;
        }

        // ── List available groups ─────────────────────────────────────────────
        $allGroups = ScaffoldingHelper::listViewGroups($theme);
        if ($input->getOption('list')) {
            $output->writeln("<info>Available view groups for theme '$theme':</info>");
            foreach ($allGroups as $group => $files) {
                $output->writeln("  <comment>$group</comment>");
                foreach ($files as $f) {
                    $output->writeln("    $f");
                }
            }
            return Command::SUCCESS;
        }

        // ── Determine groups to publish ───────────────────────────────────────
        if ($input->getOption('all')) {
            $selected = array_keys($allGroups);
        } elseif ($groupOpt = $input->getOption('group')) {
            $selected = array_map('trim', explode(',', $groupOpt));
            $unknown  = array_diff($selected, array_keys($allGroups));
            if (!empty($unknown)) {
                $output->writeln('<error>Unknown group(s): ' . implode(', ', $unknown) . '</error>');
                $output->writeln('Run with --list to see available groups.');
                return Command::FAILURE;
            }
        } else {
            $output->writeln('<error>Specify --all or --group=name1,name2 (or --list to see options).</error>');
            return Command::FAILURE;
        }

        $force   = (bool) $input->getOption('force');
        $destRel = rtrim((string) $input->getOption('dest'), '/\\');
        $destDir = $baseDir . DIRECTORY_SEPARATOR . $destRel;

        $srcBase  = $themeDir . DIRECTORY_SEPARATOR . 'views';
        $copied   = 0;
        $skipped  = 0;
        $created  = 0;

        foreach ($selected as $group) {
            $files = $allGroups[$group] ?? [];
            foreach ($files as $relPath) {
                $src  = $srcBase . DIRECTORY_SEPARATOR . $relPath;
                $dest = $destDir . DIRECTORY_SEPARATOR . $relPath;

                if (!file_exists($src)) continue;

                // Ensure directory exists
                $dir = dirname($dest);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                    $created++;
                }

                if (file_exists($dest) && !$force) {
                    $output->writeln("  <comment>skip</comment>  $destRel/$relPath (use --force to overwrite)");
                    $skipped++;
                    continue;
                }

                $verb = file_exists($dest) ? '<info>overwrite</info>' : '<info>create</info>';
                copy($src, $dest);
                $output->writeln("  $verb  $destRel/$relPath");
                $copied++;
            }
        }

        $output->writeln('');
        $output->writeln(
            "<info>Done.</info> $copied file(s) written, $skipped skipped."
        );

        return Command::SUCCESS;
    }

    /**
     * Try to load the app config from `app/app.php` within the project root.
     * Returns null if the file does not exist or cannot be parsed.
     *
     * @return array|null
     */
    private function loadAppConfig(string $baseDir): ?array
    {
        $file = $baseDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'app.php';
        if (!file_exists($file)) {
            return null;
        }
        try {
            $config = require $file;
            return is_array($config) ? $config : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
