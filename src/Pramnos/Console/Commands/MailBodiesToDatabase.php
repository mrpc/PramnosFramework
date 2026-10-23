<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Email\Body;
use Pramnos\Email\BodyStore;
use Pramnos\Framework\Factory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mail:bodies-to-database` — move the bodies of sent messages from files into `mail_bodies`, the
 * compressed hypertable ({@see BodyStore}), for an installation that kept them on disk and has
 * TimescaleDB now.
 *
 * ```bash
 * php bin/pramnos mail:bodies-to-database --dry-run          # what there is to move
 * php bin/pramnos mail:bodies-to-database                     # move it, a thousand rows at a time
 * php bin/pramnos mail:bodies-to-database --include-content   # bodies kept in mails.content too
 * ```
 *
 * Each row's body is read as it is kept — a gzipped file its `path` names, an application's
 * `.html.gz` as well as the store's `.body.gz`, or one on a storage disk — written to `mail_bodies`
 * and the row pointed at it (`path` = `db:`), a batch per transaction. It stops and starts: a row
 * already moved is not read again, so a run cut short is finished by the next. A file that is not
 * there any more is counted and its row left as it is. The files are not deleted — many rows share
 * one — and once a run finds nothing left to move, the folder is the operator's to remove.
 */
class MailBodiesToDatabase extends Command
{
    protected function configure(): void
    {
        $this->setName('mail:bodies-to-database')
            ->setDescription('Move sent messages\' bodies from files into the mail_bodies hypertable')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per transaction', '1000')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many rows (0: all)', '0')
            ->addOption('include-content', null, InputOption::VALUE_NONE, 'Move bodies kept in mails.content as well')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what there is to move, and move nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = Factory::getDatabase();
        if (!$db->schema()->hasTable('pramnos.mail_bodies')) {
            $output->writeln('<error>There is no mail_bodies table.</error> It is made by the messaging migrations, on TimescaleDB only: run migrate there.');

            return Command::FAILURE;
        }
        $batch   = max(1, (int) $input->getOption('batch'));
        $limit   = max(0, (int) $input->getOption('limit'));
        $content = (bool) $input->getOption('include-content');

        if ($input->getOption('dry-run')) {
            $count = (int) $this->pending($content)->count();
            $output->writeln(sprintf('%d row(s) to move into mail_bodies%s.', $count, $content ? ', those with a body in content among them' : ''));

            return Command::SUCCESS;
        }

        $moved   = 0;
        $missing = 0;
        $after   = 0;
        $started = microtime(true);
        // With mail_bodies' compression paused: a body moved now is dated in the past, and a
        // chunk compressed while the move still fills it takes each write at many times the cost.
        $status = $db->schema()->withCompressionPaused('pramnos.mail_bodies', function () use ($db, $batch, $limit, $content, $output, $started, &$moved, &$missing, &$after): int {
            while ($limit === 0 || $moved + $missing < $limit) {
                $rows = $this->pending($content)->select(['id', 'date', 'path', 'content'])->where('id', '>', $after)
                    ->orderBy('id')->limit($limit === 0 ? $batch : min($batch, $limit - $moved - $missing))->get()->fetchAll();
                if ($rows === []) {
                    break;
                }
                // Read once per batch: the rows of an hour share a file.
                $files = [];
                $db->startTransaction();
                try {
                    foreach ($rows as $row) {
                        $after = (int) $row['id'];
                        [$body, $rebuild] = $this->bodyOf($row, $files);
                        if ($body === null) {
                            $missing++;
                            continue;
                        }
                        $this->keep($row, $body, $rebuild);
                        $db->queryBuilder()->table('#PREFIX#mails')->where('id', (int) $row['id'])
                            ->update(['path' => BodyStore::IN_DATABASE, 'content' => '']);
                        $moved++;
                    }
                    $db->commitTransaction();
                } catch (\Throwable $e) {
                    $db->rollbackTransaction();
                    $output->writeln('<error>Stopped at mail ' . $after . ':</error> ' . $e->getMessage() . ' — what was moved before this batch stays moved; run again to go on.');

                    return Command::FAILURE;
                }
                $output->writeln(sprintf('%d moved, %d without a body, up to mail %d (%.0f s)', $moved, $missing, $after, microtime(true) - $started));
            }

            return Command::SUCCESS;
        });
        if ($status !== Command::SUCCESS) {
            return $status;
        }
        $output->writeln(sprintf('<info>%d body/bodies moved into mail_bodies</info>, %d row(s) whose file is not there any more left as they are.', $moved, $missing));

        return Command::SUCCESS;
    }

    /**
     * Write one row's body to `mail_bodies` (seam so a test can make a batch fail).
     *
     * @param array<string, mixed> $row
     */
    protected function keep(array $row, string $body, bool $rebuild): void
    {
        BodyStore::putRow((int) $row['id'], (int) $row['date'], $body, $rebuild);
    }

    /** The rows whose body is still outside `mail_bodies`. */
    protected function pending(bool $content): \Pramnos\Database\QueryBuilder
    {
        return Factory::getDatabase()->queryBuilder()->table('#PREFIX#mails')
            ->where(static function ($q) use ($content): void {
                $q->where(static function ($q): void {
                    $q->where('path', '<>', '')->where('path', '<>', BodyStore::IN_DATABASE);
                });
                if ($content) {
                    $q->orWhere('content', '<>', '');
                }
            });
    }

    /**
     * A row's body as it is kept, and whether it is kept before what differs per recipient; null
     * for a body that is not there any more. A file shared by several rows is read once.
     *
     * @param array<string, mixed>  $row
     * @param array<string, string> $files What this batch has read, by path
     * @return array{0: ?string, 1: bool}
     */
    private function bodyOf(array $row, array &$files): array
    {
        // A body in content is the one read, as Body::of() reads it.
        if ((string) $row['content'] !== '') {
            return [(string) $row['content'], false];
        }
        $path = (string) $row['path'];
        $rebuild = str_ends_with($path, BodyStore::REBUILD_EXTENSION);
        if (!array_key_exists($path, $files)) {
            // Read raw: kept before what differs per recipient, it stays so in mail_bodies.
            $files[$path] = $this->read($path);
        }

        return [$files[$path] === '' ? null : $files[$path], $rebuild];
    }

    /** A kept body by its path, as kept — '' where it cannot be read. */
    private function read(string $path): string
    {
        if (str_starts_with($path, 'disk:')) {
            [, $disk, $relative] = array_pad(explode(':', $path, 3), 3, '');
            try {
                $raw = \Pramnos\Storage\Storage::getManager()->disk($disk)->get($relative);
            } catch (\Throwable) {
                return '';
            }

            return str_ends_with($relative, '.gz') ? (string) @gzdecode($raw) : $raw;
        }
        $file = Body::file($path);
        $raw  = $file === null ? false : @file_get_contents($file);

        return $raw === false ? '' : (str_ends_with($file, '.gz') ? (string) @gzdecode($raw) : $raw);
    }
}
