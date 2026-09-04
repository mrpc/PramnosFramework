<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;

/**
 * Displays the current migration status as a formatted table.
 *
 * Each row shows a migration's slug, scope, feature, status (Ran / Failed /
 * Pending), batch number, execution time, and ran_at timestamp.
 *
 * Usage examples:
 *   migrate:status
 *   migrate:status --path=/custom/migrations/dir
 */
class MigrateStatus extends Command
{
    /**
     * Configure command metadata, arguments, and options.
     */
    protected function configure(): void
    {
        $this->setName('migrate:status')
            ->setDescription('Show the status of all migrations (ran / failed / pending)')
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to migrations directory (default: app/Migrations)'
            );
    }

    /**
     * Execute the migrate:status command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $consoleApp = $this->getApplication();
        if (!($consoleApp instanceof \Pramnos\Console\Application)) {
            $output->writeln('<error>This command must run within the Pramnos console application.</error>');
            return 1;
        }

        // Two different needs, and only one of them is fatal. Listing what is on
        // disk needs an application to hand to the loader; a *connection* is
        // needed only to tell Ran from Pending. The report is worth printing
        // without one, so the connection is checked further down instead of here.
        $app = $consoleApp->internalApplication;
        if (!$app instanceof \Pramnos\Application\Application) {
            $output->writeln('<error>No Pramnos application available.</error>');
            return 1;
        }
        $db = $app->database ?? null;

        // Which migrations apply here is the application's answer: the `features`
        // gate and app.php's `migration_cutoff`. Reading it is what stops this
        // report counting the baseline epoch, and the features the installation
        // declined, as pending. Nothing in it touches the database, so it is
        // available even on the no-connection path below.
        $migrationScope = MigrationLoader::scopeFor($app, true);

        $explicitPath = $input->getOption('path');

        // --path means "report on exactly this directory": no feature gate to
        // apply, because the operator named the directory themselves.
        if ($explicitPath) {
            $dirs        = [$explicitPath];
            $skippedDirs = [];
        } else {
            $dirs        = $migrationScope['dirs'];
            $skippedDirs = $migrationScope['skipped'];
        }

        // Loaded from the skipped directories too. Omitting them would leave an
        // operator with no way to find out why a migration they can see on disk
        // is never going to run, which is exactly the silence that sent somebody
        // looking for this.
        $migrations = MigrationLoader::loadFromDirectories($dirs, $app);
        $skipReasons = [];
        foreach ($skippedDirs as $dir => $reason) {
            foreach (MigrationLoader::loadFromDirectory($dir, $app) as $migration) {
                $skipReasons[$migration->getSlug()] = $reason;
                $migrations[] = $migration;
            }
        }

        $cutoff = $migrationScope['cutoff'];

        // A connection is what tells Ran from Pending; without one the disk
        // listing is still worth printing, and saying so beats returning 1 and
        // nothing.
        $history = [];
        if ($db !== null) {
            $history = (new MigrationRunner($db))->getHistory();
        } else {
            $output->writeln(
                '<comment>No database connection: showing what is on disk, '
                . 'without run history.</comment>'
            );
            $output->writeln('');
        }

        // Build a slug → history row map for fast lookup
        $historyMap = [];
        foreach ($history as $row) {
            $historyMap[$row['key']] = $row;
        }

        if (empty($migrations) && empty($history)) {
            $output->writeln('<comment>No migrations found.</comment>');
            return 0;
        }

        $table = new Table($output);
        $table->setHeaders(['Migration', 'Scope', 'Feature', 'Status', 'Batch', 'Time (s)', 'Ran At']);

        $hasPending  = false;
        $skippedRows = [];
        $withErrors  = [];
        $declinedRows = [];

        // Rows for known migration classes (may or may not have a history entry)
        foreach ($migrations as $migration) {
            $slug = $migration->getSlug();
            if (isset($historyMap[$slug])) {
                $row    = $historyMap[$slug];
                $status = $this->statusLabel((int) $row['result']);
                if ((int) $row['result'] === MigrationRunner::RESULT_RAN_WITH_ERRORS) {
                    $withErrors[$slug] = (string) ($row['error_message'] ?? '');
                }
                if ((int) $row['result'] === MigrationRunner::RESULT_DECLINED) {
                    $declinedRows[$slug] = (string) ($row['error_message'] ?? '');
                    // Declined and still pending are the same thing: it refused,
                    // and `migrate` will attempt it again. Counting it as pending
                    // is what makes the closing hint true.
                    $hasPending = true;
                }
                $table->addRow([
                    $slug,
                    $row['scope']    ?? $migration->scope,
                    $row['feature']  ?? $migration->feature,
                    $status,
                    $row['batch']    ?? '-',
                    isset($row['execution_time']) ? number_format((float) $row['execution_time'], 4) : '-',
                    $row['when']     ?? '-',
                ]);
                unset($historyMap[$slug]);
                continue;
            }

            // Out of scope for this installation: shown, with the reason, and
            // never counted as pending. A migration whose feature is off is
            // skipped whatever its timestamp, so the feature reason is checked
            // first.
            $reason = $skipReasons[$slug] ?? $this->cutoffReason($migration, $cutoff);
            if ($reason !== null) {
                $skippedRows[] = [
                    $slug,
                    $migration->scope,
                    $migration->feature,
                    '<comment>Skipped</comment> (' . $reason . ')',
                    '-',
                    '-',
                    '-',
                ];
                continue;
            }

            $hasPending = true;
            $table->addRow([
                $slug,
                $migration->scope,
                $migration->feature,
                '<comment>Pending</comment>',
                '-',
                '-',
                '-',
            ]);
        }

        // Grouped after the live rows rather than interleaved: on the
        // installation this was found on the skipped set is 44 rows against 2
        // that matter, and a report where the answer is buried is the problem
        // this is fixing, not the fix.
        if (!empty($skippedRows)) {
            $table->addRow(new TableSeparator());
            foreach ($skippedRows as $row) {
                $table->addRow($row);
            }
        }

        // History rows for migrations no longer in the codebase
        if (!empty($historyMap)) {
            $table->addRow(new TableSeparator());
            foreach ($historyMap as $slug => $row) {
                $status = $this->statusLabel((int) $row['result']);
                $table->addRow([
                    $slug . ' <comment>(removed)</comment>',
                    $row['scope']   ?? '-',
                    $row['feature'] ?? '-',
                    $status,
                    $row['batch']   ?? '-',
                    isset($row['execution_time']) ? number_format((float) $row['execution_time'], 4) : '-',
                    $row['ran_at']  ?? '-',
                ]);
            }
        }

        $table->render();

        if (!empty($skippedRows)) {
            $output->writeln(sprintf(
                '<comment>%d migration(s) do not apply to this installation'
                . ' and will not run.</comment>',
                count($skippedRows)
            ));
            if ($cutoff !== '') {
                $output->writeln(
                    '<comment>Cutoff:</comment> ' . $cutoff . ' <comment>(app.php'
                    . ' migration_cutoff)</comment>'
                );
            }
        }

        // The whole point of the third state: what was rejected is readable here,
        // rather than in var/logs/upgradeerrors.log, which nothing points at.
        if (!empty($withErrors)) {
            $output->writeln('');
            $output->writeln(sprintf(
                '<error>%d migration(s) completed with rejected statements:</error>',
                count($withErrors)
            ));
            foreach ($withErrors as $slug => $message) {
                $output->writeln(' <options=bold>' . $slug . '</>');
                foreach (explode("\n", trim($message)) as $line) {
                    if (trim($line) !== '') {
                        $output->writeln('   <comment>' . $line . '</comment>');
                    }
                }
            }
        }

        // A decline is only useful if the reason travels with it. `Declined` in a
        // column with nothing underneath would be the silence the state exists to
        // avoid.
        if (!empty($declinedRows)) {
            $output->writeln('');
            $output->writeln(sprintf(
                '<comment>%d migration(s) declined — the data is not ready:</comment>',
                count($declinedRows)
            ));
            foreach ($declinedRows as $slug => $reason) {
                $output->writeln(' <options=bold>' . $slug . '</>');
                foreach (explode("\n", trim($reason)) as $line) {
                    if (trim($line) !== '') {
                        $output->writeln('   <comment>' . $line . '</comment>');
                    }
                }
            }
        }

        if ($hasPending) {
            $output->writeln('<comment>Run <info>migrate</info> to execute pending migrations.</comment>');
        }

        return 0;
    }

    /**
     * The Status cell for a history row's `result`.
     *
     * `Ran` and `Failed` alone could not describe a migration that completed
     * while the database rejected some of its statements — the case that used to
     * read as `Ran` — nor one that looked at the data and refused, which is
     * neither. Note that `Declined` is not the same as the `Skipped (cutoff)` a
     * row can carry: those never ran, this one ran and said no.
     */
    private function statusLabel(int $result): string
    {
        return match ($result) {
            MigrationRunner::RESULT_OK               => '<info>Ran</info>',
            MigrationRunner::RESULT_RAN_WITH_ERRORS  => '<error>Ran with errors</error>',
            MigrationRunner::RESULT_DECLINED         => '<comment>Declined</comment>',
            default                                  => '<error>Failed</error>',
        };
    }

    /**
     * Why the cutoff excludes this migration, or null when it does not.
     *
     * A migration with no timestamp in its filename (the legacy `Migration0126`
     * shape) has nothing to compare and is never cut off — the same rule
     * MigrationRunner::filterCutoff() applies, because a report that disagreed
     * with the runner would be a new way to be wrong.
     */
    private function cutoffReason(Migration $migration, string $cutoff): ?string
    {
        if ($cutoff === '') {
            return null;
        }
        $timestamp = $migration->getTimestamp();
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        return strcmp($timestamp, $cutoff) <= 0 ? 'cutoff' : null;
    }

}
