<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Llm\Chat\Skills;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * skill:list — every skill in the skills folder: the application's own, and those taken from
 * catalogues with whether they are on; with `--setup` (as `llm:eval` takes it), the tools each names
 * that nobody declared — then those installed from a catalogue into the database from the AI settings.
 */
class SkillList extends SkillCommand
{
    protected function configure(): void
    {
        $this->setName('skill:list')
            ->setDescription('List the skills, whether each is on, and the tools they name that nobody declared')
            ->addOption('setup', null, InputOption::VALUE_REQUIRED, 'PHP file returning [tools] — the application\'s tools, to check the names against');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Those installed from a catalogue into the database, from the AI settings: theirs is the
        // page, these commands govern the files.
        $stored = (new \Pramnos\Llm\Chat\CatalogSkills())->all();
        if (Skills::folder() === null && $stored !== []) {
            $this->listStored($stored, $output);

            return Command::SUCCESS;
        }
        if (($folder = $this->folder($output)) === null) {
            return Command::FAILURE;
        }
        $tools = null;
        if (($file = $input->getOption('setup')) !== null && is_file((string) $file)) {
            $tools = array_keys((array) ((array) (require (string) $file))['tools'] ?? []);
        }
        $lock = $this->lock($folder);
        foreach (Skills::load($folder, true) as $name => $skill) {
            $entry = $lock->get($skill['folder'] !== null ? basename($skill['folder']) : $name);
            $line  = $name . '  ' . ($entry === null ? 'own' : (!empty($entry['enabled']) ? 'on' : 'off (not read yet)') . '  ' . $entry['source'] . '@' . substr((string) $entry['commit'], 0, 7));
            if ($tools !== null) {
                $unknown = array_values(array_diff($skill['tools'], $tools));
                $line   .= $unknown !== [] ? '  — names tools nobody declared: ' . implode(', ', $unknown) : '';
            }
            $output->writeln($line);
        }
        $this->listStored($stored, $output);

        return Command::SUCCESS;
    }

    /**
     * The skills installed from a catalogue into the database, each with whether it is on.
     *
     * @param list<array<string, mixed>> $stored
     */
    private function listStored(array $stored, OutputInterface $output): void
    {
        foreach ($stored as $skill) {
            $output->writeln(\Pramnos\Llm\Chat\CatalogSkills::PREFIX . $skill['name'] . '  ' . ($skill['status'] === 'on' ? 'on' : 'off (not read yet)')
                . '  ' . $skill['source'] . '@' . substr((string) $skill['commit'], 0, 7) . '  (database, from the AI settings)');
        }
    }
}
