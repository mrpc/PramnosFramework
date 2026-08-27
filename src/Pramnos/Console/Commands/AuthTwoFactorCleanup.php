<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Auth\EmailSecondFactor;
use Pramnos\Auth\TwoFactorAuthService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Delete the second-factor rows that have been spent or have expired.
 *
 * Two tables, and two cleanup methods that existed with no caller — the same shape as
 * `cleanupAllAuthTokens()` before `auth:token-cleanup` was written:
 *
 * - `authserver.twofactor_email_codes`: one row per mailed code, and a code lives ten
 *   minutes. An account that signs in daily with email as its factor leaves a row a day,
 *   for ever, each holding an HMAC of a code that stopped being usable the same afternoon.
 * - `authserver.twofactor_setup`: the half-finished enrolments. A person who opens the
 *   setup screen and does not finish leaves one, and the ones that *were* finished are
 *   dead the moment they are used.
 *
 * Neither is urgent and neither is large, which is exactly why nothing ever ran them:
 * there was no visible consequence until an audit asked why a table of expired secrets
 * kept every row it had ever written. Deleted rather than retired, unlike a token: a spent
 * code is not an audit trail — what happened is in `user_activity_log`, and the hash of a
 * code proves nothing about it.
 *
 * ```
 * php pramnos auth:twofactor-cleanup
 * ```
 *
 * Registered in the framework schedule, daily and off-peak.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class AuthTwoFactorCleanup extends Command
{
    /** @var string The command name as typed */
    protected static $defaultName = 'auth:twofactor-cleanup';

    protected function configure(): void
    {
        $this
            ->setName('auth:twofactor-cleanup')
            ->setDescription('Delete spent and expired second-factor codes and enrolments');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = false;

        // Each on its own, because the two tables arrive with different migrations and an
        // installation mid-upgrade has one and not the other. A sweep that stopped at the
        // first missing table would silently stop sweeping the one that is there.
        foreach ($this->sweeps() as $what => $sweep) {
            try {
                $sweep();

                if ($output->isVerbose()) {
                    $output->writeln('<info>Swept ' . $what . '.</info>');
                }
            } catch (\Throwable $exception) {
                if ($this->looksLikeMissingTable($exception)) {
                    if ($output->isVerbose()) {
                        $output->writeln('<comment>No ' . $what . ' table; nothing to do.</comment>');
                    }

                    continue;
                }

                $output->writeln('<error>' . $what . ': ' . $exception->getMessage() . '</error>');
                $failed = true;
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * What this command sweeps, by the name it reports.
     *
     * A map rather than two inline calls so a third table is one entry, and so the loop
     * above can name whichever one failed.
     *
     * @return array<string, callable():void>
     */
    protected function sweeps(): array
    {
        return [
            // This one logs its own failures and returns, so a missing table never
            // reaches the loop above — which is why the loop reports per sweep rather
            // than assuming one outcome for both.
            'twofactor_email_codes' => static function (): void {
                (new EmailSecondFactor())->cleanupExpired();
            },
            'twofactor_setup' => static function (): void {
                (new TwoFactorAuthService(\Pramnos\Framework\Factory::getDatabase()))
                    ->cleanupExpiredSessions();
            },
        ];
    }

    /**
     * Whether a failure is "the table is not there" rather than a real error.
     *
     * Matched on the drivers' own words, because both report it as an ordinary query error
     * and neither offers a portable code the query builder passes on.
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
