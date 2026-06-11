<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Drivers\PusherDriver;

/**
 * Unit tests for PusherDriver.
 *
 * The Pusher PHP SDK (pusher/pusher-php-server) is an optional dependency.
 * Tests that require the actual SDK are skipped when the package is not
 * installed, so the test suite always passes in the base Docker environment.
 *
 * Tests that exercise the runtime guard (missing package detection) always run,
 * since they only require that the class NOT be present.
 */
#[CoversClass(PusherDriver::class)]
class PusherDriverTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Runtime guard — always runs (Pusher SDK not installed in base Docker)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * PusherDriver must throw a descriptive RuntimeException when the
     * pusher/pusher-php-server package is not installed.
     *
     * This is the primary guard that prevents a confusing "class not found"
     * error deep inside the driver. The exception message must tell the
     * developer exactly what to install.
     */
    public function testConstructorThrowsWhenPusherPackageNotInstalled(): void
    {
        $GLOBALS['mock_pusher_absent'] = true;

        try {
            // Assert — RuntimeException with helpful message
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/pusher\/pusher-php-server/');

            // Act
            new PusherDriver([
                'app_id'     => 'test-id',
                'app_key'    => 'test-key',
                'app_secret' => 'test-secret',
            ]);
        } finally {
            unset($GLOBALS['mock_pusher_absent']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Full SDK tests — only run when pusher/pusher-php-server is installed
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * name() must return 'pusher' so BroadcastingManager can address the
     * driver by its canonical name.
     */
    public function testNameReturnsPusher(): void
    {
        if (!class_exists('Pusher\\Pusher')) {
            $this->markTestSkipped('pusher/pusher-php-server not installed.');
        }

        // Arrange + Act
        $driver = new PusherDriver([
            'app_id'     => 'test-id',
            'app_key'    => 'test-key',
            'app_secret' => 'test-secret',
            'cluster'    => 'eu',
        ]);

        // Assert
        $this->assertSame('pusher', $driver->name());
    }

    /**
     * When 'host' is set in config, PusherDriver must configure the SDK for
     * a self-hosted Pusher-compatible server (e.g. Laravel Reverb) using the
     * specified host, port, and scheme.
     *
     * Verified by asserting no exception is thrown and name() returns 'pusher'.
     */
    public function testConstructorAcceptsReverbConfiguration(): void
    {
        if (!class_exists('Pusher\\Pusher')) {
            $this->markTestSkipped('pusher/pusher-php-server not installed.');
        }

        // Arrange + Act — no exception expected
        $driver = new PusherDriver([
            'app_id'     => 'app-id',
            'app_key'    => 'app-key',
            'app_secret' => 'app-secret',
            'host'       => '127.0.0.1',
            'port'       => 8080,
            'scheme'     => 'http',
            'encrypted'  => false,
        ]);

        // Assert
        $this->assertSame('pusher', $driver->name(),
            'Reverb config must create a valid PusherDriver instance');
    }
}
