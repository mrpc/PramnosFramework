<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Http\WebSocket;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\WebSocket\FrameCodec;
use Pramnos\Http\WebSocket\MessageAssembler;
use Pramnos\Http\WebSocket\WebSocketProtocolException;

/**
 * Covers reassembly of fragmented WebSocket messages.
 *
 * The invariant under test is that a caller never sees a fragment. The framework's
 * own server violated it before this class existed — it read the opcode but never
 * the FIN bit — so a peer that split a JSON message delivered two halves, each an
 * invalid document, with nothing in the logs to say why.
 */
class MessageAssemblerTest extends TestCase
{
    /**
     * Build a raw frame with explicit FIN and opcode, which encode() cannot do
     * because it always sets FIN.
     */
    private function rawFrame(string $payload, int $opcode, bool $fin): string
    {
        $byte0 = ($fin ? 0x80 : 0x00) | $opcode;
        $len   = strlen($payload);

        if ($len < 126) {
            return chr($byte0) . chr($len) . $payload;
        }

        return chr($byte0) . chr(126) . pack('n', $len) . $payload;
    }

    /**
     * A single unfragmented frame yields exactly one message.
     */
    public function testYieldsSingleFrameMessage(): void
    {
        // Arrange
        $assembler = new MessageAssembler();

        // Act
        $messages = $assembler->feed(FrameCodec::encode('hello'));

        // Assert
        $this->assertCount(1, $messages);
        $this->assertSame('hello', $messages[0]['payload']);
        $this->assertSame(FrameCodec::OP_TEXT, $messages[0]['opcode']);
        $this->assertFalse($assembler->hasPartialMessage());
    }

    /**
     * A message split across a text frame and two continuation frames is
     * delivered once, whole, with the opcode of the first frame.
     *
     * This is the case the server used to get wrong; the assertion that matters
     * is the count, because a fragment-blind reader returns three messages here.
     */
    public function testReassemblesFragmentedMessage(): void
    {
        // Arrange
        $assembler = new MessageAssembler();
        $bytes = $this->rawFrame('{"a":', FrameCodec::OP_TEXT, false)
            . $this->rawFrame('1,"b":', FrameCodec::OP_CONTINUATION, false)
            . $this->rawFrame('2}', FrameCodec::OP_CONTINUATION, true);

        // Act
        $messages = $assembler->feed($bytes);

        // Assert
        $this->assertCount(1, $messages, 'three frames form one message, not three');
        $this->assertSame('{"a":1,"b":2}', $messages[0]['payload']);
        $this->assertSame(FrameCodec::OP_TEXT, $messages[0]['opcode'], 'the opcode comes from the first frame');
    }

    /**
     * Bytes arriving in arbitrary chunks — including chunks that split a frame
     * header in half — produce the same result as one contiguous feed.
     *
     * A stream reader has no control over where TCP segments land, so this is the
     * normal case rather than an edge case.
     */
    public function testReassemblesAcrossArbitraryChunkBoundaries(): void
    {
        // Arrange
        $assembler = new MessageAssembler();
        $bytes     = FrameCodec::encode(str_repeat('q', 400));
        $collected = [];

        // Act: feed one byte at a time.
        for ($i = 0; $i < strlen($bytes); $i++) {
            foreach ($assembler->feed($bytes[$i]) as $message) {
                $collected[] = $message;
            }
        }

        // Assert
        $this->assertCount(1, $collected);
        $this->assertSame(str_repeat('q', 400), $collected[0]['payload']);
    }

    /**
     * While a fragmented message is in progress, hasPartialMessage() is true —
     * so a caller can tell a quiet connection from one stuck mid-message.
     */
    public function testReportsPartialMessageInProgress(): void
    {
        // Arrange
        $assembler = new MessageAssembler();

        // Act
        $messages = $assembler->feed($this->rawFrame('start', FrameCodec::OP_TEXT, false));

        // Assert
        $this->assertSame([], $messages, 'nothing completes until FIN arrives');
        $this->assertTrue($assembler->hasPartialMessage());
    }

    /**
     * A ping arriving between two fragments is surfaced immediately, ahead of the
     * message still being assembled.
     *
     * Order matters: holding a ping behind an in-progress message defeats the
     * keepalive it exists for, and a peer may drop a connection that fails to
     * answer in time.
     */
    public function testPassesControlFrameThroughAheadOfPendingMessage(): void
    {
        // Arrange
        $assembler = new MessageAssembler();
        $bytes = $this->rawFrame('first-half', FrameCodec::OP_TEXT, false)
            . FrameCodec::encode('are-you-there', FrameCodec::OP_PING)
            . $this->rawFrame('second-half', FrameCodec::OP_CONTINUATION, true);

        // Act
        $messages = $assembler->feed($bytes);

        // Assert
        $this->assertCount(2, $messages);
        $this->assertSame(FrameCodec::OP_PING, $messages[0]['opcode'], 'the ping comes out first');
        $this->assertSame('are-you-there', $messages[0]['payload']);
        $this->assertSame(FrameCodec::OP_TEXT, $messages[1]['opcode']);
        $this->assertSame('first-halfsecond-half', $messages[1]['payload']);
    }

    /**
     * A continuation frame with no message in progress is a protocol error.
     *
     * Accepting it would mean silently inventing a message boundary, which is how
     * a desynchronised stream turns into plausible-looking garbage.
     */
    public function testRejectsContinuationWithNothingInProgress(): void
    {
        // Arrange
        $assembler = new MessageAssembler();

        // Act & Assert
        $this->expectException(WebSocketProtocolException::class);
        $this->expectExceptionMessageMatches('/no message in progress/');
        $assembler->feed($this->rawFrame('orphan', FrameCodec::OP_CONTINUATION, true));
    }

    /**
     * A new data frame while a fragmented message is open is a protocol error —
     * RFC 6455 forbids interleaving data messages.
     */
    public function testRejectsNewDataFrameMidMessage(): void
    {
        // Arrange
        $assembler = new MessageAssembler();
        $assembler->feed($this->rawFrame('open', FrameCodec::OP_TEXT, false));

        // Act & Assert
        $this->expectException(WebSocketProtocolException::class);
        $this->expectExceptionMessageMatches('/while a fragmented message was in progress/');
        $assembler->feed($this->rawFrame('interleaved', FrameCodec::OP_TEXT, true));
    }

    /**
     * Fragments that together exceed the message ceiling are refused.
     *
     * The per-frame limit alone does not close this hole: unlimited small
     * fragments that never set FIN grow one buffer without any single frame
     * looking suspicious.
     */
    public function testRefusesReassembledMessageOverTheCeiling(): void
    {
        // Arrange: 100-byte ceiling, fed 60 bytes at a time.
        $assembler = new MessageAssembler(FrameCodec::DEFAULT_MAX_PAYLOAD, 100);
        $assembler->feed($this->rawFrame(str_repeat('a', 60), FrameCodec::OP_TEXT, false));

        // Act & Assert
        $this->expectException(WebSocketProtocolException::class);
        $this->expectExceptionMessageMatches('/100-byte limit/');
        $assembler->feed($this->rawFrame(str_repeat('b', 60), FrameCodec::OP_CONTINUATION, false));
    }

    /**
     * reset() drops buffered bytes and any in-progress message, so a reconnected
     * socket does not inherit half a frame from the previous connection.
     */
    public function testResetDiscardsPartialState(): void
    {
        // Arrange
        $assembler = new MessageAssembler();
        $assembler->feed($this->rawFrame('half', FrameCodec::OP_TEXT, false));

        // Act
        $assembler->reset();

        // Assert
        $this->assertFalse($assembler->hasPartialMessage());
        // A continuation now has nothing to continue, proving the state is gone.
        $this->expectException(WebSocketProtocolException::class);
        $assembler->feed($this->rawFrame('rest', FrameCodec::OP_CONTINUATION, true));
    }

    /**
     * An empty feed is a no-op, which a non-blocking reader hits constantly when
     * fread() returns '' on an idle socket.
     */
    public function testEmptyFeedYieldsNothing(): void
    {
        // Arrange
        $assembler = new MessageAssembler();

        // Act & Assert
        $this->assertSame([], $assembler->feed(''));
    }
}
