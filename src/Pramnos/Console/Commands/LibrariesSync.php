<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Application\LibraryManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * project:install — install front-end vendor libraries into an existing project,
 * without re-running `pramnos init`.
 *
 * Use cases:
 *  - Install additional libraries on demand (pass their keys as arguments).
 *  - A newly-mandatory framework library (e.g. Chart.js, required by the log
 *    analytics dashboard) needs to be added to a project scaffolded before it
 *    became mandatory.
 *  - A library's local assets were deleted or never downloaded (`--no-download`
 *    at init) and need to be fetched now.
 *
 * With no library arguments it installs the mandatory libraries plus every
 * library already registered in `src/Application.php`. Named libraries can be
 * passed as space-separated arguments (or via `--libraries=`). Assets are
 * downloaded into `www/assets/vendor/<lib>/<version>/` and, unless `--no-register`
 * is given, missing registrations are added to the application bootstrap so the
 * libraries are enqueue-able. Mandatory libraries are always ensured.
 *
 * Examples:
 *   ./pramnos project:install                     # top-up mandatory + registered libs
 *   ./pramnos project:install leaflet select2     # install specific libraries
 *   ./pramnos project:install --libraries=chartjs,leaflet
 *   ./pramnos project:install --list              # show install/registration status
 *   ./pramnos project:install --force             # re-download even if present
 *   ./pramnos project:install --no-register       # download only, don't touch Application.php
 */
class LibrariesSync extends Command
{
    /** Target project root. Overridable for testing. */
    public string $targetBaseDir = '';

    /** Injectable manager (overridable for testing). */
    public ?LibraryManager $manager = null;

    protected function configure(): void
    {
        $this
            ->setName('project:install')
            ->setDescription('Install front-end vendor libraries into an existing project')
            ->addArgument('libraries', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Library keys to install (space-separated). Omit to top-up mandatory + already-registered.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-download assets even if they already exist')
            ->addOption('no-register', null, InputOption::VALUE_NONE, 'Only download assets; do not edit src/Application.php')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List catalog libraries with their install/registration status and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baseDir = $this->targetBaseDir ?: (defined('ROOT') ? ROOT : (string) getcwd());
        $wwwDir  = $baseDir . DIRECTORY_SEPARATOR . 'www';
        $appFile = $baseDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Application.php';

        $manager    = $this->manager ?? new LibraryManager();
        $registered = $manager->detectRegisteredHandles($appFile);
        $mandatory  = $manager->mandatoryKeys();

        // ── --list: report status and exit ───────────────────────────────────
        if ($input->getOption('list')) {
            $output->writeln('<info>Vendor library status:</info>');
            foreach ($manager->availableKeys() as $key) {
                $installed = $manager->isInstalled($key, $wwwDir) ? 'installed' : 'missing';
                $reg       = in_array($key, $registered, true) ? 'registered' : 'not registered';
                $flag      = in_array($key, $mandatory, true) ? ' <comment>(mandatory)</comment>' : '';
                $output->writeln(sprintf('  %-18s %-10s %s%s', $key, $installed, $reg, $flag));
            }
            return Command::SUCCESS;
        }

        // ── Determine target libraries ────────────────────────────────────────
        $libArg = (array) $input->getArgument('libraries');
        if ($libArg !== []) {
            $targets = array_values(array_filter(array_map('trim', $libArg)));
        } else {
            // Default: mandatory libraries plus whatever the app already registers.
            $targets = array_merge($mandatory, $registered);
        }
        // Mandatory libraries are always included, whatever was requested.
        $targets = array_values(array_unique(array_merge($targets, $mandatory)));

        $force        = (bool) $input->getOption('force');
        $skipRegister = (bool) $input->getOption('no-register');

        $installedCount = 0;
        $presentCount   = 0;
        $failed         = [];
        $toRegister     = [];

        foreach ($targets as $key) {
            if ($manager->libraryDef($key) === null) {
                $output->writeln("  <error>unknown</error>   $key (not in catalog)");
                continue;
            }

            $result = $manager->install($key, $wwwDir, $force);
            switch ($result['status']) {
                case 'installed':
                    $output->writeln("  <info>install</info>   $key");
                    $installedCount++;
                    break;
                case 'present':
                    $output->writeln("  <comment>present</comment>   $key" . ($force ? '' : ' (use --force to re-download)'));
                    $presentCount++;
                    break;
                case 'failed':
                    $output->writeln("  <error>failed</error>    $key");
                    $failed[] = $key;
                    break;
                default:
                    $output->writeln("  <error>unknown</error>   $key");
                    $failed[] = $key;
            }

            // Flag for registration if the asset is available but not yet registered.
            if ($result['status'] !== 'failed' && $result['status'] !== 'unknown'
                && !in_array($key, $registered, true)) {
                $toRegister[] = $key;
            }
        }

        // ── Register missing libraries in src/Application.php ─────────────────
        if (!$skipRegister && $toRegister !== []) {
            $result = $manager->registerInBootstrap($appFile, $toRegister);
            foreach ($result['registered'] as $key) {
                $output->writeln("  <info>register</info>  $key → src/Application.php");
            }
            if ($result['manual'] !== []) {
                $output->writeln('');
                $output->writeln("<comment>Could not auto-edit $appFile. Add these to Application::registerVendorLibraries():</comment>");
                foreach ($result['manual'] as $line) {
                    $output->writeln($line);
                }
            }
        } elseif ($skipRegister && $toRegister !== []) {
            $output->writeln('');
            $output->writeln('<comment>Not registered (--no-register). Add these to Application::registerVendorLibraries():</comment>');
            foreach ($toRegister as $key) {
                foreach ($manager->registrationLines($key) as $line) {
                    $output->writeln('    ' . $line);
                }
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done.</info> %d installed, %d already present%s.',
            $installedCount,
            $presentCount,
            $failed ? ', ' . count($failed) . ' failed' : ''
        ));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
