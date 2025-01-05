<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\ProgressBar;
use Pramnos\Logs\LogMigrator;

/**
 * Migrate log files to new structured format
 */
class MigrateLogs extends Command
{
    /**
     * Command configuration
     */
    protected function configure()
    {
        $this->setName('migratelogs');
        $this->setDescription('Migrate log files to structured format');
        $this->setHelp(
            "Migrate log files to a structured single-line JSON format.\n" .
                "Examples:\n" .
                " - Single file: migratelogs /path/to/file.log\n" .
                " - Directory: migratelogs /path/to/logs/dir --all\n" .
                " - No backup: migratelogs /path/to/file.log --no-backup\n"
        );

        $this->addArgument(
            'path',
            InputArgument::REQUIRED,
            'Path to log file or directory'
        );

        $this->addOption(
            'all',
            'a',
            InputOption::VALUE_NONE,
            'Process all .log files in directory'
        );

        $this->addOption(
            'no-backup',
            null,
            InputOption::VALUE_NONE,
            'Do not create backup files'
        );
    }

    /**
     * Command execution
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        $processAll = $input->getOption('all');
        $createBackup = !$input->getOption('no-backup');

        if (!file_exists($path)) {
            $output->writeln("<error>Path not found: $path</error>");
            return 1; // Error return code
        }

        // Create migrator with progress callback
        $migrator = new LogMigrator(function ($processed, $total) use ($output) {
            static $progressBar = null;

            if ($progressBar === null) {
                $progressBar = new ProgressBar($output, $total);
                $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');
                $progressBar->start();
            }

            $progressBar->setProgress($processed);

            if ($processed >= $total) {
                $progressBar->finish();
                $output->writeln("");
                $progressBar = null;
            }
        });

        try {
            if (is_dir($path) && $processAll) {
                // Process directory
                $files = glob($path . "/*.log");
                if (empty($files)) {
                    $output->writeln("<comment>No .log files found in directory</comment>");
                    return 0; // Success return code
                }

                $totalStats = [
                    'total_files' => count($files),
                    'processed_files' => 0,
                    'failed_files' => 0,
                    'total_lines' => 0,
                    'converted_lines' => 0,
                    'start_time' => microtime(true)
                ];

                foreach ($files as $filepath) {
                    $output->writeln("\n<info>Processing: " . basename($filepath) . "</info>");
                    try {
                        $stats = $migrator->migrateFile($filepath, $createBackup);
                        $totalStats['processed_files']++;
                        $totalStats['total_lines'] += $stats['total_lines'];
                        $totalStats['converted_lines'] += $stats['converted_lines'];
                    } catch (\Exception $e) {
                        $totalStats['failed_files']++;
                        $output->writeln("<error>Error processing {$filepath}: " . $e->getMessage() . "</error>");
                    }
                }

                $this->displaySummary($output, $totalStats);
                return $totalStats['failed_files'] === 0 ? 0 : 1;
            } elseif (is_file($path)) {
                // Process single file
                $stats = $migrator->migrateFile($path, $createBackup);
                $this->displaySummary($output, ['processed_files' => 1] + $stats);
                return 0;
            } else {
                $output->writeln("<error>Please provide a file path or use --all with a directory path</error>");
                return 1;
            }
        } catch (\Exception $e) {
            $output->writeln("<error>Migration failed: " . $e->getMessage() . "</error>");
            return 1;
        }
        return 0;
    }

    /**
     * Display migration summary
     * @param OutputInterface $output Output interface
     * @param array $stats Migration statistics
     */
    private function displaySummary(OutputInterface $output, array $stats): void
    {
        $output->writeln("\n<info>Migration Summary:</info>");
        if (isset($stats['total_files'])) {
            $output->writeln(sprintf(
                "Files processed: %d/%d (Failed: %d)",
                $stats['processed_files'],
                $stats['total_files'],
                $stats['failed_files'] ?? 0
            ));
        }
        $output->writeln(sprintf(
            "Lines processed: %d (Converted: %d)",
            $stats['total_lines'],
            $stats['converted_lines']
        ));

        if (isset($stats['start_time'])) {
            $duration = round(microtime(true) - $stats['start_time'], 2);
            $output->writeln(sprintf("Duration: %.2f seconds", $duration));
        }
    }
}
