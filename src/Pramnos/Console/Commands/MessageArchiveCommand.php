<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Messaging\MessageArchive;
use Pramnos\Storage\BodyStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Move message bodies out of the database and into the gzipped archive.
 *
 * ```
 * ./yourapp messages:archive            # what it would move, and nothing else
 * ./yourapp messages:archive --apply
 * ```
 *
 * The counterpart of `mail:archive` for the account's own inbox. **Nothing is deleted**: the body
 * is compressed onto disk and the row keeps a path to it, so every screen answers exactly what it
 * answered before.
 *
 * The table this works on is the one a mass send makes large — one row per recipient, each with
 * its own copy of one identical body — and the store is content-addressed, so those copies
 * collapse to a single file.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MessageArchiveCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('messages:archive')
            ->setDescription('Compress message bodies onto disk, keeping every one of them')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED,
                'How many rows to move in this run. Default: everything, in batches.')
            ->addOption('apply', null, InputOption::VALUE_NONE,
                'Actually do it. Without this the command only reports.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!BodyStore::enabled()) {
            $output->writeln('<comment>The body store is off.</comment> Nothing is moved.');
            $output->writeln('');
            $output->writeln('It is on by default, so this installation has turned it off. Remove');
            $output->writeln('the setting from app.php, or set it back:');
            $output->writeln('');
            $output->writeln("  'mail' => ['body_store' => ['enabled' => true]],");

            return Command::FAILURE;
        }

        $pending = MessageArchive::pending();

        if ($pending === 0) {
            $output->writeln('<info>Nothing to move.</info> Every message body is already stored.');

            return Command::SUCCESS;
        }

        if (!$input->getOption('apply')) {
            $output->writeln(sprintf('<info>%s</info> message %s would move to <info>%s</info>.',
                number_format($pending), $pending === 1 ? 'body' : 'bodies', BodyStore::root()));
            $output->writeln('');
            $output->writeln('Nothing has been changed. Re-run with <info>--apply</info>.');

            return Command::SUCCESS;
        }

        $limit = (int) ($input->getOption('limit') ?? 0);
        $moved = $freed = $failed = 0;

        // In batches, so an interrupted run has still done most of its work and the next one
        // picks up where this stopped.
        do {
            $batch = MessageArchive::run(
                $limit > 0 ? min($limit - $moved, MessageArchive::BATCH) : MessageArchive::BATCH
            );

            $moved  += $batch['moved'];
            $freed  += $batch['freed'];
            $failed += $batch['failed'];

            if ($batch['moved'] === 0) {
                break;
            }

            $output->write("\r  moved " . number_format($moved) . '…');
        } while ($limit === 0 || $moved < $limit);

        $output->writeln("\r" . sprintf('  moved <info>%s</info>, freeing <info>%s</info> in the database.',
            number_format($moved), self::bytes($freed)));

        if ($failed > 0) {
            $output->writeln(sprintf('  <comment>%d could not be stored</comment> and kept their '
                . 'body in the row — see the messaging log.', $failed));
        }

        return Command::SUCCESS;
    }

    private static function bytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1) . ' ' . $unit;
            }

            $bytes = intdiv($bytes, 1024);
        }

        return $bytes . ' B';
    }
}
