<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Messaging\MassMessageDispatcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deliver the pending recipients of every mass message that is due.
 *
 * The sending half of the messaging feature. `massmessages` and `massmessagerecipients`
 * existed with models and no dispatcher; this is what turns a composed message into
 * deliveries, and it runs on a timer rather than in the request that pressed send.
 *
 * **Not in the request, deliberately.** A send of four thousand emails inside a POST is a
 * request that times out somewhere in the middle, leaving an operator with no idea how far
 * it got and a page that offers to send again. Here every recipient is marked as it is
 * attempted, so the answer to "how far did it get" is a row count and the answer to "is it
 * safe to run again" is yes.
 *
 * ```
 * php pramnos messages:dispatch                 # up to 100 recipients
 * php pramnos messages:dispatch --limit=1000    # a bigger bite
 * ```
 *
 * Registered in the framework schedule, every five minutes. The batch size is what bounds
 * one run, not the number of messages: a due message with ten thousand recipients is sent
 * over as many runs as it takes, and a second message queued behind it is not starved,
 * because the batch is shared across due messages in order.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MessagesDispatch extends Command
{
    /** @var string The command name as typed */
    protected static $defaultName = 'messages:dispatch';

    protected function configure(): void
    {
        $this
            ->setName('messages:dispatch')
            ->setDescription('Deliver pending recipients of due mass messages')
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'How many recipients to attempt in this run',
                (string) MassMessageDispatcher::DEFAULT_BATCH
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));

        try {
            $stats = $this->dispatcher()->dispatch($limit);
        } catch (\Throwable $exception) {
            if ($this->looksLikeMissingTable($exception)) {
                if ($output->isVerbose()) {
                    $output->writeln('<comment>No massmessages table; nothing to do.</comment>');
                }

                return Command::SUCCESS;
            }

            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($stats['attempted'] === 0) {
            if ($output->isVerbose()) {
                $output->writeln('Nothing due.');
            }

            return Command::SUCCESS;
        }

        $output->writeln(
            $stats['delivered'] . ' delivered, ' . $stats['failed'] . ' failed, of '
            . $stats['attempted'] . ' attempted.'
        );

        // A failure here is a delivery failure, not a command failure: the rows are marked,
        // the next run carries on, and a red line every five minutes for a handful of dead
        // addresses is how a schedule log stops being read. The counts are the report.
        return Command::SUCCESS;
    }

    /**
     * The dispatcher this command drives.
     *
     * Constructed here rather than inline in the `try`, for the same reason as elsewhere: a
     * collaborator built inside the branch you care about is a branch nobody can watch. The
     * interesting behaviour of this command is what it does with a throw, a zero and a set of
     * counts, and none of the three was reachable while the dispatcher was a `new` in an
     * expression.
     */
    protected function dispatcher(): MassMessageDispatcher
    {
        return new MassMessageDispatcher();
    }

    /**
     * Whether a failure is "the table is not there" rather than a real error.
     *
     * The messaging feature is optional, and the schedule runs everywhere.
     */
    protected function looksLikeMissingTable(\Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, "doesn't exist")          // MySQL
            || str_contains($message, 'does not exist')          // PostgreSQL
            || str_contains($message, 'undefined table')         // PostgreSQL, SQLSTATE text
            || str_contains($message, 'no such table');          // SQLite
    }
}
