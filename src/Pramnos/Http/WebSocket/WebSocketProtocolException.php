<?php

declare(strict_types=1);

namespace Pramnos\Http\WebSocket;

/**
 * Thrown when a peer sends something RFC 6455 does not allow, or something this
 * implementation refuses on purpose (an oversized payload, a continuation frame
 * with nothing to continue).
 *
 * It is deliberately distinct from "the frame has not fully arrived yet", which
 * {@see FrameCodec::decode()} signals by returning null. Collapsing the two into
 * one return value is how a framing bug becomes a silent stall: a caller that
 * treats a protocol error as "wait for more data" waits forever, on a connection
 * that will never become valid.
 */
class WebSocketProtocolException extends \RuntimeException
{
}
