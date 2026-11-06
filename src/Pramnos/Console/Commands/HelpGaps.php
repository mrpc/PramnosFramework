<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Logs\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `help:gaps` — where the help fell short of what the analyst was asked.
 *
 * ```bash
 * php bin/pramnos help:gaps                  # the last 30 days
 * php bin/pramnos help:gaps --days=7 --weak=3
 * php bin/pramnos help:gaps --golden         # the questions as lines for _golden.php, to fill in
 * ```
 *
 * Read from the `help` log, where the analyst's `help` tool notes each search and each section it
 * read by name ({@see \Pramnos\Llm\Analyst\Tools\HelpTool}). Three lists, most asked first: the
 * questions nothing answered — a page that is missing; those answered weakly, below `--weak` — a
 * section that needs the question among its own; and the sections read by name — what a search
 * should have brought and did not. `--golden` prints the questions as `_golden.php` lines with
 * the answer the search gave, for `help:eval` once corrected.
 */
class HelpGaps extends Command
{
    protected function configure(): void
    {
        $this->setName('help:gaps')
            ->setDescription('What the analyst asked the help that it answered weakly or not at all, from the help log')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How far back', '30')
            ->addOption('weak', null, InputOption::VALUE_REQUIRED, 'A best score below this is a weak answer', '2')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many lines per list', '20')
            ->addOption('golden', null, InputOption::VALUE_NONE, 'Print the questions as _golden.php lines');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $since = time() - max(1, (int) $input->getOption('days')) * 86400;
        $weak  = (float) $input->getOption('weak');
        $limit = max(1, (int) $input->getOption('limit'));

        $unanswered = [];
        $weakly     = [];
        $read       = [];
        $asked      = [];
        foreach (self::entries($since) as $entry) {
            if (isset($entry['read'])) {
                $read[(string) $entry['read']] = ($read[(string) $entry['read']] ?? 0) + 1;
                continue;
            }
            $question = trim((string) ($entry['question'] ?? ''));
            if ($question === '') {
                continue;
            }
            $asked[$question] = (string) ($entry['best'] ?? '');
            if ((int) ($entry['found'] ?? 0) === 0) {
                $unanswered[$question] = ($unanswered[$question] ?? 0) + 1;
            } elseif ((float) ($entry['score'] ?? 0) < $weak) {
                $weakly[$question] = [($weakly[$question][0] ?? 0) + 1, (string) ($entry['best'] ?? ''), (float) ($entry['score'] ?? 0)];
            }
        }

        if ($asked === [] && $read === []) {
            $output->writeln('Nothing was asked of the help in that time, or the help log is off (help.log in app.php).');

            return Command::SUCCESS;
        }

        if ($input->getOption('golden')) {
            $output->writeln('<?php // app/help/_golden.php — correct each answer to the page#anchor that should come first');
            $output->writeln('return [');
            foreach ($asked as $question => $best) {
                $output->writeln('    ' . var_export($question, true) . ' => ' . var_export($best, true) . ',');
            }
            $output->writeln('];');

            return Command::SUCCESS;
        }

        arsort($unanswered);
        uasort($weakly, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        arsort($read);

        $output->writeln('Nothing answered (' . count($unanswered) . '):');
        foreach (array_slice($unanswered, 0, $limit, true) as $question => $times) {
            $output->writeln(sprintf('  %3d× %s', $times, $question));
        }
        $output->writeln('Answered weakly, below ' . $weak . ' (' . count($weakly) . '):');
        foreach (array_slice($weakly, 0, $limit, true) as $question => [$times, $best, $score]) {
            $output->writeln(sprintf('  %3d× %s → %s (%.2f)', $times, $question, $best, $score));
        }
        $output->writeln('Read by name, after a search did not bring them (' . count($read) . '):');
        foreach (array_slice($read, 0, $limit, true) as $section => $times) {
            $output->writeln(sprintf('  %3d× %s', $times, $section));
        }

        return Command::SUCCESS;
    }

    /**
     * The help log's entries since a moment: each line's context, from `help.log` and the files
     * it was rotated into.
     *
     * @return \Generator<array<string, mixed>>
     */
    private static function entries(int $since): \Generator
    {
        $files = glob(Logger::logDirectory() . DIRECTORY_SEPARATOR . 'help.log*') ?: [];
        sort($files);
        foreach ($files as $file) {
            $handle = @fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                if (!is_array($entry) || !is_array($entry['context'] ?? null)) {
                    continue;
                }
                if (Logger::timestampOf((string) ($entry['timestamp'] ?? '')) < $since) {
                    continue;
                }
                yield $entry['context'];
            }
            fclose($handle);
        }
    }
}
