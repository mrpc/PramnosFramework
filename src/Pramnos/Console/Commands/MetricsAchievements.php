<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Metrics\Achievements;
use Pramnos\Metrics\Metrics;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `metrics:achievements` — keep what was reached: milestones, best weeks, goals met
 * ({@see Achievements}). Nightly, after the metrics are computed; nothing to do where no metric
 * earns them and no goal is set.
 */
class MetricsAchievements extends Command
{
    protected function configure(): void
    {
        $this->setName('metrics:achievements')
            ->setDescription('Keep the milestones, best weeks and goals met that are new');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!Metrics::connection()->schema()->hasTable(Achievements::TABLE)) {
            $output->writeln('No achievements table: run migrate.', OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        }
        $new = Achievements::scan();
        $output->writeln(sprintf('%d new achievements.', $new), $new > 0 ? OutputInterface::VERBOSITY_NORMAL : OutputInterface::VERBOSITY_VERBOSE);

        return Command::SUCCESS;
    }
}
