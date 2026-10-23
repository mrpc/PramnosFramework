<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Conversations\Unfurls;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `conversations:unfurls-prune` — drop link previews not read again in a while, and the
 * pictures only they kept under `previews/`.
 *
 * ```bash
 * php bin/pramnos conversations:unfurls-prune                 # by 'conversations' => ['unfurl' => ['keep_days' => …]]
 * php bin/pramnos conversations:unfurls-prune --days=14
 * ```
 *
 * Unlike notifications or audit rows, how long a cached preview is kept is not an
 * application decision to opt into — the row and its picture are a cache, not history — so
 * this runs with a built-in default when the application has not configured one.
 */
class ConversationsUnfurlsPrune extends Command
{
    /** The days a preview is kept, unread again, when the application says nothing. */
    public const DEFAULT_DAYS = 30;

    protected function configure(): void
    {
        $this->setName('conversations:unfurls-prune')
            ->setDescription('Drop link previews not read again in a while, and their pictures')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Days to keep an unread preview; defaults to conversations.unfurl.keep_days, 30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app  = \Pramnos\Application\Application::currentInstance();
        $days = $input->getOption('days') !== null ? (int) $input->getOption('days')
            : (int) (is_object($app) ? ($app->applicationInfo['conversations']['unfurl']['keep_days'] ?? self::DEFAULT_DAYS) : self::DEFAULT_DAYS);
        if (!\Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('pramnos.link_previews')) {
            $output->writeln('There is no link_previews table.');

            return Command::SUCCESS;
        }
        $output->writeln(sprintf('%d link preview(s) not read again in %d days removed, with their pictures.', Unfurls::prune($days), $days));

        return Command::SUCCESS;
    }
}
