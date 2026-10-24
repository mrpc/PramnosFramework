<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Console\DaemonControls;
use Pramnos\Database\Database;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `db:resume-daemons` — bring back exactly what `db:migrate-from-mysql --pause-daemons` stopped
 * (FW-286).
 *
 * Never run automatically — an operational copy with `--pause-daemons` stops the worker pools
 * and scheduler and leaves them stopped, on purpose: resuming is a decision for whoever just
 * verified the application on PostgreSQL, not something a successful cutover should do by
 * itself.
 *
 * Only a pool or service marked `'migration'` by that same flag is touched. One an operator had
 * already stopped for their own reason, before or during the migration, is left exactly as it
 * was — this is not a blanket "start everything" command.
 *
 * ```bash
 * php bin/pramnos db:resume-daemons
 * ```
 */
class DatabaseResumeDaemons extends Command
{
    protected function configure(): void
    {
        $this->setName('db:resume-daemons')
            ->setDescription('Resume the daemon pool(s) and scheduler a migration paused with --pause-daemons');
    }

    /** The application the command runs for; a method, so a test can hand it one. */
    protected function application(): ?\Pramnos\Application\Application
    {
        $console = $this->getApplication();

        return $console !== null && property_exists($console, 'internalApplication') ? $console->internalApplication : \Pramnos\Application\Application::currentInstance();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $database = $this->application()?->database;
        if (!$database instanceof Database) {
            $output->writeln('<error>No database configured.</error>');

            return Command::FAILURE;
        }

        $controls = new DaemonControls($database);
        $resumed  = [];
        try {
            foreach ($controls->pools() as $name => $pool) {
                if (!$pool['enabled'] && $pool['updated_by'] === 'migration') {
                    $controls->setPoolEnabled($name, true, 'migration-resume');
                    $resumed[] = $name;
                }
            }
            $scheduler = $controls->stoppedServices()['schedule'] ?? null;
            if ($scheduler !== null && $scheduler['stopped_by'] === 'migration') {
                $controls->startService('schedule');
                $resumed[] = 'schedule (scheduler)';
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not resume: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($resumed === []) {
            $output->writeln('Nothing paused by a migration was found to resume.');

            return Command::SUCCESS;
        }
        $output->writeln('<info>Resumed:</info> ' . implode(', ', $resumed));

        return Command::SUCCESS;
    }
}
