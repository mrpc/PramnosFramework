<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands\Make;

use Pramnos\Console\Commands\SpaCommandBase;
use Pramnos\Routing\OpenApiClientGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate the front end's endpoint functions from the OpenAPI document.
 *
 * The document already knows every path, parameter and field the API has. The screens
 * did not: they hand-wrote path strings and field names, so a rename in the backend
 * was found in the browser, one screen at a time. This closes that loop — the last
 * item of a consumer's review, and the one they described as most likely to reduce the
 * "wrong field" class of bug.
 *
 * It writes two files and owns both:
 *
 *   - `lib/endpoints.js` — one function per documented operation, delegating to the
 *     project's own `lib/api.js` for the call;
 *   - `lib/endpoints.d.ts` — the types an editor reads.
 *
 * Both are **regenerated**, which is the point: staying in step with the backend
 * means being rewritten from the document rather than maintained by hand. Nothing
 * else is touched — `lib/api.js` stays the project's.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license     MIT
 */
class MakeApiClient extends SpaCommandBase
{
    protected function configure(): void
    {
        $this->setName('create:api-client');
        $this->setDescription('Generate typed endpoint functions from the OpenAPI document');
        $this->addOption('document', null, InputOption::VALUE_OPTIONAL, 'Path to the OpenAPI JSON (default: www/api/openapi.json)');
        $this->addOption('output', null, InputOption::VALUE_OPTIONAL, 'Directory for endpoints.js (default: the SPA lib/ directory)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be written, and write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->root();

        $documentPath = (string) ($input->getOption('document') ?: 'www/api/openapi.json');
        $absolute     = str_starts_with($documentPath, '/') ? $documentPath : $root . '/' . $documentPath;

        if (!is_file($absolute)) {
            $output->writeln('<error>No OpenAPI document at ' . $documentPath . '.</error>');
            $output->writeln('  Generate one first: <comment>npm run docs:build</comment> '
                . '(or <comment>php ' . $this->cliName() . ' api:docs</comment> for an '
                . 'attribute-routed project), then run this again.');
            return Command::FAILURE;
        }

        $decoded = json_decode((string) file_get_contents($absolute), true);
        if (!is_array($decoded)) {
            $output->writeln('<error>' . $documentPath . ' is not valid JSON.</error>');
            return Command::FAILURE;
        }

        $generator  = new OpenApiClientGenerator($decoded);
        $operations = $generator->operations();

        if ($operations === []) {
            $output->writeln('<comment>The document describes no operations, so there is '
                . 'nothing to generate.</comment>');
            $output->writeln('  Paths come from `@api` comment blocks or #[Route] attributes; '
                . 'if the API has endpoints, the document is out of date.');
            return Command::SUCCESS;
        }

        $directory = trim((string) ($input->getOption('output') ?: ''));
        if ($directory === '') {
            $sourceDir = $this->spaSourceDirectory();
            if ($sourceDir === '') {
                $output->writeln('<error>This project has no SPA front end to generate into.</error>');
                $output->writeln('  Add one with <comment>scaffold:spa</comment>, or pass '
                    . '<comment>--output=some/dir</comment>.');
                return Command::FAILURE;
            }
            $directory = $sourceDir . 'lib';
        }
        $directory = rtrim($directory, '/');

        $files = [
            $directory . '/endpoints.js'   => $generator->javaScript(),
            $directory . '/endpoints.d.ts' => $generator->declarations(),
        ];

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>%d operation(s)</info> from <comment>%s</comment>',
            count($operations),
            $documentPath
        ));

        if ($input->getOption('dry-run')) {
            foreach (array_keys($files) as $path) {
                $output->writeln('  would write  ' . $path);
            }
            $output->writeln('');
            $output->writeln('<comment>Dry run — nothing was written.</comment>');
            return Command::SUCCESS;
        }

        if (!is_dir($root . '/' . $directory)) {
            @mkdir($root . '/' . $directory, 0777, true);
        }

        foreach ($files as $path => $contents) {
            if (file_put_contents($root . '/' . $path, $contents) === false) {
                $output->writeln('<error>Could not write ' . $path . '</error>');
                return Command::FAILURE;
            }
            $output->writeln('  <info>written</info>  ' . $path);
        }

        $output->writeln('');
        $output->writeln('Import them where a screen used to write its own paths:');
        $output->writeln('  <comment>import { listThings, updateThing } from \'./lib/endpoints.js\';</comment>');
        $output->writeln('');
        $output->writeln('Both files are regenerated — do not edit them. Re-run this after '
            . 'changing the API.');

        return Command::SUCCESS;
    }

    /**
     * Where this project keeps its front end, with a trailing slash, or ''.
     *
     * Reads `spa_source_dir` first, so a project whose sources are not where the
     * framework would have put them is served without a rename.
     *
     * @return string
     */
    private function spaSourceDirectory(): string
    {
        return \Pramnos\Console\Commands\Init::spaSourceDirFor($this->config());
    }

    /**
     * The project's CLI entry point, for the message that names a command.
     *
     * @return string
     */
    private function cliName(): string
    {
        $name = (string) ($this->config()['name'] ?? 'pramnos');

        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $name) ?: 'pramnos');
    }
}
