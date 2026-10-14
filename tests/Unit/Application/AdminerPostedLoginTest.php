<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Adminer;
use Pramnos\Application\Settings;

/**
 * What a POST may say about Adminer's connection: nothing while it signs in with the
 * application's own; with that off, the operator's own username and password — on the
 * application's server only. Dropping every posted login left the form that `autologin => false`
 * brings back unable to sign anybody in.
 */
#[CoversClass(Adminer::class)]
class AdminerPostedLoginTest extends TestCase
{
    private mixed $devpanel = null;

    private mixed $database = null;

    protected function setUp(): void
    {
        $app            = \Pramnos\Application\Application::getInstance();
        $this->devpanel = $app->applicationInfo['devpanel'] ?? null;
        $this->database = Settings::getSetting('database');
        Settings::setSetting('database', ['type' => 'postgresql', 'hostname' => 'db.internal', 'user' => 'app', 'password' => '', 'database' => 'appdb'], false);
    }

    protected function tearDown(): void
    {
        \Pramnos\Application\Application::getInstance()->applicationInfo['devpanel'] = $this->devpanel;
        Settings::setSetting('database', $this->database, false);
        $_POST = [];
    }

    /** The screen, without its constructor, so only the method is exercised. */
    private function screen(): Adminer
    {
        return new class extends Adminer {
            public function __construct()
            {
            }

            public function restrict(): void
            {
                $this->restrictPostedLogin();
            }
        };
    }

    private function post(): void
    {
        $_POST = ['auth' => ['driver' => 'server', 'server' => 'evil.example', 'username' => 'ops', 'password' => 'p', 'db' => 'appdb', 'permanent' => 1], 'logout' => ''];
    }

    /** With autologin — the default — a posted login is dropped, whatever it names. */
    public function testWithAutologinNothingPostedChoosesAConnection(): void
    {
        // Arrange
        \Pramnos\Application\Application::getInstance()->applicationInfo['devpanel'] = [];
        $this->post();

        // Act
        $this->screen()->restrict();

        // Assert
        $this->assertArrayNotHasKey('auth', $_POST);
        $this->assertArrayNotHasKey('logout', $_POST);
    }

    /**
     * Without autologin the operator signs in with their own account — to the application's
     * server and driver, whatever the form said, and never with a password kept in a cookie.
     */
    public function testWithoutAutologinTheOperatorSignsInToThisServerOnly(): void
    {
        // Arrange
        \Pramnos\Application\Application::getInstance()->applicationInfo['devpanel'] = ['adminer_autologin' => false];
        $this->post();

        // Act
        $this->screen()->restrict();

        // Assert
        $this->assertSame('db.internal', $_POST['auth']['server'], 'not the host the form named');
        $this->assertSame('pgsql', $_POST['auth']['driver']);
        $this->assertSame('ops', $_POST['auth']['username'], 'their own account');
        $this->assertArrayNotHasKey('permanent', $_POST['auth']);
    }
}
