<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Event\Transport\StreamConsumer;
use Pramnos\Event\Transport\StreamTransport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete event-stream entries older than `events.log_days` (30) that every consumer group has read
 * and nothing waits to try again. Scheduled daily by the framework.
 */
class EventsPrune extends Command
{
    /** The days the stream keeps when the application says nothing. */
    public const DEFAULT_DAYS = 30;

    protected function configure(): void
    {
        $this->setName('events:prune')
            ->setDescription('Delete event-stream entries every group has read, past the retention')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Days to keep (default: events.log_days, 30)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!(new StreamTransport())->available()) {
            $output->writeln('No event stream here; nothing to prune.');

            return Command::SUCCESS;
        }
        $app  = \Pramnos\Application\Application::currentInstance();
        $days = $input->getOption('days') !== null ? (int) $input->getOption('days')
            : (int) (is_object($app) ? ($app->applicationInfo['events']['log_days'] ?? self::DEFAULT_DAYS) : self::DEFAULT_DAYS);
        $output->writeln('Event stream: ' . StreamConsumer::prune($days) . ' entries older than ' . $days . ' days, read by every group, deleted.');

        return Command::SUCCESS;
    }
}
