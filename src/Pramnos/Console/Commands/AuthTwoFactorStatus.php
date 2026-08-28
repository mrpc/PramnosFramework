<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Auth\FactorEnrolment;
use Pramnos\Auth\SecurityPolicy;
use Pramnos\Auth\TwoFactorAuthService;
use Pramnos\User\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What second factor an account holds — or which privileged accounts hold none.
 *
 * The read half of second-factor support. Two questions, and both are asked from a terminal
 * because the person asking is usually looking at somebody who cannot sign in:
 *
 * ```
 * php pramnos auth:twofactor-status admin        # this account
 * php pramnos auth:twofactor-status --missing    # who the enrolment wall will stop
 * ```
 *
 * `--missing` is the one to run **before** switching
 * `auth.security.require_factor_enrolment_from_usertype` on. It lists the accounts at or
 * above that usertype holding nothing stronger than a mailed code — which is exactly the set
 * of people who will be redirected to the setup screen the moment the switch is set. Turning
 * a wall on without knowing who is behind it is how an operator finds out from a support
 * ticket.
 *
 * A word on what it does not print: never a secret, never a backup code, never a QR URI. A
 * support command that could read out an enrolment secret is a support command that can
 * enrol an attacker over the phone; the way back for somebody who has lost their
 * authenticator is `auth:twofactor-reset`, which makes them enrol again themselves.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class AuthTwoFactorStatus extends Command
{
    /** @var string The command name as typed */
    protected static $defaultName = 'auth:twofactor-status';

    /** How many accounts `--missing` lists before it says there are more. */
    protected const MISSING_LIMIT = 200;

    /** The fields an identifier is looked up against, in order. */
    private const LOOKUP_FIELDS = array('username', 'email', 'userid');

    private ?FactorEnrolment $enrolment = null;

    /**
     * The enrolment reading, as a seam.
     *
     * A test cannot use the real one: with no database, `FactorEnrolment` fails open and
     * reports every account as holding a strong factor — which is right in production and
     * makes "who is missing one" impossible to assert.
     */
    protected function enrolment(): FactorEnrolment
    {
        return $this->enrolment ??= new FactorEnrolment();
    }

    protected function configure(): void
    {
        $this
            ->setName('auth:twofactor-status')
            ->setDescription('Show what second factor an account holds, or who holds none')
            ->addArgument(
                'user',
                InputArgument::OPTIONAL,
                'Username, email address or user id'
            )
            ->addOption(
                'missing',
                'm',
                InputOption::VALUE_NONE,
                'List privileged accounts with nothing stronger than a mailed code'
            )
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Usertype floor for --missing; defaults to the configured one'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('missing')) {
            return $this->listMissing($input, $output);
        }

        $needle = (string) ($input->getArgument('user') ?? '');

        if (trim($needle) === '') {
            $output->writeln(
                '<error>Which account? Pass a username, an email address or a user id — '
                . 'or --missing to list the privileged ones with no strong factor.</error>'
            );

            return Command::FAILURE;
        }

        $userId = $this->resolveUser(trim($needle));

        if ($userId === null) {
            $output->writeln('<error>No account matches "' . $needle . '".</error>');

            return Command::FAILURE;
        }

        return $this->showOne($userId, $output);
    }

    /**
     * One account: what it holds, and whether that satisfies this installation.
     */
    protected function showOne(int $userId, OutputInterface $output): int
    {
        $user      = $this->loadUser($userId);
        $enrolment = $this->enrolment();
        $held      = $enrolment->factorsFor($userId);
        $usertype  = (int) ($user->usertype ?? 0);

        $rows = array(
            array('Account', ($user->username ?? '?') . ' (' . $userId . ')'),
            array('Usertype', (string) $usertype),
            array('Factors held', $held === [] ? 'none' : implode(', ', $held)),
        );

        $status = $this->totpStatus($userId);

        if ($status !== null) {
            $rows[] = array(
                'Authenticator',
                $status['enabled'] ? 'enabled' : ($status['setup'] ? 'set up, not enabled' : 'no'),
            );
            $rows[] = array('Backup codes left', (string) $status['backup_codes_remaining']);
        }

        $signInFloor  = SecurityPolicy::secondFactorFromUsertype();
        $enrolFloor   = SecurityPolicy::factorEnrolmentFromUsertype();
        $mustSignIn   = $signInFloor > 0 && $usertype >= $signInFloor;
        $mustEnrol    = $enrolment->isRequiredFor($userId, $usertype);

        $rows[] = array(
            'Second factor required to sign in',
            $signInFloor < 1
                ? 'no (switch off)'
                : ($mustSignIn ? 'yes (usertype ' . $signInFloor . '+)' : 'no (below the floor)')
        );
        $rows[] = array(
            'Must enrol something stronger',
            $enrolFloor < 1
                ? 'no (switch off)'
                : ($mustEnrol ? 'YES — every page redirects to the setup screen' : 'no')
        );

        (new Table($output))->setRows($rows)->render();

        if ($mustEnrol) {
            $output->writeln('');
            $output->writeln(
                '<comment>This account is behind the enrolment wall. It can still sign in — '
                . 'a mailed code satisfies the sign-in requirement — and lands on the setup '
                . 'screen until it enrols an authenticator or a passkey.</comment>'
            );
        }

        return Command::SUCCESS;
    }

    /**
     * The privileged accounts holding nothing stronger than a mailed code.
     */
    protected function listMissing(InputInterface $input, OutputInterface $output): int
    {
        $floor = (int) ($input->getOption('from') ?? 0);

        if ($floor < 1) {
            $floor = SecurityPolicy::factorEnrolmentFromUsertype()
                ?: SecurityPolicy::secondFactorFromUsertype();
        }

        if ($floor < 1) {
            $output->writeln(
                '<comment>No usertype floor is configured and none was given. Pass --from=80 '
                . 'to ask "who would the wall stop if I set it there".</comment>'
            );

            return Command::SUCCESS;
        }

        try {
            $accounts = $this->privilegedAccounts($floor);
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $enrolment = $this->enrolment();
        $rows      = array();

        foreach ($accounts as $account) {
            $userId = (int) ($account['userid'] ?? 0);

            if ($userId < 1 || $enrolment->hasStrongFactor($userId)) {
                continue;
            }

            $rows[] = array(
                $userId,
                (string) ($account['username'] ?? ''),
                (string) ($account['usertype'] ?? ''),
                implode(', ', $enrolment->factorsFor($userId)) ?: 'none',
            );
        }

        if ($rows === []) {
            $output->writeln(
                '<info>Every account of usertype ' . $floor . ' and above holds a strong '
                . 'factor.</info>'
            );

            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(array('id', 'username', 'usertype', 'holds'))
            ->setRows($rows)
            ->render();

        $output->writeln('');
        $output->writeln(
            '<comment>' . count($rows) . ' account(s) would be sent to the setup screen by '
            . 'require_factor_enrolment_from_usertype = ' . $floor . '.</comment>'
        );

        if (count($accounts) >= self::MISSING_LIMIT) {
            // Said out loud rather than silently truncated: a list that stops at a round
            // number reads as "that is everybody".
            $output->writeln(
                '<comment>Only the first ' . self::MISSING_LIMIT . ' privileged accounts were '
                . 'examined.</comment>'
            );
        }

        return Command::SUCCESS;
    }

    // ── Seams ────────────────────────────────────────────────────────────────

    /**
     * The accounts at or above a usertype.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function privilegedAccounts(int $floor): array
    {
        // @codeCoverageIgnoreStart — live-DB boundary; the tests override this
        $result = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()
            ->table('#PREFIX#users')
            ->select(array('userid', 'username', 'usertype'))
            ->where('usertype', '>=', $floor)
            ->orderBy('usertype', 'desc')
            ->limit(self::MISSING_LIMIT)
            ->get();

        $rows = array();

        while (($row = $result->fetch()) !== null) {
            $rows[] = (array) $row;
        }

        return $rows;
        // @codeCoverageIgnoreEnd
    }

    /**
     * The authenticator's own status, or null when the feature is not installed.
     *
     * @return ?array{enabled:bool,setup:bool,backup_codes_remaining:int}
     */
    protected function totpStatus(int $userId): ?array
    {
        // @codeCoverageIgnoreStart — live-DB boundary; the tests override this
        try {
            return (new TwoFactorAuthService(\Pramnos\Framework\Factory::getDatabase()))
                ->getStatus($userId);
        } catch (\Throwable) {
            return null;
        }
        // @codeCoverageIgnoreEnd
    }

    /** @return object The account */
    protected function loadUser(int $userId): object
    {
        // @codeCoverageIgnoreStart — live-DB boundary; the tests override this
        return new User($userId);
        // @codeCoverageIgnoreEnd
    }

    /**
     * A username, an email address or an id, in that order.
     */
    protected function resolveUser(string $needle): ?int
    {
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
        // @codeCoverageIgnoreStart — live-DB boundary; the tests override this
        if ($field === 'userid') {
            $user = new User((int) $needle);

            return (int) $user->userid > 0 ? (int) $user->userid : null;
        }

        $found = User::getuserid($needle, $field);

        return $found === false ? null : (int) $found;
        // @codeCoverageIgnoreEnd
    }
}
