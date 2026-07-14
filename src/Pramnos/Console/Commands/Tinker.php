<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interactive REPL with the framework bootstrapped — Pramnos' answer to
 * Laravel's `tinker`.
 *
 * The command drops you into an interactive PHP shell in which the whole
 * framework is already initialised. You can call framework services directly,
 * e.g.:
 *
 * ```php
 * >>> $db = \Pramnos\Framework\Factory::getDatabase();
 * >>> $db->query('SELECT NOW()');
 * ```
 *
 * ## Usage
 *
 * ```
 * php pramnos tinker
 * ```
 *
 * ### Shell selection
 *
 * If the optional `psy/psysh` package is installed (`\Psy\Shell` exists), a
 * full-featured PsySH shell is launched (tab-completion, history, pretty
 * printing, doc lookups, …). Otherwise a **minimal built-in fallback REPL** is
 * used: it reads lines from STDIN, evaluates each one, prints the result and
 * understands the `exit`/`quit` commands. For a richer experience install
 * PsySH as a dev dependency:
 *
 * ```
 * composer require --dev psy/psysh
 * ```
 *
 * ### Non-interactive / no-TTY
 *
 * A REPL only makes sense with an interactive terminal. When STDIN is not a TTY
 * or the command is run non-interactively (e.g. under a test harness or in a
 * pipeline), the command prints an informational message and exits cleanly
 * (0) instead of blocking on input.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class Tinker extends Command
{
    protected static $defaultName = 'tinker';

    protected function configure(): void
    {
        $this
            ->setName('tinker')
            ->setDescription(
                'Launch an interactive REPL with the framework bootstrapped'
            )
            ->setHelp(
                "Drops you into an interactive PHP shell with the Pramnos "
                . "framework already initialised.\n\n"
                . "If the optional psy/psysh package is installed a full PsySH "
                . "shell is used; otherwise a minimal built-in REPL is "
                . "provided. Type 'exit' or 'quit' (or press Ctrl+D) to leave "
                . "the shell.\n\n"
                . "For a richer shell run: composer require --dev psy/psysh"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // A REPL needs a human at an interactive terminal. If we are running
        // non-interactively (Symfony's --no-interaction, a CommandTester with
        // ['interactive' => false], a pipe, cron, CI, …) there is nobody to
        // type at the prompt. Blocking on STDIN here would hang forever, so we
        // print a message and exit cleanly instead.
        if (!$input->isInteractive() || !$this->stdinIsInteractive()) {
            $output->writeln(
                '<comment>tinker requires an interactive terminal (TTY). '
                . 'Nothing to do in a non-interactive context.</comment>'
            );
            return Command::SUCCESS;
        }

        if (class_exists('\\Psy\\Shell')) {
            return $this->runPsysh($output);
        }

        return $this->runFallbackRepl($output);
    }

    // -------------------------------------------------------------------------
    // Shell implementations
    // -------------------------------------------------------------------------

    /**
     * Launch the full PsySH shell when psy/psysh is available.
     */
    private function runPsysh(OutputInterface $output): int
    {
        $configClass = '\\Psy\\Configuration';
        $shellClass  = '\\Psy\\Shell';

        if (class_exists($configClass)) {
            $config = new $configClass();
            $shell  = new $shellClass($config);
        } else {
            $shell = new $shellClass();
        }

        $shell->run();

        return Command::SUCCESS;
    }

    /**
     * Minimal fallback REPL used when psy/psysh is not installed.
     *
     * Reads a line at a time from STDIN, evaluates it and prints the result.
     * This is intentionally simple — for anything beyond quick one-liners
     * install PsySH (composer require --dev psy/psysh).
     */
    private function runFallbackRepl(OutputInterface $output): int
    {
        $output->writeln('<info>Pramnos tinker — minimal fallback REPL</info>');
        $output->writeln(
            '<comment>psy/psysh is not installed. For a richer shell run: '
            . 'composer require --dev psy/psysh</comment>'
        );
        $output->writeln(
            "Type PHP expressions terminated by ';'. Type 'exit' or 'quit' "
            . '(or Ctrl+D) to leave.'
        );
        $output->writeln('');

        $stdin = defined('STDIN') ? STDIN : fopen('php://stdin', 'r');
        if ($stdin === false) {
            $output->writeln('<error>Unable to open STDIN.</error>');
            return Command::FAILURE;
        }

        while (true) {
            $output->write('>>> ');

            $line = fgets($stdin);

            // EOF (Ctrl+D or closed stream) — leave the shell.
            if ($line === false) {
                $output->writeln('');
                break;
            }

            $code = trim($line);
            if ($code === '') {
                continue;
            }
            if (in_array(strtolower($code), ['exit', 'quit', 'exit;', 'quit;'], true)) {
                break;
            }

            $this->evaluate($code, $output);
        }

        $output->writeln('<info>Bye.</info>');
        return Command::SUCCESS;
    }

    /**
     * Evaluate a single line of user input, guarding against parse/throwables so
     * a bad expression does not kill the REPL.
     */
    private function evaluate(string $code, OutputInterface $output): void
    {
        // Ensure the snippet is a complete statement so eval() does not choke on
        // a missing trailing semicolon for simple expressions.
        $statement = rtrim($code, ';') . ';';

        try {
            // Return the value of the expression when possible so we can print
            // it; fall back to executing the raw statement otherwise.
            $result = eval('return (' . rtrim($code, ';') . ');');
            $this->printResult($result, $output);
        } catch (\ParseError) {
            // Not a single expression (e.g. an assignment, echo, loop, …).
            // Execute it as a statement instead and report only failures.
            try {
                eval($statement);
            } catch (\ParseError $e) {
                $output->writeln('<error>Parse error: ' . $e->getMessage() . '</error>');
            } catch (\Throwable $e) {
                $output->writeln(
                    '<error>' . get_class($e) . ': ' . $e->getMessage() . '</error>'
                );
            }
        } catch (\Throwable $e) {
            $output->writeln(
                '<error>' . get_class($e) . ': ' . $e->getMessage() . '</error>'
            );
        }
    }

    /**
     * Pretty-print the result of an evaluated expression.
     *
     * @param mixed $result
     */
    private function printResult($result, OutputInterface $output): void
    {
        if ($result === null) {
            $output->writeln('=> null');
            return;
        }

        if (is_scalar($result)) {
            $output->writeln('=> ' . var_export($result, true));
            return;
        }

        // Arrays / objects — print_r is more readable than var_export here.
        $output->writeln('=> ' . rtrim(print_r($result, true)));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Whether STDIN is connected to an interactive terminal (TTY).
     *
     * Uses stream_isatty() when available (PHP 7.2+), falling back to
     * posix_isatty(). If neither can determine the answer we conservatively
     * assume non-interactive so the command never blocks on input.
     */
    private function stdinIsInteractive(): bool
    {
        if (!defined('STDIN')) {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }

        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDIN);
        }

        return false;
    }
}
