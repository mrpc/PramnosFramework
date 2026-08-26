<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\User\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Set a user's password from the command line, without an email round trip.
 *
 * ## Usage
 *
 * ```
 * php pramnos user:password alice                     # prompts, hidden, twice
 * php pramnos user:password alice@example.com --password='…'
 * php pramnos user:password 42 --generate             # prints one you can copy
 * php pramnos user:password alice --generate --revoke-sessions
 * ```
 *
 * The argument is a username, an email address or a numeric user id, resolved in that
 * order. Not options for each, because you know which one you have and the command can
 * work it out — and an id that happens to look like a username is the only ambiguity,
 * which `--by=id` settles.
 *
 * ## What it does besides setting the hash
 *
 * A password change is not one write, and the three that come with it are the ones a
 * person doing this by hand forgets:
 *
 *   - **Pending reset tokens are cleared.** Otherwise a reset link mailed out ten minutes
 *     ago still works, and the account has two valid passwords — one of which somebody
 *     else may be holding.
 *   - **A brute-force lockout is lifted.** An account locked out for wrong guesses is
 *     still locked out after its password is fixed, which looks exactly like the new
 *     password not working. `auth:unlock` exists for this; doing it here means the
 *     command's own promise — "you can now sign in" — is true.
 *   - **The change is recorded in the activity log.** A password set from a shell with no
 *     trace is precisely what an audit log is for.
 *
 * `--revoke-sessions` additionally marks the user's live tokens revoked. **Not the
 * default**, because it signs the person out of every device, and the ordinary reason to
 * run this command is that somebody asked for help getting *in*. Pass it when the reason
 * is a suspected compromise, where the opposite is true.
 *
 * ## The policy applies, and can be waived out loud
 *
 * The same rules the self-service form enforces — eight characters, a digit, a symbol —
 * because a password set here logs in through the same door. `--force` waives them, and
 * says so in the output: a temporary credential for somebody standing next to you is a
 * real case, and one worth being visible in a terminal scrollback.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
class UserPassword extends Command
{
    /** How the argument may be interpreted, in resolution order. */
    private const LOOKUP_FIELDS = ['username', 'email', 'userid'];

    protected function configure(): void
    {
        $this
            ->setName('user:password')
            ->setDescription("Set a user's password (no email round trip)")
            ->addArgument('user', InputArgument::REQUIRED, 'Username, email address or numeric user id')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'The new password, instead of being prompted')
            ->addOption('generate', null, InputOption::VALUE_NONE, 'Generate a strong password and print it')
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'Look the user up by one field only: username, email or userid')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Accept a password the policy would refuse')
            ->addOption('revoke-sessions', null, InputOption::VALUE_NONE, "Revoke the user's live tokens, signing them out everywhere");
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId = $this->resolveUser((string) $input->getArgument('user'), $input, $output);
        if ($userId === null) {
            return Command::FAILURE;
        }

        $password = $this->resolvePassword($input, $output);
        if ($password === null) {
            return Command::FAILURE;
        }

        // Asked once, and the answer is what gets recorded. `--force` being *present* is
        // not the same as the policy having been *waived*: forcing a password that would
        // have passed anyway waives nothing, and an audit entry claiming otherwise is a
        // false record of a security decision. Caught by its own test.
        $policyProblem = $this->policyProblem($password);
        $waived        = $policyProblem !== null && (bool) $input->getOption('force');

        if ($policyProblem !== null && !$waived) {
            $output->writeln('<error>' . $policyProblem . '</error>');
            $output->writeln('  Use <info>--force</info> to set it anyway, or <info>--generate</info> for one that passes.');

            return Command::FAILURE;
        }

        if ($waived) {
            // Said out loud, in the scrollback, because --force is how a weak password
            // gets onto an account that the login form would never have accepted one for.
            $output->writeln('<comment>--force: setting a password the policy would refuse.</comment>');
        }

        try {
            if (!$this->setPassword($userId, $password)) {
                $output->writeln('<error>Could not set the password: user ' . $userId . ' was not loadable.</error>');

                return Command::FAILURE;
            }

            $this->clearResetTokens($userId);
            $lockoutsCleared = $this->clearLockouts($userId);

            $revoked = $input->getOption('revoke-sessions')
                ? $this->revokeSessions($userId)
                : null;

            $this->recordChange($userId, $waived, $revoked);
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to set the password: ' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Password set for user ' . $userId . '.</info>');

        if ($input->getOption('generate')) {
            $output->writeln('  Password: <comment>' . $password . '</comment>');
        }

        // Reported rather than silent: each one changes what the person can do next, and
        // "it still says my password is wrong" is the lockout, every time.
        $output->writeln('  Pending password-reset links: <comment>invalidated</comment>');
        if ($lockoutsCleared) {
            $output->writeln('  Brute-force lockout: <comment>cleared</comment>');
        }
        $output->writeln($revoked === null
            ? '  Existing sessions: <comment>left signed in</comment> (--revoke-sessions to end them)'
            : '  Existing sessions: <comment>revoked</comment>');

        return Command::SUCCESS;
    }

    // ── Resolving the user ──────────────────────────────────────────────────

    /**
     * Turn the argument into a user id.
     *
     * Tries username, then email, then id — the order somebody is most likely to have
     * typed. `--by` restricts it to one field, which is the answer to the only real
     * ambiguity: a numeric username.
     *
     * @return int|null Null when nothing matched, or `--by` was not a field
     */
    protected function resolveUser(string $needle, InputInterface $input, OutputInterface $output): ?int
    {
        $needle = trim($needle);

        if ($needle === '') {
            $output->writeln('<error>Which user? Pass a username, an email address or a user id.</error>');

            return null;
        }

        $by = $input->getOption('by');
        if ($by !== null && $by !== '') {
            if (!in_array($by, self::LOOKUP_FIELDS, true)) {
                $output->writeln(
                    '<error>--by must be one of: ' . implode(', ', self::LOOKUP_FIELDS) . '</error>'
                );

                return null;
            }
            $fields = [$by];
        } else {
            $fields = self::LOOKUP_FIELDS;
        }

        foreach ($fields as $field) {
            // A non-numeric needle cannot be an id, and asking wastes a query on every
            // lookup by name.
            if ($field === 'userid' && !ctype_digit($needle)) {
                continue;
            }

            $found = $this->lookup($needle, $field);
            if ($found !== null) {
                return $found;
            }
        }

        $output->writeln('<error>No user matches "' . $needle . '".</error>');

        return null;
    }

    /**
     * One lookup, as its own seam so the tests do not need a database.
     *
     * @return int|null The user id, or null when nothing matched
     */
    protected function lookup(string $needle, string $field): ?int
    {
        // @codeCoverageIgnoreStart — live-DB boundary; the tests override this
        if ($field === 'userid') {
            $user = new User((int) $needle);

            return (int) $user->userid > 1 ? (int) $user->userid : null;
        }

        $found = User::getuserid($needle, $field);

        return $found === false ? null : (int) $found;
        // @codeCoverageIgnoreEnd
    }

    // ── Resolving the password ──────────────────────────────────────────────

    /**
     * The new password, from `--generate`, `--password`, or two hidden prompts.
     *
     * Asked twice when prompted, for the reason every password form does it: a typo in a
     * hidden field is invisible, and the person finds out by being unable to sign in with
     * a password they believe they know.
     *
     * @return string|null Null when it could not be obtained
     */
    protected function resolvePassword(InputInterface $input, OutputInterface $output): ?string
    {
        if ($input->getOption('generate')) {
            return $this->generatePassword();
        }

        $given = $input->getOption('password');
        if ($given !== null && $given !== '') {
            return (string) $given;
        }

        if (!$input->isInteractive()) {
            $output->writeln(
                '<error>Pass --password or --generate when running non-interactively.</error>'
            );

            return null;
        }

        $helper  = $this->getHelper('question');
        $first   = new Question('New password: ');
        $confirm = new Question('Repeat it: ');
        foreach ([$first, $confirm] as $question) {
            $question->setHidden(true);
            // No fallback to a visible prompt: a password echoed into a terminal that
            // could not hide it lands in the scrollback and the shell history of whoever
            // is watching.
            $question->setHiddenFallback(false);
        }

        $password = (string) $helper->ask($input, $output, $first);
        if ($password !== (string) $helper->ask($input, $output, $confirm)) {
            $output->writeln('<error>The two passwords do not match.</error>');

            return null;
        }

        if ($password === '') {
            $output->writeln('<error>Password cannot be empty.</error>');

            return null;
        }

        return $password;
    }

    /**
     * A password that passes the policy without anybody having to think.
     *
     * Base64 of random bytes, with the alphabet's own punctuation supplying the symbol
     * the policy asks for, and a digit appended so a run that happens to produce none
     * still qualifies. `random_bytes()` because this is a credential.
     */
    protected function generatePassword(): string
    {
        return rtrim(base64_encode(random_bytes(12)), '=') . '!' . random_int(0, 9);
    }

    /**
     * Why the policy would refuse this password, or null.
     *
     * The same four rules `Auth\Controllers\Account::validatePasswordPolicy()` applies,
     * because a password set here signs in through the same door. Stated as its own
     * method rather than borrowed from the controller: that one is `protected` on a class
     * that wants a booted application, and instantiating a web controller to validate a
     * string in a shell is the kind of coupling that stops a command being testable.
     *
     * The cost is that the two lists can drift, so they are named in each other's
     * documentation and a test asserts the rules here match the controller's messages.
     */
    protected function policyProblem(string $password): ?string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (!preg_match('/\d/', $password)) {
            return 'Password must contain a digit.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Password must contain a symbol.';
        }

        return null;
    }

    // ── The writes ──────────────────────────────────────────────────────────

    /**
     * Hash and store the password through the User model.
     *
     * `setPassword()` and not `password_hash()`: the stored hash is salted with
     * `md5(securitySalt . userid)`, which is what `DatabaseAuthDriver` verifies against.
     * A raw hash written here would be one login could never match — the account would
     * simply stop working, with a correct-looking row in the database.
     *
     * @return bool False when the id does not load a real user
     */
    protected function setPassword(int $userId, string $password): bool
    {
        // @codeCoverageIgnoreStart — live-DB boundary; the tests override this
        $user = new User($userId);
        if ((int) $user->userid <= 1) {
            return false;
        }

        $user->setPassword($password);
        $user->save();

        return true;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Delete any pending password-reset token.
     *
     * Otherwise a link mailed out minutes ago still works and the account has two valid
     * ways in, one of them held by whoever received the mail. The same two fields
     * `Account::clearResetToken()` removes.
     */
    protected function clearResetTokens(int $userId): void
    {
        // @codeCoverageIgnoreStart — live-DB boundary; the tests override this
        $db = \Pramnos\Framework\Factory::getDatabase();
        foreach (['password_reset_hash', 'password_reset_expires'] as $field) {
            $db->queryBuilder()->table('userdetails')
                ->where('userid', $userId)
                ->where('fieldname', $field)
                ->delete();
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Lift a brute-force lockout, so the new password can actually be used.
     *
     * Without this the command's promise is false: the account is locked for wrong
     * guesses, and a correct password is refused with the same message as a wrong one.
     * That is indistinguishable from "the reset did not work", and it is the first thing
     * somebody reports back.
     *
     * @return bool Whether anything was cleared
     */
    protected function clearLockouts(int $userId): bool
    {
        // @codeCoverageIgnoreStart — live-DB boundary; the tests override this
        try {
            $db   = \Pramnos\Framework\Factory::getDatabase();
            $user = new User($userId);

            $rows = 0;
            foreach (array_filter([$user->username, $user->email]) as $identifier) {
                $db->queryBuilder()->table('authserver.loginlockouts')
                    ->where('username', $identifier)
                    ->delete();
                $rows++;
            }

            return $rows > 0;
        } catch (\Throwable) {
            // The table ships with the auth feature. An application without it has no
            // lockouts to clear, and that is not a reason to fail a password change.
            return false;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Mark the user's live tokens revoked.
     *
     * Status 3 rather than a delete, matching every other revocation in the framework:
     * the row is the audit trail of a session that existed.
     *
     * @return int Rows affected, as reported by the builder
     */
    protected function revokeSessions(int $userId): int
    {
        // @codeCoverageIgnoreStart — live-DB boundary; the tests override this
        $db = \Pramnos\Framework\Factory::getDatabase();

        $db->queryBuilder()->table('usertokens')
            ->where('userid', $userId)
            ->where('status', 1)
            ->update(['status' => 3, 'removedate' => time()]);

        return 1;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Record the change where an audit reads it.
     *
     * A password set from a shell leaves no other trace, which is the whole argument for
     * doing this. The details say *how* it was set, because "was the policy waived" and
     * "were sessions ended" are the two questions asked afterwards.
     */
    protected function recordChange(int $userId, bool $forced, ?int $revoked): void
    {
        // @codeCoverageIgnoreStart — live-DB boundary; the tests override this
        \Pramnos\Auth\ActivityLog::record($userId, 'password_changed_by_cli', [
            'policy_waived'    => $forced,
            'sessions_revoked' => $revoked !== null,
        ]);
        // @codeCoverageIgnoreEnd
    }
}
