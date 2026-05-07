<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;

/**
 * Runs pending database migrations.
 *
 * Usage examples:
 *   migrate
 *   migrate --scope=framework
 *   migrate --feature=auth
 *   migrate create_users_table
 *   migrate --force
 *   migrate --cutoff=2022_01_01_000000
 *   migrate --path=/custom/migrations/dir
 */
class Migrate extends Command
{
    /**
     * Configure command metadata, arguments, and options.
     */
    protected function configure(): void
    {
        $this->setName('migrate')
            ->setDescription('Run pending database migrations')
            ->addArgument(
                'migration',
                InputArgument::OPTIONAL,
                'Run a single migration by slug or class name'
            )
            ->addOption(
                'scope',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter migrations by scope (app / framework)'
            )
            ->addOption(
                'feature',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter migrations by feature key (e.g. auth, queue)'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Include autorun=false migrations'
            )
            ->addOption(
                'cutoff',
                null,
                InputOption::VALUE_REQUIRED,
                'Skip migrations whose timestamp is at or before this value (YYYY_MM_DD_HHmmss)'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to migrations directory (default: app/Migrations)'
            );
    }

    /**
     * Execute the migrate command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $consoleApp = $this->getApplication();
        if (!($consoleApp instanceof \Pramnos\Console\Application)) {
            $output->writeln('<error>This command must run within the Pramnos console application.</error>');
            return 1;
        }

        $app = $consoleApp->internalApplication;
        $db  = $app->database ?? null;

        if ($db === null) {
            $output->writeln('<error>No database connection available.</error>');
            return 1;
        }

        $explicitPath = $input->getOption('path');
        $dirs         = $explicitPath
            ? [$explicitPath]
            : $this->resolveMigrationDirectories();
        $migrations   = MigrationLoader::loadFromDirectories($dirs, $app);

        if (empty($migrations)) {
            $output->writeln('<comment>No migrations found in: ' . $path . '</comment>');
            return 0;
        }

        // Scope filter
        if ($scope = $input->getOption('scope')) {
            $migrations = array_values(array_filter(
                $migrations,
                fn(Migration $m) => $m->scope === $scope
            ));
        }

        // Feature filter
        if ($feature = $input->getOption('feature')) {
            $migrations = array_values(array_filter(
                $migrations,
                fn(Migration $m) => $m->feature === $feature
            ));
        }

        // Single-migration filter by slug or class name
        if ($name = $input->getArgument('migration')) {
            $migrations = array_values(array_filter(
                $migrations,
                fn(Migration $m) => $m->getSlug() === $name || (new \ReflectionClass($m))->getShortName() === $name
            ));
            if (empty($migrations)) {
                $output->writeln('<error>Migration not found: ' . $name . '</error>');
                return 1;
            }
        }

        $options = [];
        if ($input->getOption('force')) {
            $options['force'] = true;
        }
        if ($cutoff = $input->getOption('cutoff')) {
            $options['cutoff'] = $cutoff;
        }

        $runner = new MigrationRunner($db);
        $result = $runner->run($migrations, $options);

        if (empty($result['ran']) && empty($result['failed'])) {
            $output->writeln('<info>Nothing to migrate.</info>');
            return 0;
        }

        foreach ($result['ran'] as $slug) {
            $output->writeln('<info>Migrated:</info>  ' . $slug);
        }
        foreach ($result['failed'] as $slug) {
            $output->writeln('<error>Failed:  </error>  ' . $slug);
        }

        return empty($result['failed']) ? 0 : 1;
    }

    /**
     * Returns all directories that should be scanned for migrations.
     *
     * Includes the app's own Migrations directory plus every feature
     * subdirectory under the framework's database/migrations/framework/ tree.
     * This means --scope=framework automatically finds built-in migrations
     * without requiring a --path override.
     *
     * @return string[]
     */
    private function resolveMigrationDirectories(): array
    {
        $root = defined('ROOT') ? ROOT : getcwd();
        $dirs = [$root . '/app/Migrations'];

        $frameworkBase = $this->findFrameworkMigrationsBase();
        if ($frameworkBase !== null && is_dir($frameworkBase)) {
            foreach (glob($frameworkBase . '/*', GLOB_ONLYDIR) ?: [] as $featureDir) {
                $dirs[] = $featureDir;
            }
        }

        return $dirs;
    }

    /**
     * Locates the framework's database/migrations/framework directory.
     *
     * Works whether the framework is used as the project root (development)
     * or installed as a Composer package inside vendor/.
     */
    private function findFrameworkMigrationsBase(): ?string
    {
        // Path relative to this file: src/Pramnos/Console/Commands → ../../../../database/migrations/framework
        $fromSource = dirname(__DIR__, 4) . '/database/migrations/framework';
        if (is_dir($fromSource)) {
            return $fromSource;
        }

        // Installed as Composer package
        $root = defined('ROOT') ? ROOT : getcwd();
        $fromVendor = $root . '/vendor/mrpc/pramnosframework/database/migrations/framework';
        if (is_dir($fromVendor)) {
            return $fromVendor;
        }

        return null;
    }
}
