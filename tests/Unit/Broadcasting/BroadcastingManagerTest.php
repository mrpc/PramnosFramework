<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Broadcasting;

use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\BroadcastingManager;
use Pramnos\Broadcasting\Drivers\DriverInterface;
use Pramnos\Broadcasting\Drivers\LogDriver;
use Pramnos\Broadcasting\Drivers\NullDriver;
use Pramnos\Application\FeatureRegistry;

/**
 * Unit tests for BroadcastingManager and the built-in drivers.
 *
 * These tests cover the core dispatch path, driver registration, default-driver
 * selection, the LogDriver's file I/O and read-back helpers, and the
 * FeatureRegistry entry for the 'broadcasting' feature key.
 */
class BroadcastingManagerTest extends TestCase
{
    protected function setUp(): void
    {
        FeatureRegistry::reset();
    }

    protected function tearDown(): void
    {
        FeatureRegistry::reset();
    }

    // ── FeatureRegistry ───────────────────────────────────────────────────────

    /**
     * 'broadcasting' must be registered so that app.php can enable it without
     * throwing UnknownFeatureException.
     */
    public function testBroadcastingFeatureIsRegistered(): void
    {
        // Act
        $known = FeatureRegistry::getKnown();

        // Assert
        $this->assertContains('broadcasting', $known);
    }

    /**
     * The 'broadcasting' feature must point to BroadcastingServiceProvider.
     */
    public function testBroadcastingFeatureHasCorrectProvider(): void
    {
        // Act
        $provider = FeatureRegistry::getProvider('broadcasting');

        // Assert
        $this->assertSame(\Pramnos\Broadcasting\BroadcastingServiceProvider::class, $provider);
    }

    // ── BroadcastingManager defaults ─────────────────────────────────────────

    /**
     * A fresh BroadcastingManager must have the NullDriver registered as default.
     *
     * The null driver is the safe no-op fallback so an unconfigured broadcasting
     * feature doesn't cause errors.
     */
    public function testDefaultDriverIsNull(): void
    {
        // Arrange / Act
        $manager = new BroadcastingManager();

        // Assert — default driver name
        $this->assertInstanceOf(NullDriver::class, $manager->driver());
        $this->assertSame('null', $manager->driver()->name());
    }

    /**
     * broadcast() must not throw when using the NullDriver.
     *
     * The null driver silently discards all events.
     */
    public function testBroadcastWithNullDriverDoesNotThrow(): void
    {
        // Arrange
        $manager = new BroadcastingManager();

        // Act + Assert — no exception
        $this->expectNotToPerformAssertions();
        $manager->broadcast('test-channel', 'test.event', ['key' => 'value']);
    }

    // ── Driver registration ───────────────────────────────────────────────────

    /**
     * addDriver() must register the driver so getDriverNames() includes it.
     */
    public function testAddDriverRegistersDriver(): void
    {
        // Arrange
        $manager = new BroadcastingManager();
        $log     = new LogDriver(tempnam(sys_get_temp_dir(), 'bcast_test_'));

        // Act
        $manager->addDriver($log);

        // Assert
        $this->assertContains('log', $manager->getDriverNames());
    }

    /**
     * setDefault() must switch the active driver.
     */
    public function testSetDefaultSwitchesActiveDriver(): void
    {
        // Arrange
        $logPath = tempnam(sys_get_temp_dir(), 'bcast_test_');
        $manager = new BroadcastingManager();
        $manager->addDriver(new LogDriver($logPath));

        // Act
        $manager->setDefault('log');

        // Assert — active driver is now log
        $this->assertInstanceOf(LogDriver::class, $manager->driver());
    }

    /**
     * setDefault() with an unregistered name must throw InvalidArgumentException.
     */
    public function testSetDefaultUnknownDriverThrows(): void
    {
        // Arrange
        $manager = new BroadcastingManager();

        // Act + Assert
        $this->expectException(\InvalidArgumentException::class);
        $manager->setDefault('unknown-driver');
    }

    /**
     * via() must route the event to a specific named driver, not the default.
     */
    public function testViaRoutesToSpecificDriver(): void
    {
        // Arrange
        $logPath = tempnam(sys_get_temp_dir(), 'bcast_via_');
        @unlink($logPath); // ensure clean start

        $log     = new LogDriver($logPath);
        $manager = new BroadcastingManager(); // default = null
        $manager->addDriver($log);

        // Act — broadcast via 'log' explicitly, default stays null
        $manager->via('log', 'my-channel', 'ping', ['ts' => 1]);

        // Assert — log file has one entry; null driver received nothing (no assertion needed)
        $entries = $log->getEntries();
        $this->assertCount(1, $entries);
        $this->assertSame('my-channel', $entries[0]['channel']);

        @unlink($logPath);
    }

    // ── LogDriver ─────────────────────────────────────────────────────────────

    /**
     * LogDriver must write each broadcast as a JSON line to its log file.
     */
    public function testLogDriverWritesJsonLine(): void
    {
        // Arrange
        $logPath = tempnam(sys_get_temp_dir(), 'logdriver_');
        @unlink($logPath);
        $driver  = new LogDriver($logPath);

        // Act
        $driver->broadcast('room.1', 'message.created', ['body' => 'Hello']);

        // Assert — file exists and contains valid JSON
        $this->assertFileExists($logPath);
        $line    = trim(file_get_contents($logPath));
        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded);
        $this->assertSame('room.1', $decoded['channel']);
        $this->assertSame('message.created', $decoded['event']);
        $this->assertSame('Hello', $decoded['payload']['body']);

        @unlink($logPath);
    }

    /**
     * LogDriver::getEntries() must return all written entries as decoded arrays.
     */
    public function testLogDriverGetEntriesReturnsAllLines(): void
    {
        // Arrange
        $logPath = tempnam(sys_get_temp_dir(), 'logdriver_entries_');
        @unlink($logPath);
        $driver  = new LogDriver($logPath);

        // Act
        $driver->broadcast('ch', 'ev1', ['n' => 1]);
        $driver->broadcast('ch', 'ev2', ['n' => 2]);

        $entries = $driver->getEntries();

        // Assert — both entries present in order
        $this->assertCount(2, $entries);
        $this->assertSame('ev1', $entries[0]['event']);
        $this->assertSame('ev2', $entries[1]['event']);

        @unlink($logPath);
    }

    /**
     * LogDriver::getEntries() must return an empty array when no file exists.
     */
    public function testLogDriverGetEntriesEmptyWhenNoFile(): void
    {
        // Arrange — no file written
        $driver = new LogDriver('/nonexistent/path/bcast.log');

        // Act
        $entries = $driver->getEntries();

        // Assert
        $this->assertSame([], $entries);
    }

    /**
     * LogDriver::clear() must truncate the log file.
     */
    public function testLogDriverClearTruncatesFile(): void
    {
        // Arrange
        $logPath = tempnam(sys_get_temp_dir(), 'logdriver_clear_');
        $driver  = new LogDriver($logPath);
        $driver->broadcast('ch', 'ev', []);

        // Act
        $driver->clear();

        // Assert — file exists but is empty
        $this->assertSame('', file_get_contents($logPath));

        @unlink($logPath);
    }

    /**
     * LogDriver::name() must return 'log'.
     */
    public function testLogDriverName(): void
    {
        $this->assertSame('log', (new LogDriver())->name());
    }

    // ── NullDriver ────────────────────────────────────────────────────────────

    /**
     * NullDriver::name() must return 'null'.
     */
    public function testNullDriverName(): void
    {
        $this->assertSame('null', (new NullDriver())->name());
    }

    /**
     * NullDriver must implement DriverInterface.
     */
    public function testNullDriverImplementsInterface(): void
    {
        $this->assertInstanceOf(DriverInterface::class, new NullDriver());
    }
}
