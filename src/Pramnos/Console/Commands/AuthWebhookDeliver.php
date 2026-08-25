<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Auth\WebhookService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deliver the queued OAuth2 webhook events.
 *
 * The authorization server queues an event whenever something a relying party
 * needs to hear about happens: a user deauthorizes an application, a token is
 * revoked, a GDPR erasure completes, a profile changes. `Gdpr`, `Device` and
 * `PermissionsController` all call `WebhookService::queueEvent()`.
 *
 * Nothing called `processQueue()`. The events were written, and they stayed
 * `pending` forever — a delivery pipeline with retries, exponential back-off and
 * HMAC signing, complete except for anything that ran it. The applications that
 * had registered an endpoint were never told a thing, and the failure is
 * invisible from both ends: the server logs a successful queue write, and the
 * relying party has nothing to notice the absence of.
 *
 * ```
 * php pramnos auth:webhook-deliver              # send what is due
 * php pramnos auth:webhook-deliver --batch=200  # a bigger bite
 * php pramnos auth:webhook-deliver --purge=30   # also drop settled events older than 30 days
 * ```
 *
 * Registered in the framework schedule to run every five minutes. That is the
 * cadence the retry back-off assumes: it starts at five minutes, so a slower
 * schedule does not delay the first attempt — it delays every attempt.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class AuthWebhookDeliver extends Command
{
    /** @var string The command name as typed */
    protected static $defaultName = 'auth:webhook-deliver';

    protected function configure(): void
    {
        $this
            ->setName('auth:webhook-deliver')
            ->setDescription('Deliver queued OAuth2 webhook events to registered endpoints')
            ->addOption(
                'batch',
                'b',
                InputOption::VALUE_REQUIRED,
                'How many events to attempt in one run',
                '50'
            )
            ->addOption(
                'purge',
                null,
                InputOption::VALUE_REQUIRED,
                'Also delete settled events older than this many days'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batch = max(1, (int) $input->getOption('batch'));

        try {
            $service = $this->service();
            $result  = $service->processQueue($batch);
        } catch (\Throwable $exception) {
            // An installation without the authserver feature has no webhook
            // tables, and the schedule runs this everywhere. That is not a
            // failure; anything else is.
            if ($this->looksLikeMissingTable($exception)) {
                if ($output->isVerbose()) {
                    $output->writeln('<comment>No webhook tables; nothing to deliver.</comment>');
                }

                return Command::SUCCESS;
            }

            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $sent   = (int) ($result['sent'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);

        $purged = null;
        $purge  = $input->getOption('purge');
        if ($purge !== null && $purge !== '') {
            try {
                $purged = $service->purgeOldEvents(max(1, (int) $purge));
            } catch (\Throwable $exception) {
                // Delivery already happened and is the point of the run; a purge
                // that could not complete is worth saying and not worth failing.
                $output->writeln('<comment>Purge skipped: ' . $exception->getMessage() . '</comment>');
            }
        }

        // Quiet when there was nothing to do: this runs every five minutes, and a
        // scheduler that logs a line each time buries everything that matters.
        if ($sent === 0 && $failed === 0 && !$purged && !$output->isVerbose()) {
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>Webhooks: %d delivered, %d failed%s.</info>',
            $sent,
            $failed,
            $purged !== null ? ', ' . $purged . ' purged' : ''
        ));

        // A failed delivery is not a failed run. The event keeps its attempts and
        // its back-off, and the next run will try again; exiting non-zero would
        // make a scheduler treat an unreachable relying party as a broken command.
        return Command::SUCCESS;
    }

    /** The delivery service (seam: tests supply a double rather than a database). */
    protected function service(): WebhookService
    {
        return new WebhookService(\Pramnos\Framework\Factory::getDatabase());
    }

    /**
     * Whether a failure is "the table is not there" rather than a real error.
     *
     * Matched on the drivers' own words, because both report it as an ordinary
     * query error and neither offers a portable code the query builder passes on.
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
