<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table as TableHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Application\Application;
use Pramnos\Database\ContinuousAggregateRegistry;
use Pramnos\Database\HypertableRegistry;

/**
 * Bring declared hypertables in line with what the framework intends.
 *
 * Seven framework migrations create a table and then convert it inside
 * `ifCapable(TIMESCALEDB, …)`. That is the right tool for a fresh install and
 * the wrong one for the lifecycle: a database that ran those migrations
 * **before** TimescaleDB was installed keeps plain tables for ever, because the
 * migrations are recorded as applied and never run again. Such tables are never
 * partitioned, never compressed, and — the part that actually costs money —
 * their retention policies never apply, so they grow without bound.
 *
 * This command repairs that. It walks {@see HypertableRegistry}, the framework's
 * one declaration of these parameters, and for each declared table that exists
 * but is not yet configured it converts, enables compression, and adds the two
 * policies. Every step is guarded by its own existence check, so running it
 * twice is a no-op and running it on a correct database changes nothing.
 *
 * ## Usage
 *
 * ```
 * php pramnos timescale:ensure --dry-run    # what would change, and how big
 * php pramnos timescale:ensure              # do it
 * php pramnos timescale:ensure --table=authserver.user_activity_log
 * ```
 *
 * Exit codes: 0 = success (including "nothing to do" and "no extension"),
 * 1 = at least one table could not be repaired.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class TimescaleEnsure extends Command
{
    /** @var string The command name as typed */
    protected static $defaultName = 'timescale:ensure';

    protected function configure(): void
    {
        $this
            ->setName('timescale:ensure')
            ->setDescription(
                'Convert declared tables to hypertables and (re)apply compression '
                . 'and retention policies — idempotent'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what would change, with row counts, without touching anything'
            )
            ->addOption(
                'table',
                't',
                InputOption::VALUE_REQUIRED,
                'Limit to one declared table, e.g. authserver.user_activity_log'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = Application::getInstance();
        if (!$app instanceof Application
            || !$app->database instanceof \Pramnos\Database\Database) {
            $output->writeln('<error>No application instance with a database available.</error>');

            return Command::FAILURE;
        }

        $database = $app->database;
        $schema   = $database->schema();

        $only     = $input->getOption('table');
        $dryRun   = (bool) $input->getOption('dry-run');
        $hasTs    = $database->capabilities()->hasTimescaleDB();

        // Rolled-up views need a refresh policy on **every** backend, and the
        // backend without TimescaleDB is the one where they were left without
        // one — a materialized view that nothing refreshes is frozen at the
        // moment it was created. So this part runs first and runs everywhere,
        // before the hypertable work bows out.
        $aggregates = $this->ensureAggregates($output, $schema, $dryRun);

        if (!$hasTs) {
            $output->writeln('');
            $output->writeln(
                '<comment>TimescaleDB is not available on this connection ('
                . $database->type . ').</comment>'
            );
            $output->writeln(
                'No hypertable was touched. On this backend both compression and '
                . 'retention are the policy engine\'s job (service:policy-engine).'
            );

            return $aggregates ? Command::SUCCESS : Command::FAILURE;
        }

        $tables = HypertableRegistry::all();

        if (is_string($only) && $only !== '') {
            if (!isset($tables[$only])) {
                $output->writeln(
                    '<error>"' . $only . '" is not a declared hypertable.</error>'
                );
                $output->writeln('Declared: ' . implode(', ', array_keys($tables)));

                return Command::FAILURE;
            }
            $tables = [$only => $tables[$only]];
        }

        $plan = [];
        foreach ($tables as $table => $spec) {
            $plan[$table] = $this->inspect($schema, $database, $table, $spec);
        }

        return $dryRun
            ? $this->report($output, $plan)
            : $this->repair($output, $schema, $plan);
    }

    /**
     * Make sure every declared rolled-up view is being refreshed.
     *
     * Runs on both backends, because the defect it repairs belongs to the one
     * without TimescaleDB: four migrations registered the refresh only inside
     * their TimescaleDB branch, so on plain PostgreSQL the materialized view was
     * created and then never updated again.
     *
     * @param  \Pramnos\Database\SchemaBuilder $schema
     * @return bool Whether everything that could be done was done
     */
    protected function ensureAggregates(OutputInterface $output, $schema, bool $dryRun): bool
    {
        $pending = [];

        foreach (ContinuousAggregateRegistry::all() as $view => $spec) {
            if (!$schema->hasView($view)) {
                continue;   // this installation does not have that feature
            }
            if ($schema->hasContinuousAggregatePolicy($view)) {
                continue;
            }
            $pending[$view] = $spec;
        }

        if ($pending === []) {
            $output->writeln('<info>Rolled-up views: every one is being refreshed.</info>');

            return true;
        }

        if ($dryRun) {
            $output->writeln('<comment>Rolled-up views with no refresh policy:</comment>');
            foreach ($pending as $view => $spec) {
                $output->writeln(
                    '  ' . $view . ' — would refresh every ' . $spec['schedule_interval']
                );
            }
            $output->writeln(
                '  <comment>Until one is added these views answer with the data they '
                . 'held when they were created.</comment>'
            );

            return true;
        }

        $ok = true;
        foreach ($pending as $view => $spec) {
            try {
                foreach (ContinuousAggregateRegistry::apply($schema, $view) as $step) {
                    $output->writeln('  <info>✓</info> ' . $view . ': ' . $step);
                }
            } catch (\Throwable $ex) {
                $ok = false;
                $output->writeln('<error>' . $view . ': ' . $ex->getMessage() . '</error>');
            }
        }

        return $ok;
    }

    // -------------------------------------------------------------------------
    // Inspection
    // -------------------------------------------------------------------------

    /**
     * Work out the state of one declared table without changing it.
     *
     * @param  \Pramnos\Database\SchemaBuilder $schema
     * @param  \Pramnos\Database\Database      $database
     * @param  string                          $table
     * @param  array<string, mixed>            $spec
     * @return array<string, mixed> {
     *     @type bool        $exists     Whether the table is there at all
     *     @type bool        $hypertable Whether it is already partitioned
     *     @type list<string> $missing   The steps that are not yet done
     *     @type int|null    $rows       Row count, only when a conversion is pending
     *     @type string|null $blocker    Why it cannot be repaired, if it cannot
     * }
     */
    protected function inspect($schema, $database, string $table, array $spec): array
    {
        $state = [
            'exists'     => false,
            'hypertable' => false,
            'missing'    => [],
            'rows'       => null,
            'blocker'    => null,
        ];

        if (!$schema->hasTable($table)) {
            return $state;
        }
        $state['exists'] = true;

        $state['hypertable'] = $schema->hasHypertable($table);

        if (!$state['hypertable']) {
            $state['missing'][] = 'convert';

            // Converting rewrites the table, so the operator wants the size
            // before deciding when to run it — not after taking the lock.
            $state['rows'] = $this->countRows($database, $schema, $table);

            // TimescaleDB requires the partitioning column in every unique
            // constraint. These tables are created with a composite key of
            // (id, <time column>) unconditionally, so this should always hold —
            // which is exactly why it is worth checking rather than assuming.
            $key = $schema->primaryKeyColumns($table);
            if ($key !== [] && !in_array((string) $spec['time_column'], $key, true)) {
                $state['blocker'] = 'primary key (' . implode(', ', $key)
                    . ') does not include the partitioning column "'
                    . $spec['time_column'] . '"';
            }
        }

        if (!$schema->isCompressionEnabled($table)) {
            $state['missing'][] = 'compression';
        }
        // Presence *and* the interval. Checking only presence is why a changed
        // declaration used to be invisible here: the command reported nothing missing,
        // changed nothing, and exited successfully, while the number in the code and the
        // number in the database disagreed for ever — and the code is the one people
        // read.
        if ($spec['compress_after'] !== null) {
            if (!$schema->hasCompressionPolicy($table)) {
                $state['missing'][] = 'compression policy';
            } else {
                $actual = $schema->policyInterval($table, 'compression');
                if ($actual !== null && !$this->sameInterval($actual, (string) $spec['compress_after'])) {
                    $state['missing'][] = 'compression policy (' . $actual
                        . ' → ' . $spec['compress_after'] . ')';
                }
            }
        }

        if ($spec['retention'] !== null) {
            if (!$schema->hasRetentionPolicy($table)) {
                $state['missing'][] = 'retention policy';
            } else {
                $actual = $schema->policyInterval($table, 'retention');
                if ($actual !== null && !$this->sameInterval($actual, (string) $spec['retention'])) {
                    $state['missing'][] = 'retention policy (' . $actual
                        . ' → ' . $spec['retention'] . ')';
                }
            }
        }

        return $state;
    }

    /**
     * Are two interval spellings the same duration?
     *
     * Says yes whenever it cannot tell, which keeps a formatting difference from being
     * reported as drift on every run. The registry applies the same rule when it decides
     * whether to replace a policy, so the report and the repair agree.
     */
    protected function sameInterval(string $actual, string $declared): bool
    {
        $normalise = static function (string $interval): string {
            $value = ltrim(strtolower(trim($interval)), '@ ');
            $value = preg_replace('/\s+/', ' ', $value) ?? '';

            return preg_match('/^(\d+)\s*(second|minute|hour|day|week|month|year)s?$/', $value, $m)
                ? $m[1] . ' ' . $m[2]
                : '';
        };

        $left  = $normalise($actual);
        $right = $normalise($declared);

        return $left === '' || $right === '' || $left === $right;
    }

    /**
     * How many rows a pending conversion would have to rewrite.
     *
     * A failure to count is not a failure to repair, so it degrades to null
     * rather than aborting the run.
     *
     * @param  \Pramnos\Database\Database      $database
     * @param  \Pramnos\Database\SchemaBuilder $schema
     * @param  string                          $table
     * @return int|null
     */
    protected function countRows($database, $schema, string $table): ?int
    {
        try {
            $result = $database->query(
                'SELECT COUNT(*) AS cnt FROM ' . $schema->quoteTable($table)
            );

            return $result ? (int) ($result->fields['cnt'] ?? 0) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    /**
     * Print the plan without executing it.
     *
     * @param  array<string, array<string, mixed>> $plan
     */
    protected function report(OutputInterface $output, array $plan): int
    {
        $table = new TableHelper($output);
        $table->setHeaders(['Table', 'State', 'Rows', 'Would do']);

        $conversions = 0;
        $rowsToMove  = 0;
        $blocked     = 0;

        foreach ($plan as $name => $state) {
            if (!$state['exists']) {
                $table->addRow([$name, 'absent', '—', 'nothing (table not in this database)']);
                continue;
            }

            if ($state['blocker'] !== null) {
                $blocked++;
                $table->addRow([$name, 'blocked', $state['rows'] ?? '—', $state['blocker']]);
                continue;
            }

            if ($state['missing'] === []) {
                $table->addRow([$name, 'ok', '—', 'nothing']);
                continue;
            }

            if (in_array('convert', $state['missing'], true)) {
                $conversions++;
                $rowsToMove += (int) ($state['rows'] ?? 0);
            }

            $table->addRow([
                $name,
                $state['hypertable'] ? 'partial' : 'plain table',
                $state['rows'] === null ? '—' : number_format($state['rows']),
                implode(', ', $state['missing']),
            ]);
        }

        $table->render();

        if ($conversions > 0) {
            $output->writeln('');
            $output->writeln(
                '<comment>' . $conversions . ' table(s) would be converted, moving '
                . number_format($rowsToMove) . ' row(s).</comment>'
            );
            $output->writeln(
                'Conversion runs with <options=bold>migrate_data => true</>, which '
                . 'rewrites each table under an exclusive lock. On a years-old audit '
                . 'table that is not instant — plan for the write outage.'
            );
        }

        if ($blocked > 0) {
            $output->writeln('');
            $output->writeln(
                '<error>' . $blocked . ' table(s) cannot be converted as they stand. '
                . 'See the blocked rows above.</error>'
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Apply the plan.
     *
     * @param  \Pramnos\Database\SchemaBuilder     $schema
     * @param  array<string, array<string, mixed>> $plan
     */
    protected function repair(OutputInterface $output, $schema, array $plan): int
    {
        $changed = 0;
        $failed  = 0;

        foreach ($plan as $name => $state) {
            if (!$state['exists'] || $state['missing'] === []) {
                continue;
            }

            if ($state['blocker'] !== null) {
                $failed++;
                $output->writeln('<error>' . $name . ': ' . $state['blocker'] . '</error>');
                continue;
            }

            if (in_array('convert', $state['missing'], true)) {
                $output->writeln(
                    'Converting <info>' . $name . '</info>'
                    . ($state['rows'] === null
                        ? ''
                        : ' (' . number_format($state['rows']) . ' rows)')
                    . ' — this holds an exclusive lock until it finishes.'
                );
            }

            try {
                $done = HypertableRegistry::apply($schema, $name);
            } catch (\Throwable $ex) {
                $failed++;
                $output->writeln('<error>' . $name . ': ' . $ex->getMessage() . '</error>');
                continue;
            }

            if ($done !== []) {
                $changed++;
                foreach ($done as $step) {
                    $output->writeln('  <info>✓</info> ' . $name . ': ' . $step);
                }
            }
        }

        if ($changed === 0 && $failed === 0) {
            $output->writeln('<info>Nothing to do — every declared hypertable is configured.</info>');

            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln('<info>' . $changed . ' table(s) brought in line.</info>');

        if ($failed > 0) {
            $output->writeln('<error>' . $failed . ' table(s) failed.</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
