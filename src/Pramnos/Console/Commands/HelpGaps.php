<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Llm\Analyst\Tools\HelpTool;
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
 * php bin/pramnos help:gaps --rated=bad      # the help behind the answers rated 👎, with their notes
 * php bin/pramnos help:gaps --golden         # the questions as lines for _golden.php, to fill in
 * ```
 *
 * Read from the analyst's own conversations: every answer keeps the tools it called
 * (`chat_messages.tools`), so each `help` call is there with the answer it was for and that
 * answer's rating — kept, and pruned, as the conversations are. Each question is answered again by
 * the help as it is now ({@see HelpTool::lookedUp()}), so a page written since counts.
 *
 * Three lists, most asked first: the questions nothing answers — a page that is missing; those
 * answered below `--weak` — a section that needs the question among its own; and the sections the
 * analyst read by name — what a search should have brought and did not. `--rated=bad` lists the
 * help behind each answer rated bad, with what was wrong: nothing found means the help does not
 * have it; a strong section means it had it and the answer did not use it.
 */
class HelpGaps extends Command
{
    protected function configure(): void
    {
        $this->setName('help:gaps')
            ->setDescription('What the analyst asked the help that it answers weakly or not at all, from the conversations')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How far back', '30')
            ->addOption('weak', null, InputOption::VALUE_REQUIRED, 'A best score below this is a weak answer', '2')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many lines per list', '20')
            ->addOption('rated', null, InputOption::VALUE_REQUIRED, 'bad: the help behind the answers rated bad')
            ->addOption('golden', null, InputOption::VALUE_NONE, 'Print the questions as _golden.php lines');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rated = (string) $input->getOption('rated');
        if ($rated !== '' && $rated !== 'bad') {
            $output->writeln('<error>--rated is bad.</error>');

            return Command::INVALID;
        }
        $since = time() - max(1, (int) $input->getOption('days')) * 86400;
        $weak  = (float) $input->getOption('weak');
        $limit = max(1, (int) $input->getOption('limit'));

        $calls = self::calls($since, $rated === 'bad');
        if ($calls === []) {
            $output->writeln($rated === 'bad'
                ? 'No answer rated bad in that time asked the help.'
                : 'Nothing was asked of the help in that time.');

            return Command::SUCCESS;
        }

        if ($rated === 'bad') {
            return self::rated($calls, $weak, $output);
        }

        $unanswered = [];
        $weakly     = [];
        $read       = [];
        $asked      = [];
        foreach ($calls as $call) {
            $looked = $call['looked'];
            if ($looked['kind'] === 'read') {
                $read[$looked['best']] = ($read[$looked['best']] ?? 0) + 1;
                continue;
            }
            $question = $call['question'];
            if ($looked['kind'] !== 'question' || $question === '') {
                continue;
            }
            $asked[$question] = $looked['best'];
            if ($looked['found'] === 0) {
                $unanswered[$question] = ($unanswered[$question] ?? 0) + 1;
            } elseif ($looked['score'] < $weak) {
                $weakly[$question] = [($weakly[$question][0] ?? 0) + 1, $looked['best'], $looked['score']];
            }
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

        $output->writeln('Nothing answers (' . count($unanswered) . '):');
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
     * The help behind each answer rated bad, the answer's note with it: whether the help has
     * nothing for its questions, or had a strong section the answer did not use.
     *
     * @param list<array<string, mixed>> $calls
     */
    private static function rated(array $calls, float $weak, OutputInterface $output): int
    {
        $answers = [];
        foreach ($calls as $call) {
            $answers[$call['message']][] = $call;
        }
        $output->writeln(count($answers) . ' answers rated bad asked the help:');
        foreach ($answers as $message => $its) {
            $output->writeln('');
            $output->writeln('#' . $message . ' (conversation ' . $its[0]['conversation'] . ')' . ($its[0]['note'] !== '' ? ' — "' . $its[0]['note'] . '"' : ''));
            $strong = false;
            foreach ($its as $call) {
                $looked = $call['looked'];
                $what   = $looked['kind'] === 'read' ? 'read ' . $looked['best']
                    : ($looked['kind'] === 'map' ? 'map' : $call['question'] . ' → ' . ($looked['found'] === 0 ? 'nothing' : sprintf('%s (%.2f)', $looked['best'], $looked['score'])));
                $output->writeln('  ' . $what);
                $strong = $strong || ($looked['kind'] === 'question' && $looked['found'] > 0 && $looked['score'] >= $weak);
            }
            $output->writeln($strong
                ? '  The help has a strong section for it: the answer did not find or use it.'
                : '  The help has nothing strong for it: a page or a section is missing.');
        }

        return Command::SUCCESS;
    }

    /**
     * The analyst's `help` calls since a moment, from the answers that made them: each with its
     * question, the answer's message and conversation, its rating note, and what the help gives
     * for it now.
     *
     * @return list<array{message: int, conversation: int, note: string, question: string, looked: array<string, mixed>}>
     */
    private static function calls(int $since, bool $badOnly): array
    {
        $db    = \Pramnos\Framework\Factory::getDatabase();
        $rated = \Pramnos\Llm\Chat\Ratings::available();
        if ($badOnly && !$rated) {
            return [];
        }
        $query = $db->queryBuilder()->table('assist.chat_messages as m')
            ->join('assist.chat_conversations as c', 'c.conversation_id', '=', 'm.conversation_id')
            ->select(array_merge(['m.message_id', 'm.conversation_id', 'm.tools'], $rated ? ['m.rating_note'] : []))
            ->where('c.space', \Pramnos\Llm\Chat\Scope::ADMIN)
            ->where('m.role', 'assistant')
            ->where('m.created_at', '>=', \Pramnos\Llm\CallLog::instant($since))
            ->whereLike('m.tools', '%"help"%')
            ->orderBy('m.message_id');
        if ($badOnly) {
            $query->where('m.rating', -1);
        }

        $calls = [];
        foreach ($query->get()->fetchAll() as $row) {
            foreach ((array) json_decode((string) $row['tools'], true) as $tool) {
                if (!is_array($tool) || ($tool['name'] ?? '') !== 'help') {
                    continue;
                }
                $arguments = is_array($tool['arguments'] ?? null) ? $tool['arguments'] : [];
                $calls[]   = [
                    'message'      => (int) $row['message_id'],
                    'conversation' => (int) $row['conversation_id'],
                    'note'         => (string) ($row['rating_note'] ?? ''),
                    'question'     => trim((string) ($arguments['question'] ?? '')),
                    'looked'       => HelpTool::lookedUp($arguments),
                ];
            }
        }

        return $calls;
    }
}
