<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * skill:enable — turn on a skill taken from a catalogue, once somebody has read it; `--off` turns
 * it off. Its hash now is recorded as what was read.
 */
class SkillEnable extends SkillCommand
{
    protected function configure(): void
    {
        $this->setName('skill:enable')
            ->setDescription('Turn on a skill taken from a catalogue, after reading it (--off to turn it off)')
            ->addArgument('skill', InputArgument::REQUIRED, 'Its name in the skills folder')
            ->addOption('off', null, InputOption::VALUE_NONE, 'Turn it off instead');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (($folder = $this->folder($output)) === null) {
            return Command::FAILURE;
        }
        $skill = (string) $input->getArgument('skill');
        $on    = !$input->getOption('off');
        if (!$this->lock($folder)->enable($skill, $on)) {
            $output->writeln('<error>' . $skill . ' was not taken from a catalogue: a skill of the application\'s own is on as it is.</error>');

            return Command::FAILURE;
        }
        $output->writeln($skill . ($on ? ' is on.' : ' is off.'));

        return Command::SUCCESS;
    }
}
