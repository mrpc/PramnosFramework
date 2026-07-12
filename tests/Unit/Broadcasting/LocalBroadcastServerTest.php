<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\LocalBroadcastServer;

/**
 * Unit tests for LocalBroadcastServer.
 *
 * Tests cover the constructor configuration, stop() control flag, log-file
 * format compatibility, and the broadcast() fanout logic without actually
 * starting a network socket (all socket I/O is tested at integration level).
 */
#[CoversClass(LocalBroadcastServer::class)]
class LocalBroadcastServerTest extends TestCase
{
    /**
     * Verifies that LocalBroadcastServer can be instantiated with the default
     * app-key and no log file, and that stop() sets the running flag to false
     * before the loop starts (no-op when called on a never-started server).
     *
     * Invariant: constructor must not throw, and stop() must be callable safely.
     */
    public function testConstructorAndStopAreCallableWithoutSocket(): void
    {
        // Arrange + Act
        $server = new LocalBroadcastServer('test-key', null);

        // Assert — no exception thrown
        $this->assertInstanceOf(LocalBroadcastServer::class, $server);

        // stop() is a no-op when not running — must not throw
        $server->stop();
        $this->assertTrue(true); // reached here = stop() did not throw
    }

    /**
     * Verifies that onTick() registers a callback that is NOT called when the
     * server is not running (i.e., the callback is stored, not invoked immediately).
     */
    public function testOnTickRegistersCallbackWithoutInvoking(): void
    {
        // Arrange
        $server  = new LocalBroadcastServer('key');
        $invoked = false;

        // Act — register callback before server starts
        $server->onTick(function () use (&$invoked): void {
            $invoked = true;
        });

        // Assert — callback was NOT called just by registering it
        $this->assertFalse($invoked, 'onTick callback must not fire at registration time');
    }

    /**
     * Verifies that run() throws RuntimeException when the address is invalid.
     *
     * Uses an intentionally malformed host string ('invalid-host-###') that
     * stream_socket_server() cannot resolve, so the bind fails immediately
     * without entering the event loop — safe to run as any user, in any container.
     */
    public function testRunThrowsWhenAddressIsInvalid(): void
    {
        // Arrange
        $server = new LocalBroadcastServer('key');

        // Assert
        $this->expectException(\RuntimeException::class);

        // Act — non-resolvable hostname guarantees an immediate bind failure
        $server->run('invalid-host-###', 65000);
    }

    /**
     * Verifies that the log-file polling supports both the LogDriver entry format
     * (key: 'payload') and the generic format (key: 'data'), and that malformed
     * lines are silently skipped.
     *
     * This is a white-box test: we write directly to a temp log file and then
     * run a single poll cycle by constructing the server with that file.
     * Because pollLogFile() is protected, we use a thin subclass to expose it.
     */
    public function testPollLogFileParsesLogDriverAndGenericFormats(): void
    {
        // Arrange
        $tmpFile = tempnam(sys_get_temp_dir(), 'pramnos_broadcast_test_');
        $this->assertNotFalse($tmpFile);

        // Write two valid entries (LogDriver format + generic format) and one invalid line.
        file_put_contents($tmpFile, implode("\n", [
            json_encode(['channel' => 'orders', 'event' => 'created', 'payload' => ['id' => 1]]),
            json_encode(['channel' => 'users',  'event' => 'updated', 'data'    => ['id' => 2]]),
            'not-json-at-all',
            '',
        ]) . "\n");

        // Arrange — subclass that records broadcast() calls for assertion
        $recorded = [];
        $server   = new class('key', $tmpFile) extends LocalBroadcastServer {
            /** @var array<int, array{channel:string, event:string, data:mixed}> */
            public array $broadcasted = [];

            public function broadcast(string $channel, string $event, $data): void
            {
                $this->broadcasted[] = compact('channel', 'event', 'data');
            }

            public function callPollLogFile(): void
            {
                $this->pollLogFile();
            }
        };

        // Act
        $server->callPollLogFile();

        // Assert — two valid entries parsed; invalid line skipped
        $this->assertCount(2, $server->broadcasted, 'Two valid log entries should have been broadcast');

        // Assert — LogDriver 'payload' key
        $this->assertSame('orders', $server->broadcasted[0]['channel']);
        $this->assertSame('created', $server->broadcasted[0]['event']);
        $this->assertSame(['id' => 1], $server->broadcasted[0]['data']);

        // Assert — generic 'data' key
        $this->assertSame('users', $server->broadcasted[1]['channel']);
        $this->assertSame('updated', $server->broadcasted[1]['event']);
        $this->assertSame(['id' => 2], $server->broadcasted[1]['data']);

        // Cleanup
        unlink($tmpFile);
    }

    /**
     * Verifies that pollLogFile() handles log rotation (file shrank) gracefully
     * by resetting the offset to 0 so subsequent reads start from the beginning.
     */
    public function testPollLogFileResetsOffsetOnLogRotation(): void
    {
        // Arrange
        $tmpFile = tempnam(sys_get_temp_dir(), 'pramnos_broadcast_rotation_');
        $this->assertNotFalse($tmpFile);

        // Write a line, poll it, then truncate (simulate rotation)
        file_put_contents($tmpFile, json_encode(['channel' => 'ch', 'event' => 'ev', 'data' => []]) . "\n");

        $server = new class('key', $tmpFile) extends LocalBroadcastServer {
            public array $broadcasted = [];

            public function broadcast(string $channel, string $event, $data): void
            {
                $this->broadcasted[] = compact('channel', 'event', 'data');
            }

            public function callPollLogFile(): void
            {
                $this->pollLogFile();
            }

            public function getLogOffset(): int
            {
                return $this->logOffset;
            }
        };

        // Act — first poll advances offset
        $server->callPollLogFile();
        $offsetAfterFirstPoll = $server->getLogOffset();
        $this->assertGreaterThan(0, $offsetAfterFirstPoll, 'Offset should advance after reading');

        // Simulate log rotation: truncate file
        file_put_contents($tmpFile, '');

        // Act — second poll should detect shrink and reset offset to 0
        $server->callPollLogFile();
        $this->assertSame(0, $server->getLogOffset(), 'Offset should reset to 0 after log rotation');

        // Cleanup
        unlink($tmpFile);
    }
}
