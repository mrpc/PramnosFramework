<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\RedisSubscriberSocket;

/**
 * Unit tests for the non-blocking RESP pub/sub subscriber used to feed the
 * WebSocket server from Redis inside its stream_select loop.
 *
 * A stream_socket_pair stands in for the Redis connection: the subscriber reads
 * one end (injected via the stream factory) and the test writes RESP bytes to
 * the other end, then asserts drain() returns the parsed pub/sub messages —
 * including when a frame is split across reads.
 */
#[CoversClass(RedisSubscriberSocket::class)]
class RedisSubscriberSocketTest extends TestCase
{
    /** @var array{0:resource,1:resource} */
    private array $pair;

    protected function setUp(): void
    {
        $this->pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($this->pair[0], false);
        stream_set_blocking($this->pair[1], false);
    }

    protected function tearDown(): void
    {
        foreach ($this->pair as $s) {
            if (is_resource($s)) {
                @fclose($s);
            }
        }
    }

    private function subscriber(array $channels = ['chat:updates']): RedisSubscriberSocket
    {
        $sub = new RedisSubscriberSocket(['host' => 'x'], $channels, fn () => $this->pair[0]);
        $sub->connect();
        return $sub;
    }

    private function resp(array $items): string
    {
        $s = '*' . count($items) . "\r\n";
        foreach ($items as $it) {
            $s .= '$' . strlen($it) . "\r\n" . $it . "\r\n";
        }
        return $s;
    }

    /**
     * A subscribe confirmation is ignored; a following message is parsed to
     * {channel, message}.
     */
    public function testParsesMessageAndIgnoresSubscribeConfirmation(): void
    {
        $sub = $this->subscriber();

        fwrite($this->pair[1], $this->resp(['subscribe', 'chat:updates', '1']));
        fwrite($this->pair[1], $this->resp(['message', 'chat:updates', '{"a":1}']));

        $messages = $sub->drain();

        $this->assertSame([['channel' => 'chat:updates', 'message' => '{"a":1}']], $messages);
    }

    /**
     * A frame split across two reads is buffered and parsed once complete.
     */
    public function testHandlesFrameSplitAcrossReads(): void
    {
        $sub   = $this->subscriber();
        $frame = $this->resp(['message', 'chat:updates', '{"body":"hello world"}']);
        $split = intdiv(strlen($frame), 2);

        fwrite($this->pair[1], substr($frame, 0, $split));
        $this->assertSame([], $sub->drain(), 'incomplete frame yields nothing yet');

        fwrite($this->pair[1], substr($frame, $split));
        $this->assertSame(
            [['channel' => 'chat:updates', 'message' => '{"body":"hello world"}']],
            $sub->drain()
        );
    }

    /**
     * Pattern deliveries (pmessage) surface their concrete channel + payload.
     */
    public function testParsesPatternMessage(): void
    {
        $sub = $this->subscriber(['chat:*']);

        fwrite($this->pair[1], $this->resp(['pmessage', 'chat:*', 'chat:private', '{"x":true}']));

        $this->assertSame(
            [['channel' => 'chat:private', 'message' => '{"x":true}']],
            $sub->drain()
        );
    }

    /**
     * Two messages arriving in one read are both returned in order.
     */
    public function testParsesMultipleMessagesInOneRead(): void
    {
        $sub = $this->subscriber();

        fwrite($this->pair[1], $this->resp(['message', 'chat:updates', 'one']) . $this->resp(['message', 'chat:updates', 'two']));

        $messages = $sub->drain();
        $this->assertSame(['one', 'two'], array_column($messages, 'message'));
    }
}
