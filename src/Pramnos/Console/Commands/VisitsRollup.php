<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Visits\Rollup;
use Pramnos\Visits\Visits;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `visits:rollup` — keep `stats.visit_pages_daily` ({@see Rollup}): make it, and fill yesterday and
 * today where it is a table; on TimescaleDB it is a continuous aggregate and its policy keeps it.
 * Scheduled every 30 minutes by the framework; a no-op without `visits.sites`.
 *
 * ```bash
 * php bin/pramnos visits:rollup
 * php bin/pramnos visits:rollup --from=2026-01-01 --to=2026-10-31   # older days, each replaced whole
 * ```
 */
class VisitsRollup extends Command
{
    /** Name, description and the days. */
    protected function configure(): void
    {
        $this->setName('visits:rollup')
            ->setDescription('Keep the daily rollup of the site\'s pages, referrers, clicks and campaigns')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'The first UTC day, YYYY-MM-DD (yesterday by default)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'The last UTC day, YYYY-MM-DD (today by default)');
    }

    /** Make it, fill it, say how many days. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (Visits::sites() === [] || !$db->schema()->hasTable(\Pramnos\Visits\VisitsMetrics::TABLE)) {
            $output->writeln('The site\'s traffic is not counted here: nothing to roll up.');

            return Command::SUCCESS;
        }
        $day = static function (mixed $value): ?int {
            if (!is_string($value) || $value === '') {
                return null;
            }
            $time = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

            return $time === false ? -1 : $time->getTimestamp();
        };
        [$from, $to] = [$day($input->getOption('from')), $day($input->getOption('to'))];
        if ($from === -1 || $to === -1) {
            $output->writeln('<error>Days are written YYYY-MM-DD.</error>');

            return Command::FAILURE;
        }
        $kind = Rollup::ensure($db);
        if ($kind === 'aggregate') {
            $output->writeln(Rollup::NAME . ' is a continuous aggregate: its policy keeps it.');

            return Command::SUCCESS;
        }
        $output->writeln(sprintf('%d day(s) rolled up into %s.', Rollup::run($db, $from, $to), Rollup::NAME));

        return Command::SUCCESS;
    }
}
