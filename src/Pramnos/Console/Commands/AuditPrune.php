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
 * `'audit' => ['retention_days' => N]` in app.php, or one per event (`['admin.page' => 180,
 * '*' => 730]`) — because how long an audit trail is kept is a
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

    /**
     * The configured retention: days for everything, or days per event (`'admin.page' => 180,
     * '*' => 730`); 0 or [] for none.
     *
     * @return int|array<string, int>
     */
    protected function configuredDays(): int|array
    {
        $app  = \Pramnos\Application\Application::currentInstance();
        $days = is_object($app) ? ($app->applicationInfo['audit']['retention_days'] ?? 0) : 0;
        if (is_array($days)) {
            return array_filter(array_map('intval', $days), static fn (int $d): bool => $d > 0);
        }

        return max(0, (int) $days);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days') !== null ? (int) $input->getOption('days') : $this->configuredDays();
        if ($days === 0 || $days === [] || (is_int($days) && $days < 0)) {
            $output->writeln('No audit retention is configured; the audit trail is kept whole.');

            return Command::SUCCESS;
        }
        $deleted = AuditLog::prune($days);
        if ($deleted === null) {
            $output->writeln('No audit log here; nothing to prune.');

            return Command::SUCCESS;
        }
        $kept = is_int($days) ? $days . ' days' : implode(', ', array_map(static fn ($event, $d): string => $event . ' ' . $d . ' days', array_keys($days), $days));
        $output->writeln('Kept the last ' . $kept . ' of the audit trail; ' . $deleted . ' older rows deleted.');

        return Command::SUCCESS;
    }
}
