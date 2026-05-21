<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;

/**
 * Rolls back ALL migrations and then re-runs them (migrate:reset + migrate).
 *
 * Usage examples:
 *   migrate:refresh
 *   migrate:refresh --force        (skip confirmation prompt)
 *   migrate:refresh --path=/custom/migrations/dir
 */
class MigrateRefresh extends Command
{
    /**
     * Configure command metadata, arguments, and options.
     */
    protected function configure(): void
    {
        $this->setName('migrate:refresh')
            ->setDescription('Roll back all migrations and re-run them from scratch')
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
     * Execute the migrate:refresh command.
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

        if (!$input->getOption('force')) {
            $helper   = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                '<question>This will roll back and re-run ALL migrations. Continue? [y/N]</question> ',
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

        // Phase 1 — reset
        $output->writeln('<comment>Rolling back all migrations...</comment>');
        $resetResult = $runner->rollbackAll($migrations, function (string $event, string $slug) use ($output): void {
            $output->writeln('  <comment>Rolled back:</comment>  ' . $slug);
        });

        // Phase 2 — re-run
        $output->writeln('<comment>Re-running migrations...</comment>');
        $runResult = $runner->run($migrations, [], function (string $event, string $slug, string $error) use ($output): void {
            if ($event === 'ran') {
                $output->writeln('  <info>Migrated:</info>   ' . $slug);
            } else {
                $output->writeln('  <error>Failed:  </error>   ' . $slug);
                $firstLine = strtok(trim($error), "\n");
                $output->writeln('             <comment>' . $firstLine . '</comment>');
            }
        });

        $this->printSummary($output, $db, $resetResult, $runResult);

        return empty($runResult['failed']) ? 0 : 1;
    }

    /**
     * Prints refresh summary: rolled-back count, migrated count, and any failures.
     *
     * @param array{rolledBack: string[]}              $resetResult
     * @param array{ran: string[], failed: array<string,string>} $runResult
     */
    private function printSummary(
        OutputInterface $output,
        Database        $db,
        array           $resetResult,
        array           $runResult
    ): void {
        $sep = str_repeat('─', 62);
        $output->writeln('');
        $output->writeln('<comment>' . $sep . '</comment>');

        $rolledBack  = count($resetResult['rolledBack']);
        $ranCount    = count($runResult['ran']);
        $failedCount = count($runResult['failed']);

        if ($failedCount === 0) {
            $output->writeln(sprintf(
                '<info> ✓  %d rolled back · %d migrated</info>',
                $rolledBack, $ranCount
            ));
        } else {
            $output->writeln(sprintf(
                '<info> ✓  %d rolled back · %d migrated</info>   <error> ✗  %d failed </error>',
                $rolledBack, $ranCount, $failedCount
            ));
        }

        $output->writeln('');
        $output->writeln(' <comment>Database:</comment>   ' . $this->formatDbType($db));

        if (!empty($runResult['failed'])) {
            $output->writeln('');
            $output->writeln('<comment>' . $sep . '</comment>');
            $output->writeln('<error> Failed migrations                                                </error>');
            $output->writeln('<comment>' . $sep . '</comment>');
            foreach ($runResult['failed'] as $slug => $errorMessage) {
                $output->writeln(' <error>✗</error> <options=bold>' . $slug . '</>');
                foreach (explode("\n", trim($errorMessage)) as $line) {
                    if (trim($line) !== '') {
                        $output->writeln('   <comment>' . $line . '</comment>');
                    }
                }
                $output->writeln('');
            }
        }

        $output->writeln('<comment>' . $sep . '</comment>');
    }

    /**
     * Returns a human-readable database type string, including TimescaleDB if detected.
     */
    private function formatDbType(Database $db): string
    {
        $label = match ($db->type) {
            'postgresql' => 'PostgreSQL',
            'mysql'      => 'MySQL',
            default      => ucfirst($db->type),
        };

        try {
            if ($db->schema()->getCapabilities()->hasTimescaleDB()) {
                $label .= ' · TimescaleDB';
            }
        } catch (\Throwable) {}

        return $label;
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
