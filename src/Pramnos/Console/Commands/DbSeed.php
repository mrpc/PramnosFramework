<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Pramnos\Database\Seeder;

/**
 * Runs database seeders to populate tables with fake development data.
 *
 * Usage examples:
 *   db:seed                          Run all seeders in database/seeds/
 *   db:seed UsersSeeder              Run a single seeder by class name
 *   db:seed --path=/custom/seeds/    Run seeders from a custom directory
 *
 * Seeders must extend Pramnos\Database\Seeder and implement run().
 *
 */
class DbSeed extends Command
{
    protected function configure(): void
    {
        $this->setName('db:seed')
            ->setDescription('Seed the database with fake development data')
            ->addArgument(
                'seeder',
                InputArgument::OPTIONAL,
                'Seeder class name to run (e.g. UsersSeeder). Runs all if omitted.'
            )
            ->addOption(
                'path',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to seeders directory (default: database/seeds)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $consoleApp = $this->getApplication();
        if (!($consoleApp instanceof \Pramnos\Console\Application)) {
            $output->writeln('<error>This command must run within the Pramnos console application.</error>');
            return Command::FAILURE;
        }

        $seedsPath = $input->getOption('path') ?? $this->defaultSeedsPath();

        if (!is_dir($seedsPath)) {
            $output->writeln('<comment>Seeds directory not found: ' . $seedsPath . '</comment>');
            return Command::SUCCESS;
        }

        $target = $input->getArgument('seeder');

        $seeders = $this->loadSeeders($seedsPath, $target);

        if (empty($seeders)) {
            $msg = $target
                ? '<error>Seeder not found: ' . $target . '</error>'
                : '<comment>No seeders found in: ' . $seedsPath . '</comment>';
            $output->writeln($msg);
            return $target ? Command::FAILURE : Command::SUCCESS;
        }

        $ran  = [];
        $failed = [];

        foreach ($seeders as $class => $file) {
            require_once $file;

            if (!class_exists($class)) {
                $output->writeln('<error>Class ' . $class . ' not found in ' . $file . '</error>');
                $failed[] = $class;
                continue;
            }

            $seeder = new $class();

            if (!($seeder instanceof Seeder)) {
                $output->writeln('<error>' . $class . ' does not extend Pramnos\Database\Seeder</error>');
                $failed[] = $class;
                continue;
            }

            try {
                $seeder->run();
                $output->writeln('<info>Seeded:</info> ' . $class);
                $ran[] = $class;
            } catch (\Throwable $e) {
                $output->writeln('<error>Failed ' . $class . ': ' . $e->getMessage() . '</error>');
                $failed[] = $class;
            }
        }

        if (!empty($ran)) {
            $output->writeln('<info>' . count($ran) . ' seeder(s) ran successfully.</info>');
        }
        if (!empty($failed)) {
            $output->writeln('<error>' . count($failed) . ' seeder(s) failed.</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Scan the seeds directory and return [ClassName => filepath] entries.
     *
     * When $target is given, only the matching seeder is returned.
     *
     * @param string      $dir
     * @param string|null $target Class name to find (null = all)
     * @return array<string, string>  [ClassName => absoluteFilePath]
     */
    private function loadSeeders(string $dir, ?string $target): array
    {
        $result = [];

        foreach (new \DirectoryIterator($dir) as $file) {
            if ($file->isDot() || $file->getExtension() !== 'php') {
                continue;
            }

            // Derive class name from filename: UsersSeeder.php → UsersSeeder
            $class = $file->getBasename('.php');

            if ($target !== null && $class !== $target) {
                continue;
            }

            $result[$class] = $file->getPathname();
        }

        ksort($result);
        return $result;
    }

    /**
     * Default seeds directory: <project-root>/database/seeds/
     */
    private function defaultSeedsPath(): string
    {
        $root = defined('ROOT') ? ROOT : getcwd();
        return $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeds';
    }
}
