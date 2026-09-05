<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Pramnos\Application\Application;

/**
 * Takes the site out of maintenance mode — if a person put it there.
 *
 * It refuses to clear a flag the framework raised for its own work. A flag a
 * migration raised means a schema is in flux and something is either still
 * running or died half way; clearing it because you meant to clear your own is
 * the mistake this refusal exists to prevent. `--force` is there for the second
 * case, where the answer is «yes, I know, that batch is not coming back».
 *
 * Usage:
 *   maintenance:off
 *   maintenance:off --force
 */
class MaintenanceOff extends Command
{
    protected function configure(): void
    {
        $this->setName('maintenance:off')
            ->setDescription('Take the site out of maintenance mode')
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Clear the flag even when the framework raised it'
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

        $origin = $app->maintenanceOrigin();

        if ($origin === '') {
            $output->writeln('<info>Maintenance mode is already off.</info>');

            return 0;
        }

        if ($origin !== Application::MAINTENANCE_MANUAL && !$input->getOption('force')) {
            $output->writeln(sprintf(
                '<error>This flag was not raised by hand (origin: %s).</error>',
                $origin
            ));
            $output->writeln('');
            $output->writeln(
                $origin === Application::MAINTENANCE_AUTOMATIC
                    ? ' The framework raised it for work of its own. Either that work is'
                        . ' still running, or it died and left this behind.'
                    : ' It predates the origin being recorded, so there is no way to tell'
                        . ' who raised it.'
            );
            $output->writeln(' Check first, then <info>maintenance:off --force</info>.');

            return 1;
        }

        $app->stopMaintenance();

        if ($app->maintenanceOrigin() !== '') {
            $output->writeln(
                '<error>The flag is still there. Check that var/MAINTENANCE is writable.</error>'
            );

            return 1;
        }

        $output->writeln('<info>Maintenance mode is off.</info>');
        if ($origin !== Application::MAINTENANCE_MANUAL) {
            $output->writeln(
                ' <comment>Forced: the flag had origin ' . $origin . '.</comment>'
            );
        }

        return 0;
    }
}
