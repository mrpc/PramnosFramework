<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Pramnos\Health\HealthRegistry;
use Pramnos\Health\HealthStatus;
use Pramnos\Health\Checks\DatabaseConnectivityCheck;
use Pramnos\Health\Checks\DiskSpaceCheck;
use Pramnos\Health\Checks\MemoryLimitCheck;

/**
 * Runs all registered health checks and displays a status table.
 *
 * ## Usage
 *
 * ```
 * php pramnos health:check
 * php pramnos health:check --json
 * php pramnos health:check --only=database,disk_space
 * ```
 *
 * Exit codes:
 *   0 — all checks OK
 *   1 — one or more checks degraded
 *   2 — one or more checks down
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class HealthCheck extends Command
{
    protected static $defaultName = 'health:check';

    protected function configure(): void
    {
        $this
            ->setName('health:check')
            ->setDescription('Run all registered health checks and display results')
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Output results as JSON instead of a table'
            )
            ->addOption(
                'only',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of check names to run (default: all)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->registerBuiltinChecks();

        $only = $input->getOption('only');
        if ($only !== null) {
            $names = array_map('trim', explode(',', $only));
            $report = $this->runSelected($names, $output);
        } else {
            $report = HealthRegistry::runAll();
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $this->exitCode($report['status']);
        }

        $this->renderTable($output, $report);
        return $this->exitCode($report['status']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function registerBuiltinChecks(): void
    {
        try {
            $db = \Pramnos\Database\Database::getInstance();
            HealthRegistry::register(new DatabaseConnectivityCheck($db));
        } catch (\Throwable) {
            // DB not available — the check itself will report Down when run
        }

        HealthRegistry::register(new DiskSpaceCheck());
        HealthRegistry::register(new MemoryLimitCheck());
    }

    /**
     * Runs only the checks whose names appear in $names.
     *
     * @param string[]        $names
     * @return array{status: string, checks: array<string, array<string, mixed>>}
     */
    private function runSelected(array $names, OutputInterface $output): array
    {
        $overall = HealthStatus::Ok;
        $checks  = [];

        foreach ($names as $name) {
            try {
                $result         = HealthRegistry::run($name);
                $checks[$name]  = $result->toArray();
                $overall        = $overall->worst($result->status);
            } catch (\InvalidArgumentException $e) {
                $output->writeln("<comment>Warning: {$e->getMessage()}</comment>");
            }
        }

        return ['status' => $overall->value, 'checks' => $checks];
    }

    /**
     * @param array{status: string, checks: array<string, array<string, mixed>>} $report
     */
    private function renderTable(OutputInterface $output, array $report): void
    {
        $overallStatus = $report['status'];
        $tag = match ($overallStatus) {
            'ok'       => 'info',
            'degraded' => 'comment',
            default    => 'error',
        };

        $output->writeln('');
        $output->writeln("<{$tag}>Overall status: " . strtoupper($overallStatus) . "</{$tag}>");
        $output->writeln('');

        if (empty($report['checks'])) {
            $output->writeln('<comment>No health checks registered.</comment>');
            return;
        }

        $table = new Table($output);
        $table->setHeaders(['Check', 'Status', 'Message', 'Details']);

        foreach ($report['checks'] as $name => $row) {
            $statusTag = match ($row['status']) {
                'ok'       => 'info',
                'degraded' => 'comment',
                default    => 'error',
            };

            $details = '';
            if (!empty($row['details'])) {
                $parts = [];
                foreach ($row['details'] as $k => $v) {
                    $parts[] = "{$k}: {$v}";
                }
                $details = implode(', ', $parts);
            }

            $table->addRow([
                $name,
                "<{$statusTag}>" . strtoupper($row['status']) . "</{$statusTag}>",
                $row['message'],
                $details,
            ]);
        }

        $table->render();
        $output->writeln('');
    }

    private function exitCode(string $status): int
    {
        return match ($status) {
            'ok'       => Command::SUCCESS,
            'degraded' => 1,
            default    => 2,
        };
    }
}
