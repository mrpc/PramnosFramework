<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * spa:dev — start the front-end dev server, with HMR.
 *
 * A shortcut for `./dockernpm run dev`, so the command belongs to the same CLI
 * as everything else in the project and shows up in `pramnos list`.
 *
 *   php bin/pramnos spa:dev
 *   php bin/pramnos spa:serve      # alias
 *
 * It also says the one thing about this workflow that is not guessable: **do not
 * open the Vite port.** The dev server serves no HTML — while it runs it writes a
 * hot file that the application's own shell reads, and the shell then loads
 * modules from it. So you keep browsing the application URL and get HMR against
 * the real backend. Opening the Vite port yields nothing at all, which reads as a
 * broken dev server.
 */
class SpaDev extends SpaCommandBase
{
    protected function configure(): void
    {
        $this->setName('spa:dev')
            ->setAliases(['spa:serve'])
            ->setDescription('Start the SPA dev server with HMR (npm run dev, inside the container)')
            ->setHelp(
                "Starts the front-end dev server for this project's SPA.\n\n"
                . "Keep browsing the application URL, not the Vite port: the dev server\n"
                . "serves no HTML — it only supplies modules to this application's pages."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->requireBuildStack($output)) {
            return Command::FAILURE;
        }

        $url = $this->appUrl();
        $output->writeln('<info>Starting the SPA dev server…</info>');
        $output->writeln($url === ''
            // No URL to offer, so the instruction has to carry itself.
            ? '  Browse the <comment>application</comment> URL, not the Vite port — the dev server serves no HTML.'
            : '  Browse <comment>' . $url . '</comment> — not the Vite port, which serves no HTML.');
        $output->writeln('  Stop it with Ctrl-C.');
        $output->writeln('');

        return $this->npm('run dev', $output);
    }
}
