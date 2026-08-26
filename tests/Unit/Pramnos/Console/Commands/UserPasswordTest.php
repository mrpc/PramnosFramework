<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\UserPassword;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for `user:password`.
 *
 * Every write the command makes is a live-database boundary, so each one is a
 * `protected` seam and the stub below records what was asked for instead of doing it.
 * That is what makes the interesting assertions possible: not "the hash is right" — the
 * `User` model owns that and the integration suite covers it — but **which of the four
 * writes happened, and in what circumstances**, which is where a password reset done by
 * hand goes wrong.
 *
 * The three that are easy to forget, and are therefore asserted hardest:
 *
 *   - a pending reset token left valid, so the account has two passwords;
 *   - a brute-force lockout left in place, so the new password is refused exactly like a
 *     wrong one and the person reports that the reset "did not work";
 *   - no audit trail, from a credential change made in a shell.
 */
#[CoversClass(UserPassword::class)]
class UserPasswordTest extends TestCase
{
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        // Symfony's completion command reads $_SERVER['PHP_SELF'] in configure().
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    /**
     * The command with every database boundary replaced by a recorder.
     *
     * @return array{0: CommandTester, 1: object} The tester and the command
     */
    private function make(?int $resolvesTo = 42, bool $setPasswordSucceeds = true): array
    {
        $command = new class ($resolvesTo, $setPasswordSucceeds) extends UserPassword {
            /** @var list<string> Every write, in order */
            public array $calls = [];
            public ?string $storedPassword = null;
            /** @var array<string, mixed>|null */
            public ?array $logged = null;
            /** @var list<array{string, string}> Lookups attempted */
            public array $lookups = [];

            public function __construct(
                private ?int $resolvesTo,
                private bool $setPasswordSucceeds
            ) {
                parent::__construct();
            }

            protected function lookup(string $needle, string $field): ?int
            {
                $this->lookups[] = [$needle, $field];

                // Resolve on the field the caller asked about first, so tests can assert
                // the order without caring which one won.
                return $this->resolvesTo;
            }

            protected function setPassword(int $userId, string $password): bool
            {
                $this->calls[] = 'setPassword';
                $this->storedPassword = $password;

                return $this->setPasswordSucceeds;
            }

            protected function clearResetTokens(int $userId): void
            {
                $this->calls[] = 'clearResetTokens';
            }

            protected function clearLockouts(int $userId): bool
            {
                $this->calls[] = 'clearLockouts';

                return true;
            }

            protected function revokeSessions(int $userId): int
            {
                $this->calls[] = 'revokeSessions';

                return 3;
            }

            protected function recordChange(int $userId, bool $forced, ?int $revoked): void
            {
                $this->calls[] = 'recordChange';
                $this->logged  = ['forced' => $forced, 'revoked' => $revoked];
            }
        };

        $app = new Application('test', '1.0');
        $app->add($command);
        $app->setAutoExit(false);

        return [new CommandTester($app->find('user:password')), $command];
    }

    // ── The writes a manual reset forgets ───────────────────────────────────

    /**
     * A successful change clears the reset token, the lockout, and logs itself.
     *
     * The heart of why this is a command rather than a note telling somebody to run two
     * queries. Asserted as a set rather than one at a time so that adding a fifth write
     * later does not quietly drop one of these four.
     */
    public function testASuccessfulChangeDoesAllTheWorkNotJustTheHash(): void
    {
        // Arrange
        [$tester, $command] = $this->make();

        // Act
        $status = $tester->execute(
            ['user' => 'alice', '--password' => 'Sufficient1!'],
            ['interactive' => false]
        );

        // Assert
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame(
            ['setPassword', 'clearResetTokens', 'clearLockouts', 'recordChange'],
            $command->calls
        );
    }

    /**
     * Sessions are left alone unless asked for.
     *
     * The default matters more than it looks. The ordinary reason to run this is that
     * somebody cannot get in; signing them out of every other device as well turns one
     * problem into several. `--revoke-sessions` is for the other reason — a suspected
     * compromise — where the opposite is true.
     */
    public function testSessionsSurviveByDefault(): void
    {
        // Arrange
        [$tester, $command] = $this->make();

        // Act
        $tester->execute(['user' => 'alice', '--password' => 'Sufficient1!'], ['interactive' => false]);

        // Assert
        $this->assertNotContains('revokeSessions', $command->calls);
        $this->assertStringContainsString('left signed in', $tester->getDisplay());
        $this->assertStringContainsString('--revoke-sessions', $tester->getDisplay(),
            'the output has to name the flag, or the option is undiscoverable');
    }

    /**
     * `--revoke-sessions` revokes them, and the log records that it did.
     */
    public function testRevokeSessionsIsHonouredAndAudited(): void
    {
        // Arrange
        [$tester, $command] = $this->make();

        // Act
        $tester->execute(
            ['user' => 'alice', '--password' => 'Sufficient1!', '--revoke-sessions' => true],
            ['interactive' => false]
        );

        // Assert
        $this->assertContains('revokeSessions', $command->calls);
        // The stub records what revokeSessions() returned, so a count reaches the log
        // rather than a flag — the command turns it into a boolean.
        $this->assertSame(3, $command->logged['revoked']);
        $this->assertStringContainsString('revoked', $tester->getDisplay());
    }

    /**
     * Nothing else is written when the password itself could not be set.
     *
     * The ordering that matters: clearing a reset token for an account whose password did
     * not change would remove the one working way in and leave nothing behind it.
     */
    public function testNothingElseHappensWhenTheHashCouldNotBeStored(): void
    {
        // Arrange
        [$tester, $command] = $this->make(setPasswordSucceeds: false);

        // Act
        $status = $tester->execute(
            ['user' => 'alice', '--password' => 'Sufficient1!'],
            ['interactive' => false]
        );

        // Assert
        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame(['setPassword'], $command->calls);
    }

    // ── The policy ──────────────────────────────────────────────────────────

    /**
     * A password the login form would refuse is refused here too.
     *
     * A password set from a shell signs in through the same door, so the same four rules
     * apply. The message has to name the way out, or the only option is guessing.
     *
     * @param string $password
     * @param string $expected Fragment of the refusal
     * @return void
     */
    #[DataProvider('weakPasswordProvider')]
    public function testAWeakPasswordIsRefused(string $password, string $expected): void
    {
        // Arrange
        [$tester, $command] = $this->make();

        // Act
        $status = $tester->execute(
            ['user' => 'alice', '--password' => $password],
            ['interactive' => false]
        );

        // Assert
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString($expected, $tester->getDisplay());
        $this->assertStringContainsString('--force', $tester->getDisplay());
        $this->assertSame([], $command->calls, 'nothing may be written for a refused password');
    }

    /** @return array<string, array{string, string}> */
    public static function weakPasswordProvider(): array
    {
        return [
            'too short'   => ['Ab1!', 'at least 8'],
            'no digit'    => ['abcdefgh!', 'must contain a digit'],
            'no symbol'   => ['abcdefgh1', 'must contain a symbol'],
        ];
    }

    /**
     * `--force` accepts a weak password and says so where somebody will see it.
     *
     * A temporary credential for somebody standing next to you is a real case. Leaving no
     * trace of it in the scrollback is not — this is how a weak password ends up on an
     * account the login form would never have accepted one for.
     */
    public function testForceAcceptsAWeakPasswordAndAnnouncesIt(): void
    {
        // Arrange
        [$tester, $command] = $this->make();

        // Act
        $status = $tester->execute(
            ['user' => 'alice', '--password' => 'weak', '--force' => true],
            ['interactive' => false]
        );

        // Assert
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame('weak', $command->storedPassword);
        $this->assertStringContainsString('--force: setting a password the policy would refuse', $tester->getDisplay());
        $this->assertTrue($command->logged['forced'], 'the audit entry has to record that the policy was waived');
    }

    /**
     * `--force` with a password that passes waives nothing, and records nothing.
     *
     * Two halves. The warning is for a waiver that actually happened — printed whenever
     * the flag is present it would be noise, and noise is not read.
     *
     * And the audit entry has to be **true**: the first version of the command recorded
     * `policy_waived` from the presence of `--force` rather than from whether anything was
     * waived, so forcing a strong password left a false record of a security decision.
     * This test is what found it.
     */
    public function testForceIsQuietWhenNothingWasWaived(): void
    {
        // Arrange
        [$tester, $command] = $this->make();

        // Act
        $tester->execute(
            ['user' => 'alice', '--password' => 'Sufficient1!', '--force' => true],
            ['interactive' => false]
        );

        // Assert
        $this->assertStringNotContainsString('would refuse', $tester->getDisplay());
        $this->assertFalse($command->logged['forced']);
    }

    // ── --generate ──────────────────────────────────────────────────────────

    /**
     * A generated password passes the policy and is printed.
     *
     * Printed because it is otherwise unrecoverable — the whole point is to hand it to
     * somebody. And it must pass the policy without `--force`, or the convenience option
     * would need the escape hatch to work.
     */
    public function testAGeneratedPasswordPassesThePolicyAndIsShown(): void
    {
        // Arrange
        [$tester, $command] = $this->make();

        // Act
        $status = $tester->execute(
            ['user' => 'alice', '--generate' => true],
            ['interactive' => false]
        );

        // Assert
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertNotNull($command->storedPassword);
        $this->assertStringContainsString($command->storedPassword, $tester->getDisplay());
        $this->assertNull(
            (new \ReflectionMethod($command, 'policyProblem'))->invoke($command, $command->storedPassword),
            'a generated password must not need --force'
        );
    }

    /**
     * Generated passwords differ between runs.
     *
     * It is a credential. A generator that repeats itself is worse than no generator,
     * because it looks like one.
     */
    public function testGeneratedPasswordsAreNotRepeated(): void
    {
        // Arrange
        [, $command] = $this->make();
        $method = new \ReflectionMethod($command, 'generatePassword');

        // Act
        $generated = [];
        for ($i = 0; $i < 25; $i++) {
            $generated[] = $method->invoke($command);
        }

        // Assert
        $this->assertCount(25, array_unique($generated));
    }

    // ── Resolving the user ──────────────────────────────────────────────────

    /**
     * A non-numeric argument is never looked up as an id.
     *
     * Not an optimisation for its own sake: `new User('alice')` casts to 0, and a lookup
     * for user 0 is a query that can only ever answer wrongly or not at all.
     */
    public function testANameIsNeverLookedUpAsAnId(): void
    {
        // Arrange
        [$tester, $command] = $this->make(resolvesTo: null);

        // Act
        $tester->execute(['user' => 'alice', '--password' => 'Sufficient1!'], ['interactive' => false]);

        // Assert
        $fields = array_column($command->lookups, 1);
        $this->assertSame(['username', 'email'], $fields);
    }

    /**
     * A numeric argument is tried as an id as well.
     */
    public function testANumberIsAlsoTriedAsAnId(): void
    {
        // Arrange
        [$tester, $command] = $this->make(resolvesTo: null);

        // Act
        $tester->execute(['user' => '42', '--password' => 'Sufficient1!'], ['interactive' => false]);

        // Assert
        $this->assertSame(['username', 'email', 'userid'], array_column($command->lookups, 1));
    }

    /**
     * `--by` restricts the lookup to one field.
     *
     * The answer to the only real ambiguity: a numeric username.
     */
    public function testByRestrictsTheLookup(): void
    {
        // Arrange
        [$tester, $command] = $this->make();

        // Act
        $tester->execute(
            ['user' => '42', '--password' => 'Sufficient1!', '--by' => 'userid'],
            ['interactive' => false]
        );

        // Assert
        $this->assertSame([['42', 'userid']], $command->lookups);
    }

    /**
     * An unknown `--by` field is refused, not ignored.
     *
     * Silently falling back to trying everything would mean `--by=usernme` widened the
     * search instead of narrowing it — the opposite of what was asked for.
     */
    public function testAnUnknownByFieldIsRefused(): void
    {
        // Arrange
        [$tester, $command] = $this->make();

        // Act
        $status = $tester->execute(
            ['user' => 'alice', '--password' => 'Sufficient1!', '--by' => 'nickname'],
            ['interactive' => false]
        );

        // Assert
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('--by must be one of', $tester->getDisplay());
        $this->assertSame([], $command->lookups);
    }

    /**
     * A user that matches nothing is a failure, named.
     */
    public function testAnUnknownUserIsReported(): void
    {
        // Arrange
        [$tester, $command] = $this->make(resolvesTo: null);

        // Act
        $status = $tester->execute(['user' => 'nobody', '--password' => 'Sufficient1!'], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('No user matches "nobody"', $tester->getDisplay());
        $this->assertSame([], $command->calls);
    }

    /**
     * Non-interactive with no password is refused rather than prompting into the void.
     *
     * A hidden prompt with no terminal blocks forever or reads EOF as an empty password,
     * and a deploy script is exactly where that happens.
     */
    public function testNonInteractiveWithoutAPasswordIsRefused(): void
    {
        // Arrange
        [$tester, $command] = $this->make();

        // Act
        $status = $tester->execute(['user' => 'alice'], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('--password or --generate', $tester->getDisplay());
        $this->assertSame([], $command->calls);
    }

    // ── Interactive ─────────────────────────────────────────────────────────

    /**
     * The password is asked for twice and a mismatch is refused.
     *
     * The reason every password form does this: a typo in a hidden field is invisible, and
     * the person discovers it by being unable to sign in with a password they believe they
     * typed.
     */
    public function testAMismatchedConfirmationIsRefused(): void
    {
        // Arrange
        [$tester, $command] = $this->make();
        $tester->setInputs(['Sufficient1!', 'Sufficient2!']);

        // Act
        $status = $tester->execute(['user' => 'alice']);

        // Assert
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('do not match', $tester->getDisplay());
        $this->assertSame([], $command->calls);
    }

    /**
     * Two matching answers are accepted.
     */
    public function testTwoMatchingAnswersAreAccepted(): void
    {
        // Arrange
        [$tester, $command] = $this->make();
        $tester->setInputs(['Sufficient1!', 'Sufficient1!']);

        // Act
        $status = $tester->execute(['user' => 'alice']);

        // Assert
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame('Sufficient1!', $command->storedPassword);
    }

    // ── Policy parity ───────────────────────────────────────────────────────

    /**
     * The policy matches the one the self-service form applies.
     *
     * The rules are stated twice — here and in `Account::validatePasswordPolicy()` —
     * because that one is `protected` on a class that wants a booted application, and
     * instantiating a web controller to validate a string in a shell is the coupling that
     * stops a command being testable. Two copies can drift, so this compares them.
     *
     * Read out of the controller's source rather than executed, for the same reason the
     * duplication exists in the first place.
     */
    public function testThePolicyMatchesTheWebForms(): void
    {
        // Arrange
        $controller = (string) file_get_contents(
            dirname(__DIR__, 5) . '/src/Pramnos/Auth/Controllers/Account.php'
        );
        [, $command] = $this->make();
        $problem = new \ReflectionMethod($command, 'policyProblem');

        // Assert — the three rules the controller enforces, each rejected here too.
        $this->assertStringContainsString("strlen(\$newPassword) < 8", $controller);
        $this->assertNotNull($problem->invoke($command, 'Ab1!'), 'the 8-character rule');

        $this->assertStringContainsString("preg_match('/\\d/', \$newPassword)", $controller);
        $this->assertNotNull($problem->invoke($command, 'abcdefgh!'), 'the digit rule');

        $this->assertStringContainsString("preg_match('/[^A-Za-z0-9]/', \$newPassword)", $controller);
        $this->assertNotNull($problem->invoke($command, 'abcdefgh1'), 'the symbol rule');

        // …and a password satisfying all three passes both.
        $this->assertNull($problem->invoke($command, 'Sufficient1!'));
    }
}
