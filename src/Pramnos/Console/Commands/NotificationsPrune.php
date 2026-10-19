<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Notification\Feed;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `notifications:prune` — remove in-app notifications read more than so many days ago.
 *
 * ```bash
 * php bin/pramnos notifications:prune                 # by 'notifications' => ['keep_read_days' => …]
 * php bin/pramnos notifications:prune --days=180
 * ```
 *
 * Unread notifications are never removed: an alert nobody opened is the one case where age is not
 * a reason to. With no number given or configured it removes nothing and says so — how long to keep
 * them is the application's decision, and the framework runs this daily so that deciding is all
 * an application has to do.
 */
class NotificationsPrune extends Command
{
    protected function configure(): void
    {
        $this->setName('notifications:prune')
            ->setDescription('Remove in-app notifications read more than so many days ago')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Days to keep a read notification; defaults to notifications.keep_read_days');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int) ($input->getOption('days') ?? 0);
        if ($days < 1) {
            $app  = \Pramnos\Application\Application::currentInstance();
            $days = (int) (is_object($app) ? ($app->applicationInfo['notifications']['keep_read_days'] ?? 0) : 0);
        }
        if ($days < 1) {
            $output->writeln('No retention is set, so nothing is removed. Set \'notifications\' => [\'keep_read_days\' => 180] in app.php.');

            return Command::SUCCESS;
        }
        if (!\Pramnos\Framework\Factory::getDatabase()->schema()->hasTable('notifications')) {
            $output->writeln('There is no notifications table.');

            return Command::SUCCESS;
        }
        $output->writeln(sprintf('%d notification(s) read over %d days ago removed.', Feed::prune($days), $days));

        return Command::SUCCESS;
    }
}
