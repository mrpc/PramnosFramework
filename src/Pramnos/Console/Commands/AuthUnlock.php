<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Auth\Loginlockout;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lifts a brute-force lockout, so testing an account does not mean waiting.
 *
 * The progressive lockout is doing its job when it locks somebody out: three
 * wrong passwords cost a minute, ten cost an hour. That is right for the
 * internet and wrong for the developer who has just mistyped a fixture password
 * three times and now cannot test the login flow they are working on.
 *
 * ```
 * php pramnos auth:unlock admin              # this identifier, every scope
 * php pramnos auth:unlock 2 --scope=user     # by user id
 * php pramnos auth:unlock 10.0.0.5 --scope=ip
 * php pramnos auth:unlock --list             # who is locked, and for how long
 * php pramnos auth:unlock --all              # everything (development only)
 * ```
 *
 * Deliberately **not** a way to bypass authentication: it clears the counter
 * that says how many times somebody has failed, and nothing else. A wrong
 * password is still a wrong password afterwards.
 *
 * `--all` refuses to run outside development, because "clear every lockout on
 * the server" is exactly what somebody trying passwords would want, and a
 * command that offers it on a live installation is a hole with a friendly name.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class AuthUnlock extends Command
{
    /** @var string The command name as typed */
    protected static $defaultName = 'auth:unlock';

    /**
     * The scopes a lockout can be recorded under.
     *
     * `identifier` is what the login form was given (a username or an email),
     * `user` is the account it resolved to, and `ip` is where it came from. A
     * failed login writes to more than one, which is why clearing "the lockout"
     * has to mean clearing all three unless told otherwise.
     *
     * @var list<string>
     */
    private const SCOPES = ['identifier', 'user', 'ip'];

    protected function configure(): void
    {
        $this
            ->setName('auth:unlock')
            ->setDescription('Lift a login lockout for a user, identifier or IP')
            ->addArgument(
                'identifier',
                InputArgument::OPTIONAL,
                'Username, email, user id or IP address to unlock'
            )
            ->addOption(
                'scope',
                's',
                InputOption::VALUE_REQUIRED,
                'Limit to one scope: identifier, user or ip. Default: all three.'
            )
            ->addOption(
                'list',
                'l',
                InputOption::VALUE_NONE,
                'Show what is currently locked instead of changing anything'
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Clear every lockout on this installation (development only)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identifier = (string) ($input->getArgument('identifier') ?? '');
        $scope      = (string) ($input->getOption('scope') ?? '');

        if ($scope !== '' && !in_array($scope, self::SCOPES, true)) {
            $output->writeln(
                '<error>Unknown scope "' . $scope . '". Use one of: '
                . implode(', ', self::SCOPES) . '.</error>'
            );

            return Command::FAILURE;
        }

        if ($input->getOption('list')) {
            return $this->listLockouts($output, $identifier, $scope);
        }

        if ($input->getOption('all')) {
            return $this->clearEverything($output);
        }

        if ($identifier === '') {
            $output->writeln(
                '<error>Give an identifier to unlock, or --list to see what is '
                . 'locked, or --all in development.</error>'
            );

            return Command::FAILURE;
        }

        return $this->clearOne($output, $identifier, $scope);
    }

    /**
     * Clear the lockout rows for one identifier.
     */
    private function clearOne(OutputInterface $output, string $identifier, string $scope): int
    {
        $lockout = new Loginlockout();
        $scopes  = $scope !== '' ? [$scope] : self::SCOPES;
        $cleared = 0;

        foreach ($scopes as $each) {
            // Reported before it is cleared: "nothing was locked" and "a lockout
            // was lifted" are different answers, and somebody running this wants
            // to know which one they got.
            $status = $lockout->getLockoutStatus($each, $identifier);
            $lockout->clearSuccessfulLoginState($each, $identifier);

            if ($status['locked']) {
                $cleared++;
                $output->writeln(
                    '  <info>' . $each . '</info> — lifted, '
                    . $this->describe((int) $status['remaining']) . ' remained'
                );
            }
        }

        $output->writeln('');
        $output->writeln(
            $cleared > 0
                ? '  <info>' . $identifier . '</info> can sign in again.'
                : '  <comment>' . $identifier . ' was not locked. The failure '
                    . 'counters were reset anyway.</comment>'
        );
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Show what is locked right now.
     */
    private function listLockouts(OutputInterface $output, string $identifier, string $scope): int
    {
        $rows = $this->activeLockouts($identifier, $scope);

        $output->writeln('');

        if ($rows === []) {
            $output->writeln('  <info>Nothing is locked.</info>');
            $output->writeln('');

            return Command::SUCCESS;
        }

        foreach ($rows as $row) {
            $output->writeln(
                '  <options=bold>' . $row['lookupvalue'] . '</> '
                . '<comment>(' . $row['locktype'] . ')</comment> — '
                . $row['failures'] . ' failures, unlocks in '
                . $this->describe($row['remaining'])
            );
        }

        $output->writeln('');
        $output->writeln(
            '  Lift one with <options=bold>auth:unlock ' . $rows[0]['lookupvalue'] . '</>'
        );
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Clear every lockout — development only.
     */
    private function clearEverything(OutputInterface $output): int
    {
        if (!$this->isDevelopment()) {
            $output->writeln('');
            $output->writeln(
                '<error>  --all only runs in development.</error>'
            );
            $output->writeln(
                '  Clearing every lockout on a live server is what somebody '
                . 'working through a password list would want. Unlock one '
                . 'account instead: <options=bold>auth:unlock <identifier></>'
            );
            $output->writeln('');

            return Command::FAILURE;
        }

        $db = \Pramnos\Database\Database::getInstance();
        $db->queryBuilder()->table('authserver.loginlockouts')->delete();

        $output->writeln('');
        $output->writeln('  <info>Every lockout cleared.</info>');
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * The lockouts still in effect, newest first.
     *
     * @return list<array{locktype: string, lookupvalue: string, failures: int, remaining: int}>
     */
    private function activeLockouts(string $identifier, string $scope): array
    {
        $db      = \Pramnos\Database\Database::getInstance();
        $builder = $db->queryBuilder()->table('authserver.loginlockouts')->select('*');

        if ($identifier !== '') {
            $builder->where('lookupvalue', $identifier);
        }
        if ($scope !== '') {
            $builder->where('locktype', $scope);
        }

        $result = $builder->get();
        if (!$result) {
            return [];
        }

        $now  = time();
        $rows = [];

        foreach ($result->fetchAll() as $row) {
            $until = !empty($row['lockoutuntil'])
                ? strtotime((string) $row['lockoutuntil'])
                : 0;

            // Rows outlive the lock they recorded — the counter is kept for the
            // sliding window. Only what is still in force is "locked".
            if ($until > $now) {
                $rows[] = [
                    'locktype'    => (string) ($row['locktype'] ?? ''),
                    'lookupvalue' => (string) ($row['lookupvalue'] ?? ''),
                    'failures'    => (int) ($row['failedattempts'] ?? 0),
                    'remaining'   => $until - $now,
                ];
            }
        }

        return $rows;
    }

    /**
     * Is this a development installation?
     *
     * The same question the debug toolbar asks, answered the same way, so an
     * installation cannot be development for one and production for the other.
     */
    private function isDevelopment(): bool
    {
        if (defined('DEVELOPMENT') && DEVELOPMENT === true) {
            return true;
        }

        $env = getenv('APP_DEBUG');
        if ($env !== false && $env !== '' && $env !== '0' && $env !== 'false') {
            return true;
        }

        $debug = \Pramnos\Application\Settings::getSetting('development');

        return $debug === true || $debug === '1' || $debug === 'true' || $debug === 'yes';
    }

    /**
     * Seconds as something a person reads without counting.
     */
    private function describe(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        }

        return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
    }
}
