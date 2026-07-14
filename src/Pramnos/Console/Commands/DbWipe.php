<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Pramnos\Database\Database;

/**
 * Drops every table in the current database connection.
 *
 * This is a destructive command: it removes ALL tables (including the
 * migrations bookkeeping table). It supports both MySQL and PostgreSQL:
 *   - MySQL: foreign key checks are disabled, each table is dropped, then
 *     foreign key checks are restored.
 *   - PostgreSQL: each table is dropped with CASCADE to tear down dependent
 *     foreign keys / views.
 *
 * Usage examples:
 *   db:wipe                  (prompts for confirmation when interactive)
 *   db:wipe --force          (skip the confirmation prompt)
 */
class DbWipe extends Command
{
    /**
     * Configure command metadata and options.
     */
    protected function configure(): void
    {
        $this->setName('db:wipe')
            ->setDescription('Drop ALL tables in the current database (destructive)')
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Skip the confirmation prompt (required in non-interactive mode)'
            );
    }

    /**
     * Execute the db:wipe command.
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

        return $this->wipe($db, $output);
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
            '<question>This will DROP ALL TABLES in the database. Continue? [y/N]</question> ',
            false
        );

        return (bool) $helper->ask($input, $output, $question);
    }

    /**
     * Produces the exit code / message when confirmDestruction() returned false.
     *
     * Non-interactive without --force is a hard error (non-zero exit) so scripts
     * cannot silently drop a production database. An interactive "no" is a clean
     * abort (exit 0).
     */
    protected function guardExitCode(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('force') && !$input->isInteractive()) {
            $output->writeln('<error>Refusing to wipe the database: run with --force in non-interactive mode.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<comment>Aborted.</comment>');
        return Command::SUCCESS;
    }

    /**
     * Drops every table in the connection, handling MySQL and PostgreSQL.
     */
    private function wipe(Database $db, OutputInterface $output): int
    {
        $tables = $this->getTableNames($db);

        if (empty($tables)) {
            $output->writeln('<comment>No tables to drop.</comment>');
            return Command::SUCCESS;
        }

        $isPostgres = ($db->type === 'postgresql');

        if (!$isPostgres) {
            // MySQL: disable FK checks so tables can be dropped in any order.
            $db->execute('SET FOREIGN_KEY_CHECKS = 0');
        }

        $dropped = 0;
        $failed  = [];

        foreach ($tables as $table) {
            $sql = $this->buildDropStatement($db, $table, $isPostgres);
            try {
                if ($db->execute($sql) === false) {
                    $failed[$table] = $db->error_text ?? 'unknown error';
                    continue;
                }
                $output->writeln('<info>Dropped:</info> ' . $table);
                $dropped++;
            } catch (\Throwable $e) {
                $failed[$table] = $e->getMessage();
            }
        }

        if (!$isPostgres) {
            $db->execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        if (!empty($failed)) {
            foreach ($failed as $table => $error) {
                $output->writeln('<error>Failed to drop ' . $table . ': ' . $error . '</error>');
            }
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Wiped %d table(s).</info>', $dropped));
        return Command::SUCCESS;
    }

    /**
     * Returns all base table names in the current connection.
     *
     * Cross-DB: MySQL uses SHOW TABLES, PostgreSQL queries information_schema
     * for the active schema. Returned names are bare (already include any
     * configured table prefix, since they are the real names in the DB).
     *
     * @return string[]
     */
    private function getTableNames(Database $db): array
    {
        if ($db->type === 'postgresql') {
            $schema = $db->schema ?: 'public';
            $sql = "SELECT table_name FROM information_schema.tables "
                 . "WHERE table_schema = '" . addslashes($schema) . "'"
                 . " AND table_type = 'BASE TABLE' ORDER BY table_name";
        } else {
            $sql = 'SHOW TABLES';
        }

        $result = $db->query($sql);
        $names  = [];
        while ($result->fetch()) {
            $row = array_values($result->fields);
            if (!empty($row[0])) {
                $names[] = (string) $row[0];
            }
        }

        return $names;
    }

    /**
     * Builds a driver-specific DROP TABLE statement for a single table.
     */
    private function buildDropStatement(Database $db, string $table, bool $isPostgres): string
    {
        if ($isPostgres) {
            $schema = $db->schema ?: 'public';
            return 'DROP TABLE IF EXISTS "' . $schema . '"."' . $table . '" CASCADE';
        }

        return 'DROP TABLE IF EXISTS `' . $table . '`';
    }
}
