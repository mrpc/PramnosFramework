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
 * `help:questions` — questions for the help's sections that have none, proposed by a model.
 *
 * ```bash
 * php bin/pramnos help:questions                    # print what it would add, section by section
 * php bin/pramnos help:questions --page=billing     # one page
 * php bin/pramnos help:questions --write            # write them under each heading
 * php bin/pramnos help:questions --framework        # the framework's own pages too
 * ```
 *
 * The questions a section lists are what finds it: a question asked word for word puts it first
 * (see {@see HelpBook::searchSections()}). Writing them by hand is what lifts a help's search the
 * most, and the most tedious part. This asks the cheapest model of the configured connection for
 * two to four questions a reader would ask that each section answers, in the page's language, and
 * prints them; with `--write` it puts them in each file as `<!-- questions: a | b -->` under the
 * section's heading, for the author to read and correct. A section that has questions is left
 * alone, so running it again only fills what is still empty.
 *
 * Only the application's pages unless `--framework`: those are in `vendor/` in an application,
 * where a write would be lost at the next update.
 */
class HelpQuestions extends Command
{
    /** The most of a section's text the model reads. */
    public const READS = 3000;

    /** @var (\Closure(): \Pramnos\Llm\Client)|null The client to ask; a new one on the configured connection when null */
    public ?\Closure $client = null;

    protected function configure(): void
    {
        $this->setName('help:questions')
            ->setDescription('Propose questions for the help sections that have none, and write them with --write')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'admin or site', HelpBook::ADMIN)
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Only this page')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many sections', '50')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Write the questions under each heading')
            ->addOption('framework', null, InputOption::VALUE_NONE, 'The framework\'s own pages too');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $level = (string) $input->getOption('level');
        if (!in_array($level, [HelpBook::ADMIN, HelpBook::SITE], true)) {
            $output->writeln('<error>--level is admin or site.</error>');

            return Command::INVALID;
        }
        $book  = Help::book($level);
        $only  = Help::slug((string) $input->getOption('page'));
        $limit = max(1, (int) $input->getOption('limit'));
        $write = (bool) $input->getOption('write');

        $todo = [];
        foreach ($book->sections() as $section) {
            if (($only !== '' && $section['page'] !== $only) || $section['questions'] !== []
                || (!$input->getOption('framework') && $section['framework']) || mb_strlen(trim((string) $section['text'])) < 40) {
                continue;
            }
            $todo[] = $section;
        }
        if ($todo === []) {
            $output->writeln('Every section has questions.');

            return Command::SUCCESS;
        }

        $client  = $this->client !== null ? ($this->client)() : new \Pramnos\Llm\Client();
        $written = 0;
        $failed  = 0;
        foreach (array_slice($todo, 0, $limit) as $section) {
            $questions = self::propose($client, $section);
            $where     = $section['page'] . ($section['anchor'] !== '' ? '#' . $section['anchor'] : '');
            if ($questions === []) {
                $failed++;
                $output->writeln('<comment>' . $where . ': no questions came back.</comment>');
                continue;
            }
            $output->writeln($where . '  (' . $section['path'] . ')');
            foreach ($questions as $question) {
                $output->writeln('  - ' . $question);
            }
            if ($write) {
                $page = $book->page((string) $section['page']);
                if ($page !== null && self::insert((string) $page['file'], (string) $section['anchor'], $questions)) {
                    $written++;
                }
            }
        }

        if ($write) {
            $book->forget();
            $output->writeln($written . ' sections written.');
        }
        if (count($todo) > $limit) {
            $output->writeln((count($todo) - $limit) . ' more sections without questions: run it again, or raise --limit.');
        }

        return $failed > 0 && $failed === min($limit, count($todo)) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * The model's questions for one section: two to four, each one line without `|` or `-->`,
     * which would end the comment they are written in.
     *
     * @param array<string, mixed> $section
     * @return list<string>
     */
    private static function propose(\Pramnos\Llm\Client $client, array $section): array
    {
        $prompt = 'You write the questions people ask that one section of an application\'s help answers, so a search '
            . 'finds the section by them. Write two to four short questions, as a reader would type them, in the '
            . 'language the section is written in, each answered by this section and not merely mentioning it. '
            . 'Answer JSON only: {"questions": ["...", "..."]}.';
        try {
            $answer = $client->askCheaply($prompt, [['role' => 'user', 'content' => json_encode([
                'page'    => $section['title'],
                'section' => $section['path'],
                'text'    => mb_substr((string) $section['text'], 0, self::READS),
            ], JSON_UNESCAPED_UNICODE)]]);
        } catch (\Throwable) {
            return [];
        }
        $reply = (string) ($answer['reply'] ?? '');
        $json  = json_decode((string) (preg_match('/\{.*\}/s', $reply, $m) === 1 ? $m[0] : $reply), true);

        $questions = [];
        foreach (array_slice((array) ($json['questions'] ?? []), 0, 4) as $question) {
            $question = trim(str_replace(['|', '-->', "\n", "\r"], [' ', ' ', ' ', ' '], (string) $question));
            if ($question !== '') {
                $questions[] = $question;
            }
        }

        return $questions;
    }

    /**
     * Writes the questions under a section's heading in its file — under the page's `#` title,
     * or at the top of its body, for the text before the first `##`. False when the heading is
     * not found or the file cannot be written.
     *
     * @param list<string> $questions
     */
    private static function insert(string $file, string $anchor, array $questions): bool
    {
        $content = (string) @file_get_contents($file);
        $lines   = explode("\n", $content);
        $comment = '<!-- questions: ' . implode(' | ', $questions) . ' -->';

        // Where the body starts: after the front matter, if the file has one.
        $start = 0;
        if (($lines[0] ?? '') === '---') {
            for ($i = 1; $i < count($lines); $i++) {
                if (trim($lines[$i]) === '---') {
                    $start = $i + 1;
                    break;
                }
            }
        }

        $at       = null;
        $anchors  = Help::anchors();
        $fenced   = false;
        for ($i = $start; $i < count($lines); $i++) {
            if (preg_match('/^\s*```/', $lines[$i]) === 1) {
                $fenced = !$fenced;
            }
            if ($fenced || preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $lines[$i], $m) !== 1) {
                continue;
            }
            $id = $anchors($m[2]);
            if ($anchor === '' ? strlen($m[1]) === 1 : $id === $anchor) {
                $at = $i + 1;
                break;
            }
            if ($anchor === '' && strlen($m[1]) > 1) {
                break;
            }
        }
        if ($at === null && $anchor !== '') {
            return false;
        }
        array_splice($lines, $at ?? $start, 0, [$comment]);

        return @file_put_contents($file, implode("\n", $lines)) !== false;
    }
}
