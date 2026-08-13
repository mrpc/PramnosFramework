<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Add a SPA front end to an application that already exists.
 *
 * The case `init` cannot serve. The SPA was only reachable through a full `init`,
 * which refuses to run in a project that already has one (and, before that guard,
 * would have overwritten `app/app.php`, `composer.json`, `CLAUDE.md`, the Docker
 * files and eighteen controllers). `project:resync` only refreshes files the project
 * already has. So the documented path for "I have an application and want a Svelte
 * front end" was to copy fifteen stubs by hand and do the token substitution
 * yourself — which is what a reviewer did, and what this removes.
 *
 * **Nothing existing is overwritten.** Every write goes through one funnel in
 * `Init`, and this command sets `skipExisting`, so a file the project already has is
 * left exactly as it is and reported as kept. Running it twice therefore does
 * nothing the second time, which is the property that makes it safe to run when you
 * are not sure.
 *
 * It writes the same files `init --app-style=spa` writes, from the same stubs and
 * with the same tokens, because it calls the same method. There is no second
 * implementation to drift.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license     MIT
 */
class ScaffoldSpa extends SpaCommandBase
{
    /**
     * A test seam: the Init instance to scaffold through.
     *
     * @var Init|null
     */
    public ?Init $scaffolder = null;

    protected function configure(): void
    {
        $this->setName('scaffold:spa');
        $this->setDescription('Add a SPA front end to an existing project (nothing existing is overwritten)');
        $this->addOption('spa-stack', null, InputOption::VALUE_OPTIONAL, 'svelte, vanilla-vite or vanilla');
        $this->addOption('app-style', null, InputOption::VALUE_OPTIONAL, 'spa (at the site root) or hybrid (under /app)');
        $this->addOption('spa-dev-port', null, InputOption::VALUE_OPTIONAL, 'Port for the Vite dev server');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite files the project already has');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be written, and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->root();
        if (!is_file($root . '/app/app.php')) {
            $output->writeln('<error>No app/app.php here — run this from a project root.</error>');
            return Command::FAILURE;
        }

        $config   = $this->config();
        $appStyle = strtolower((string) ($input->getOption('app-style') ?: ''));
        if ($appStyle === '') {
            // An application that already declares a style keeps it; one that does
            // not is asking for a SPA at its root, since that is what it just typed.
            $declared = (string) ($config['app_style'] ?? 'mvc');
            $appStyle = $declared !== 'mvc' ? $declared : 'spa';
        }
        if (!in_array($appStyle, ['spa', 'hybrid'], true)) {
            $output->writeln('<error>--app-style must be spa or hybrid.</error>');
            return Command::FAILURE;
        }

        $spaStack = strtolower((string) ($input->getOption('spa-stack') ?: ''));
        if ($spaStack === '') {
            $spaStack = (string) ($config['spa_stack'] ?? '') ?: 'svelte';
        }
        if (!in_array($spaStack, ['svelte', 'vanilla-vite', 'vanilla'], true)) {
            $output->writeln('<error>--spa-stack must be svelte, vanilla-vite or vanilla.</error>');
            return Command::FAILURE;
        }

        $scaffolder = $this->scaffolder ?? new Init();
        $scaffolder->targetBaseDir = $root;
        $scaffolder->skipExisting  = !$input->getOption('force');

        $appName   = (string) ($config['name'] ?? basename($root));
        $namespace = (string) ($config['namespace'] ?? 'App');
        $features  = is_array($config['features'] ?? null) ? array_values($config['features']) : [];
        $uiSystem  = (string) ($config['ui_system'] ?? 'plain-css');
        $apiPrefix = (string) ($config['api_prefix'] ?? '/api/1.0');
        $cliName   = strtolower(preg_replace('/[^a-z0-9]+/i', '', $appName) ?: 'pramnos');
        $devPort   = (int) ($input->getOption('spa-dev-port') ?: 5173);

        $output->writeln('');
        $output->writeln(sprintf(
            'Adding a <info>%s</info> front end (<info>%s</info>) to <comment>%s</comment>',
            $spaStack,
            $appStyle,
            $appName
        ));
        if ($scaffolder->skipExisting) {
            $output->writeln('Files this project already has will be left alone.');
        } else {
            $output->writeln('<comment>--force: existing files will be overwritten.</comment>');
        }
        $output->writeln('');

        if ($input->getOption('dry-run')) {
            $output->writeln('<comment>Dry run — nothing was written.</comment>');
            $output->writeln('  Re-run without --dry-run to add the front end.');
            return Command::SUCCESS;
        }

        try {
            $scaffolder->scaffoldSpa(
                $appName,
                $spaStack,
                $appStyle,
                $apiPrefix,
                $cliName,
                $devPort,
                (int) ($config['docker_port'] ?? 8080),
                $features,
                $namespace,
                $uiSystem
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not scaffold: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $report = $scaffolder->report();
        foreach ($report['written'] as $path) {
            $output->writeln('  <info>created</info>  ' . $path);
        }
        foreach ($report['kept'] as $path) {
            $output->writeln('  kept     ' . $path . ' <comment>(yours)</comment>');
        }

        $this->recordStyle($root, $appStyle, $spaStack, $output);

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done.</info> %d created, %d left alone.',
            count($report['written']),
            count($report['kept'])
        ));
        $output->writeln('');
        $output->writeln('Next: <comment>npm install</comment>, then <comment>npm run build</comment> '
            . '(or <comment>spa:dev</comment> for the dev server).');

        return Command::SUCCESS;
    }

    /**
     * Record the style in `app/app.php`, so the rest of the tooling knows.
     *
     * `spa:dev`, `spa:build` and `project:resync` all read `app_style` and
     * `spa_stack` from there — without them the front end exists and every command
     * that should help with it says the project has none.
     *
     * Left alone when the keys are already present: their values are the project's,
     * and an edit here would overwrite a deliberate choice.
     *
     * @param  string          $root
     * @param  string          $appStyle
     * @param  string          $spaStack
     * @param  OutputInterface $output
     * @return void
     */
    private function recordStyle(string $root, string $appStyle, string $spaStack, OutputInterface $output): void
    {
        $path     = $root . '/app/app.php';
        $contents = (string) @file_get_contents($path);

        if ($contents === '' || str_contains($contents, "'app_style'")) {
            if (str_contains($contents, "'app_style'")) {
                return;
            }
            $output->writeln('');
            $output->writeln('<comment>Could not read app/app.php to record the style.</comment>');
            $output->writeln("  Add <comment>'app_style' => '{$appStyle}'</comment> and "
                . "<comment>'spa_stack' => '{$spaStack}'</comment> by hand, or spa:dev, "
                . 'spa:build and project:resync will report that this project has no SPA.');
            return;
        }

        // Inserted after the opening `return [`, which every scaffolded app.php has.
        $lines = "    'app_style' => '{$appStyle}',\n    'spa_stack' => '{$spaStack}',\n";
        $edited = preg_replace('/(return\s*\[\s*\n)/', '$1' . $lines, $contents, 1);

        if ($edited === null || $edited === $contents) {
            $output->writeln('');
            $output->writeln('<comment>Add these to app/app.php yourself — the file is not in the '
                . 'shape this command edits:</comment>');
            $output->writeln('  ' . trim($lines));
            return;
        }

        file_put_contents($path, $edited);
        $output->writeln('  <info>updated</info>  app/app.php <comment>(app_style, spa_stack)</comment>');
    }
}
