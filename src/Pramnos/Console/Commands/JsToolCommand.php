<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What `test:js` and `lint:js` share: the project, its config, the framework's JavaScript tooling,
 * and running Node where the console runs.
 *
 * Where the console runs is the environment: a project in Docker runs its console in the container
 * (`./dockerbash`, the generated CLI), and that is where its Node is. A command that went looking
 * for a container of its own would guess at a name; one that runs here uses whatever the project
 * already runs PHP in.
 */
abstract class JsToolCommand extends Command
{
    /** The project root. Overridable for testing. */
    public string $targetBaseDir = '';

    /** The project root. */
    protected function base(): string
    {
        return $this->targetBaseDir !== '' ? $this->targetBaseDir : (defined('ROOT') ? ROOT : (string) getcwd());
    }

    /** A key of the project's `app/app.php`, or null. */
    protected function config(string $key): mixed
    {
        $file = $this->base() . '/app/app.php';
        if (!is_file($file)) {
            return null;
        }
        $config = require $file;

        return is_array($config) ? ($config[$key] ?? null) : null;
    }

    /** The directory `require('pramnos-testing')` resolves in: the framework's `resources/js`. */
    public static function toolingDir(): string
    {
        return dirname(__DIR__, 4) . '/resources/js';
    }

    /** Whether `$binary` is on the PATH here. */
    protected function has(string $binary): bool
    {
        $found = [];
        $code  = 1;
        @exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null', $found, $code);

        return $code === 0 && $found !== [];
    }

    /**
     * Run a command in the project, its output passed through as it comes.
     *
     * @param list<string>          $command
     * @param array<string, string> $env     Added to this process's environment
     */
    protected function runTool(array $command, array $env, OutputInterface $output): int
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->base(),
            array_merge(getenv(), $env)
        );
        if (!is_resource($process)) {
            $output->writeln('<error>Could not start ' . $command[0] . '.</error>');

            return Command::FAILURE;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                break;
            }
            foreach ($read as $pipe) {
                $chunk = (string) fread($pipe, 8192);
                if ($chunk !== '') {
                    $output->write($chunk, false, OutputInterface::OUTPUT_RAW);
                }
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** Said when Node is not where the console runs. */
    protected function noNode(OutputInterface $output, string $binary = 'node'): int
    {
        $output->writeln("<error>'{$binary}' is not on the PATH where this console runs.</error>");
        $output->writeln('Run it where the project runs PHP — inside its container (./dockerbash) — or install Node.js here.');

        return Command::FAILURE;
    }
}
