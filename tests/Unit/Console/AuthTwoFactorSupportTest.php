<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\SecondFactorRegistry;
use Pramnos\Console\Commands\AuthTwoFactorReset;
use Pramnos\Console\Commands\AuthTwoFactorStatus;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The two commands that make the enrolment wall supportable.
 *
 * They exist because of what the wall does: once
 * `auth.security.require_factor_enrolment_from_usertype` is set, an administrator who has
 * lost their authenticator cannot reach the screen that would fix it — and neither, possibly,
 * can the colleague who would help them. A terminal is the only door left, so these two have
 * to work when the web application is, for that person, unusable.
 *
 * What is asserted here is what an operator reads and what the commands refuse. The database
 * boundaries are seams, overridden below: the queries themselves belong to
 * `TwoFactorAuthService` and are tested against a real store there.
 */
#[CoversClass(AuthTwoFactorStatus::class)]
#[CoversClass(AuthTwoFactorReset::class)]
class AuthTwoFactorSupportTest extends TestCase
{
    private ?array $savedInstances = null;

    protected function tearDown(): void
    {
        SecondFactorRegistry::reset();

        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))
                ->setValue(null, $this->savedInstances);
            $this->savedInstances = null;
        }

        parent::tearDown();
    }

    /**
     * An application with both floors set.
     */
    private function withFloors(int $floor): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = [
            'auth' => [
                'twofactor_methods' => ['totp', 'email'],
                'security'          => [
                    'require_second_factor_from_usertype'     => $floor,
                    'require_factor_enrolment_from_usertype'  => $floor,
                ],
            ],
        ];

        $reflection = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedInstances = $reflection->getValue() ?? [];
        $instances = $this->savedInstances;
        $instances['default'] = $stub;
        $reflection->setValue(null, $instances);
    }

    /**
     * Named `execute` rather than `run`: `TestCase::run()` is final, and overriding it is a
     * fatal error rather than a failing test.
     */
    private function execute(object $command, array $input): string
    {
        $output = new BufferedOutput();
        $command->run(new ArrayInput($input), $output);

        return $output->fetch();
    }

    // ── Status ───────────────────────────────────────────────────────────────

    /**
     * With no account named and nothing to list, it says what to do instead of failing quietly.
     */
    public function testStatusWithNoArgumentsExplainsItself(): void
    {
        // Arrange
        $command = new StatusProbe();

        // Act
        $output = new BufferedOutput();
        $status = $command->run(new ArrayInput([]), $output);

        // Assert
        $this->assertSame(1, $status);
        $this->assertStringContainsString('--missing', $output->fetch());
    }

    /**
     * An unknown identifier is reported rather than treated as a user with nothing.
     *
     * The difference matters on this command in particular: "no factors held" and "no such
     * account" would otherwise read the same, and one of them means the operator is looking
     * at the wrong installation.
     */
    public function testStatusRefusesAnUnknownAccount(): void
    {
        // Arrange
        $command = new StatusProbe();

        // Act
        $output = new BufferedOutput();
        $status = $command->run(new ArrayInput(['user' => 'nobody']), $output);

        // Assert
        $this->assertSame(1, $status);
        $this->assertStringContainsString('No account matches', $output->fetch());
    }

    /**
     * For an account behind the wall, it says so in as many words.
     */
    public function testStatusNamesAnAccountBehindTheWall(): void
    {
        // Arrange — usertype 90, nothing enrolled, floors at 80
        $this->withFloors(80);
        SecondFactorRegistry::reset();
        $command = new StatusProbe();

        // Act
        $output = $this->execute($command, ['user' => 'admin']);

        // Assert
        $this->assertStringContainsString('yes (usertype 80+)', $output);
        $this->assertStringContainsString('YES', $output);
        $this->assertStringContainsString('behind the enrolment wall', $output);
    }

    /**
     * With the switches off it says that, rather than implying the account is fine.
     */
    public function testStatusSaysWhenTheSwitchesAreOff(): void
    {
        // Arrange — an application with no floors at all
        $this->withFloors(0);
        $command = new StatusProbe();

        // Act
        $output = $this->execute($command, ['user' => 'admin']);

        // Assert
        $this->assertStringContainsString('switch off', $output);
        $this->assertStringNotContainsString('behind the enrolment wall', $output);
    }

    /**
     * `--missing` lists the accounts the wall would stop, and says how many.
     *
     * The command to run *before* setting the switch: turning a wall on without knowing who
     * is behind it is how an operator finds out from a support ticket.
     */
    public function testMissingListsWhoTheWallWouldStop(): void
    {
        // Arrange
        $this->withFloors(80);
        SecondFactorRegistry::reset();
        $command = new StatusProbe();

        // Act
        $output = $this->execute($command, ['--missing' => true]);

        // Assert
        $this->assertStringContainsString('admin', $output);
        $this->assertStringContainsString('would be sent to the setup screen', $output);
    }

    /**
     * With no floor configured and none given, `--missing` asks for one.
     */
    public function testMissingWithNoFloorAsksForOne(): void
    {
        // Arrange
        $this->withFloors(0);
        $command = new StatusProbe();

        // Act
        $output = $this->execute($command, ['--missing' => true]);

        // Assert
        $this->assertStringContainsString('--from=80', $output);
    }

    // ── Reset ────────────────────────────────────────────────────────────────

    /**
     * A reset says what it cleared, and how the person gets back in.
     *
     * The second half is the part somebody reads out on a phone call, so it is asserted.
     */
    public function testResetReportsWhatItClearedAndTheWayBack(): void
    {
        // Arrange
        $command = new ResetProbe();

        // Act
        $output = $this->execute($command, ['user' => 'admin']);

        // Assert
        $this->assertStringContainsString('authenticator enrolment', $output);
        $this->assertStringContainsString('mailed-code factor', $output);
        $this->assertStringContainsString('code sent to their email', $output);
    }

    /**
     * Passkeys are only removed when asked for.
     *
     * A passkey is a device the person still has. Clearing it because they lost a *different*
     * one is help nobody asked for, and it takes away a working credential.
     */
    public function testResetLeavesPasskeysAloneUnlessAsked(): void
    {
        // Arrange
        $command = new ResetProbe();

        // Act
        $without = $this->execute($command, ['user' => 'admin']);
        $with    = $this->execute(new ResetProbe(), ['user' => 'admin', '--passkeys' => true]);

        // Assert
        $this->assertStringNotContainsString('passkeys', $without);
        $this->assertStringContainsString('passkeys', $with);
    }

    /**
     * A dry run changes nothing and says so.
     */
    public function testResetCanBeRehearsed(): void
    {
        // Arrange
        $command = new ResetProbe();

        // Act
        $output = $this->execute($command, ['user' => 'admin', '--dry-run' => true]);

        // Assert
        $this->assertStringContainsString('Would clear', $output);
        $this->assertFalse($command->cleared, 'a dry run must not write');
    }

    /**
     * An account with nothing enrolled is reported as such, not as a success.
     */
    public function testResetSaysWhenThereWasNothingToClear(): void
    {
        // Arrange
        $command = new ResetProbe();
        $command->holdsNothing = true;

        // Act
        $output = $this->execute($command, ['user' => 'admin']);

        // Assert
        $this->assertStringContainsString('nothing to clear', $output);
    }

    /**
     * And an unknown identifier fails rather than clearing something adjacent.
     */
    public function testResetRefusesAnUnknownAccount(): void
    {
        // Arrange
        $command = new ResetProbe();

        // Act
        $output = new BufferedOutput();
        $status = $command->run(new ArrayInput(['user' => 'nobody']), $output);

        // Assert
        $this->assertSame(1, $status);
        $this->assertStringContainsString('No account matches', $output->fetch());
    }
}

/**
 * The status command with its database boundaries replaced.
 *
 * `admin` is a usertype-90 account holding nothing; anything else does not exist.
 */
class StatusProbe extends AuthTwoFactorStatus
{
    /**
     * The enrolment reading, with a passkey store that answers instead of failing open.
     *
     * The real one cannot be used here: with no database it reports every account as
     * holding a strong factor — correct in production, since walling everybody out on a
     * failed query is worse, and useless for asserting who is missing one.
     */
    protected function enrolment(): \Pramnos\Auth\FactorEnrolment
    {
        return new \Pramnos\Auth\FactorEnrolment(
            new \Pramnos\Tests\Support\FakePasskeyService(false)
        );
    }

    protected function lookup(string $needle, string $field): ?int
    {
        return $needle === 'admin' && $field === 'username' ? 42 : null;
    }

    protected function loadUser(int $userId): object
    {
        return (object) ['userid' => $userId, 'username' => 'admin', 'usertype' => 90];
    }

    protected function totpStatus(int $userId): ?array
    {
        return ['enabled' => false, 'setup' => false, 'backup_codes_remaining' => 0];
    }

    protected function privilegedAccounts(int $floor): array
    {
        return [['userid' => 42, 'username' => 'admin', 'usertype' => 90]];
    }
}

/**
 * The reset command with its writes replaced by flags.
 */
class ResetProbe extends AuthTwoFactorReset
{
    public bool $cleared = false;

    public bool $holdsNothing = false;

    protected function lookup(string $needle, string $field): ?int
    {
        return $needle === 'admin' && $field === 'username' ? 42 : null;
    }

    protected function clearAuthenticator(int $userId, bool $dryRun): bool
    {
        if ($this->holdsNothing) {
            return false;
        }

        $this->cleared = $this->cleared || !$dryRun;

        return true;
    }

    protected function clearMailedCodeFlag(int $userId, bool $dryRun): bool
    {
        if ($this->holdsNothing) {
            return false;
        }

        $this->cleared = $this->cleared || !$dryRun;

        return true;
    }

    protected function clearPasskeys(int $userId, bool $dryRun): bool
    {
        if ($this->holdsNothing) {
            return false;
        }

        $this->cleared = $this->cleared || !$dryRun;

        return true;
    }
}
