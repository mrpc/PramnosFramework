<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Metrics\Metrics;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `metrics:status` — every declared metric's health ({@see Metrics::health()}): when it was last
 * computed, how long the last run took, what it failed with, and which of the last few periods
 * have no figure.
 *
 * ```bash
 * php bin/pramnos metrics:status
 * ```
 *
 * Exits `1` when a metric has a failure, is late against its own `expect()`, or is missing a
 * recent period — the same condition the `metrics_health` health check reports on the ops board.
 */
class MetricsStatus extends Command
{
    protected function configure(): void
    {
        $this->setName('metrics:status')
            ->setDescription('Show every declared metric\'s last compute run, lateness and gaps');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (Metrics::all() === []) {
            $output->writeln('No metrics are declared.');

            return Command::SUCCESS;
        }
        $problem = false;
        foreach (Metrics::health() as $metric => $grains) {
            foreach ($grains as $grain => $health) {
                $computed = $health['last_computed'] !== null ? date('Y-m-d H:i', $health['last_computed']) : 'never';
                $line     = sprintf('%s@%s: last computed %s', $metric, $grain, $computed);
                if ($health['duration_ms'] !== null) {
                    $line .= sprintf(', last run %dms', $health['duration_ms']);
                }
                if ($health['error'] !== null) {
                    $line   .= ' — <error>failed: ' . $health['error'] . '</error>';
                    $problem = true;
                }
                if ($health['late']) {
                    $line   .= ' — <error>late</error>';
                    $problem = true;
                }
                if ($health['missing'] !== []) {
                    $line   .= ' — <error>missing: ' . implode(', ', $health['missing']) . '</error>';
                    $problem = true;
                }
                $output->writeln($line);
            }
        }

        return $problem ? Command::FAILURE : Command::SUCCESS;
    }
}
