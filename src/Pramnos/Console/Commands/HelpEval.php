<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Assist\Help;
use Pramnos\Assist\HelpBook;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `help:eval` — how well the help search answers the questions people actually ask.
 *
 * ```bash
 * php bin/pramnos help:eval                       # app/help/_golden.php, the administration's help
 * php bin/pramnos help:eval --level=site          # the site's help, its own _golden.php
 * php bin/pramnos help:eval --min=0.8             # exit 1 when fewer than 80% are in the first three
 * ```
 *
 * The golden questions are `_golden.php` in a help directory: an array of question → the page
 * that answers it, or `page#anchor` for the section — `['Πώς συνδέω το Stripe;' => 'billing#gateways']`.
 * Every directory's questions are read. It prints recall@1 (the answer first) and recall@3 (in the
 * first three) and each question that missed, with what came first instead.
 */
class HelpEval extends Command
{
    protected function configure(): void
    {
        $this->setName('help:eval')
            ->setDescription('Score the help search against the golden questions in _golden.php: recall@1 and recall@3')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'admin or site', HelpBook::ADMIN)
            ->addOption('min', null, InputOption::VALUE_REQUIRED, 'Exit 1 when recall@3 is below this (0 to 1)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $level = (string) $input->getOption('level');
        if (!in_array($level, [HelpBook::ADMIN, HelpBook::SITE], true)) {
            $output->writeln('<error>--level is admin or site.</error>');

            return Command::INVALID;
        }
        $book   = Help::book($level);
        $golden = self::golden($book);
        if ($golden === []) {
            $output->writeln('No golden questions: add _golden.php to a help directory (question => page or page#anchor).');

            return Command::SUCCESS;
        }

        $first = 0;
        $three = 0;
        foreach ($golden as $question => $expected) {
            [$page, $anchor] = array_pad(explode('#', $expected, 2), 2, '');
            $found = $book->searchSections($question, 3);
            $at    = null;
            foreach ($found as $rank => $section) {
                if ($section['page'] === Help::slug($page) && ($anchor === '' || $section['anchor'] === $anchor)) {
                    $at = $rank;
                    break;
                }
            }
            $first += $at === 0 ? 1 : 0;
            $three += $at !== null ? 1 : 0;
            if ($at !== 0) {
                $came = $found === [] ? 'nothing' : $found[0]['page'] . ($found[0]['anchor'] !== '' ? '#' . $found[0]['anchor'] : '');
                $output->writeln(sprintf('%s  %s → %s, first was %s', $at === null ? 'MISS' : '@' . ($at + 1) . '  ', $question, $expected, $came));
            }
        }

        $count = count($golden);
        $output->writeln(sprintf('%d questions: recall@1 %.2f, recall@3 %.2f', $count, $first / $count, $three / $count));

        $min = $input->getOption('min');

        return $min !== null && $three / $count < (float) $min ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Every help directory's golden questions.
     *
     * @return array<string, string> Question → page or page#anchor
     */
    private static function golden(HelpBook $book): array
    {
        $golden = [];
        foreach ($book->directories() as $directory) {
            $file = rtrim($directory, '/') . '/_golden.php';
            if (!is_file($file)) {
                continue;
            }
            $read = (static fn (string $f): mixed => include $f)($file);
            foreach (is_array($read) ? $read : [] as $question => $expected) {
                if (is_string($question) && trim($question) !== '' && is_string($expected) && trim($expected) !== '') {
                    $golden[$question] = trim($expected);
                }
            }
        }

        return $golden;
    }
}
