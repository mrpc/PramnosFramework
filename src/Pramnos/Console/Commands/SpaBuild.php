<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * spa:build — build the front end for production.
 *
 * A shortcut for `./dockernpm run build`, in the CLI the rest of the project
 * already uses.
 *
 *   php bin/pramnos spa:build
 *   php bin/pramnos spa:build --watch    # rebuild on change, no dev server
 *
 * The output goes to `www/assets/spa/` with content-hashed filenames, which the
 * shell reads from the build manifest — that hash *is* the cache-buster, so a
 * deploy needs no extra step. The directory is generated: never edited, never
 * committed.
 *
 * `--watch` exists for the case the dev server does not cover: a page that must
 * be served by the real application from built files (a hard reload test, a
 * cache header, something behind a proxy) while you keep changing the source.
 */
class SpaBuild extends SpaCommandBase
{
    protected function configure(): void
    {
        $this->setName('spa:build')
            ->setDescription('Build the SPA front end for production (npm run build, inside the container)')
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Rebuild on every change instead of building once')
            ->setHelp(
                "Builds this project's SPA into www/assets/spa/.\n\n"
                . "That directory is generated: never edit it and never commit it. The\n"
                . "filenames carry a content hash, which is what busts the cache on deploy."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->requireBuildStack($output)) {
            return Command::FAILURE;
        }

        $watch = (bool) $input->getOption('watch');

        $output->writeln($watch
            ? '<info>Building the SPA, and rebuilding on every change…</info>'
            : '<info>Building the SPA for production…</info>');
        $output->writeln('  Output: <comment>www/assets/spa/</comment> (generated — do not edit or commit)');
        if ($watch) {
            $output->writeln('  Stop it with Ctrl-C.');
        }
        $output->writeln('');

        // `--` keeps the flag for Vite rather than npm, which would swallow it.
        return $this->npm($watch ? 'run build -- --watch' : 'run build', $output);
    }
}
