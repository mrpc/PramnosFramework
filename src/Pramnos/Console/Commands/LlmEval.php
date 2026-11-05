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
 * `['system' => string, 'tools' => array, 'client' => callable(): Client, 'context' => array]`,
 * each optional — the application's own assistant's prompt and tools, usually. Exits 1 when a gate
 * fails ({@see Report::regressions()}), so a CI step stops on it.
 *
 * ```
 * ./yourapp llm:eval --skills --setup=tests/llm-eval/setup.php              # every skill folder's evals/
 * ./yourapp llm:eval --skill=crm-loyalty --setup=… --write-baseline
 * ```
 *
 * With `--skills` or `--skill`, the cases are each skill folder's `evals/*.json`
 * ({@see \Pramnos\Llm\Chat\Skills}), asked as a conversation asks: the setup's tools grouped into
 * skills, with `use_skill` and the skills' own tools. A case naming no `available_tools` is offered
 * all of them, and one naming no `expected_skill` is expected to turn on its own skill — scored as
 * `skill_used`. Each skill is compared with its own `evals/baseline.json`, and asked with 16 rounds,
 * as a conversation is, unless `--rounds` says otherwise.
 *
 * `--repeat=N` asks every case N times and scores the mean ({@see Report::average()}): a judge's
 * grades vary from run to run. `-v` prints what each case called and the assertions it missed.
 */
class LlmEval extends Command
{
    protected function configure(): void
    {
        $this->setName('llm:eval')
            ->setDescription('Score the language model on eval fixtures and fail on a regression against the baseline')
            ->addArgument('fixtures', InputArgument::OPTIONAL, 'Directory of *.json eval cases')
            ->addOption('skills', null, InputOption::VALUE_NONE, 'Ask every skill folder\'s evals/ instead')
            ->addOption('skill', null, InputOption::VALUE_REQUIRED, 'Ask one skill\'s evals/')
            ->addOption('setup', null, InputOption::VALUE_REQUIRED, 'PHP file returning [system, tools, client]')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'JSON file to compare with')
            ->addOption('write-baseline', null, InputOption::VALUE_NONE, 'Write the scores to --baseline instead of comparing')
            ->addOption('rounds', null, InputOption::VALUE_REQUIRED, 'Rounds each answer may take (6; with --skills, 16 as in a conversation)')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Ask each case this many times and score the mean', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $setup = [];
        if (($file = $input->getOption('setup')) !== null) {
            $setup = is_file((string) $file) ? (array) (require (string) $file) : [];
        }
        $client = isset($setup['client']) && is_callable($setup['client']) ? $setup['client'] : null;
        $skills = $input->getOption('skills') || $input->getOption('skill') !== null;
        // A skill's cases are asked as a conversation asks them, with as many rounds as it has.
        $rounds = max(1, (int) ($input->getOption('rounds') ?? ($skills ? 16 : 6)));

        if ($skills) {
            return $this->skills($input, $output, $setup, $client, $rounds);
        }

        $fixtures = (string) $input->getArgument('fixtures');
        $suite    = $fixtures !== '' && is_dir($fixtures) ? Suite::fromDirectory($fixtures) : new Suite();
        if ($suite->count() === 0) {
            $output->writeln('<error>No eval cases in ' . $fixtures . '.</error>');

            return Command::FAILURE;
        }

        $report = $this->repeated($input, static fn (): Report => $suite->run((string) ($setup['system'] ?? ''), (array) ($setup['tools'] ?? []), $client, $rounds));
        $this->print($report, $output);

        $baseline = $input->getOption('baseline');

        return $baseline === null ? Command::SUCCESS : $this->compare($report, (string) $baseline, (bool) $input->getOption('write-baseline'), $output);
    }

    /**
     * Each skill folder's evals, asked with the setup's tools grouped into skills, each compared
     * with its own baseline.
     *
     * @param array<string, mixed> $setup
     */
    private function skills(InputInterface $input, OutputInterface $output, array $setup, ?callable $client, int $rounds): int
    {
        $all   = \Pramnos\Llm\Chat\Skills::load();
        $named = $input->getOption('skill');
        if ($named !== null && !isset($all[(string) $named])) {
            $output->writeln('<error>No skill called ' . $named . ' in the skills folder.</error>');

            return Command::FAILURE;
        }
        $context  = (array) ($setup['context'] ?? []);
        $registry = \Pramnos\Llm\Chat\Skills::declare((new \Pramnos\Llm\ToolRegistry())->add((array) ($setup['tools'] ?? [])), $all);
        $tools    = $registry->tools($context, []) + $registry->skillTool($context);

        $failed = false;
        $asked  = 0;
        foreach ($named !== null ? [(string) $named => $all[(string) $named]] : $all as $name => $skill) {
            if ($skill['evals'] === null) {
                continue;
            }
            $suite = Suite::fromDirectory($skill['evals'])->withTools(array_keys($tools), $name);
            if ($suite->count() === 0) {
                continue;
            }
            $asked++;
            $output->writeln('<info>' . $name . '</info>');
            $report = $this->repeated($input, static fn (): Report => $suite->run((string) ($setup['system'] ?? ''), $tools, $client, $rounds));
            $this->print($report, $output);
            $baseline = $skill['evals'] . '/baseline.json';
            if ($input->getOption('write-baseline') || is_file($baseline)) {
                $failed = $this->compare($report, $baseline, (bool) $input->getOption('write-baseline'), $output) !== Command::SUCCESS || $failed;
            }
        }
        if ($asked === 0) {
            $output->writeln('<error>No skill has eval cases (a skill folder\'s evals/*.json).</error>');

            return Command::FAILURE;
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * A run, or `--repeat` runs averaged ({@see Report::average()}).
     *
     * @param callable(): Report $run
     */
    private function repeated(InputInterface $input, callable $run): Report
    {
        $times = max(1, (int) $input->getOption('repeat'));
        if ($times === 1) {
            return $run();
        }
        $runs = [];
        for ($i = 0; $i < $times; $i++) {
            $runs[] = $run();
        }

        return Report::average($runs);
    }

    /** Each case's scores, then the means; with -v, what each called and the assertions it missed. */
    private function print(Report $report, OutputInterface $output): void
    {
        foreach ($report->cases as $case) {
            $output->writeln(sprintf(
                '%s  tools %d%%  arguments %d%%  answer %s  rounds %s%s%s%s',
                $case['name'],
                (int) round($case['tool_accuracy'] * 100),
                (int) round($case['argument_fidelity'] * 100),
                isset($case['runs']) ? sprintf('%d%%', (int) round($case['final_state'] * 100)) : ($case['final_state'] >= 1 ? 'ok' : 'no'),
                isset($case['runs']) ? sprintf('%.1f', $case['rounds']) : (string) $case['rounds'],
                (array_key_exists('skill_used', $case) ? (isset($case['runs']) ? sprintf('  skill used %d%%', (int) round($case['skill_used'] * 100)) : '  skill ' . ($case['skill_used'] >= 1 ? 'used' : 'not used')) : '')
                    . (array_key_exists('assertions', $case) ? sprintf('  assertions %d%%', (int) round($case['assertions'] * 100)) : ''),
                !empty($case['out_of_rounds']) ? '  (hit the round limit)' : '',
                $case['ok'] ? '' : '  (' . ($case['says'] ?? '') . ')'
            ));
            if ($output->isVerbose()) {
                if (($case['called'] ?? []) !== []) {
                    $output->writeln('    called: ' . implode(', ', (array) $case['called']));
                }
                foreach ((array) ($case['unmet'] ?? []) as $unmet) {
                    $output->writeln('    unmet: ' . $unmet);
                }
            }
        }
        $summary = $report->toArray()['summary'];
        $output->writeln(sprintf(
            'Mean: tools %.1f%%, arguments %.1f%%, answers %.1f%%, %.2f rounds%s.',
            $summary['tool_accuracy'] * 100,
            $summary['argument_fidelity'] * 100,
            $summary['final_state'] * 100,
            $summary['rounds'],
            (isset($summary['skill_used']) ? sprintf(', skill used %.1f%%', $summary['skill_used'] * 100) : '')
                . (isset($summary['assertions']) ? sprintf(', assertions met %.1f%%', $summary['assertions'] * 100) : '')
        ));
    }

    /** Write the baseline, or compare with it and say what regressed. */
    private function compare(Report $report, string $baseline, bool $write, OutputInterface $output): int
    {
        if ($write) {
            file_put_contents($baseline, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
            $output->writeln('Baseline written to ' . $baseline . '.');

            return Command::SUCCESS;
        }

        $saved = is_file($baseline) ? json_decode((string) file_get_contents($baseline), true) : null;
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
