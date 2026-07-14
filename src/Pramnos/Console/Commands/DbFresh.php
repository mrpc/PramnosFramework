<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Drops every table in the current database, then runs all migrations.
 *
 * This is the "clean slate" workflow: it wipes the schema (delegating to the
 * db:wipe command) and then re-applies every migration by delegating to the
 * migrate command, so the migration logic is never duplicated here. With
 * --seed it also runs db:seed afterwards.
 *
 * Usage examples:
 *   db:fresh                 (prompts for confirmation when interactive)
 *   db:fresh --force         (skip the confirmation prompt)
 *   db:fresh --force --seed  (wipe, migrate, then seed)
 */
class DbFresh extends Command
{
    /**
     * Configure command metadata and options.
     */
    protected function configure(): void
    {
        $this->setName('db:fresh')
            ->setDescription('Drop ALL tables and re-run every migration (destructive)')
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Skip the confirmation prompt (required in non-interactive mode)'
            )
            ->addOption(
                'seed',
                null,
                InputOption::VALUE_NONE,
                'Run database seeders after migrating'
            );
    }

    /**
     * Execute the db:fresh command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Safety guard first: never touch the database (or even resolve the
        // connection) until the destructive operation has been authorised.
        if (!$this->confirmDestruction($input, $output)) {
            return $this->guardExitCode($input, $output);
        }

        $consoleApp = $this->getApplication();
        if (!($consoleApp instanceof \Pramnos\Console\Application)) {
            $output->writeln('<error>This command must run within the Pramnos console application.</error>');
            return Command::FAILURE;
        }

        $app = $consoleApp->internalApplication;
        $db  = $app->database ?? null;

        if ($db === null) {
            $output->writeln('<error>No database connection available.</error>');
            return Command::FAILURE;
        }

        // 1. Wipe — delegate to db:wipe with --force (we already confirmed above).
        $output->writeln('<comment>Wiping database...</comment>');
        $wipeCode = $this->getApplication()
            ->find('db:wipe')
            ->run(new ArrayInput(['--force' => true]), $output);
        if ($wipeCode !== Command::SUCCESS) {
            $output->writeln('<error>Wipe failed; aborting fresh.</error>');
            return $wipeCode;
        }

        // 2. Migrate — reuse the migrate command so migration logic is shared.
        $output->writeln('<comment>Running migrations...</comment>');
        $migrateCode = $this->getApplication()
            ->find('migrate')
            ->run(new ArrayInput([]), $output);
        if ($migrateCode !== Command::SUCCESS) {
            $output->writeln('<error>Migrations failed.</error>');
            return $migrateCode;
        }

        // 3. Optionally seed — delegate to db:seed.
        if ($input->getOption('seed')) {
            $output->writeln('<comment>Seeding database...</comment>');
            $seedCode = $this->getApplication()
                ->find('db:seed')
                ->run(new ArrayInput([]), $output);
            if ($seedCode !== Command::SUCCESS) {
                $output->writeln('<error>Seeding failed.</error>');
                return $seedCode;
            }
        }

        $output->writeln('<info>Fresh complete.</info>');
        return Command::SUCCESS;
    }

    /**
     * Decides whether the destructive operation is allowed to proceed.
     *
     * Returns true when it is safe to continue (either --force was given, or the
     * user confirmed the interactive prompt). Returns false when the operation
     * must be aborted; the caller then uses guardExitCode() for the exit status.
     */
    protected function confirmDestruction(InputInterface $input, OutputInterface $output): bool
    {
        if ($input->getOption('force')) {
            return true;
        }

        // In non-interactive mode we refuse without --force (see guardExitCode()).
        if (!$input->isInteractive()) {
            return false;
        }

        $helper   = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            '<question>This will DROP ALL TABLES and re-run every migration. Continue? [y/N]</question> ',
            false
        );

        return (bool) $helper->ask($input, $output, $question);
    }

    /**
     * Produces the exit code / message when confirmDestruction() returned false.
     *
     * Non-interactive without --force is a hard error (non-zero exit) so scripts
     * cannot silently rebuild a production database. An interactive "no" is a
     * clean abort (exit 0).
     */
    protected function guardExitCode(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('force') && !$input->isInteractive()) {
            $output->writeln('<error>Refusing to rebuild the database: run with --force in non-interactive mode.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<comment>Aborted.</comment>');
        return Command::SUCCESS;
    }
}
