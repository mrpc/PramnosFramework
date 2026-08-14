<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Broadcasting;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\SubscriptionOptions;

/**
 * `SubscriptionOptions::blockingWindow()` — the clamp that makes `maxRuntime` a number.
 *
 * A driver's loop checks the deadline at the top and then blocks for `readTimeout` seconds, so
 * a deadline falling *during* a read was not noticed until that read returned. The stream ended
 * somewhere in `[maxRuntime, maxRuntime + readTimeout]`: at the top of that window on a quiet
 * channel, and at the **bottom** on a busy one, where an event arrives just after the deadline.
 *
 * That range was the bug. A client doing an overlapping reconnect has only `maxRuntime` to go
 * on, its own clock starts at `open` — strictly after the server's — and so at equal periods the
 * server leads. It wins exactly on the busy installs, and the symptom is an occasional transport
 * error that looks like a network blip and gets worse under load. One consumer ran a 95-second
 * client timer against `maxRuntime: 95` and survived only because its channels were quiet.
 */
class BlockingWindowTest extends TestCase
{
    /**
     * With no deadline, the full read timeout is used.
     *
     * An unlimited stream has nothing to clamp against, and shortening its reads would only
     * make it wake up more often for no reason.
     */
    public function testNoDeadlineMeansTheFullReadTimeout(): void
    {
        // Arrange
        $options = new SubscriptionOptions(readTimeout: 20);

        // Act & Assert
        $this->assertSame(20, $options->blockingWindow(null));
    }

    /**
     * A deadline further away than the read timeout does not shorten the read.
     */
    public function testADistantDeadlineDoesNotShortenTheRead(): void
    {
        // Arrange
        $options = new SubscriptionOptions(readTimeout: 20);

        // Act & Assert
        $this->assertSame(20, $options->blockingWindow(time() + 300));
    }

    /**
     * A deadline inside the read timeout shortens the read to the remainder.
     *
     * This is the whole fix: the last read ends *at* the deadline instead of up to
     * `readTimeout` seconds past it.
     */
    public function testANearDeadlineShortensTheReadToTheRemainder(): void
    {
        // Arrange
        $options = new SubscriptionOptions(readTimeout: 20);

        // Act
        $window = $options->blockingWindow(time() + 5);

        // Assert — 5, allowing for the second boundary being crossed mid-test
        $this->assertGreaterThanOrEqual(4, $window);
        $this->assertLessThanOrEqual(5, $window);
    }

    /**
     * The window is never zero, even for a deadline that has just passed.
     *
     * A driver only reaches a read while the deadline is still ahead, so this is defensive —
     * but zero means "block forever" to most clients, which is the opposite of the intent and
     * would hang the stream precisely when it was trying to end.
     */
    public function testTheWindowIsNeverZero(): void
    {
        // Arrange
        $options = new SubscriptionOptions(readTimeout: 20);

        // Act & Assert
        $this->assertSame(1, $options->blockingWindow(time()));
        $this->assertSame(1, $options->blockingWindow(time() - 60));
    }

    /**
     * Every driver that blocks uses the clamp rather than the raw timeout.
     *
     * Asserted against the source, because the alternative is four separate integration tests
     * that each need a live backend to prove a one-line arithmetic change. The three drivers
     * had the same shape and the same bug, and the way this regresses is somebody fixing one
     * and not the others.
     *
     * @param string $driver Class file under `Broadcasting/Drivers/`
     * @return void
     */
    #[DataProvider('blockingDrivers')]
    public function testEveryBlockingDriverUsesTheClamp(string $driver): void
    {
        // Arrange
        $path   = dirname(__DIR__, 4) . '/src/Pramnos/Broadcasting/Drivers/' . $driver;
        $source = (string) file_get_contents($path);

        // Assert
        $this->assertStringContainsString(
            'blockingWindow($deadline)',
            $source,
            $driver . ' must clamp its blocking read to the deadline.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$options->readTimeout\s*\*\s*1000/',
            $source,
            $driver . ' still blocks for the unclamped readTimeout.'
        );
    }

    /**
     * The drivers that block on a read.
     *
     * @return array<string, array{string}>
     */
    public static function blockingDrivers(): array
    {
        return [
            'Redis Streams' => ['RedisStreamDriver.php'],
            'Redis pub/sub' => ['RedisDriver.php'],
            'Database poll' => ['DatabaseDriver.php'],
        ];
    }
}
