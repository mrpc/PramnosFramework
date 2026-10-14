<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * lint:js — ESLint over the application's JavaScript, with the framework's rules.
 *
 * ```
 * php bin/<app> lint:js                  # the web root's assets/js and tests/js
 * php bin/<app> lint:js www/assets/js/notes.js
 * php bin/<app> lint:js --fix
 * ```
 *
 * The rules are the framework's: defects only — a redeclared variable, an undefined name,
 * unreachable code — never a style. An application with an `eslint.config.mjs` of its own is
 * linted with that; it extends the framework's from
 * `vendor/mrpc/pramnosframework/resources/js/pramnos-testing/eslint.base.mjs`. Without one the
 * framework's whole configuration applies.
 *
 * ESLint is the project's `node_modules/.bin/eslint` when it has one, or `npx` fetches the
 * framework's version.
 */
class LintJs extends JsToolCommand
{
    /** The ESLint version `npx` fetches for a project that has none. */
    public const ESLINT = 'eslint@9';

    protected function configure(): void
    {
        $this
            ->setName('lint:js')
            ->setDescription("Lint the application's JavaScript with the framework's ESLint rules")
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Files or directories (default: the web root\'s assets/js and tests/js)')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Fix what can be fixed automatically');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $base  = $this->base();
        $local = is_file($base . '/node_modules/.bin/eslint');
        if (!$local && !$this->has('npx')) {
            return $this->noNode($output, 'npx');
        }

        $paths = array_values(array_map('strval', (array) $input->getArgument('paths')));
        if ($paths === []) {
            $web   = basename(\Pramnos\Application\FrameworkScripts::webRoot($base));
            $paths = array_values(array_filter([$web . '/assets/js', 'tests/js'], static fn (string $p): bool => is_dir($base . '/' . $p)));
        }
        if ($paths === []) {
            $output->writeln('<comment>No JavaScript to lint.</comment>');

            return self::SUCCESS;
        }

        // ESLint reads a flat config's globs relative to the config file, so the framework's
        // configuration is used through a file in the project root, removed afterwards.
        $own    = is_file($base . '/eslint.config.mjs');
        $config = $own ? 'eslint.config.mjs' : '.pramnos-eslint.config.mjs';
        if (!$own) {
            file_put_contents(
                $base . '/' . $config,
                "export { default } from " . var_export(self::toolingDir() . '/pramnos-testing/eslint.base.mjs', true) . ";\n"
            );
        }

        try {
            $eslint  = $local ? ['node_modules/.bin/eslint'] : ['npx', '--yes', self::ESLINT];
            $command = [...$eslint, '--config', $config, ...($input->getOption('fix') ? ['--fix'] : []), ...$paths];

            return $this->runTool($command, [], $output);
        } finally {
            if (!$own) {
                @unlink($base . '/' . $config);
            }
        }
    }
}
