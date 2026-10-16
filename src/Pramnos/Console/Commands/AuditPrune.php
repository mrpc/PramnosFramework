<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Auth\AuditLog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete audit-log rows older than the retention an installation chose.
 *
 * Scheduled daily by the framework, and a no-op until a retention is set — `--days`, or
 * `'audit' => ['retention_days' => N]` in app.php — because how long an audit trail is kept is a
 * decision of law or policy, and a deletion nobody asked for is the one thing an audit trail must
 * not do.
 */
class AuditPrune extends Command
{
    protected function configure(): void
    {
        $this->setName('audit:prune')
            ->setDescription('Delete audit-log rows older than the configured retention')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Days to keep (default: audit.retention_days in app.php; none = keep everything)');
    }

    /** The configured retention, in days; 0 for none. */
    protected function configuredDays(): int
    {
        $app = \Pramnos\Application\Application::currentInstance();

        return is_object($app) ? max(0, (int) ($app->applicationInfo['audit']['retention_days'] ?? 0)) : 0;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days') !== null ? (int) $input->getOption('days') : $this->configuredDays();
        if ($days <= 0) {
            $output->writeln('No audit retention is configured; the audit trail is kept whole.');

            return Command::SUCCESS;
        }
        $deleted = AuditLog::prune($days);
        if ($deleted === null) {
            $output->writeln('No audit log here; nothing to prune.');

            return Command::SUCCESS;
        }
        $output->writeln('Kept the last ' . $days . ' days of the audit trail; ' . $deleted . ' older rows deleted.');

        return Command::SUCCESS;
    }
}
