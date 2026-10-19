<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Auth\EmailSecondFactor;
use Pramnos\Auth\TwoFactorAuthService;
use Pramnos\User\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clear an account's second-factor enrolment, so its owner can enrol again.
 *
 * The way back for somebody who has lost their authenticator — and, once
 * `auth.security.require_factor_enrolment_from_usertype` is set, the **only** way back for
 * an administrator, because the screen that would fix it is behind the wall they cannot get
 * past and the operator who would help them may be behind the same wall.
 *
 * ```
 * php pramnos auth:twofactor-reset admin              # authenticator and mailed-code flag
 * php pramnos auth:twofactor-reset admin --passkeys   # their passkeys as well
 * php pramnos auth:twofactor-reset admin --dry-run    # say what would be cleared
 * ```
 *
 * ## It cannot lock anybody out
 *
 * Clearing everything leaves the account with no factor at all, which sounds like the worst
 * possible outcome for an account that is *required* to have one. It is not, and the reason
 * is worth stating: with nothing enrolled, `LoginFlow` satisfies the requirement with a
 * six-digit code by email — a demand every account can meet. So the person signs in with
 * their mailbox, meets the enrolment wall, and sets up a new authenticator. That is the
 * whole recovery path, and it needs no secret to travel over a phone call.
 *
 * ## What it does not do
 *
 * It does not print a secret, a QR URI or a backup code, and it does not enrol anything.
 * A command that could hand over an enrolment secret is a command that can enrol an
 * attacker on a support call; the person re-enrols themselves, from their own session, on
 * their own device.
 *
 * Passkeys are only removed when asked for explicitly. A passkey is a device somebody still
 * has — clearing it because they lost a *different* device is the kind of helpfulness that
 * takes away a working credential.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class AuthTwoFactorReset extends Command
{
    /** @var string The command name as typed */
    protected static $defaultName = 'auth:twofactor-reset';

    /** The fields an identifier is looked up against, in order. */
    private const LOOKUP_FIELDS = array('username', 'email', 'userid');

    protected function configure(): void
    {
        $this
            ->setName('auth:twofactor-reset')
            ->setDescription('Clear an account\'s second-factor enrolment so it can enrol again')
            ->addArgument(
                'user',
                InputArgument::REQUIRED,
                'Username, email address or user id'
            )
            ->addOption(
                'passkeys',
                'p',
                InputOption::VALUE_NONE,
                'Remove the account\'s passkeys too'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what would be cleared and change nothing'
            )
            ->addOption(
                'by',
                null,
                InputOption::VALUE_REQUIRED,
                'The administrator doing it, as a user id, for the audit log'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $needle = trim((string) $input->getArgument('user'));
        $userId = $this->resolveUser($needle);

        if ($userId === null) {
            $output->writeln('<error>No account matches "' . $needle . '".</error>');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $done   = array();

        if ($this->clearAuthenticator($userId, $dryRun)) {
            $done[] = 'authenticator enrolment and backup codes';
        }

        if ($this->clearMailedCodeFlag($userId, $dryRun)) {
            $done[] = 'the mailed-code factor';
        }

        if ($input->getOption('passkeys') && $this->clearPasskeys($userId, $dryRun)) {
            $done[] = 'passkeys';
        }

        // A browser trusted to skip the second factor would go on skipping the one enrolled next.
        $trusted = $this->clearTrustedDevices($userId, $dryRun);
        if ($trusted > 0) {
            $done[] = $trusted . ' trusted device(s)';
        }

        if ($done === []) {
            $output->writeln(
                '<comment>Account ' . $userId . ' had nothing enrolled; nothing to clear.</comment>'
            );

            return Command::SUCCESS;
        }

        $output->writeln(
            ($dryRun ? '<comment>Would clear' : '<info>Cleared') . ' for account ' . $userId
            . ': ' . implode(', ', $done) . ($dryRun ? '.</comment>' : '.</info>')
        );

        if (!$dryRun) {
            $this->audit($userId, $input->getOption('by') !== null ? (int) $input->getOption('by') : null, $done);
            // What the next sign-in asks for, by what this application offers.
            $output->writeln($this->offersEmailCode()
                ? 'They can sign in with a code sent to their email address, and will be asked to enrol again.'
                : 'Their next sign-in asks for the password alone, and they will be asked to enrol again.');
        }

        return Command::SUCCESS;
    }

    // ── Seams ────────────────────────────────────────────────────────────────

    /** @return bool Whether there was an enrolment to clear */
    protected function clearAuthenticator(int $userId, bool $dryRun): bool
    {
        // live-DB boundary; the tests override this
        // @codeCoverageIgnoreStart
        try {
            $service = new TwoFactorAuthService(\Pramnos\Framework\Factory::getDatabase());
            $status  = $service->getStatus($userId);

            if (empty($status['enabled']) && empty($status['setup'])) {
                return false;
            }

            return $dryRun ? true : $service->disableForOperator($userId);
        } catch (\Throwable) {
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /** @return bool Whether the mailed-code factor was on */
    protected function clearMailedCodeFlag(int $userId, bool $dryRun): bool
    {
        // live-DB boundary; the tests override this
        // @codeCoverageIgnoreStart
        try {
            $factor = new EmailSecondFactor();

            if (!$factor->isEnabledFor($userId)) {
                return false;
            }

            return $dryRun ? true : $factor->setEnabledFor($userId, false);
        } catch (\Throwable) {
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /** @return bool Whether any passkey was removed */
    /** The reset, in the audit log: whose, by whom when said, and what went. */
    protected function audit(int $userId, ?int $by, array $cleared): void
    {
        \Pramnos\Auth\AuditLog::record('auth.twofactor_reset', $by, $userId, ['cleared' => $cleared, 'via' => 'console']);
    }

    /** Whether this application signs a person in with a mailed code when they have no other factor. */
    protected function offersEmailCode(): bool
    {
        return \Pramnos\Auth\SecondFactorRegistry::get(\Pramnos\Auth\EmailSecondFactor::METHOD) !== null;
    }

    /**
     * Stop trusting the account's browsers. Returns how many were — or, in a dry run, are —
     * trusted.
     */
    protected function clearTrustedDevices(int $userId, bool $dryRun): int
    {
        $devices = new \Pramnos\Auth\TrustedDevices();
        if ($dryRun) {
            try {
                return count($devices->forUser($userId));
            } catch (\Throwable) {
                return 0;
            }
        }

        return $devices->revokeAll($userId);
    }

    protected function clearPasskeys(int $userId, bool $dryRun): bool
    {
        // live-DB boundary; the tests override this
        // @codeCoverageIgnoreStart
        try {
            $service = new \Pramnos\Auth\Passkey\PasskeyService();

            if (!$service->hasCredentials($userId)) {
                return false;
            }

            if ($dryRun) {
                return true;
            }

            \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
                ->table('authserver.passkey_credentials')
                ->where('userid', $userId)
                ->delete();

            return true;
        } catch (\Throwable) {
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * A username, an email address or an id, in that order.
     */
    protected function resolveUser(string $needle): ?int
    {
        if ($needle === '') {
            return null;
        }

        foreach (self::LOOKUP_FIELDS as $field) {
            if ($field === 'userid' && !ctype_digit($needle)) {
                continue;
            }

            $found = $this->lookup($needle, $field);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** One lookup, as its own seam so the tests need no database. */
    protected function lookup(string $needle, string $field): ?int
    {
        // live-DB boundary; the tests override this
        // @codeCoverageIgnoreStart
        if ($field === 'userid') {
            $user = new User((int) $needle);

            return (int) $user->userid > 0 ? (int) $user->userid : null;
        }

        $found = User::getuserid($needle, $field);

        return $found === false ? null : (int) $found;
        // @codeCoverageIgnoreEnd
    }
}
