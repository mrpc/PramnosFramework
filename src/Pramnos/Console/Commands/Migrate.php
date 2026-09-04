<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;

/**
 * Runs pending database migrations.
 *
 * Usage examples:
 *   migrate
 *   migrate --scope=framework
 *   migrate --feature=auth
 *   migrate create_users_table
 *   migrate --force
 *   migrate --cutoff=2022_01_01_000000
 *   migrate --path=/custom/migrations/dir
 */
class Migrate extends Command
{
    /**
     * Configure command metadata, arguments, and options.
     */
    protected function configure(): void
    {
        $this->setName('migrate')
            ->setDescription('Run pending database migrations')
            ->addArgument(
                'migration',
                InputArgument::OPTIONAL,
                'Run a single migration by slug or class name'
            )
            ->addOption(
                'scope',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter migrations by scope (app / framework)'
            )
            ->addOption(
                'feature',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter migrations by feature key (e.g. auth, queue)'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Include autorun=false migrations'
            )
            ->addOption(
                'cutoff',
                null,
                InputOption::VALUE_REQUIRED,
                'Skip migrations whose timestamp is at or before this value (YYYY_MM_DD_HHmmss)'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to migrations directory (default: app/Migrations)'
            );
    }

    /**
     * Execute the migrate command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $consoleApp = $this->getApplication();
        if (!($consoleApp instanceof \Pramnos\Console\Application)) {
            $output->writeln('<error>This command must run within the Pramnos console application.</error>');
            return 1;
        }

        $app = $consoleApp->internalApplication;
        $db  = $app->database ?? null;

        if ($db === null) {
            $output->writeln('<error>No database connection available.</error>');
            return 1;
        }

        // Which migrations apply here is the application's answer, not this
        // command's: the `features` gate and app.php's `migration_cutoff`. Read
        // it before --path/--cutoff, both of which still override it below —
        // that is how somebody runs one directory, or one epoch, deliberately.
        $migrationScope = MigrationLoader::scopeFor($app, true);

        $explicitPath = $input->getOption('path');
        $dirs         = $explicitPath ? [$explicitPath] : $migrationScope['dirs'];
        $migrations   = MigrationLoader::loadFromDirectories($dirs, $app);

        if (empty($migrations)) {
            $output->writeln('<comment>No migrations found.</comment>');
            return 0;
        }

        // Scope filter
        if ($scope = $input->getOption('scope')) {
            $migrations = array_values(array_filter(
                $migrations,
                fn(Migration $m) => $m->scope === $scope
            ));
        }

        // Feature filter
        if ($feature = $input->getOption('feature')) {
            $migrations = array_values(array_filter(
                $migrations,
                fn(Migration $m) => $m->feature === $feature
            ));
        }

        // Single-migration filter by slug or class name
        if ($name = $input->getArgument('migration')) {
            $migrations = array_values(array_filter(
                $migrations,
                fn(Migration $m) => $m->getSlug() === $name || (new \ReflectionClass($m))->getShortName() === $name
            ));
            if (empty($migrations)) {
                $output->writeln('<error>Migration not found: ' . $name . '</error>');
                return 1;
            }
        }

        $options = [];
        if ($input->getOption('force')) {
            $options['force'] = true;
        }
        // --cutoff wins; otherwise the configured one applies. Without this the
        // CLI attempted the whole baseline epoch on every installation whose
        // app.php exists precisely to skip it.
        $cutoff = $input->getOption('cutoff') ?: $migrationScope['cutoff'];
        if ($cutoff !== '') {
            $options['cutoff'] = $cutoff;
        }

        $runner = new MigrationRunner($db);
        // Allow a migration in the selected set to depend on a framework migration
        // that isn't part of that set (e.g. `migrate --path=app/Migrations` where an
        // app migration pulls in the framework table it needs). Loaded on demand.
        if (method_exists($app, 'frameworkMigrationPool')) {
            $runner->setDependencyPool(fn(): array => $app->frameworkMigrationPool());
        }
        $result = $runner->run($migrations, $options, function (string $event, string $slug, string $error) use ($output): void {
            if ($event === 'ran') {
                $output->writeln('<info>Migrated:</info>   ' . $slug);
            } elseif ($event === 'declined') {
                // It looked at the data and refused. Not a failure, and not a
                // success either — and it will be attempted again next time.
                $output->writeln('<comment>Declined:</comment>   ' . $slug);
                $output->writeln('           <comment>' . strtok(trim($error), "\n") . '</comment>');
            } elseif ($event === 'ran_with_errors') {
                // It completed, so it is not a failure — but it is not a success
                // either, and saying "Migrated" would be the lie this exists to
                // stop telling.
                $output->writeln('<comment>Migrated*:</comment>  ' . $slug);
                $output->writeln('           <comment>' . strtok(trim($error), "\n") . '</comment>');
            } else {
                $output->writeln('<error>Failed:  </error>   ' . $slug);
                // Print the first line of the error inline (full detail in summary)
                $firstLine = strtok(trim($error), "\n");
                $output->writeln('           <comment>' . $firstLine . '</comment>');
            }
        });

        // A run whose only outcome was a decline has something to say, so the
        // emptiness check has to know about it or it prints «Nothing to migrate»
        // over the reason.
        if (empty($result['ran'])
            && empty($result['failed'])
            && empty($result['declined'])
        ) {
            $output->writeln('<info>Nothing to migrate.</info>');
            return 0;
        }

        $this->printSummary($output, $db, $input, $dirs, $result, $migrationScope['cutoff']);

        return empty($result['failed']) ? 0 : 1;
    }

    /**
     * Prints a summary block with totals, context, and full error details.
     *
     * @param array{ran: string[], failed: array<string,string>} $result
     * @param string[] $dirs
     * @param string   $configuredCutoff app.php's cutoff, reported when --cutoff is absent
     */
    private function printSummary(
        OutputInterface $output,
        Database        $db,
        InputInterface  $input,
        array           $dirs,
        array           $result,
        string          $configuredCutoff = ''
    ): void {
        $sep = str_repeat('─', 62);
        $output->writeln('');
        $output->writeln('<comment>' . $sep . '</comment>');

        $ranCount    = count($result['ran']);
        $failedCount = count($result['failed']);
        $warned      = $result['warned'] ?? [];
        $warnedCount = count($warned);
        $declined    = $result['declined'] ?? [];

        $line = sprintf('<info> ✓  %d migrated</info>', $ranCount);
        if ($warnedCount > 0) {
            $line .= sprintf('   <comment> *  %d with rejected statements </comment>', $warnedCount);
        }
        if (!empty($declined)) {
            $line .= sprintf('   <comment> ⏭  %d declined </comment>', count($declined));
        }
        if ($failedCount > 0) {
            $line .= sprintf('   <error> ✗  %d failed </error>', $failedCount);
        }
        $output->writeln($line);

        $output->writeln('');

        // Context
        $output->writeln(' <comment>Database:</comment>   ' . $this->formatDbType($db));

        if ($scope = $input->getOption('scope')) {
            $output->writeln(' <comment>Scope:</comment>      ' . $scope);
        }
        if ($feature = $input->getOption('feature')) {
            $output->writeln(' <comment>Feature:</comment>    ' . $feature);
        }
        if ($name = $input->getArgument('migration')) {
            $output->writeln(' <comment>Migration:</comment>  ' . $name);
        }
        if ($cutoff = $input->getOption('cutoff')) {
            $output->writeln(' <comment>Cutoff:</comment>     ' . $cutoff);
        } elseif ($configuredCutoff !== '') {
            $output->writeln(
                ' <comment>Cutoff:</comment>     ' . $configuredCutoff . ' <comment>(app.php)</comment>'
            );
        }

        $output->writeln(' <comment>Dirs:</comment>       ' . array_shift($dirs));
        foreach ($dirs as $dir) {
            $output->writeln('             ' . $dir);
        }

        // Declines, with the reason and what would clear it. This block is the
        // whole point of the state: a guard that refused and said nothing would
        // leave the schema wrong with nothing to read.
        if (!empty($declined)) {
            $output->writeln('');
            $output->writeln('<comment>' . $sep . '</comment>');
            $output->writeln('<comment> Declined — the data is not ready for these            </comment>');
            $output->writeln('<comment>' . $sep . '</comment>');
            foreach ($declined as $slug => $reason) {
                $output->writeln(' <comment>⏭</comment> <options=bold>' . $slug . '</>');
                foreach (explode("\n", trim($reason)) as $detailLine) {
                    if (trim($detailLine) !== '') {
                        $output->writeln('   <comment>' . $detailLine . '</comment>');
                    }
                }
                $output->writeln('');
            }
            $output->writeln(
                ' <comment>These stay pending: repair the rows and run'
                . ' <info>migrate</info> again.</comment>'
            );
            $output->writeln('');
        }

        // Rejected-statement details. Printed in full here because the
        // alternative is var/logs/upgradeerrors.log, which is where this hid.
        if (!empty($warned)) {
            $output->writeln('');
            $output->writeln('<comment>' . $sep . '</comment>');
            $output->writeln('<comment> Completed, but the database rejected statements          </comment>');
            $output->writeln('<comment>' . $sep . '</comment>');
            foreach ($warned as $slug => $message) {
                $output->writeln(' <comment>*</comment> <options=bold>' . $slug . '</>');
                foreach (explode("\n", trim($message)) as $detailLine) {
                    if (trim($detailLine) !== '') {
                        $output->writeln('   <comment>' . $detailLine . '</comment>');
                    }
                }
                $output->writeln('');
            }
        }

        // Failed details
        if (!empty($result['failed'])) {
            $output->writeln('');
            $output->writeln('<comment>' . $sep . '</comment>');
            $output->writeln('<error> Failed migrations                                                </error>');
            $output->writeln('<comment>' . $sep . '</comment>');
            foreach ($result['failed'] as $slug => $errorMessage) {
                $output->writeln(' <error>✗</error> <options=bold>' . $slug . '</>');
                foreach (explode("\n", trim($errorMessage)) as $line) {
                    if (trim($line) !== '') {
                        $output->writeln('   <comment>' . $line . '</comment>');
                    }
                }
                $output->writeln('');
            }
        }

        $output->writeln('<comment>' . $sep . '</comment>');
    }

    /**
     * Returns a human-readable database type string, including TimescaleDB if detected.
     */
    private function formatDbType(Database $db): string
    {
        $label = match ($db->type) {
            'postgresql' => 'PostgreSQL',
            'mysql'      => 'MySQL',
            default      => ucfirst($db->type),
        };

        try {
            if ($db->schema()->getCapabilities()->hasTimescaleDB()) {
                $label .= ' · TimescaleDB';
            }
        } catch (\Throwable) {
            // Progress reporting only — the migration itself already ran.
        }

        return $label;
    }

}
