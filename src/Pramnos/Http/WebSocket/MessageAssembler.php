<?php

declare(strict_types=1);

namespace Pramnos\Http\WebSocket;

/**
 * Turns a byte stream into complete WebSocket messages.
 *
 * {@see FrameCodec} decodes one frame; this holds the state a stream needs on top
 * of that — a partially-arrived frame, and a data message spread over several
 * frames. Callers feed it whatever `fread()` returned and get back only whole
 * messages, so no caller reassembles frames itself.
 *
 * **Fragmentation is not hypothetical, and ignoring it fails quietly.** A sender
 * may split any data message across a first frame (text/binary, FIN clear), any
 * number of continuation frames (opcode 0x0) and a final one with FIN set. A
 * reader that looks only at the opcode — as this framework's WebSocket server did
 * before this class existed — hands the application each fragment as if it were a
 * whole message. For JSON that means a decode failure on a payload that was
 * perfectly valid, and it appears only against senders that fragment, which is a
 * property of the peer and the payload size rather than of anything local.
 *
 * Control frames (ping/pong/close) may be interleaved *between* the fragments of
 * a data message, so they are passed straight through in arrival order rather
 * than queued behind the message being assembled — reordering them would break
 * the keepalive they exist for.
 */
final class MessageAssembler
{
    /** Bytes received but not yet forming a complete frame. */
    private string $buffer = '';

    /** Payload accumulated for the data message currently being assembled. */
    private string $partial = '';

    /** Opcode of the message being assembled, or null when none is in progress. */
    private ?int $partialOpcode = null;

    /**
     * @param int $maxPayload Per-frame ceiling, passed to the codec.
     * @param int $maxMessage Ceiling for a reassembled message. Fragmentation
     *                        otherwise reopens the exhaustion hole the per-frame
     *                        limit closes: unlimited 1 MiB fragments that never
     *                        set FIN grow one buffer without any single frame
     *                        ever looking suspicious.
     */
    public function __construct(
        private readonly int $maxPayload = FrameCodec::DEFAULT_MAX_PAYLOAD,
        private readonly int $maxMessage = FrameCodec::DEFAULT_MAX_PAYLOAD,
    ) {
    }

    /**
     * Add received bytes and return every message they completed.
     *
     * @param string $bytes Raw bytes from the socket ('' is valid and yields []).
     * @return list<array{opcode:int, payload:string}> Complete messages and
     *         control frames, in arrival order. Empty when nothing completed.
     * @throws WebSocketProtocolException On a malformed frame or a broken
     *         fragmentation sequence.
     */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $out = [];

        while ($this->buffer !== '') {
            $frame = FrameCodec::decode($this->buffer, $this->maxPayload);
            if ($frame === null) {
                break;                      // incomplete — wait for more bytes
            }

            $this->buffer = substr($this->buffer, $frame['consumed']);

            // Control frames are never fragmented (the codec enforces it) and
            // must not be held back behind an in-progress data message.
            if ($frame['opcode'] >= FrameCodec::CONTROL_OPCODE_MIN) {
                $out[] = ['opcode' => $frame['opcode'], 'payload' => $frame['payload']];
                continue;
            }

            if ($frame['opcode'] === FrameCodec::OP_CONTINUATION) {
                if ($this->partialOpcode === null) {
                    throw new WebSocketProtocolException(
                        'Continuation frame received with no message in progress.'
                    );
                }
                $this->appendPartial($frame['payload']);
            } else {
                if ($this->partialOpcode !== null) {
                    throw new WebSocketProtocolException(
                        'New data frame (opcode ' . $frame['opcode']
                        . ') received while a fragmented message was in progress.'
                    );
                }
                $this->partialOpcode = $frame['opcode'];
                $this->appendPartial($frame['payload']);
            }

            if ($frame['fin']) {
                $out[] = ['opcode' => $this->partialOpcode, 'payload' => $this->partial];
                $this->partial       = '';
                $this->partialOpcode = null;
            }
        }

        return $out;
    }

    /**
     * True while a fragmented message is still being assembled — for a caller
     * deciding whether a quiet connection is idle or mid-message.
     */
    public function hasPartialMessage(): bool
    {
        return $this->partialOpcode !== null;
    }

    /**
     * Discard all buffered state (after a close, or when reconnecting).
     */
    public function reset(): void
    {
        $this->buffer        = '';
        $this->partial       = '';
        $this->partialOpcode = null;
    }

    private function appendPartial(string $payload): void
    {
        if (strlen($this->partial) + strlen($payload) > $this->maxMessage) {
            throw new WebSocketProtocolException(
                'Reassembled message exceeds the ' . $this->maxMessage . '-byte limit.'
            );
        }

        $this->partial .= $payload;
    }
}
