<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Llm\Analyst\ConsoleAsk;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * llm:ask — ask the analyst a question from the console, as an administrator, and see the answer,
 * the tools it called with their arguments, and what it cost.
 *
 *   php <cli> llm:ask "How many parents paid in October?" --as=5
 *   php <cli> llm:ask "And in September?" --as=5 --conversation=812
 *   php <cli> llm:ask "…" --as=5 --json
 *
 * For checking a fix to the analyst — a tool, the help — without a browser. The same analyst as
 * `/admin/Ai`, with the administrator's tools and abilities; the conversation is kept and opens
 * there, and is left out of `help:gaps` and of what is learned ({@see ConsoleAsk}).
 */
class LlmAsk extends Command
{
    protected function configure(): void
    {
        $this->setName('llm:ask')
            ->setDescription('Ask the analyst a question as an administrator, and see its answer, tools and cost')
            ->addArgument('question', InputArgument::REQUIRED, 'The question')
            ->addOption('as', null, InputOption::VALUE_REQUIRED, 'The administrator asking: an account id')
            ->addOption('conversation', null, InputOption::VALUE_REQUIRED, 'Continue this conversation of theirs')
            ->addOption('new', null, InputOption::VALUE_NONE, 'A new conversation (the default)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $as = (int) ($input->getOption('as') ?? 0);
        if ($as <= 0) {
            $output->writeln('<error>Say who asks: --as=<account id>.</error>');

            return Command::INVALID;
        }
        $conversation = $input->getOption('new') ? 0 : (int) ($input->getOption('conversation') ?? 0);
        $result       = $this->ask($as, (string) $input->getArgument('question'), $conversation > 0 ? $conversation : null);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
        }
        if (!$result['ok']) {
            $output->writeln('<error>' . $result['says'] . '</error>');

            return Command::FAILURE;
        }
        $output->writeln($result['answer']);
        $output->writeln('');
        foreach ($result['tools'] as $tool) {
            $output->writeln('<comment>tool</comment> ' . $tool['name'] . ' ' . json_encode($tool['arguments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $output->writeln('<comment>cost</comment> ' . ($result['cost'] === null ? '-' : sprintf('%.4f', $result['cost'])));
        $output->writeln('<comment>conversation</comment> ' . $result['conversation_id'] . ($result['url'] ? ' ' . $result['url'] : ''));

        return Command::SUCCESS;
    }

    /**
     * The question asked. A seam for tests, which cannot reach a provider.
     *
     * @return array<string, mixed> As {@see ConsoleAsk::ask()} answers
     */
    protected function ask(int $userId, string $question, ?int $conversationId): array
    {
        return ConsoleAsk::ask($userId, $question, $conversationId);
    }
}
