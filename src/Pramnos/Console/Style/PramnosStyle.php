<?php

declare(strict_types=1);

namespace Pramnos\Console\Style;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Framework-flavoured console style built on top of SymfonyStyle.
 *
 * Adds wizard-step formatting and a few convenience shorthands while
 * inheriting all SymfonyStyle methods: title(), section(), success(),
 * error(), warning(), info(), table(), progressStart/Advance/Finish(), etc.
 *
 * Usage in a Command::execute():
 *   $io = new PramnosStyle($input, $output);
 *   $io->frameworkTitle('Pramnos Framework v1.2');
 *   $io->step(1, 6, 'Project metadata');
 *   $io->success('Project created.');
 */
class PramnosStyle extends SymfonyStyle
{
    public function __construct(InputInterface $input, OutputInterface $output)
    {
        parent::__construct($input, $output);
    }

    /**
     * Print a prominent framework title banner.
     *
     * Use once at the top of a multi-step command (e.g. init wizard).
     */
    public function frameworkTitle(string $title): void
    {
        $this->newLine();
        $this->block($title, null, 'fg=cyan;options=bold', ' ', true);
    }

    /**
     * Print a numbered wizard step header.
     *
     * Example output:
     *   Step 2/6: Framework features
     *
     * @param int    $current  1-based step number
     * @param int    $total    Total number of steps
     * @param string $label    Short description of the step
     */
    public function step(int $current, int $total, string $label): void
    {
        $this->section(sprintf('Step %d/%d: %s', $current, $total, $label));
    }

    /**
     * Print a key-value summary table (two-column, no header row).
     *
     * Useful for printing configuration summaries after a wizard completes.
     *
     * @param array<string, string> $pairs  Associative array of label => value
     */
    public function summaryTable(array $pairs): void
    {
        $rows = [];
        foreach ($pairs as $label => $value) {
            $rows[] = ["<info>{$label}</info>", $value];
        }
        $this->table([], $rows);
    }

    /**
     * Print a status row in the style used by migrate:status.
     *
     * Wraps the value in a colored tag depending on the status string:
     *   'Ran'     → green, 'Failed' → red, 'Pending' → yellow, else default.
     *
     * @param string $status One of: 'Ran', 'Failed', 'Pending', or custom
     * @return string The value wrapped in the appropriate output tag
     */
    public static function statusTag(string $status): string
    {
        return match ($status) {
            'Ran'     => "<fg=green>{$status}</>",
            'Failed'  => "<fg=red>{$status}</>",
            'Pending' => "<fg=yellow>{$status}</>",
            default   => $status,
        };
    }

    /**
     * Write a dimmed comment line — lower visual weight than info().
     */
    public function hint(string $message): void
    {
        $this->writeln("<fg=gray>{$message}</>");
    }

    /**
     * Print a check-mark success item (✓ <message>).
     *
     * Use inside a step for individual item confirmations.
     */
    public function check(string $message): void
    {
        $this->writeln(" <fg=green>✓</> {$message}");
    }

    /**
     * Print a cross failure item (✗ <message>).
     */
    public function cross(string $message): void
    {
        $this->writeln(" <fg=red>✗</> {$message}");
    }
}
