<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Window;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `metrics:compute` — compute the application's metrics ({@see Metrics}) and keep their values.
 *
 * ```bash
 * php bin/pramnos metrics:compute                                   # what is due: open periods, and closing those just ended
 * php bin/pramnos metrics:compute --metric=payments.count --grain=month --from=2026-01-01 --to=2026-10-31
 * php bin/pramnos metrics:compute --from=2026-01-01 --to=2026-10-31 --recompute   # closed periods too
 * ```
 *
 * Idempotent: a period's values are replaced, never added to. The framework's schedule runs it
 * hourly; it does nothing where no metric is declared.
 *
 * Exits `1` when a metric could not be computed; the others are kept all the same.
 */
class MetricsCompute extends Command
{
    protected function configure(): void
    {
        $this->setName('metrics:compute')
            ->setDescription('Compute the application\'s metrics: open periods, and close those that ended')
            ->addOption('metric', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these metrics')
            ->addOption('grain', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these grains: ' . implode(', ', Window::GRAINS))
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Every period from this day (YYYY-MM-DD)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Every period to this day (YYYY-MM-DD); today by default')
            ->addOption('recompute', null, InputOption::VALUE_NONE, 'Compute closed periods again (never a level\'s)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (Metrics::all() === []) {
            $output->writeln('No metrics are declared.', OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }
        $grains = (array) $input->getOption('grain');
        foreach ($grains as $grain) {
            if (!in_array($grain, Window::GRAINS, true)) {
                $output->writeln('<error>Grain "' . $grain . '" is not one of: ' . implode(', ', Window::GRAINS) . '.</error>');

                return Command::INVALID;
            }
        }
        foreach (['from', 'to'] as $end) {
            $day = $input->getOption($end);
            if ($day !== null && (\DateTime::createFromFormat('!Y-m-d', (string) $day) === false)) {
                $output->writeln('<error>--' . $end . ' is a day, YYYY-MM-DD.</error>');

                return Command::INVALID;
            }
        }
        $only = (array) $input->getOption('metric');
        foreach ($only as $name) {
            if (!isset(Metrics::all()[$name])) {
                $output->writeln('<error>There is no metric "' . $name . '".</error>');

                return Command::INVALID;
            }
        }

        $report = Metrics::compute($only ?: null, $grains ?: null, $input->getOption('from'), $input->getOption('to'), (bool) $input->getOption('recompute'));
        if ($report['busy']) {
            $output->writeln('The metrics\' store is busy (being changed, or computed by another run): nothing done; the next run computes what is due.');

            return Command::SUCCESS;
        }
        $output->writeln(sprintf('Computed %d open, closed %d, skipped %d.', $report['computed'], $report['closed'], $report['skipped']));
        // The alerts read what was just computed.
        if (Metrics::connection()->schema()->hasTable(\Pramnos\Metrics\Alerts::TABLE)) {
            foreach (\Pramnos\Metrics\Alerts::evaluate() as $id => $said) {
                $output->writeln('Alert ' . $id . ': ' . $said);
            }
        }
        // And the goals: each judged, those that turned told.
        if (Metrics::connection()->schema()->hasTable(\Pramnos\Metrics\Goals::TABLE)) {
            foreach (\Pramnos\Metrics\Goals::check() as $id => $said) {
                $output->writeln('Goal ' . $id . ': ' . $said);
            }
        }
        foreach ($report['failed'] as $what => $why) {
            $output->writeln('<error>' . $what . ': ' . $why . '</error>');
        }

        return $report['failed'] === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
