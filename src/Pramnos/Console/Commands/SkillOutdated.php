<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Llm\Chat\SkillCatalog;
use Pramnos\Llm\Chat\SkillLock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * skill:outdated — which skills taken from catalogues have changed upstream since they were taken,
 * and which have been changed here. Changes nothing.
 */
class SkillOutdated extends SkillCommand
{
    protected function configure(): void
    {
        $this->setName('skill:outdated')
            ->setDescription('Say which skills taken from catalogues changed upstream, or here');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (($folder = $this->folder($output)) === null) {
            return Command::FAILURE;
        }
        $entries = $this->lock($folder)->all();
        if ($entries === []) {
            $output->writeln('No skills taken from a catalogue.');

            return Command::SUCCESS;
        }
        foreach ($entries as $skill => $entry) {
            $notes = [];
            if (SkillLock::hash($folder . '/' . $skill) !== ($entry['hash'] ?? '')) {
                $notes[] = 'changed here';
            }
            try {
                $source = SkillCatalog::parse((string) $entry['source']);
                $last   = $this->catalog()->lastCommitFor($source['owner'], $source['repo'], $source['path']);
                if ($last !== '' && $last !== ($entry['commit'] ?? '')) {
                    $notes[] = 'upstream has ' . substr($last, 0, 7);
                }
            } catch (\Throwable $e) {
                $notes[] = 'upstream not read: ' . $e->getMessage();
            }
            $output->writeln(sprintf('%s  %s  %s%s', $skill, substr((string) ($entry['commit'] ?? ''), 0, 7), empty($entry['enabled']) ? 'off' : 'on', $notes !== [] ? '  — ' . implode('; ', $notes) : '  — up to date'));
        }

        return Command::SUCCESS;
    }
}
