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
}
