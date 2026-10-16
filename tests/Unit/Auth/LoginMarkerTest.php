<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\LoginMarker;

/**
 * The cookie that says only "somebody is signed in": off until named, a name that is not a plain
 * one refused, set and cleared, and brought in line with the session — set where it is missing,
 * cleared where it outlived the session.
 */
#[CoversClass(LoginMarker::class)]
class LoginMarkerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $info = [];

    protected function setUp(): void
    {
        $this->info = Application::getInstance()->applicationInfo;
        $_COOKIE    = [];
    }

    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
        $_COOKIE = [];
    }

    /** Named in app.php, or nothing; a name with characters a cookie name cannot carry is nothing. */
    public function testTheName(): void
    {
        // Act
        unset(Application::getInstance()->applicationInfo['session']);
        $off = LoginMarker::name();
        LoginMarker::set(true);
        $nothingSet = $_COOKIE;
        Application::getInstance()->applicationInfo['session'] = ['login_marker' => 'logged in; x'];
        $bad = LoginMarker::name();
        Application::getInstance()->applicationInfo['session'] = ['login_marker' => 'logged_in'];
        $on = LoginMarker::name();

        // Assert
        $this->assertSame(['', [], '', 'logged_in'], [$off, $nothingSet, $bad, $on]);
    }

    /** Set and cleared; synced with the session both ways; left alone when it already agrees. */
    public function testSetClearAndSync(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['session'] = ['login_marker' => 'logged_in'];

        // Act
        LoginMarker::set(false);
        $set = $_COOKIE['logged_in'] ?? null;
        LoginMarker::clear();
        $cleared = isset($_COOKIE['logged_in']);
        LoginMarker::clear();
        LoginMarker::sync(true);
        $syncedOn = $_COOKIE['logged_in'] ?? null;
        LoginMarker::sync(true);
        LoginMarker::sync(false);
        $syncedOff = isset($_COOKIE['logged_in']);

        // Assert
        $this->assertSame('1', $set);
        $this->assertFalse($cleared);
        $this->assertSame('1', $syncedOn, 'signed in without one: set');
        $this->assertFalse($syncedOff, 'outlived the session: cleared');
    }
}
