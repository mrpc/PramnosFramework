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
 * skill:update — bring a skill taken from a catalogue up to its upstream, off again until it is read.
 *
 * Never automatic. A skill changed here is not overwritten: the upstream version is put beside it in
 * `.pf-upstream/`, and each file is said as changed here, upstream, or both — against `.pf-base/`, what
 * was taken — so the two can be merged by hand; `--force` takes upstream's and loses the changes.
 */
class SkillUpdate extends SkillCommand
{
    protected function configure(): void
    {
        $this->setName('skill:update')
            ->setDescription('Bring a skill taken from a catalogue up to its upstream (off again until enabled)')
            ->addArgument('skill', InputArgument::REQUIRED, 'Its name in the skills folder')
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, 'The commit or branch to take', 'HEAD')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Take upstream\'s even over changes made here');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (($folder = $this->folder($output)) === null) {
            return Command::FAILURE;
        }
        $skill = (string) $input->getArgument('skill');
        $lock  = $this->lock($folder);
        $entry = $lock->get($skill);
        if ($entry === null) {
            $output->writeln('<error>' . $skill . ' was not taken from a catalogue.</error>');

            return Command::FAILURE;
        }
        $dest = $folder . '/' . $skill;
        try {
            $source = SkillCatalog::parse((string) $entry['source']);
            $commit = $this->catalog()->commit($source['owner'], $source['repo'], (string) $input->getOption('ref'));
            if ($commit === ($entry['commit'] ?? '')) {
                $output->writeln($skill . ' is up to date.');

                return Command::SUCCESS;
            }
            $taken = $this->catalog()->fetch($source['owner'], $source['repo'], $source['path'], $commit);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $changedHere = SkillLock::hash($dest) !== ($entry['hash'] ?? '');
        if ($changedHere && !$input->getOption('force')) {
            $this->write($dest . '/.pf-upstream', $taken['files']);
            $base  = SkillLock::files($dest . '/.pf-base');
            $local = SkillLock::files($dest);
            $output->writeln('<error>' . $skill . ' has been changed here; nothing was overwritten. Upstream\'s is in ' . $dest . '/.pf-upstream:</error>');
            foreach (array_unique(array_merge(array_keys($base), array_keys($local), array_keys($taken['files']))) as $file) {
                $here = ($base[$file] ?? null) !== ($local[$file] ?? null);
                $up   = ($base[$file] ?? null) !== ($taken['files'][$file] ?? null);
                if ($here || $up) {
                    $output->writeln('  ' . $file . ': ' . ($here && $up ? 'changed here and upstream' : ($here ? 'changed here' : 'changed upstream')));
                }
            }
            $output->writeln('Merge them by hand, or take upstream\'s with --force.');

            return Command::FAILURE;
        }

        $this->write($dest, $taken['files']);
        $this->write($dest . '/.pf-base', $taken['files']);
        $lock->put($skill, ['commit' => $commit, 'hash' => SkillLock::hash($dest), 'enabled' => false, 'approved_hash' => null, 'updated_at' => gmdate('c')] + $entry);
        $output->writeln(sprintf('%s is at %s now (%d files).', $skill, substr($commit, 0, 7), count($taken['files'])));
        foreach ($taken['left_out'] as $left) {
            $output->writeln('  left out: ' . $left);
        }
        $output->writeln('<comment>It is off again until it is read: skill:enable ' . $skill . '</comment>');

        return Command::SUCCESS;
    }
}
