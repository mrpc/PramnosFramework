<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Llm\Chat\SkillCatalog;
use Pramnos\Llm\Chat\SkillLock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * skill:add — take a skill folder from a GitHub repository into the skills folder, off.
 *
 * ```
 * ./yourapp skill:add coreyhaines31/marketingskills/skills/email-sequence@<commit>
 * ```
 *
 * Only its `SKILL.md`, `references/*.md|*.txt` and `evals/*.json` are taken; what else it carries is
 * listed as left out. It is recorded in `skills.lock.json` with its commit, hash and licence, and stays
 * off until somebody reads it and runs `skill:enable`: it is instructions to a model that can reach
 * the application's data. A copy of what was taken is kept in `.pf-base/`, which `skill:update`
 * compares with when the skill has been changed here.
 */
class SkillAdd extends SkillCommand
{
    protected function configure(): void
    {
        $this->setName('skill:add')
            ->setDescription('Take a skill from a GitHub repository into the skills folder, off until enabled')
            ->addArgument('source', InputArgument::REQUIRED, 'owner/repo/path/to/skill[@commit or branch]')
            ->addOption('as', null, InputOption::VALUE_REQUIRED, 'The name to keep it under (the folder\'s name otherwise)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (($folder = $this->folder($output)) === null) {
            return Command::FAILURE;
        }
        try {
            $source = SkillCatalog::parse((string) $input->getArgument('source'));
            $slug   = $this->slug($source['path'], $input->getOption('as'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $dest = $folder . '/' . $slug;
        if (file_exists($dest) || is_file($folder . '/' . $slug . '.md')) {
            $output->writeln('<error>There is already a skill called ' . $slug . '; skill:update brings a taken one up to date, or --as names this one.</error>');

            return Command::FAILURE;
        }

        try {
            $catalog = $this->catalog();
            $commit  = $catalog->commit($source['owner'], $source['repo'], $source['ref']);
            $taken   = $catalog->fetch($source['owner'], $source['repo'], $source['path'], $commit);
            $licence = $catalog->licence($source['owner'], $source['repo']);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $this->write($dest, $taken['files']);
        $this->write($dest . '/.pf-base', $taken['files']);
        $this->lock($folder)->put($slug, [
            'source'        => $source['owner'] . '/' . $source['repo'] . '/' . $source['path'],
            'commit'        => $commit,
            'hash'          => SkillLock::hash($dest),
            'licence'       => $licence,
            'enabled'       => false,
            'approved_hash' => null,
            'added_at'      => gmdate('c'),
        ]);

        $output->writeln(sprintf('Took %s at %s (%d files%s) into %s.', $slug, substr($commit, 0, 7), count($taken['files']), $licence !== '' ? ', ' . $licence : ', no licence named', $dest));
        foreach ($taken['left_out'] as $left) {
            $output->writeln('  left out: ' . $left);
        }
        $output->writeln('<comment>It is off. Read ' . $dest . '/SKILL.md and its references, then: skill:enable ' . $slug . '</comment>');

        return Command::SUCCESS;
    }
}
