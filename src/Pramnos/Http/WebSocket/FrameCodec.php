<?php

declare(strict_types=1);

namespace Pramnos\Http\WebSocket;

/**
 * RFC 6455 frame encoding and decoding — the wire format only, with no socket,
 * no connection state and no protocol layer above it.
 *
 * Both directions of a WebSocket use the same frame layout, so one codec serves
 * {@see \Pramnos\Broadcasting\LocalBroadcastServer} (which sends unmasked frames
 * and receives masked ones) and {@see \Pramnos\Http\WebSocketClient} (the
 * reverse). The single asymmetry RFC 6455 §5.3 imposes is *who* masks:
 *
 *   - a client MUST mask every frame it sends
 *   - a server MUST NOT mask any frame it sends
 *
 * That is the `$mask` argument to {@see encode()}, and it is the caller's to get
 * right because only the caller knows which end it is. Decoding is symmetric:
 * unmask if the mask bit is set, whoever set it.
 *
 * **The three payload-length forms are the part that gets written wrong.** A
 * length below 126 travels in the second byte; 126 means "the next two bytes are
 * the length"; 127 means "the next eight are". An implementation that reads only
 * the 7-bit form works perfectly until the first payload over 125 bytes, and then
 * misreads every one of them — a reference application lost a day to exactly
 * that, with a working authorizer that looked like it was refusing. All three
 * forms are implemented here once, and tested against payloads that need each.
 */
final class FrameCodec
{
    public const OP_CONTINUATION = 0x0;
    public const OP_TEXT         = 0x1;
    public const OP_BINARY       = 0x2;
    public const OP_CLOSE        = 0x8;
    public const OP_PING         = 0x9;
    public const OP_PONG         = 0xA;

    /** Frames at or above this opcode are control frames (RFC 6455 §5.5). */
    public const CONTROL_OPCODE_MIN = 0x8;

    /**
     * Default ceiling for a single frame's payload, in bytes.
     *
     * A peer that never stops sending must not be able to take the process with
     * it: the length field is 64 bits wide, so an unchecked decoder will happily
     * be told to wait for 16 exabytes and buffer forever. 8 MiB is far above any
     * realtime event and far below anything that threatens a PHP process.
     */
    public const DEFAULT_MAX_PAYLOAD = 8 * 1024 * 1024;

    /**
     * Encode one frame.
     *
     * @param string $payload Raw payload bytes.
     * @param int    $opcode  One of the OP_* constants.
     * @param bool   $mask    True to mask (clients MUST; servers MUST NOT).
     * @return string The complete frame, ready to write to a socket.
     */
    public static function encode(
        string $payload,
        int $opcode = self::OP_TEXT,
        bool $mask = false
    ): string {
        $len    = strlen($payload);
        $header = chr(0x80 | $opcode);          // FIN set: this codec never fragments
        $maskBit = $mask ? 0x80 : 0x00;

        if ($len < 126) {
            $header .= chr($maskBit | $len);
        } elseif ($len < 65536) {
            $header .= chr($maskBit | 126) . pack('n', $len);
        } else {
            // 64-bit length. pack('J') needs PHP 5.6+ and is big-endian, which is
            // what the wire wants; the high bit must be 0 per RFC 6455 §5.2.
            $header .= chr($maskBit | 127) . pack('J', $len);
        }

        if (!$mask) {
            return $header . $payload;
        }

        $maskKey = random_bytes(4);

        return $header . $maskKey . self::applyMask($payload, $maskKey);
    }

    /**
     * Decode the first frame in $buffer.
     *
     * @param string $buffer     Bytes received so far (may hold part of a frame,
     *                           one frame, or several).
     * @param int    $maxPayload Refuse any frame declaring more than this.
     * @return array{fin:bool, opcode:int, payload:string, consumed:int}|null
     *         Null when the frame has not fully arrived — call again with more
     *         bytes. `consumed` is how many bytes of $buffer the frame occupied.
     * @throws WebSocketProtocolException When the frame is malformed or oversized.
     */
    public static function decode(
        string $buffer,
        int $maxPayload = self::DEFAULT_MAX_PAYLOAD
    ): ?array {
        $len = strlen($buffer);
        if ($len < 2) {
            return null;
        }

        $byte0  = ord($buffer[0]);
        $byte1  = ord($buffer[1]);
        $fin    = ($byte0 & 0x80) !== 0;
        $opcode = $byte0 & 0x0F;
        $masked = ($byte1 & 0x80) !== 0;
        $payLen = $byte1 & 0x7F;
        $offset = 2;

        if ($payLen === 126) {
            if ($len < 4) {
                return null;
            }
            $payLen = (ord($buffer[2]) << 8) | ord($buffer[3]);
            $offset = 4;
        } elseif ($payLen === 127) {
            if ($len < 10) {
                return null;
            }
            // Read as two 32-bit halves so a >2GiB declaration cannot silently
            // wrap into a negative int on the way to the length check below.
            $high = unpack('N', substr($buffer, 2, 4))[1];
            $low  = unpack('N', substr($buffer, 6, 4))[1];
            if ($high !== 0 || $low > $maxPayload) {
                throw new WebSocketProtocolException(
                    'Frame declares a payload larger than the ' . $maxPayload
                    . '-byte limit; refusing to buffer it.'
                );
            }
            $payLen = $low;
            $offset = 10;
        }

        if ($payLen > $maxPayload) {
            throw new WebSocketProtocolException(
                'Frame declares a ' . $payLen . '-byte payload, over the '
                . $maxPayload . '-byte limit; refusing to buffer it.'
            );
        }

        // A control frame carries at most 125 bytes and is never fragmented
        // (RFC 6455 §5.5) — enforcing it here keeps the assembler simple.
        if ($opcode >= self::CONTROL_OPCODE_MIN) {
            if (!$fin) {
                throw new WebSocketProtocolException(
                    'Control frame (opcode ' . $opcode . ') must not be fragmented.'
                );
            }
            if ($payLen > 125) {
                throw new WebSocketProtocolException(
                    'Control frame (opcode ' . $opcode . ') carries ' . $payLen
                    . ' bytes; the maximum is 125.'
                );
            }
        }

        $maskLen = $masked ? 4 : 0;
        if ($len < $offset + $maskLen + $payLen) {
            return null;
        }

        $maskKey = $masked ? substr($buffer, $offset, 4) : '';
        $offset += $maskLen;
        $payload = substr($buffer, $offset, $payLen);

        if ($masked) {
            $payload = self::applyMask($payload, $maskKey);
        }

        return [
            'fin'      => $fin,
            'opcode'   => $opcode,
            'payload'  => $payload,
            'consumed' => $offset + $payLen,
        ];
    }

    /**
     * XOR $payload with the 4-byte $maskKey (RFC 6455 §5.3).
     *
     * Masking and unmasking are the same operation, so one helper serves both.
     */
    private static function applyMask(string $payload, string $maskKey): string
    {
        $len = strlen($payload);
        if ($len === 0) {
            return '';
        }

        // Repeat the key to the payload length and XOR in one call: a per-byte
        // loop here showed up in profiles on the 350-430 byte now-playing events
        // this codec was extracted for.
        $repeated = str_repeat($maskKey, intdiv($len, 4) + 1);

        return $payload ^ substr($repeated, 0, $len);
    }
}
