<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Console\CommandBase;
use Pramnos\User\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retire session tokens nobody has used for a month.
 *
 * `usertokens` is append-only in practice. A `web_session` token is created on every
 * login and, until it was given an expiry, never stopped being valid; API tokens live
 * until something revokes them. Nothing ran a cleanup, because `cleanupAllAuthTokens()`
 * existed and had no caller.
 *
 * Measured on a two-day-old development installation with one user: 7,255 rows, all
 * `web_session`, arriving at about 230 an hour. That is also the table `tokenactions`
 * points a foreign key at, so the rows are not only dead weight — they are the thing a
 * buffered write can outlive.
 *
 * Retiring is `status = 2`, not a delete: the row stays for the audit trail and stops
 * being accepted. `lastused` is updated on every request that presents a token, so a
 * token idle for a month has nothing behind it — the PHP session it belonged to expired
 * after `session.gc_maxlifetime`, 24 minutes by default.
 *
 * ```
 * php pramnos auth:token-cleanup             # retire tokens idle for 30 days
 * php pramnos auth:token-cleanup --days=90   # be more patient
 * ```
 *
 * Registered in the framework schedule to run daily.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class AuthTokenCleanup extends Command
{
    /** @var string The command name as typed */
    protected static $defaultName = 'auth:token-cleanup';

    protected function configure(): void
    {
        $this
            ->setName('auth:token-cleanup')
            ->setDescription('Retire session tokens that have been idle for a month')
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_REQUIRED,
                'Idle days after which a token is retired',
                '30'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = max(1, (int) $input->getOption('days'));

        try {
            User::cleanupAllAuthTokens($days);
        } catch (\Throwable $exception) {
            // An application without the auth schema has no tokens to retire, and
            // that is not a failure — the schedule runs this on every installation.
            // Anything else is worth seeing.
            if ($this->looksLikeMissingTable($exception)) {
                if ($output->isVerbose()) {
                    $output->writeln('<comment>No usertokens table; nothing to do.</comment>');
                }

                return Command::SUCCESS;
            }

            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($output->isVerbose()) {
            $output->writeln(
                '<info>Tokens idle for more than ' . $days . ' days were retired.</info>'
            );
        }

        return Command::SUCCESS;
    }

    /**
     * Whether a failure is "the table is not there" rather than a real error.
     *
     * Matched on the drivers' own words, because both report it as an ordinary
     * query error and neither offers a portable code the query builder passes on.
     *
     * @param  \Throwable $exception
     * @return bool
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
