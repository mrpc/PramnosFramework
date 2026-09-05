<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\MigrationRunner;

/**
 * Records migrations the legacy version ledger says already ran.
 *
 * Both migration systems write to `schemaversion` and key it differently: the
 * legacy one stores a migration's `$version` (`0.010`), the runner stores its
 * slug (`migration0010`). An application that migrated for years through the old
 * path therefore has a full ledger the runner cannot read a row of —
 * `migrate:status` calls every one of those migrations pending, and `migrate`
 * would run them again against a schema that already has their changes.
 *
 * Usage:
 *   migrate:adopt-legacy --dry-run     # what would be recorded
 *   migrate:adopt-legacy               # record it
 */
class MigrateAdoptLegacy extends Command
{
    protected function configure(): void
    {
        $this->setName('migrate:adopt-legacy')
            ->setDescription(
                'Record migrations that the legacy version ledger says already ran'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be recorded and write nothing'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Migrations directory (default: the application-declared ones)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $consoleApp = $this->getApplication();
        if (!($consoleApp instanceof \Pramnos\Console\Application)) {
            $output->writeln('<error>This command must run within the Pramnos console application.</error>');
            return 1;
        }

        $app = $consoleApp->internalApplication;
        if (!$app instanceof \Pramnos\Application\Application) {
            $output->writeln('<error>No Pramnos application available.</error>');
            return 1;
        }

        $db = $app->database ?? null;
        if ($db === null) {
            $output->writeln('<error>No database connection available.</error>');
            return 1;
        }

        $explicitPath = $input->getOption('path');
        $dirs = $explicitPath
            ? [$explicitPath]
            : MigrationLoader::scopeFor($app, true)['dirs'];

        $migrations = MigrationLoader::loadFromDirectories($dirs, $app);
        $dryRun     = (bool) $input->getOption('dry-run');

        $adopted = (new MigrationRunner($db))->adoptLegacyVersions($migrations, $dryRun);

        if (empty($adopted)) {
            $output->writeln(
                '<info>Nothing to adopt: no migration carries a version this ledger'
                . ' already records.</info>'
            );

            return 0;
        }

        foreach ($adopted as $slug => $version) {
            $output->writeln(
                ($dryRun ? '<comment>Would adopt:</comment> ' : '<info>Adopted:</info>    ')
                . $slug . ' <comment>(legacy version ' . $version . ')</comment>'
            );
        }

        $output->writeln('');
        $output->writeln(sprintf(
            $dryRun
                ? '<comment>%d migration(s) would be recorded as already applied.'
                    . ' Nothing was written.</comment>'
                : '<info>%d migration(s) recorded as already applied. They were not'
                    . ' executed.</info>',
            count($adopted)
        ));

        return 0;
    }
}
