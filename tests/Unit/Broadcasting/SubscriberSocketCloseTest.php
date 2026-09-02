<?php

declare(strict_types=1);

namespace Tests\Unit\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\RedisSubscriberSocket;

/**
 * Letting go of a subscriber socket.
 *
 * Five statements, never executed, in the one method a long-running broadcast daemon calls most
 * often — every reconnect goes through it. Three properties, and all three are about a daemon that
 * does not restart:
 *
 * - **the stream is closed**, or a daemon that reconnects for hours leaks a file descriptor per
 *   attempt until the process cannot open any more;
 * - **the handle is set to `null`**, so the next `isConnected()` says no rather than answering from
 *   a closed resource;
 * - **the buffer is emptied**, which is the subtle one: a partial message left over from the
 *   dropped connection would be prefixed onto the first message of the new one, and the reader
 *   would parse the join as a single malformed frame.
 *
 * And it is safe to call twice, because a reconnect loop cannot know whether the socket it is
 * dropping was ever open.
 */
#[CoversClass(RedisSubscriberSocket::class)]
class SubscriberSocketCloseTest extends TestCase
{
    /** A socket holding a real stream and a partial message, without a Redis in sight. */
    private function socketWith(mixed $stream, string $buffer): RedisSubscriberSocket
    {
        $socket = (new \ReflectionClass(RedisSubscriberSocket::class))->newInstanceWithoutConstructor();

        (new \ReflectionProperty(RedisSubscriberSocket::class, 'stream'))->setValue($socket, $stream);
        (new \ReflectionProperty(RedisSubscriberSocket::class, 'buffer'))->setValue($socket, $buffer);

        return $socket;
    }

    /** Reads a private property back. */
    private function property(RedisSubscriberSocket $socket, string $name): mixed
    {
        return (new \ReflectionProperty(RedisSubscriberSocket::class, $name))->getValue($socket);
    }

    /**
     * The stream is closed and the handle dropped.
     *
     * Both, because closing without dropping leaves a closed resource that `is_resource()` still
     * reports on some versions — and a daemon that believes it has a connection does not reconnect.
     */
    public function testTheStreamIsClosedAndTheHandleDropped(): void
    {
        // Arrange — a real stream, so the close is a real close
        $stream = fopen('php://memory', 'r+');
        $socket = $this->socketWith($stream, '');

        // Act
        $socket->close();

        // Assert
        $this->assertNull($this->property($socket, 'stream'));
        $this->assertFalse(is_resource($stream), 'the descriptor was left open');
    }

    /**
     * A partial message is thrown away.
     *
     * The one that would corrupt data rather than leak it. Half a frame from the dropped
     * connection, kept across a reconnect, is prefixed onto the first frame of the new one — and
     * the reader sees a single message that parses as neither.
     */
    public function testAPartialMessageIsThrownAway(): void
    {
        // Arrange
        $stream = fopen('php://memory', 'r+');
        $socket = $this->socketWith($stream, '*3' . "\r\n" . '$7' . "\r\n" . 'messag');

        // Act
        $socket->close();

        // Assert
        $this->assertSame('', $this->property($socket, 'buffer'), 'half a frame survived the reconnect');
    }

    /**
     * Closing a socket that was never open is not an error.
     *
     * A reconnect loop calls this before every attempt and cannot know whether the last one got
     * as far as a stream. The `is_resource()` guard is what makes that safe.
     */
    public function testClosingASocketThatWasNeverOpenIsSafe(): void
    {
        // Arrange
        $socket = $this->socketWith(null, 'leftover');

        // Act
        $socket->close();

        // Assert
        $this->assertNull($this->property($socket, 'stream'));
        $this->assertSame('', $this->property($socket, 'buffer'));
    }

    /**
     * And twice in a row is not an error either.
     *
     * The second call sees a `null` handle, which is the same path as never having been open —
     * asserted separately because a guard that only handled `null` on the *first* call would still
     * pass the test above.
     */
    public function testClosingTwiceIsSafe(): void
    {
        // Arrange
        $stream = fopen('php://memory', 'r+');
        $socket = $this->socketWith($stream, 'x');

        // Act
        $socket->close();
        $socket->close();

        // Assert
        $this->assertNull($this->property($socket, 'stream'));
        $this->addToAssertionCount(1);
    }
}
