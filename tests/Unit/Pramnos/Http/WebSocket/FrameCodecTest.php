<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Http\WebSocket;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\WebSocket\FrameCodec;
use Pramnos\Http\WebSocket\WebSocketProtocolException;

/**
 * Covers the RFC 6455 frame wire format.
 *
 * The payload-length forms are the reason this class exists as a separate unit:
 * an implementation that reads only the 7-bit form works against every payload
 * up to 125 bytes and misreads every one above it, which is a bug that hides
 * behind small test fixtures. Each form is therefore exercised with a payload
 * that can only travel in it.
 */
class FrameCodecTest extends TestCase
{
    /**
     * A payload under 126 bytes travels in the 7-bit length field, so the header
     * is exactly two bytes and the frame round-trips unchanged.
     *
     * Guards the common case, and pins the header size so a regression that
     * silently promotes short frames to the 16-bit form is visible.
     */
    public function testEncodesAndDecodesShortPayloadInTwoByteHeader(): void
    {
        // Arrange
        $payload = 'hello';

        // Act
        $frame   = FrameCodec::encode($payload, FrameCodec::OP_TEXT, false);
        $decoded = FrameCodec::decode($frame);

        // Assert
        $this->assertSame(2 + strlen($payload), strlen($frame), 'short frames use a 2-byte header');
        $this->assertNotNull($decoded);
        $this->assertSame($payload, $decoded['payload']);
        $this->assertSame(FrameCodec::OP_TEXT, $decoded['opcode']);
        $this->assertTrue($decoded['fin'], 'encode() never fragments, so FIN is always set');
        $this->assertSame(strlen($frame), $decoded['consumed']);
    }

    /**
     * A payload between 126 and 65535 bytes must use the 16-bit length form.
     *
     * This is the exact size class of the real events that motivated the codec
     * (350-430 byte now-playing messages), and the one a 7-bit-only reader
     * corrupts.
     */
    public function testRoundTripsSixteenBitLengthForm(): void
    {
        // Arrange
        $payload = str_repeat('x', 400);

        // Act
        $frame   = FrameCodec::encode($payload);
        $decoded = FrameCodec::decode($frame);

        // Assert
        $this->assertSame(126, ord($frame[1]) & 0x7F, 'the length byte must signal the 16-bit form');
        $this->assertSame(4 + 400, strlen($frame), '2-byte header plus 2 length bytes');
        $this->assertNotNull($decoded);
        $this->assertSame($payload, $decoded['payload']);
    }

    /**
     * A payload of 65536 bytes or more must use the 64-bit length form.
     *
     * Proves the eight length bytes are both written and read; a decoder that
     * reads the wrong number of them desynchronises the whole stream.
     */
    public function testRoundTripsSixtyFourBitLengthForm(): void
    {
        // Arrange
        $payload = str_repeat('y', 70000);

        // Act
        $frame   = FrameCodec::encode($payload);
        $decoded = FrameCodec::decode($frame);

        // Assert
        $this->assertSame(127, ord($frame[1]) & 0x7F, 'the length byte must signal the 64-bit form');
        $this->assertSame(10 + 70000, strlen($frame), '2-byte header plus 8 length bytes');
        $this->assertNotNull($decoded);
        $this->assertSame($payload, $decoded['payload']);
    }

    /**
     * A masked frame carries the mask bit and a 4-byte key, and decoding
     * reverses the XOR to yield the original bytes.
     *
     * RFC 6455 §5.3 makes masking mandatory for client frames, so a client that
     * does not mask is closed on by conforming servers.
     */
    public function testMaskedFrameSetsMaskBitAndDecodesBack(): void
    {
        // Arrange
        $payload = 'masked payload';

        // Act
        $frame   = FrameCodec::encode($payload, FrameCodec::OP_TEXT, true);
        $decoded = FrameCodec::decode($frame);

        // Assert
        $this->assertSame(0x80, ord($frame[1]) & 0x80, 'the mask bit must be set');
        $this->assertSame(2 + 4 + strlen($payload), strlen($frame), 'a 4-byte mask key is included');
        $this->assertNotNull($decoded);
        $this->assertSame($payload, $decoded['payload']);
        // The masked bytes on the wire must not equal the plaintext, or the XOR
        // never happened.
        $this->assertNotSame($payload, substr($frame, 6));
    }

    /**
     * An empty payload is legal and must not trip the masking helper, which
     * would otherwise call str_repeat() and substr() on zero length.
     */
    public function testMaskedEmptyPayloadRoundTrips(): void
    {
        // Arrange & Act
        $decoded = FrameCodec::decode(FrameCodec::encode('', FrameCodec::OP_PING, true));

        // Assert
        $this->assertNotNull($decoded);
        $this->assertSame('', $decoded['payload']);
        $this->assertSame(FrameCodec::OP_PING, $decoded['opcode']);
    }

    /**
     * decode() returns null — not an exception — for every prefix of a frame, so
     * a caller can hand it whatever arrived and try again later.
     *
     * Distinguishing "incomplete" from "invalid" is the contract that keeps a
     * partially-arrived frame from being treated as a protocol error.
     */
    public function testReturnsNullForEveryIncompletePrefix(): void
    {
        // Arrange
        $frame = FrameCodec::encode(str_repeat('z', 300));

        // Act & Assert
        for ($i = 0; $i < strlen($frame); $i++) {
            $this->assertNull(
                FrameCodec::decode(substr($frame, 0, $i)),
                "a {$i}-byte prefix of a 304-byte frame must read as incomplete"
            );
        }
        $this->assertNotNull(FrameCodec::decode($frame), 'the complete frame decodes');
    }

    /**
     * Several frames in one buffer are decoded one at a time, with `consumed`
     * telling the caller where the next one starts.
     */
    public function testConsumedAllowsWalkingConcatenatedFrames(): void
    {
        // Arrange
        $buffer = FrameCodec::encode('one') . FrameCodec::encode('two');

        // Act
        $first  = FrameCodec::decode($buffer);
        $second = FrameCodec::decode(substr($buffer, $first['consumed']));

        // Assert
        $this->assertSame('one', $first['payload']);
        $this->assertSame('two', $second['payload']);
    }

    /**
     * A frame declaring more than the configured ceiling is refused rather than
     * buffered.
     *
     * The length field is 64 bits wide, so without this an unauthenticated peer
     * can ask the process to wait for more memory than the machine has.
     */
    public function testRefusesPayloadOverTheLimit(): void
    {
        // Arrange: declare 5000 bytes via the 16-bit form, with a 1000-byte cap.
        $frame = chr(0x81) . chr(126) . pack('n', 5000);

        // Act & Assert
        $this->expectException(WebSocketProtocolException::class);
        $this->expectExceptionMessageMatches('/1000-byte limit/');
        FrameCodec::decode($frame, 1000);
    }

    /**
     * A 64-bit length whose high half is non-zero is refused before it can be
     * folded into a PHP int.
     *
     * Proves the two-halves read: a naive shift-by-8-bytes loop turns such a
     * declaration into a negative or wrapped length that passes a simple
     * upper-bound check.
     */
    public function testRefusesSixtyFourBitLengthWithHighBitsSet(): void
    {
        // Arrange: high 32 bits = 1, i.e. at least 4 GiB.
        $frame = chr(0x81) . chr(127) . pack('N', 1) . pack('N', 0);

        // Act & Assert
        $this->expectException(WebSocketProtocolException::class);
        FrameCodec::decode($frame);
    }

    /**
     * A fragmented control frame is a protocol error (RFC 6455 §5.5).
     *
     * Enforcing it in the codec is what lets MessageAssembler pass control
     * frames straight through without tracking fragmentation state for them.
     */
    public function testRefusesFragmentedControlFrame(): void
    {
        // Arrange: ping with FIN clear.
        $frame = chr(FrameCodec::OP_PING) . chr(0);

        // Act & Assert
        $this->expectException(WebSocketProtocolException::class);
        $this->expectExceptionMessageMatches('/must not be fragmented/');
        FrameCodec::decode($frame);
    }

    /**
     * A control frame carrying more than 125 bytes is a protocol error.
     */
    public function testRefusesOversizedControlFrame(): void
    {
        // Arrange: close frame declaring 200 bytes via the 16-bit form.
        $frame = chr(0x80 | FrameCodec::OP_CLOSE) . chr(126) . pack('n', 200) . str_repeat('a', 200);

        // Act & Assert
        $this->expectException(WebSocketProtocolException::class);
        $this->expectExceptionMessageMatches('/maximum is 125/');
        FrameCodec::decode($frame);
    }

    /**
     * A binary frame keeps its opcode through a round trip, so a caller can tell
     * text from binary rather than guessing from the bytes.
     */
    public function testPreservesBinaryOpcode(): void
    {
        // Arrange
        $payload = "\x00\x01\x02\xff";

        // Act
        $decoded = FrameCodec::decode(FrameCodec::encode($payload, FrameCodec::OP_BINARY));

        // Assert
        $this->assertSame(FrameCodec::OP_BINARY, $decoded['opcode']);
        $this->assertSame($payload, $decoded['payload']);
    }
}
