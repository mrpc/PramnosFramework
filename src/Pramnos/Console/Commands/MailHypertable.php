<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Email\MailHypertable as Hypertable;
use Pramnos\Framework\Factory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mail:hypertable` — make `mails` a TimescaleDB hypertable, with the site up ({@see Hypertable}).
 *
 * ```bash
 * php bin/pramnos mail:hypertable --dry-run     # where the table is, and how much there is to move
 * php bin/pramnos mail:hypertable               # convert, then move the rows, newest first
 * php bin/pramnos mail:hypertable --no-drain    # convert only; move the rows in a later run
 * ```
 *
 * The conversion takes milliseconds; new mail goes to the new table from then on. Moving the old
 * rows takes as long as there are rows — about thirty thousand a second — and a run stopped
 * halfway is finished by the next. A console command and not a migration: it is not cheaply
 * undone, and it is for an installation to choose when.
 */
class MailHypertable extends Command
{
    protected function configure(): void
    {
        $this->setName('mail:hypertable')
            ->setDescription('Make mails a TimescaleDB hypertable, moving its rows with the site up')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows moved per statement', (string) Hypertable::BATCH)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after moving this many (0: all)', '0')
            ->addOption('no-drain', null, InputOption::VALUE_NONE, 'Convert, and leave the rows for a later run')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Say where the table is, and change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mails = $this->hypertable();
        $state = $mails->state();

        if ($state === Hypertable::UNSUPPORTED) {
            $output->writeln('<error>This database is not PostgreSQL with TimescaleDB.</error> `mails` stays an ordinary table; the body store keeps it small.');

            return Command::FAILURE;
        }
        if ($input->getOption('dry-run')) {
            $output->writeln(match ($state) {
                Hypertable::PLAIN      => '`mails` is an ordinary table of ' . number_format($this->rows()) . ' row(s): a run converts it and moves them.',
                Hypertable::DRAINING   => '`mails` is a hypertable; ' . number_format($mails->pending()) . ' row(s) are still to move from ' . $mails->legacy() . '.',
                default                => '`mails` is a hypertable, with nothing left to move.',
            });

            return Command::SUCCESS;
        }
        if ($state === Hypertable::PLAIN) {
            $mails->convert();
            $output->writeln('<info>`mails` is a hypertable.</info> New mail is written to it; the old rows are in ' . $mails->legacy() . ' until they move.');
        }
        if ($input->getOption('no-drain') || $mails->state() !== Hypertable::DRAINING) {
            if ($mails->state() === Hypertable::HYPERTABLE) {
                $output->writeln('Nothing to move.');
            }

            return Command::SUCCESS;
        }

        $started = microtime(true);
        $moved   = $mails->drain(
            max(1, (int) $input->getOption('batch')),
            max(0, (int) $input->getOption('limit')),
            static function (int $moved, int $left) use ($output, $started): void {
                $output->writeln(sprintf('  %s moved, %s left (%.0f s)', number_format($moved), number_format($left), microtime(true) - $started));
            }
        );
        $output->writeln(sprintf('<info>%s row(s) moved.</info> %s', number_format($moved), $mails->state() === Hypertable::HYPERTABLE
            ? 'Nothing is left in ' . $mails->legacy() . ', and it is gone.'
            : number_format($mails->pending()) . ' left: run again to go on.'));

        return Command::SUCCESS;
    }

    /** The table's rows, for the dry run. */
    private function rows(): int
    {
        return Factory::getDatabase()->queryBuilder()->table('#PREFIX#mails')->count();
    }

    /** The conversion — a seam, so a test can point it at a table of its own. */
    protected function hypertable(): Hypertable
    {
        return new Hypertable(Factory::getDatabase());
    }
}
