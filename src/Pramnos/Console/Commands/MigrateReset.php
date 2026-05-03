<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;

/**
 * Rolls back ALL migration batches (reverse of migrate, full reset).
 *
 * Usage examples:
 *   migrate:reset
 *   migrate:reset --force          (skip confirmation prompt)
 *   migrate:reset --path=/custom/migrations/dir
 */
class MigrateReset extends Command
{
    /**
     * Configure command metadata, arguments, and options.
     */
    protected function configure(): void
    {
        $this->setName('migrate:reset')
            ->setDescription('Roll back ALL migration batches (full database reset)')
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Skip the confirmation prompt'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to migrations directory (default: app/Migrations)'
            );
    }

    /**
     * Execute the migrate:reset command.
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

        // Require confirmation unless --force is passed
        if (!$input->getOption('force')) {
            $helper   = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                '<question>This will roll back ALL migrations. Continue? [y/N]</question> ',
                false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>Aborted.</comment>');
                return 0;
            }
        }

        $path       = $input->getOption('path') ?? $this->defaultMigrationPath();
        $migrations = MigrationLoader::loadFromDirectory($path, $app);

        $runner = new MigrationRunner($db);
        $result = $runner->rollbackAll($migrations);

        if (empty($result['rolledBack'])) {
            $output->writeln('<comment>Nothing to roll back.</comment>');
            return 0;
        }

        foreach ($result['rolledBack'] as $slug) {
            $output->writeln('<info>Rolled back:</info>  ' . $slug);
        }

        $output->writeln(sprintf(
            '<info>Reset complete.</info> %d migration(s) rolled back.',
            count($result['rolledBack'])
        ));

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
