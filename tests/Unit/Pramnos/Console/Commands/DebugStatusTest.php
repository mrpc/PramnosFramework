<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\DebugStatus;
use Pramnos\Application\Settings;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the DebugStatus console command.
 *
 * DebugStatus prints the current APP_DEBUG flag (env & settings),
 * Xdebug status (loaded, mode, client_port), and tells if the toolbar is active.
 */
#[CoversClass(DebugStatus::class)]
class DebugStatusTest extends TestCase
{
    private array $originalEnv;

    protected function setUp(): void
    {
        // Save original environment variable
        $this->originalEnv = [
            'APP_DEBUG' => getenv('APP_DEBUG'),
        ];
        Settings::clearSettings();
    }

    protected function tearDown(): void
    {
        // Restore environment variables
        foreach ($this->originalEnv as $key => $val) {
            if ($val === false) {
                putenv("$key");
            } else {
                putenv("$key=$val");
            }
        }
        Settings::clearSettings();
    }

    /**
     * Test command configuration (name, description).
     */
    public function testCommandIsConfiguredCorrectly(): void
    {
        // Arrange
        $command = new DebugStatus();

        // Assert
        $this->assertSame('debug:status', $command->getName());
        $this->assertStringContainsString('Show debug configuration', $command->getDescription());
    }

    /**
     * Test executing the command when debug mode is enabled via environment variable.
     */
    public function testExecuteWithDebugEnabledViaEnv(): void
    {
        // Arrange
        putenv('APP_DEBUG=true');
        Settings::setSetting('debug', false, false);
        $command = new DebugStatus();
        $tester = new CommandTester($command);

        // Act
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(0, $code);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Debug Configuration', $display);
        $this->assertStringContainsString('APP_DEBUG (env):  true', $display);
        $this->assertStringContainsString('Toolbar active:   ON', $display);
    }

    /**
     * The `debug` setting is reported, and reported as *not* opening the toolbar.
     *
     * It used to open it, and this test asserted that. Two things were wrong with it, and the
     * second is why the assertion is inverted rather than deleted: a settings row is editable
     * from `/admin/Settings` and applies to every visitor, and what the toolbar carries is
     * every query with its bindings, the session's keys and the request's authentication
     * state. So the row no longer opens the toolbar — and the command has to say so, because
     * an operator who set it and then read "Toolbar active: ON" would draw the opposite
     * conclusion from the truth in either direction.
     */
    public function testTheDebugSettingIsShownButDoesNotOpenTheToolbar(): void
    {
        // Arrange
        putenv('APP_DEBUG=false');
        Settings::setSetting('debug', true, false);
        $command = new DebugStatus();
        $tester = new CommandTester($command);

        // Act
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(0, $code);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('debug (settings): true', $display);
        $this->assertStringContainsString('Toolbar active:   OFF', $display);
        $this->assertStringContainsString('does not open the toolbar', $display);
        $this->assertStringContainsString('debug:token', $display,
            'and it has to name the way that does work on a live server');
    }

    /**
     * Test executing the command when debug mode is disabled everywhere.
     */
    public function testExecuteWithDebugDisabled(): void
    {
        // Arrange
        putenv('APP_DEBUG=0');
        Settings::setSetting('debug', false, false);
        $command = new DebugStatus();
        $tester = new CommandTester($command);

        // Act
        $code = $tester->execute([]);

        // Assert
        $this->assertSame(0, $code);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Toolbar active:   OFF', $display);
    }
}
