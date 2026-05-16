<?php

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Pramnos\Application\Application;
use Pramnos\Policy\PolicyEngine as Engine;

/**
 * Policy Engine daemon — runs all due framework policies.
 *
 * On TimescaleDB this command exits immediately (native policies are used).
 * On MySQL and plain PostgreSQL it reads the `framework_policies` table and
 * executes each due policy.
 *
 * ## Usage
 *
 * ```
 * php pramnos service:policy-engine
 * php pramnos service:policy-engine --list
 * php pramnos service:policy-engine --pretend
 * ```
 *
 * Exit codes: 0 = success, 1 = one or more policies failed.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @package     PramnosFramework
 * @subpackage  Console
 */
class PolicyEngine extends Command
{
    protected static $defaultName = 'service:policy-engine';

    protected function configure(): void
    {
        $this
            ->setName('service:policy-engine')
            ->setDescription('Run all due framework policies (retention, aggregate_refresh, etc.)')
            ->addOption(
                'list',
                'l',
                InputOption::VALUE_NONE,
                'List all enabled policies without running them'
            )
            ->addOption(
                'pretend',
                null,
                InputOption::VALUE_NONE,
                'Show which policies would run without executing them'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = Application::getInstance();

        if (!$app instanceof Application) {
            $output->writeln('<error>No application instance available.</error>');
            return Command::FAILURE;
        }

        $engine = new Engine($app);

        if ($input->getOption('list')) {
            return $this->listPolicies($engine, $output);
        }

        $db = $app->database;

        // On TimescaleDB, native policies handle this — nothing to do
        if ($db->type === 'timescaledb') {
            $output->writeln(
                '<info>TimescaleDB detected: native policies are active. '
                . 'service:policy-engine is a no-op on this backend.</info>'
            );
            return Command::SUCCESS;
        }

        if ($input->getOption('pretend')) {
            return $this->pretendRun($engine, $output);
        }

        return $this->doRun($engine, $output);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function listPolicies(Engine $engine, OutputInterface $output): int
    {
        $policies = $engine->getAllEnabled();

        if (empty($policies)) {
            $output->writeln('<comment>No enabled policies registered.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Type', 'Target', 'Last Run', 'Next Run']);

        foreach ($policies as $p) {
            $table->addRow([
                $p->policyid,
                $p->policyType,
                $p->target,
                $p->lastRun  ?? '—',
                $p->nextRun  ?? '—',
            ]);
        }

        $table->render();
        return Command::SUCCESS;
    }

    private function pretendRun(Engine $engine, OutputInterface $output): int
    {
        $policies = $engine->getAllEnabled();

        if (empty($policies)) {
            $output->writeln('<comment>No policies would run.</comment>');
            return Command::SUCCESS;
        }

        foreach ($policies as $p) {
            $output->writeln(
                "[dry-run] Would execute <info>{$p->policyType}</info>"
                . " on <info>{$p->target}</info> (id={$p->policyid})"
            );
        }

        return Command::SUCCESS;
    }

    private function doRun(Engine $engine, OutputInterface $output): int
    {
        $results = $engine->run();
        $errors  = 0;

        if (empty($results)) {
            $output->writeln('<info>No policies due at this time.</info>');
            return Command::SUCCESS;
        }

        foreach ($results as $r) {
            $label = "{$r['policy_type']} on {$r['target']} (id={$r['policyid']})";

            if ($r['status'] === 'ok') {
                $output->writeln("<info>✓ {$label}</info>");
            } else {
                $output->writeln("<error>✗ {$label}: {$r['error']}</error>");
                ++$errors;
            }
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
