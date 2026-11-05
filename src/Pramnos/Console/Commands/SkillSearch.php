<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * skill:search — the skills of the catalogues whose folder name holds a word, as sources `skill:add`
 * takes. The catalogues are `'llm' => ['chat' => ['skill_catalogues' => ['owner/repo', …]]]`, or
 * {@see \Pramnos\Llm\Chat\SkillCatalog::DEFAULT_CATALOGUES}.
 */
class SkillSearch extends SkillCommand
{
    protected function configure(): void
    {
        $this->setName('skill:search')
            ->setDescription('Find skills in the catalogues by a word in their name')
            ->addArgument('word', InputArgument::OPTIONAL, 'A word in the skill\'s folder name; none lists them all', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $chat       = \Pramnos\Llm\Llm::config('chat', []);
        $catalogues = is_array($chat) && is_array($chat['skill_catalogues'] ?? null) ? $chat['skill_catalogues'] : \Pramnos\Llm\Chat\SkillCatalog::DEFAULT_CATALOGUES;
        $found      = 0;
        foreach ($catalogues as $catalogue) {
            try {
                foreach ($this->catalog()->search((string) $catalogue, (string) $input->getArgument('word')) as $source) {
                    $output->writeln($source);
                    $found++;
                }
            } catch (\Throwable $e) {
                $output->writeln('<comment>' . $catalogue . ' could not be read: ' . $e->getMessage() . '</comment>');
            }
        }
        if ($found === 0) {
            $output->writeln('No skill found.');
        }

        return Command::SUCCESS;
    }
}
