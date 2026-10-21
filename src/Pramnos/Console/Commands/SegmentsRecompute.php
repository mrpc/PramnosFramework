<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Audience\Segments;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Work segments out again in full: who entered, who left.
 *
 * Scheduled hourly by the framework. A no-op where there are no segments, or no table for them.
 */
class SegmentsRecompute extends Command
{
    protected function configure(): void
    {
        $this->setName('segments:recompute')
            ->setDescription('Work segments out again: who entered and who left')
            ->addOption('segment', null, InputOption::VALUE_REQUIRED, 'Only this segment id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!\Pramnos\Database\Database::getInstance()->schema()->hasTable('segments')) {
            $output->writeln('No segments here: the table is not installed.');

            return Command::SUCCESS;
        }
        try {
            $only    = $input->getOption('segment');
            $results = $only !== null ? [(int) $only => Segments::recompute((int) $only)] : Segments::recomputeAll();
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        foreach ($results as $id => $r) {
            $output->writeln(sprintf('Segment %d: %d in, +%d entered, -%d left', $id, $r['members'], $r['entered'], $r['left']));
        }

        return Command::SUCCESS;
    }
}
