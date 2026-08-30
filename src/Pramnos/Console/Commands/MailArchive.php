<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Email\BodyStore;
use Pramnos\Email\Retention;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Move mail bodies out of the database and into the gzipped archive.
 *
 * ```
 * ./yourapp mail:archive                 # what it would move, and nothing else
 * ./yourapp mail:archive --apply
 * ./yourapp mail:archive --older-than=30d --apply
 * ./yourapp mail:archive --gc --apply    # remove files no row names any more
 * ```
 *
 * **Nothing is deleted.** The body is compressed onto disk and the row keeps a path to it, so
 * every screen answers exactly what it answered before. That is the difference from
 * `mail:prune --strip-after`, which makes the same table small by throwing the body away.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MailArchive extends Command
{
    protected function configure(): void
    {
        $this->setName('mail:archive')
            ->setDescription('Compress mail bodies onto disk, keeping every one of them')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED,
                'Only messages older than this (e.g. 30d). Default: everything with a body.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED,
                'How many rows to move in this run. Default: everything, in batches.')
            ->addOption('gc', null, InputOption::VALUE_NONE,
                'Also remove stored files that no row names any more.')
            ->addOption('apply', null, InputOption::VALUE_NONE,
                'Actually do it. Without this the command only reports.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!BodyStore::enabled()) {
            $output->writeln('<comment>The mail body store is off.</comment> Nothing is moved.');
            $output->writeln('');
            $output->writeln('It is on by default, so this installation has turned it off. Remove');
            $output->writeln('the setting from app.php, or set it back:');
            $output->writeln('');
            $output->writeln("  'mail' => ['body_store' => ['enabled' => true]],");
            $output->writeln('');
            $output->writeln('New messages then write their body to <info>'
                . BodyStore::root() . '</info> and the row keeps a path to it.');
            $output->writeln('Nothing is lost: every screen reads the body from wherever it is.');

            return Command::FAILURE;
        }

        $older = $this->seconds((string) ($input->getOption('older-than') ?? ''));
        $stats = $this->stats();

        if (isset($stats['error'])) {
            $output->writeln('<error>' . $stats['error'] . '</error>');

            return Command::FAILURE;
        }

        $waiting = $this->archivable($older);

        $output->writeln('');
        $output->writeln('  Store:      ' . BodyStore::root());
        $output->writeln('  In the row: ' . number_format($stats['with_body']) . ' bodies, '
            . $this->size($stats['body_bytes']));
        $archived = (int) ($stats['archived'] ?? 0);
        $files    = (int) ($stats['archive_files'] ?? 0);

        $output->writeln('  Archived:   ' . number_format($archived) . ' bodies in '
            . number_format($files) . ' file(s), ' . $this->size((int) ($stats['archive_bytes'] ?? 0))
            . ' on disk');

        if ($archived > $files && $files > 0) {
            /*
             * The dedup, stated rather than left to be inferred.
             *
             * It is the reason to store bodies this way at all, and it is invisible in every
             * other number on the screen: a campaign to forty thousand people is one file.
             */
            $output->writeln('              ' . $this->size((int) ($stats['archived_bytes'] ?? 0))
                . ' if each had been stored separately — identical bodies are stored once');
        }
        $output->writeln('');

        if (!$input->getOption('apply')) {
            $output->writeln('<comment>Dry run.</comment> With <info>--apply</info> this would move '
                . number_format($waiting) . ' bod' . ($waiting === 1 ? 'y' : 'ies')
                . ($older > 0 ? ' older than ' . $this->human($older) : '') . '.');

            if ($input->getOption('gc')) {
                $output->writeln('  …and remove ' . number_format(count($this->orphans()))
                    . ' stored file(s) no row names any more.');
            }

            return Command::SUCCESS;
        }

        $limit  = (int) ($input->getOption('limit') ?? 0);
        $moved  = 0;
        $freed  = 0;
        $failed = 0;

        /*
         * In batches until nothing is left, unless a limit was given.
         *
         * A table nobody has archived before is every message ever sent, and reading all of
         * their bodies into one result set is how a maintenance command becomes the incident.
         */
        do {
            $pass    = $this->archive($older, $limit > 0 ? $limit : Retention::BATCH);
            $moved  += $pass['moved'];
            $freed  += $pass['freed'];
            $failed += $pass['failed'];

            if ($limit > 0) {
                break;
            }
        } while ($pass['moved'] > 0);

        $output->writeln('  moved ' . number_format($moved) . ' bod' . ($moved === 1 ? 'y' : 'ies')
            . ', ' . $this->size($freed) . ' out of the database');

        if ($failed > 0) {
            // Reported, and the rows kept their body. A failure here is a disk problem, and the
            // message is still in the table where it was.
            $output->writeln('  <comment>' . number_format($failed)
                . ' could not be stored and were left in the row</comment>');
        }

        if ($input->getOption('gc')) {
            $removed = 0;

            foreach ($this->orphans() as $orphan) {
                $removed += BodyStore::forget($orphan) ? 1 : 0;
            }

            $output->writeln('  removed ' . number_format($removed) . ' unreferenced file(s)');
        }

        return Command::SUCCESS;
    }

    // ── Seams ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    protected function stats(): array
    {
        return Retention::stats();
    }

    protected function archivable(int $olderThan): int
    {
        return Retention::archivable($olderThan);
    }

    /** @return array{moved: int, freed: int, failed: int} */
    protected function archive(int $olderThan, int $limit): array
    {
        return Retention::archive($olderThan, $limit);
    }

    /** @return list<string> */
    protected function orphans(): array
    {
        return BodyStore::orphans();
    }

    // ── Formatting ───────────────────────────────────────────────────────────

    /**
     * A duration, or zero.
     *
     * Zero means "everything", which is the right default here and the *wrong* one in
     * `mail:prune`: this command moves bodies and loses none of them, so a typo costs a longer
     * run rather than three months of an audit trail.
     */
    protected function seconds(string $value): int
    {
        $value = trim($value);

        if ($value === '' || preg_match('/^(\d+)\s*([smhdwy]|mo?)?$/i', $value, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1] * match (strtolower($matches[2] ?? '')) {
            's'       => 1,
            'h'       => 3600,
            'd'       => 86400,
            'w'       => 604800,
            'm', 'mo' => 2592000,
            'y'       => 31536000,
            default   => 60,
        };
    }

    protected function human(int $seconds): string
    {
        [$amount, $unit] = match (true) {
            $seconds >= 31536000 => [round($seconds / 31536000, 1), 'year'],
            $seconds >= 2592000  => [round($seconds / 2592000), 'month'],
            $seconds >= 86400    => [round($seconds / 86400), 'day'],
            default              => [$seconds, 'second'],
        };

        return $amount . ' ' . $unit . ((float) $amount === 1.0 ? '' : 's');
    }

    protected function size(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => round($bytes / 1073741824, 1) . ' GB',
            $bytes >= 1048576    => round($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024       => round($bytes / 1024) . ' KB',
            default              => $bytes . ' B',
        };
    }
}
