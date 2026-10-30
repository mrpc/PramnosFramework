<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Llm\Eval\Report;
use Pramnos\Llm\Eval\Suite;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * llm:eval — ask the eval fixtures of a directory, score the answers, and fail on a regression
 * against the baseline.
 *
 * ```
 * ./yourapp llm:eval tests/llm-eval --setup=tests/llm-eval/setup.php --baseline=tests/llm-eval/baseline.json
 * ./yourapp llm:eval tests/llm-eval --setup=… --baseline=… --write-baseline   # take the new scores as the baseline
 * ```
 *
 * `--setup` is a PHP file that returns what the cases are asked with:
 * `['system' => string, 'tools' => array, 'client' => callable(): Client]`, each optional — the
 * application's own assistant's prompt and tools, usually. Exits 1 when a gate fails
 * ({@see Report::regressions()}), so a CI step stops on it.
 */
class LlmEval extends Command
{
    protected function configure(): void
    {
        $this->setName('llm:eval')
            ->setDescription('Score the language model on eval fixtures and fail on a regression against the baseline')
            ->addArgument('fixtures', InputArgument::REQUIRED, 'Directory of *.json eval cases')
            ->addOption('setup', null, InputOption::VALUE_REQUIRED, 'PHP file returning [system, tools, client]')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'JSON file to compare with')
            ->addOption('write-baseline', null, InputOption::VALUE_NONE, 'Write the scores to --baseline instead of comparing')
            ->addOption('rounds', null, InputOption::VALUE_REQUIRED, 'Rounds each answer may take', '6');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $suite = Suite::fromDirectory((string) $input->getArgument('fixtures'));
        if ($suite->count() === 0) {
            $output->writeln('<error>No eval cases in ' . $input->getArgument('fixtures') . '.</error>');

            return Command::FAILURE;
        }

        $setup = [];
        if (($file = $input->getOption('setup')) !== null) {
            $setup = is_file((string) $file) ? (array) (require (string) $file) : [];
        }

        $report = $suite->run(
            (string) ($setup['system'] ?? ''),
            (array) ($setup['tools'] ?? []),
            isset($setup['client']) && is_callable($setup['client']) ? $setup['client'] : null,
            max(1, (int) $input->getOption('rounds'))
        );

        foreach ($report->cases as $case) {
            $output->writeln(sprintf(
                '%s  tools %d%%  arguments %d%%  answer %s  rounds %d%s',
                $case['name'],
                (int) round($case['tool_accuracy'] * 100),
                (int) round($case['argument_fidelity'] * 100),
                $case['final_state'] >= 1 ? 'ok' : 'no',
                $case['rounds'],
                $case['ok'] ? '' : '  (' . $case['says'] . ')'
            ));
        }
        $summary = $report->toArray()['summary'];
        $output->writeln(sprintf(
            'Mean: tools %.1f%%, arguments %.1f%%, answers %.1f%%, %.2f rounds.',
            $summary['tool_accuracy'] * 100,
            $summary['argument_fidelity'] * 100,
            $summary['final_state'] * 100,
            $summary['rounds']
        ));

        $baseline = $input->getOption('baseline');
        if ($baseline === null) {
            return Command::SUCCESS;
        }
        if ($input->getOption('write-baseline')) {
            file_put_contents((string) $baseline, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
            $output->writeln('Baseline written to ' . $baseline . '.');

            return Command::SUCCESS;
        }

        $saved = is_file((string) $baseline) ? json_decode((string) file_get_contents((string) $baseline), true) : null;
        if (!is_array($saved)) {
            $output->writeln('<error>No baseline at ' . $baseline . '; write one with --write-baseline.</error>');

            return Command::FAILURE;
        }

        $regressions = $report->regressions(Report::fromArray($saved));
        foreach ($regressions as $line) {
            $output->writeln('<error>Regression: ' . $line . '</error>');
        }

        return $regressions === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
