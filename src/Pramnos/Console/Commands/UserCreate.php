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
 *   - `--admin` marks the new account as an administrator (usertype = 1).
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class UserCreate extends Command
{
    protected static $defaultName = 'user:create';

    protected function configure(): void
    {
        $this
            ->setName('user:create')
            ->setDescription('Create a new user (or administrator) account')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, 'Username for the new account')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address for the new account')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password for the new account')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Create the account as an administrator (usertype = 1)');
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
        try {
            if (User::getuserid($username, 'username') !== false) {
                $output->writeln('<error>A user with username "' . $username . '" already exists.</error>');
                return Command::FAILURE;
            }
            if (User::getuserid($email, 'email') !== false) {
                $output->writeln('<error>A user with email "' . $email . '" already exists.</error>');
                return Command::FAILURE;
            }

            $user = new User();
            $user->username = $username;
            $user->email    = $email;
            $user->active   = 1;
            $user->validated = 1;
            $user->regdate  = time();
            if ($input->getOption('admin')) {
                $user->usertype = 1;
            }

            // First save assigns the userid; setPassword() then hashes with the
            // real userid (bcrypt + securitySalt) and a second save persists it.
            $user->save();
            $user->setPassword($password);
            $user->save();
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to create user: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($user->userid < 2) {
            $output->writeln('<error>User creation failed: no valid user id was assigned.</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>User "%s" created successfully (userid=%d%s).</info>',
            $username,
            (int) $user->userid,
            $input->getOption('admin') ? ', admin' : ''
        ));

        return Command::SUCCESS;
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
