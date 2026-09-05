<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Application\Application;

/**
 * Puts the site into maintenance mode, deliberately.
 *
 * Requests are answered with a 503 and `Retry-After` rather than served, and
 * **automatic migrations stand down** — which is the point when the reason for
 * raising it is a heavy migration you intend to run by hand. The console keeps
 * working: it is not traffic.
 *
 * Usage:
 *   maintenance:on
 *   maintenance:on --reason="Adding an index to usertokens, ~20 minutes"
 */
class MaintenanceOn extends Command
{
    protected function configure(): void
    {
        $this->setName('maintenance:on')
            ->setDescription('Put the site into maintenance mode')
            ->addOption(
                'reason',
                null,
                InputOption::VALUE_REQUIRED,
                'Shown on the maintenance page and written into the flag'
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
        if (!$app instanceof Application) {
            $output->writeln('<error>No Pramnos application available.</error>');
            return 1;
        }

        $existing = $app->maintenanceOrigin();

        if ($existing !== '') {
            // startMaintenance() returns early rather than overwriting, so saying
            // «already on» is the honest report — and naming who raised it matters,
            // because that decides whether maintenance:off will take it down.
            $output->writeln(sprintf(
                '<comment>Maintenance mode is already on (raised: %s).</comment>',
                $existing
            ));
            if ($existing !== Application::MAINTENANCE_MANUAL) {
                $output->writeln(
                    '<comment>It was not raised by hand, so <info>maintenance:off</info>'
                    . ' will not take it down.</comment>'
                );
            }

            return 0;
        }

        $reason = (string) ($input->getOption('reason') ?? '');
        $app->startMaintenance($reason, Application::MAINTENANCE_MANUAL);

        if ($app->maintenanceOrigin() === '') {
            $output->writeln(
                '<error>The flag could not be written. Check that '
                . 'var/ exists and is writable.</error>'
            );

            return 1;
        }

        $output->writeln('<info>Maintenance mode is on.</info>');
        if ($reason !== '') {
            $output->writeln(' <comment>Reason:</comment> ' . $reason);
        }
        $output->writeln('');
        $output->writeln(' Requests are answered with 503. Automatic migrations will not run.');
        $output->writeln(' The console still works, so <info>migrate</info> and the rest are available.');
        $output->writeln(' Take it down with <info>maintenance:off</info>.');

        return 0;
    }
}
