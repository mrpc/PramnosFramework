<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Llm\Chat\Memory;
use Pramnos\Llm\Chat\MemoryAdmin;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * llm:memory-review — the memories Ask learned by itself, read again by the test learning applies
 * now ({@see Memory::LASTS}), and the ones that fail it proposed for forgetting, each with why.
 *
 * Nothing is forgotten unless asked: `--forget=3,5` forgets those, and only learned ones, each in
 * the audit log as a deletion by the console. Memories a person asked to be kept are not read.
 */
class LlmMemoryReview extends Command
{
    /** @var (callable(): \Pramnos\Llm\Client)|null The client, for a test; the default connection otherwise */
    public $client = null;

    protected function configure(): void
    {
        $this->setName('llm:memory-review')
            ->setDescription('Propose which learned Ask memories no longer pass the learning test; forget only those you name')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'One person\'s memories only')
            ->addOption('forget', null, InputOption::VALUE_REQUIRED, 'Comma-separated memory ids to forget, from the proposals');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = \Pramnos\Framework\Factory::getDatabase();
        if (($forget = trim((string) $input->getOption('forget'))) !== '') {
            return $this->forget($db, array_map('intval', explode(',', $forget)), $output);
        }
        try {
            $query = $db->queryBuilder()->table('assist.chat_memory')->where('source', 'learned')->where('userid', '>', 0);
            if ($input->getOption('user') !== null) {
                $query->where('userid', (int) $input->getOption('user'));
            }
            $memories = array_values($query->orderBy('memory_id')->getAllOrFail());
        } catch (\Throwable $e) {
            $output->writeln('<error>The memories could not be read: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
        if ($memories === []) {
            $output->writeln('No learned memories to review.');

            return Command::SUCCESS;
        }
        $client   = $this->client !== null ? ($this->client)() : new \Pramnos\Llm\Client();
        $proposed = Memory::review($client, array_map(static fn (array $m): array => [
            'memory_id' => (int) $m['memory_id'], 'kind' => (string) $m['kind'], 'content' => (string) $m['content'],
        ], $memories));
        $output->writeln(sprintf('%d learned memories read; %d proposed for forgetting.', count($memories), count($proposed)));
        foreach ($memories as $m) {
            if (isset($proposed[(int) $m['memory_id']])) {
                $output->writeln(sprintf('  #%d (user %d, %s) %s — %s', (int) $m['memory_id'], (int) $m['userid'], $m['kind'], $m['content'], $proposed[(int) $m['memory_id']]));
            }
        }
        if ($proposed !== []) {
            $output->writeln('Forget them with --forget=' . implode(',', array_keys($proposed)) . ', or some of them.');
        }

        return Command::SUCCESS;
    }

    /**
     * Forget the named memories that were learned; say which were and which were not.
     *
     * @param list<int> $ids
     */
    private function forget(\Pramnos\Database\Database $db, array $ids, OutputInterface $output): int
    {
        $admin = new MemoryAdmin($db);
        $done  = 0;
        foreach (array_unique(array_filter($ids)) as $id) {
            try {
                $learned = $db->queryBuilder()->table('assist.chat_memory')->where('memory_id', $id)->where('source', 'learned')->count() > 0;
            } catch (\Throwable) {
                $learned = false;
            }
            if ($learned && $admin->delete((object) ['userid' => 0], $id)) {
                $done++;
                $output->writeln('Forgot #' . $id . '.');
            } else {
                $output->writeln('<comment>#' . $id . ' is not a learned memory; left.</comment>');
            }
        }

        return $done > 0 || $ids === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
