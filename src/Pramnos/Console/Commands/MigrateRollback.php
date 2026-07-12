<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;

/**
 * Rolls back the most recent migration batch (or a specific batch).
 *
 * Usage examples:
 *   migrate:rollback
 *   migrate:rollback --batch=3
 *   migrate:rollback --path=/custom/migrations/dir
 */
class MigrateRollback extends Command
{
    /**
     * Configure command metadata, arguments, and options.
     */
    protected function configure(): void
    {
        $this->setName('migrate:rollback')
            ->setDescription('Roll back the last (or a specific) migration batch')
            ->addOption(
                'batch',
                null,
                InputOption::VALUE_REQUIRED,
                'Batch number to roll back (default: last batch)'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to migrations directory (default: app/Migrations)'
            );
    }

    /**
     * Execute the migrate:rollback command.
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

        $path       = $input->getOption('path') ?? $this->defaultMigrationPath();
        $migrations = MigrationLoader::loadFromDirectory($path, $app);

        $options = [];
        if ($batch = $input->getOption('batch')) {
            $options['batch'] = (int) $batch;
        }

        $runner = new MigrationRunner($db);
        $result = $runner->rollback($migrations, $options);

        if (empty($result['rolledBack'])) {
            $output->writeln('<comment>Nothing to roll back.</comment>');
            return 0;
        }

        foreach ($result['rolledBack'] as $slug) {
            $output->writeln('<info>Rolled back:</info>  ' . $slug);
        }

        return 0;
    }

    /**
     * Returns the default migrations directory path.
     */
    private function defaultMigrationPath(): string
    {
        $root = defined('ROOT') ? ROOT : getcwd();
        return $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Migrations';
    }
}
