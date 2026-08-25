<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Pramnos\User\User;

/**
 * Creates a user (optionally an administrator) from the command line.
 *
 * ## Usage
 *
 * ```
 * php pramnos user:create --username=alice --email=alice@example.com --password=secret
 * php pramnos user:create --username=root --email=root@example.com --admin
 * php pramnos user:create        # interactive — prompts for anything missing
 * ```
 *
 * Behaviour:
 *   - `--username`, `--email` and `--password` may be supplied as options.
 *   - When running interactively, any missing value is prompted for; the
 *     password prompt is hidden (`$question->setHidden(true)`).
 *   - When running non-interactively, a missing required value is a hard error.
 *   - The email address is validated before any database access.
 *   - Duplicate username / email addresses are refused when detectable.
 *   - The password is hashed by the framework's User model (bcrypt, salted with
 *     the securitySalt + userid), exactly like a normally-registered account.
 *   - `--admin` creates an administrator: `usertype = 90`, the tier every
 *     administrative screen in the framework actually requires.
 *   - `--usertype=N` sets the tier explicitly, for anything in between.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class UserCreate extends Command
{
    /**
     * The tier `--admin` grants.
     *
     * 90, because that is what the framework's administrative screens require:
     * Users, Settings, Logs, Dashboard, Services, Organizations, Emails and Queue
     * ask for 80 or more; Applications, Tokens, Permissions, `phpinfo` and the
     * dev panel ask for 90.
     *
     * This option used to set 1, which satisfied none of them — the command
     * printed "created successfully (admin)" and the account it made could not
     * open a single administrative page. `init` has always created its own first
     * administrator at 90, so the two paths disagreed, and the one this command
     * produced was the broken one.
     */
    public const ADMIN_USERTYPE = 90;

    protected static $defaultName = 'user:create';

    protected function configure(): void
    {
        $this
            ->setName('user:create')
            ->setDescription('Create a new user (or administrator) account')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Username for the new account')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address for the new account')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password for the new account')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Create the account as an administrator (usertype = ' . self::ADMIN_USERTYPE . ')')
            ->addOption('usertype', null, InputOption::VALUE_REQUIRED, 'Set the usertype explicitly (overrides --admin)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interactive = $input->isInteractive();

        // ── Collect inputs (option → interactive prompt → error) ──────────────
        $username = $this->resolveValue($input, $output, 'username', 'Username: ', false, $interactive);
        if ($username === null) {
            $output->writeln('<error>The --username option is required in non-interactive mode.</error>');
            return Command::FAILURE;
        }

        $email = $this->resolveValue($input, $output, 'email', 'Email address: ', false, $interactive);
        if ($email === null) {
            $output->writeln('<error>The --email option is required in non-interactive mode.</error>');
            return Command::FAILURE;
        }

        $password = $this->resolveValue($input, $output, 'password', 'Password: ', true, $interactive);
        if ($password === null) {
            $output->writeln('<error>The --password option is required in non-interactive mode.</error>');
            return Command::FAILURE;
        }

        // ── Validation (no database access yet) ───────────────────────────────
        $username = trim($username);
        $email    = trim($email);

        if ($username === '') {
            $output->writeln('<error>Username cannot be empty.</error>');
            return Command::FAILURE;
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $output->writeln('<error>Invalid email address: ' . $email . '</error>');
            return Command::FAILURE;
        }

        if ($password === '') {
            $output->writeln('<error>Password cannot be empty.</error>');
            return Command::FAILURE;
        }

        // ── Persistence (requires a live database) ────────────────────────────
        $usertype = $this->resolveUsertype($input, $output);
        if ($usertype === null) {
            return Command::FAILURE;
        }

        try {
            if ($this->userExists($username, 'username')) {
                $output->writeln('<error>A user with username "' . $username . '" already exists.</error>');
                return Command::FAILURE;
            }
            if ($this->userExists($email, 'email')) {
                $output->writeln('<error>A user with email "' . $email . '" already exists.</error>');
                return Command::FAILURE;
            }

            $userid = $this->persistUser($username, $email, $password, $usertype);
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to create user: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($userid < 2) {
            $output->writeln('<error>User creation failed: no valid user id was assigned.</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>User "%s" created successfully (userid=%d, usertype=%d%s).</info>',
            $username,
            $userid,
            $usertype,
            $usertype >= self::ADMIN_USERTYPE ? ', administrator' : ''
        ));

        return Command::SUCCESS;
    }

    // ── Persistence (overridable DB seams) ──────────────────────────────────────

    /**
     * The tier to create the account at.
     *
     * `--usertype` wins over `--admin`, because it is the more specific
     * instruction: somebody who names a number has a number in mind. Without
     * either, 0 leaves whatever default the User model applies.
     *
     * A non-numeric or negative `--usertype` is refused rather than coerced. `(int)`
     * on a typo would silently create a user at tier 0 and report success, and the
     * account that results is indistinguishable from one that was meant to be
     * ordinary.
     *
     * @return int|null The tier, or null when the input was invalid
     */
    protected function resolveUsertype(InputInterface $input, OutputInterface $output): ?int
    {
        $explicit = $input->getOption('usertype');

        if ($explicit !== null && $explicit !== '') {
            if (!is_numeric($explicit) || (int) $explicit != $explicit || (int) $explicit < 0) {
                $output->writeln(
                    '<error>--usertype must be a non-negative whole number, got: ' . $explicit . '</error>'
                );
                return null;
            }
            return (int) $explicit;
        }

        return $input->getOption('admin') ? self::ADMIN_USERTYPE : 0;
    }

    /**
     * Whether a user already exists whose $field equals $value.
     *
     * Behaviour-preserving extraction of the two inline duplicate checks: the
     * ($value, $field) shape is used (rather than a single username+email
     * predicate) precisely so execute() can keep emitting the distinct
     * "username already exists" vs "email already exists" messages. Overridden
     * in unit tests to avoid a live database.
     *
     * @param string $value Value to look up (a username or an email address).
     * @param string $field User model field to match on ('username' | 'email').
     * @return bool True when a matching user already exists.
     */
    protected function userExists(string $value, string $field): bool
    {
        // @codeCoverageIgnoreStart
        // Genuine live-DB boundary: User::getuserid() queries the users table.
        // Unit tests override this seam; the real lookup is covered by the
        // integration suite.
        return User::getuserid($value, $field) !== false;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Persist a new user and return the assigned user id.
     *
     * Behaviour-preserving extraction of the inline User-model creation. The
     * first save() assigns the userid; setPassword() then hashes the password
     * with the real userid (bcrypt + securitySalt) and a second save() persists
     * it — exactly as before. Overridden in unit tests to avoid a live database.
     *
     * @param string $username Validated, trimmed username.
     * @param string $email    Validated, trimmed email address.
     * @param string $password Plain-text password to hash.
     * @param int    $usertype Tier to assign, or 0 to leave the model's default.
     * @return int The newly assigned user id.
     */
    protected function persistUser(string $username, string $email, string $password, int $usertype = 0): int
    {
        // @codeCoverageIgnoreStart
        // Genuine live-DB boundary: instantiates and save()s the User model,
        // which writes to the users table and hashes the password. Not
        // executable without a database; unit tests override this seam and the
        // real persistence is covered by the integration suite.
        $user = new User();
        $user->username = $username;
        $user->email    = $email;
        $user->active   = 1;
        $user->validated = 1;
        $user->regdate  = time();
        if ($usertype > 0) {
            $user->usertype = $usertype;
        }

        $user->save();
        $user->setPassword($password);
        $user->save();

        return (int) $user->userid;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Resolve a value from an option, falling back to an interactive prompt.
     *
     * @param bool $hidden       Hide typed input (used for the password prompt).
     * @param bool $interactive  Whether the command may prompt at all.
     * @return string|null       The value, or null when it is unavailable and
     *                           cannot be prompted for (non-interactive mode).
     */
    private function resolveValue(
        InputInterface $input,
        OutputInterface $output,
        string $option,
        string $prompt,
        bool $hidden,
        bool $interactive
    ): ?string {
        $value = $input->getOption($option);
        if ($value !== null && $value !== '') {
            return (string) $value;
        }

        if (!$interactive) {
            return null;
        }

        $question = new Question($prompt);
        if ($hidden) {
            $question->setHidden(true);
            $question->setHiddenFallback(false);
        }

        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $answer = $helper->ask($input, $output, $question);

        return $answer === null ? null : (string) $answer;
    }
}
