<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\AuthUnlock;
use Symfony\Component\Console\Application as SymfonyApp;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command that lifts a login lockout.
 *
 * The lockout is doing its job when it locks somebody out — three wrong
 * passwords cost a minute, ten cost an hour. That is right for the internet and
 * wrong for the developer who has just mistyped a fixture password and cannot
 * test the login flow they are working on.
 *
 * Two things matter enough to pin down, and neither is the happy path:
 *
 *  - **`--all` must refuse outside development.** "Clear every lockout on this
 *    server" is precisely what somebody working through a password list would
 *    want, and a command that offers it on a live installation is a hole with a
 *    friendly name.
 *  - **A bad scope must be rejected rather than silently matching nothing**,
 *    because "nothing was locked" and "you asked the wrong question" look
 *    identical from the outside and lead to opposite conclusions.
 */
#[CoversClass(AuthUnlock::class)]
class AuthUnlockTest extends TestCase
{
    /** @var string|false The APP_DEBUG the environment had */
    private string|false $originalDebug = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Symfony's console names itself from PHP_SELF/SCRIPT_NAME; under
        // PHPUnit neither is set, and basename(null) is deprecated on PHP 8.
        foreach (['PHP_SELF', 'SCRIPT_NAME'] as $key) {
            if (!isset($_SERVER[$key])) {
                $_SERVER[$key] = 'pramnos';
            }
        }

        $this->originalDebug = getenv('APP_DEBUG');
    }

    protected function tearDown(): void
    {
        if ($this->originalDebug === false) {
            putenv('APP_DEBUG');
            unset($_ENV['APP_DEBUG']);
        } else {
            putenv('APP_DEBUG=' . $this->originalDebug);
        }

        parent::tearDown();
    }

    /**
     * Asked to clear everything on a production installation, it refuses and
     * says what to do instead.
     *
     * The refusal is the feature. A developer who reads it learns the one-account
     * form; somebody who was hoping to reset every counter on a live server
     * learns nothing at all.
     */
    public function testClearingEverythingIsRefusedOutsideDevelopment(): void
    {
        // Arrange — production: no APP_DEBUG, no DEVELOPMENT constant
        putenv('APP_DEBUG');
        unset($_ENV['APP_DEBUG']);

        if (defined('DEVELOPMENT') && DEVELOPMENT === true) {
            $this->markTestSkipped('The suite bootstrap declares DEVELOPMENT=true.');
        }

        $tester = $this->tester();

        // Act
        $status = $tester->execute(['--all' => true]);

        // Assert
        $this->assertSame(Command::FAILURE, $status);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('only runs in development', $output);
        // And it points at the form that is safe to run anywhere
        $this->assertStringContainsString('auth:unlock', $output);
    }

    /**
     * An unknown scope is rejected by name.
     *
     * Left to match nothing, `--scope=users` would report "not locked" for an
     * account that is locked, and the developer would go looking for the wrong
     * bug.
     */
    public function testAnUnknownScopeIsRejected(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $status = $tester->execute(['identifier' => 'admin', '--scope' => 'users']);

        // Assert
        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Unknown scope "users"', $tester->getDisplay());
        // The valid ones are listed, so the next attempt is right
        $this->assertStringContainsString('identifier, user, ip', $tester->getDisplay());
    }

    /**
     * With nothing to act on, it explains the three ways to use it rather than
     * doing something arbitrary.
     */
    public function testItAsksForAnIdentifierWhenGivenNothing(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $status = $tester->execute([]);

        // Assert
        $this->assertSame(Command::FAILURE, $status);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('--list', $output);
        $this->assertStringContainsString('--all', $output);
    }

    /**
     * The command registers under a name that says what it does to whom.
     */
    public function testItIsRegisteredAsAuthUnlock(): void
    {
        // Arrange / Act
        $command = new AuthUnlock();

        // Assert
        $this->assertSame('auth:unlock', $command->getName());
        $this->assertStringContainsString('lockout', strtolower((string) $command->getDescription()));
    }

    /**
     * A tester wired to a fresh command instance.
     */
    private function tester(): CommandTester
    {
        $application = new SymfonyApp();
        $application->add(new AuthUnlock());

        return new CommandTester($application->find('auth:unlock'));
    }
}
