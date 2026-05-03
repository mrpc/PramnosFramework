<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;

/**
 * Displays the current migration status as a formatted table.
 *
 * Each row shows a migration's slug, scope, feature, status (Ran / Failed /
 * Pending), batch number, execution time, and ran_at timestamp.
 *
 * Usage examples:
 *   migrate:status
 *   migrate:status --path=/custom/migrations/dir
 */
class MigrateStatus extends Command
{
    /**
     * Configure command metadata, arguments, and options.
     */
    protected function configure(): void
    {
        $this->setName('migrate:status')
            ->setDescription('Show the status of all migrations (ran / failed / pending)')
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to migrations directory (default: app/Migrations)'
            );
    }

    /**
     * Execute the migrate:status command.
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

        $runner  = new MigrationRunner($db);
        $history = $runner->getHistory();

        // Build a slug → history row map for fast lookup
        $historyMap = [];
        foreach ($history as $row) {
            $historyMap[$row['migration']] = $row;
        }

        if (empty($migrations) && empty($history)) {
            $output->writeln('<comment>No migrations found.</comment>');
            return 0;
        }

        $table = new Table($output);
        $table->setHeaders(['Migration', 'Scope', 'Feature', 'Status', 'Batch', 'Time (s)', 'Ran At']);

        $hasPending = false;

        // Rows for known migration classes (may or may not have a history entry)
        foreach ($migrations as $migration) {
            $slug = $migration->getSlug();
            if (isset($historyMap[$slug])) {
                $row    = $historyMap[$slug];
                $status = (int) $row['result'] === 1 ? '<info>Ran</info>' : '<error>Failed</error>';
                $table->addRow([
                    $slug,
                    $row['scope']    ?? $migration->scope,
                    $row['feature']  ?? $migration->feature,
                    $status,
                    $row['batch']    ?? '-',
                    isset($row['execution_time']) ? number_format((float) $row['execution_time'], 4) : '-',
                    $row['ran_at']   ?? '-',
                ]);
                unset($historyMap[$slug]);
            } else {
                $hasPending = true;
                $table->addRow([
                    $slug,
                    $migration->scope,
                    $migration->feature,
                    '<comment>Pending</comment>',
                    '-',
                    '-',
                    '-',
                ]);
            }
        }

        // History rows for migrations no longer in the codebase
        if (!empty($historyMap)) {
            $table->addRow(new TableSeparator());
            foreach ($historyMap as $slug => $row) {
                $status = (int) $row['result'] === 1 ? '<info>Ran</info>' : '<error>Failed</error>';
                $table->addRow([
                    $slug . ' <comment>(removed)</comment>',
                    $row['scope']   ?? '-',
                    $row['feature'] ?? '-',
                    $status,
                    $row['batch']   ?? '-',
                    isset($row['execution_time']) ? number_format((float) $row['execution_time'], 4) : '-',
                    $row['ran_at']  ?? '-',
                ]);
            }
        }

        $table->render();

        if ($hasPending) {
            $output->writeln('<comment>Run <info>migrate</info> to execute pending migrations.</comment>');
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
