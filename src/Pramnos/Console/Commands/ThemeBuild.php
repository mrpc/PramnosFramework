<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Theme\ThemeTokens;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * theme:build — turn `app/theme.css` into the forms every UI system can read.
 *
 * The palette is declared once, in the format daisyUI's theme generator emits, and
 * this writes out what a build without npm cannot produce for itself:
 *
 *   - `www/assets/css/theme-tokens.css` — the same tokens as plain custom properties,
 *     which is all a buildless Tailwind project, a Bootstrap project or a plain-CSS
 *     project needs to be themed;
 *   - `www/assets/theme-tokens.json` — the same values for a SPA's own components.
 *
 * A Tailwind project **with** npm needs neither: its `app.css` imports `app/theme.css`
 * and the plugin reads the blocks directly. Running this anyway is harmless — the
 * numbers come from the same file — and it is what keeps a project that later drops
 * the build step from losing its colours.
 *
 * ```bash
 * pramnos theme:build                  # both outputs
 * pramnos theme:build --check          # exit 1 if they are out of date (for CI)
 * pramnos theme:build --source=app/brand.css
 * ```
 *
 * @see \Pramnos\Theme\ThemeTokens for the format and why it is daisyUI's
 */
class ThemeBuild extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('theme:build')
            ->setDescription('Generate CSS custom properties and JSON from app/theme.css')
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'The palette to read',
                ThemeTokens::DEFAULT_PATH
            )
            ->addOption(
                'css',
                null,
                InputOption::VALUE_REQUIRED,
                'Where to write the custom properties',
                'www/assets/css/theme-tokens.css'
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_REQUIRED,
                'Where to write the JSON (empty to skip)',
                'www/assets/theme-tokens.json'
            )
            // For CI: a generated file committed to a repository is a file that can
            // be stale, and a stale palette is invisible until somebody looks at the
            // one theme nobody develops in.
            ->addOption(
                'check',
                null,
                InputOption::VALUE_NONE,
                'Write nothing; fail if the outputs are not what a build would produce'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root   = $this->projectRoot();
        $source = $this->resolve($root, (string) $input->getOption('source'));

        if (!is_readable($source)) {
            $output->writeln('<error>No palette at ' . $source . '</error>');
            $output->writeln(
                'Declare one in the format daisyUI\'s theme generator emits — see the'
                . ' Theme guide, "One palette, every UI system".'
            );

            return Command::FAILURE;
        }

        ThemeTokens::flush();
        $themes = ThemeTokens::parse((string) file_get_contents($source));

        if ($themes === []) {
            $output->writeln(
                '<error>' . $source . ' declares no themes.</error> Expected at least one'
                . ' `@plugin "daisyui/theme" { name: "…"; … }` block.'
            );

            return Command::FAILURE;
        }

        $outputs = [
            (string) $input->getOption('css')  => ThemeTokens::toCss($themes),
            (string) $input->getOption('json') => ThemeTokens::toJson($themes),
        ];

        $stale  = [];
        $status = Command::SUCCESS;

        foreach ($outputs as $relative => $contents) {
            if ($relative === '') {
                continue; // asked to skip this output
            }

            $path    = $this->resolve($root, $relative);
            $current = is_readable($path) ? (string) file_get_contents($path) : null;

            if ($input->getOption('check')) {
                if ($current !== $contents) {
                    $stale[] = $relative;
                    $status  = Command::FAILURE;
                }
                continue;
            }

            if ($current === $contents) {
                $output->writeln('  <comment>unchanged</comment> ' . $relative);
                continue;
            }

            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
                $output->writeln('<error>Cannot create ' . $directory . '</error>');

                return Command::FAILURE;
            }

            if (@file_put_contents($path, $contents) === false) {
                $output->writeln('<error>Cannot write ' . $path . '</error>');

                return Command::FAILURE;
            }

            $output->writeln('  <info>written</info>   ' . $relative);
        }

        if ($input->getOption('check')) {
            if ($stale === []) {
                $output->writeln('<info>Theme outputs are up to date.</info>');

                return Command::SUCCESS;
            }

            $output->writeln('<error>Out of date: ' . implode(', ', $stale) . '</error>');
            $output->writeln('Run `pramnos theme:build`.');

            return $status;
        }

        $names = implode(', ', array_keys($themes));
        $output->writeln('<info>' . count($themes) . ' theme(s): ' . $names . '</info>');

        return Command::SUCCESS;
    }

    /**
     * The project root — where `app/` and `www/` are.
     *
     * `ROOT` when the console booted an application, the working directory otherwise,
     * so the command also works in a checkout that has no bootstrapped app.
     */
    protected function projectRoot(): string
    {
        return defined('ROOT') ? (string) ROOT : (string) getcwd();
    }

    /** An absolute path stays as it is; a relative one is under the project root. */
    private function resolve(string $root, string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($root, '/') . '/' . $path;
    }
}
