<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\UserCreate;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the user:create console command.
 *
 * user:create ultimately needs a live database (it persists through the User
 * model), so these tests exercise only the input-validation / guard paths that
 * run BEFORE any database access:
 *
 *  - A missing required option in non-interactive mode is a hard error.
 *  - An invalid email address is rejected before the User model is touched.
 *  - An empty username / password is rejected.
 *
 * Because every assertion below stops the command before it reaches the
 * database, no database connection is required to run this suite. The happy
 * path (actual row creation, password hashing) is covered by integration
 * tests that run against the real databases via ./dockertest.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(UserCreate::class)]
class UserCreateTest extends TestCase
{
    private CommandTester $tester;
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        // Symfony's completion command reads $_SERVER['PHP_SELF'] in configure().
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $app = new Application('test', '1.0');
        $app->add(new UserCreate());
        $app->setAutoExit(false);

        $this->tester = new CommandTester($app->find('user:create'));
    }

    protected function tearDown(): void
    {
        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    // =========================================================================
    // Non-interactive: missing required options
    // =========================================================================

    /**
     * With no options and interaction disabled, the command cannot prompt and
     * must fail on the first missing required value (username) — not silently
     * create a broken account or hang waiting for input.
     */
    public function testFailsWhenUsernameMissingNonInteractive(): void
    {
        // Act — no options, interaction disabled so no prompt is attempted
        $exitCode = $this->tester->execute([], ['interactive' => false]);

        // Assert — failure that names the missing option
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--username', $this->tester->getDisplay());
    }

    /**
     * A username without an email must also fail in non-interactive mode,
     * proving each required option is independently guarded.
     */
    public function testFailsWhenEmailMissingNonInteractive(): void
    {
        // Act
        $exitCode = $this->tester->execute(
            ['--username' => 'alice'],
            ['interactive' => false]
        );

        // Assert
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--email', $this->tester->getDisplay());
    }

    /**
     * Username + email but no password must fail in non-interactive mode: a
     * blank password must never be accepted implicitly.
     */
    public function testFailsWhenPasswordMissingNonInteractive(): void
    {
        // Act
        $exitCode = $this->tester->execute(
            ['--username' => 'alice', '--email' => 'alice@example.com'],
            ['interactive' => false]
        );

        // Assert
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--password', $this->tester->getDisplay());
    }

    // =========================================================================
    // Validation guards (run before any database access)
    // =========================================================================

    /**
     * An malformed email address must be rejected before the User model / DB is
     * touched. All three options are supplied so the only thing that can fail is
     * the email validation itself.
     */
    public function testRejectsInvalidEmail(): void
    {
        // Act — well-formed username/password, but a clearly invalid email
        $exitCode = $this->tester->execute(
            [
                '--username' => 'alice',
                '--email'    => 'not-an-email',
                '--password' => 'secret123!',
            ],
            ['interactive' => false]
        );

        // Assert — failure and the offending value is echoed back
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Invalid email address', $this->tester->getDisplay());
    }

    /**
     * A whitespace-only username collapses to empty after trimming and must be
     * rejected before any DB access, guarding against blank accounts.
     */
    public function testRejectsEmptyUsername(): void
    {
        // Act — username is only spaces
        $exitCode = $this->tester->execute(
            [
                '--username' => '   ',
                '--email'    => 'alice@example.com',
                '--password' => 'secret123!',
            ],
            ['interactive' => false]
        );

        // Assert — rejected as empty
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Username cannot be empty', $this->tester->getDisplay());
    }

    // Note: the "Password cannot be empty" branch is only reachable when a
    // hidden interactive prompt returns a literal empty string. CommandTester's
    // in-memory hidden-input reader yields null (→ the "missing option" path)
    // rather than '', so that 2-line guard cannot be exercised deterministically
    // here; it is left to manual/TTY verification.

    // =========================================================================
    // Persistence branches (DB seams overridden — no live database)
    // =========================================================================

    /**
     * A username that already exists must be refused with the username-specific
     * message, before any new row is created. userExists() is overridden so the
     * decision logic runs without a database.
     */
    public function testDuplicateUsernameRejected(): void
    {
        // Arrange — "alice" already exists as a username.
        $command = $this->double(['usernames' => ['alice']]);
        $tester  = $this->testerFor($command);

        // Act
        $exit = $tester->execute(
            ['--username' => 'alice', '--email' => 'new@example.com', '--password' => 'secret123!'],
            ['interactive' => false]
        );

        // Assert — failure, username message, nothing persisted.
        $this->assertSame(Command::FAILURE, $exit, $tester->getDisplay());
        $this->assertStringContainsString('username "alice" already exists', $tester->getDisplay());
        $this->assertNull($command->persisted, 'no user must be created on a duplicate');
    }

    /**
     * A duplicate email (username free) must be refused with the email-specific
     * message — proving the two guards emit distinct messages.
     */
    public function testDuplicateEmailRejected(): void
    {
        // Arrange — the email is taken but the username is free.
        $command = $this->double(['emails' => ['taken@example.com']]);
        $tester  = $this->testerFor($command);

        // Act
        $exit = $tester->execute(
            ['--username' => 'bob', '--email' => 'taken@example.com', '--password' => 'secret123!'],
            ['interactive' => false]
        );

        // Assert
        $this->assertSame(Command::FAILURE, $exit, $tester->getDisplay());
        $this->assertStringContainsString('email "taken@example.com" already exists', $tester->getDisplay());
        $this->assertNull($command->persisted);
    }

    /**
     * The happy path: with no collisions the user is persisted and the assigned
     * id is echoed. The trimmed username/email and the plain password must reach
     * persistUser() verbatim; a non-admin account prints no ", admin" suffix.
     */
    public function testSuccessPersistsAndPrintsUserId(): void
    {
        // Arrange — persist returns userid 5.
        $command = $this->double(['return' => 5]);
        $tester  = $this->testerFor($command);

        // Act
        $exit = $tester->execute(
            ['--username' => '  alice ', '--email' => ' alice@example.com ', '--password' => 'secret123!'],
            ['interactive' => false]
        );

        // Assert — success + id echoed, no admin marker.
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertStringContainsString('userid=5', $tester->getDisplay());
        $this->assertStringNotContainsString(', admin', $tester->getDisplay());

        // Assert — persistUser received the trimmed values, plain password, non-admin.
        $this->assertSame('alice', $command->persisted['username']);
        $this->assertSame('alice@example.com', $command->persisted['email']);
        $this->assertSame('secret123!', $command->persisted['password']);
        $this->assertFalse($command->persisted['admin']);
    }

    /**
     * With --admin the account is persisted as an administrator and the success
     * line carries the ", admin" marker. Proves the admin flag flows through to
     * both persistUser() and the output.
     */
    public function testSuccessAdminAccount(): void
    {
        // Arrange
        $command = $this->double(['return' => 7]);
        $tester  = $this->testerFor($command);

        // Act
        $exit = $tester->execute(
            ['--username' => 'root', '--email' => 'root@example.com', '--password' => 'secret123!', '--admin' => true],
            ['interactive' => false]
        );

        // Assert
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertStringContainsString('userid=7, admin', $tester->getDisplay());
        $this->assertTrue($command->persisted['admin'], 'the admin flag must reach persistUser()');
    }

    /**
     * When persistUser() throws (e.g. a DB error), the command must catch it and
     * report a friendly failure rather than surfacing a raw exception.
     */
    public function testPersistFailureIsReported(): void
    {
        // Arrange — the persist seam raises.
        $command = $this->double(['throw' => true]);
        $tester  = $this->testerFor($command);

        // Act
        $exit = $tester->execute(
            ['--username' => 'alice', '--email' => 'alice@example.com', '--password' => 'secret123!'],
            ['interactive' => false]
        );

        // Assert — caught and reported.
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('Failed to create user', $tester->getDisplay());
    }

    /**
     * A persisted userid below 2 signals a failed creation (id 1 is the guest /
     * anonymous account) and must be reported as an error rather than success.
     */
    public function testInvalidUserIdReported(): void
    {
        // Arrange — persist returns an invalid id.
        $command = $this->double(['return' => 1]);
        $tester  = $this->testerFor($command);

        // Act
        $exit = $tester->execute(
            ['--username' => 'alice', '--email' => 'alice@example.com', '--password' => 'secret123!'],
            ['interactive' => false]
        );

        // Assert
        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('no valid user id', $tester->getDisplay());
    }

    /**
     * Interactive mode must prompt for every missing value: username, email and
     * (hidden) password. This exercises the resolveValue() prompt branch —
     * including the hidden-password configuration — end to end.
     */
    public function testInteractivePromptsForMissingValues(): void
    {
        // Arrange — nothing supplied as an option; answers fed via the prompt.
        $command = $this->double(['return' => 9]);
        $tester  = $this->testerFor($command);
        $tester->setInputs(['carol', 'carol@example.com', 'promptpass']);

        // Act — interactive (default): all three values are prompted for.
        $exit = $tester->execute([]);

        // Assert — success and each prompted value reached persistUser().
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertSame('carol', $command->persisted['username']);
        $this->assertSame('carol@example.com', $command->persisted['email']);
        $this->assertSame('promptpass', $command->persisted['password']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a UserCreate test double whose DB seams are overridden with
     * in-memory behaviour, so every persistence branch runs without a database.
     *
     * @param array{usernames?: list<string>, emails?: list<string>, throw?: bool, return?: int} $opts
     */
    private function double(array $opts = []): UserCreate
    {
        return new class($opts) extends UserCreate {
            /** @var list<string> */
            private array $existsUsernames;
            /** @var list<string> */
            private array $existsEmails;
            private bool $throwOnPersist;
            private int $persistReturn;
            /** @var array{username: string, email: string, password: string, admin: bool}|null */
            public ?array $persisted = null;

            public function __construct(array $opts)
            {
                parent::__construct();
                $this->existsUsernames = $opts['usernames'] ?? [];
                $this->existsEmails    = $opts['emails'] ?? [];
                $this->throwOnPersist  = $opts['throw'] ?? false;
                $this->persistReturn   = $opts['return'] ?? 5;
            }

            protected function userExists(string $value, string $field): bool
            {
                return $field === 'username'
                    ? in_array($value, $this->existsUsernames, true)
                    : in_array($value, $this->existsEmails, true);
            }

            protected function persistUser(string $username, string $email, string $password, bool $admin): int
            {
                if ($this->throwOnPersist) {
                    throw new \RuntimeException('database unavailable');
                }
                $this->persisted = compact('username', 'email', 'password', 'admin');
                return $this->persistReturn;
            }
        };
    }

    private function testerFor(UserCreate $command): CommandTester
    {
        $app = new Application('test', '1.0');
        $app->add($command);
        $app->setAutoExit(false);

        return new CommandTester($app->find('user:create'));
    }
}
