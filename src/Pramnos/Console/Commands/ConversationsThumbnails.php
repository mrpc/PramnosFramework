<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Conversations\Files;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Every image sent in the conversations gets its thumbnail made again: fitted with its own
 * proportions, of its own type, and none for an image small enough to be shown itself.
 *
 *   ./pramnos conversations:thumbnails
 */
class ConversationsThumbnails extends Command
{
    /** Its name and what it does. */
    protected function configure(): void
    {
        $this->setName('conversations:thumbnails')
            ->setDescription('Make the thumbnails of the images sent in the conversations again');
    }

    /** Make them again, and say how many. */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $done = Files::rebuildThumbnails();
        $output->writeln($done['images'] . ' images, ' . $done['thumbnails'] . ' with a thumbnail; the rest are shown themselves.');

        return Command::SUCCESS;
    }
}
