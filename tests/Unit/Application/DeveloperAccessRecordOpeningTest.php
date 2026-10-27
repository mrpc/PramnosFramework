<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\DeveloperAccess;
use Pramnos\Logs\Logger;

/**
 * `DeveloperAccess::recordOpening()` — the only visible trace on a live server that a
 * developer tool was opened at all, and untested in its own right: every `permits()` test in
 * {@see DeveloperAccessNamesAPersonTest} is about the gate, not the log line a caller writes
 * once a gate has already opened.
 */
#[CoversClass(DeveloperAccess::class)]
class DeveloperAccessRecordOpeningTest extends TestCase
{
    private ?string $savedAppDebug = null;

    protected function setUp(): void
    {
        $this->savedAppDebug = getenv('APP_DEBUG') === false ? null : (string) getenv('APP_DEBUG');
    }

    protected function tearDown(): void
    {
        if ($this->savedAppDebug === null) {
            putenv('APP_DEBUG');
            unset($_ENV['APP_DEBUG']);
        } else {
            putenv('APP_DEBUG=' . $this->savedAppDebug);
            $_ENV['APP_DEBUG'] = $this->savedAppDebug;
        }
        Application::getInstance()->currentUser = null;
        unset($_SESSION['logged'], $_SESSION['uid']);
        parent::tearDown();
    }

    /** Not a developer environment: every gate opens anyway, and this is the one place that says so. */
    private function notADeveloperEnvironment(): void
    {
        putenv('APP_DEBUG=0');
        $_ENV['APP_DEBUG'] = '0';
    }

    /** A developer environment, where every tool opens on every request — nothing worth logging. */
    private function aDeveloperEnvironment(): void
    {
        putenv('APP_DEBUG=1');
        $_ENV['APP_DEBUG'] = '1';
    }

    /** The log file `recordOpening()` writes to, read fresh. */
    private function authLog(): string
    {
        $path = Logger::getLogPath('auth');

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * On a development machine, nothing is written — it would run on every request to every
     * tab and be a log nobody reads.
     */
    public function testOnADeveloperEnvironmentNothingIsLogged(): void
    {
        // Arrange
        $this->aDeveloperEnvironment();
        $before = $this->authLog();

        // Act
        DeveloperAccess::recordOpening(DeveloperAccess::ADMINER);

        // Assert
        $this->assertSame($before, $this->authLog());
    }

    /** Outside a development environment, the tool, the user and their usertype are all named. */
    public function testOutsideADeveloperEnvironmentTheOpeningIsLogged(): void
    {
        // Arrange
        $this->notADeveloperEnvironment();
        $user           = new \Pramnos\User\User();
        $user->userid   = 7;
        $user->usertype = 99;
        Application::getInstance()->currentUser = $user;
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = 7;

        // Act
        DeveloperAccess::recordOpening(DeveloperAccess::ADMINER);

        // Assert
        $line = $this->authLog();
        $this->assertStringContainsString('Adminer opened outside a development environment', $line);
        $this->assertStringContainsString('user 7', $line);
        $this->assertStringContainsString('usertype 99', $line);
    }

    /**
     * An anonymous caller — `getCurrentUser()` answers `false`, not an object — is logged as
     * user 0, not a fatal reading `->userid` off a non-object.
     */
    public function testAnAnonymousCallerIsLoggedAsUserZero(): void
    {
        // Arrange
        $this->notADeveloperEnvironment();
        Application::getInstance()->currentUser = null;

        // Act
        DeveloperAccess::recordOpening(DeveloperAccess::DEBUGBAR);

        // Assert
        $line = $this->authLog();
        $this->assertStringContainsString('Debugbar opened outside a development environment by user 0', $line);
        $this->assertStringContainsString('usertype 0', $line);
    }

    /** The tool name is capitalised, and each of the three constants is accepted. */
    public function testEveryToolNameIsCapitalisedInTheLine(): void
    {
        // Arrange
        $this->notADeveloperEnvironment();

        foreach ([DeveloperAccess::DEVPANEL, DeveloperAccess::ADMINER, DeveloperAccess::DEBUGBAR] as $tool) {
            // Act
            DeveloperAccess::recordOpening($tool);

            // Assert
            $this->assertStringContainsString(ucfirst($tool) . ' opened', $this->authLog());
        }
    }
}
