<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\RedisStreamSocket;

/**
 * The stream ingest that lets the WebSocket server read a Redis stream.
 *
 * Its reason for existing is a silent failure: the pub/sub socket
 * (`RedisSubscriberSocket`) issues `SUBSCRIBE`, so an application publishing with
 * `RedisStreamDriver` left the daemon subscribed to a key nothing would ever
 * `PUBLISH` to — a healthy subscription that is never delivered anything. The
 * only way out was to publish every event twice.
 *
 * A `stream_socket_pair` stands in for Redis: the ingest reads one end (through
 * the stream factory) while the test writes RESP bytes to the other and asserts
 * what `drain()` returns. What matters beyond parsing:
 *
 *   - the command is `XREAD`, not `SUBSCRIBE` — the whole point;
 *   - the cursor advances, and the **next** read is issued from it, because
 *     `XREAD` is request/response and a subscription is not;
 *   - a resumed cursor is used, so a daemon restarted mid-deploy does not skip
 *     what was published while it was down;
 *   - the driver's `envelope` field is passed on unchanged, so the server's
 *     fan-out cannot tell which transport brought the event.
 */
#[CoversClass(RedisStreamSocket::class)]
class RedisStreamSocketTest extends TestCase
{
    /** @var array{0: resource, 1: resource} The stand-in for the Redis connection. */
    private array $pair;

    protected function setUp(): void
    {
        $this->pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($this->pair[0], false);
        stream_set_blocking($this->pair[1], false);
    }

    protected function tearDown(): void
    {
        foreach ($this->pair as $stream) {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
    }

    /**
     * A connected ingest over the socket pair.
     *
     * @param  array<string, string> $sinceIds
     * @param  array<string, mixed>  $config
     * @return RedisStreamSocket
     */
    private function ingest(
        array $streams = ['app:chat'],
        array $sinceIds = [],
        array $config = ['host' => 'x']
    ): RedisStreamSocket {
        $ingest = new RedisStreamSocket($config, $streams, $sinceIds, fn () => $this->pair[0]);
        $ingest->connect();
        return $ingest;
    }

    /** What the ingest has written to Redis so far. */
    private function sent(): string
    {
        return (string) @fread($this->pair[1], 65536);
    }

    /** Write bytes as Redis would. */
    private function reply(string $bytes): void
    {
        fwrite($this->pair[1], $bytes);
    }

    /**
     * Encode a value as RESP.
     *
     * @param  mixed $value
     * @return string
     */
    private function resp(mixed $value): string
    {
        if ($value === null) {
            return "*-1\r\n";
        }
        if (is_array($value)) {
            $out = '*' . count($value) . "\r\n";
            foreach ($value as $item) {
                $out .= $this->resp($item);
            }
            return $out;
        }
        $value = (string) $value;
        return '$' . strlen($value) . "\r\n" . $value . "\r\n";
    }

    /**
     * One XREAD reply carrying a single entry.
     *
     * @param  string $key
     * @param  string $id
     * @param  array<string, string> $fields
     * @return string
     */
    private function entry(string $key, string $id, array $fields): string
    {
        $flat = [];
        foreach ($fields as $name => $value) {
            $flat[] = $name;
            $flat[] = $value;
        }

        return $this->resp([[$key, [[$id, $flat]]]]);
    }

    /**
     * Connecting issues XREAD — not SUBSCRIBE — blocking indefinitely.
     *
     * `BLOCK 0` is what makes the socket quiet until there is something to say,
     * which is the property a single-threaded `stream_select()` loop needs. It is
     * also the assertion that would have failed on the pub/sub socket, and the
     * reason this class exists.
     */
    public function testConnectingIssuesABlockingXread(): void
    {
        // Arrange & Act
        $this->ingest(['app:chat', 'app:presence']);

        // Assert
        $sent = $this->sent();
        $this->assertStringContainsString('XREAD', $sent);
        $this->assertStringContainsString('BLOCK', $sent);
        $this->assertStringNotContainsString('SUBSCRIBE', $sent, 'a stream is read, not subscribed to');
        // Both keys, then one id each — the order XREAD requires
        $this->assertStringContainsString('app:chat', $sent);
        $this->assertStringContainsString('app:presence', $sent);
        $this->assertSame(2, substr_count($sent, "\$1\r\n\$\r\n"), 'each stream starts from new entries');
    }

    /**
     * A password and a database are dealt with before the read.
     *
     * Otherwise the first XREAD is answered with NOAUTH, which parses as an error
     * string, produces no entries, and leaves a daemon that looks connected and
     * receives nothing — the failure mode this whole class is a fix for.
     */
    public function testAuthAndSelectPrecedeTheRead(): void
    {
        // Arrange & Act
        $this->ingest(['app:chat'], [], ['host' => 'x', 'password' => 's3cret', 'database' => 3]);

        // Assert — in order
        $sent = $this->sent();
        $this->assertLessThan(strpos($sent, 'XREAD'), strpos($sent, 'AUTH'));
        $this->assertStringContainsString('s3cret', $sent);
        $this->assertLessThan(strpos($sent, 'XREAD'), strpos($sent, 'SELECT'));
    }

    /**
     * Database 0 is not SELECTed, because it is where a connection already is.
     */
    public function testTheDefaultDatabaseIsNotSelected(): void
    {
        // Arrange & Act
        $this->ingest(['app:chat'], [], ['host' => 'x', 'database' => 0]);

        // Assert
        $this->assertStringNotContainsString('SELECT', $this->sent());
    }

    /**
     * An entry becomes one message carrying the driver's envelope verbatim.
     *
     * `RedisStreamDriver` writes a single `envelope` field holding the same JSON a
     * pub/sub publish would have carried. Passing it through unchanged is what
     * lets the server's fan-out work identically for both transports — anything
     * else would make the ingest a second place that knows the event format.
     */
    public function testAnEntryBecomesAMessageWithItsEnvelope(): void
    {
        // Arrange
        $ingest   = $this->ingest();
        $envelope = '{"event":"message.sent","payload":{"id":7}}';
        $this->reply($this->entry('app:chat', '1700000000000-0', ['envelope' => $envelope]));

        // Act
        $messages = $ingest->drain();

        // Assert
        $this->assertSame([['channel' => 'app:chat', 'message' => $envelope]], $messages);
    }

    /**
     * `+OK` from AUTH is consumed without being mistaken for data.
     *
     * It arrives on the same socket, immediately before the reply that matters.
     */
    public function testAnAuthAcknowledgementIsNotAMessage(): void
    {
        // Arrange
        $ingest = $this->ingest(['app:chat'], [], ['host' => 'x', 'password' => 'p']);
        $this->reply("+OK\r\n" . $this->entry('app:chat', '1-0', ['envelope' => '{"event":"x"}']));

        // Act
        $messages = $ingest->drain();

        // Assert — one message, not two, and not an "OK" one
        $this->assertCount(1, $messages);
        $this->assertSame('{"event":"x"}', $messages[0]['message']);
    }

    /**
     * The cursor advances to the last id, and the next read starts there.
     *
     * This is the behaviour a subscription cannot have, and the reason a restarted
     * daemon does not have to lose events: `XREAD` is positional.
     */
    public function testTheCursorAdvancesAndTheNextReadResumesFromIt(): void
    {
        // Arrange
        $ingest = $this->ingest();
        $this->sent();  // discard the first XREAD
        $this->reply($this->entry('app:chat', '1700000000000-4', ['envelope' => '{"event":"x"}']));

        // Act
        $ingest->drain();

        // Assert — the cursor is readable...
        $this->assertSame(['app:chat' => '1700000000000-4'], $ingest->cursors());
        // ...and the next read asks for what follows it, rather than for `$`
        $next = $this->sent();
        $this->assertStringContainsString('XREAD', $next);
        $this->assertStringContainsString('1700000000000-4', $next);
        $this->assertStringNotContainsString("\$1\r\n\$\r\n", $next);
    }

    /**
     * A cursor handed in at construction is where reading starts.
     *
     * The deploy case: a worker restarted at 10:00:03 with the id it had handled
     * at 10:00:01 is delivered the two seconds in between. With `SUBSCRIBE` those
     * events are simply gone.
     */
    public function testAResumedCursorIsUsedForTheFirstRead(): void
    {
        // Arrange & Act
        $this->ingest(['app:chat'], ['app:chat' => '1699999999999-0']);

        // Assert
        $sent = $this->sent();
        $this->assertStringContainsString('1699999999999-0', $sent);
        $this->assertStringNotContainsString("\$1\r\n\$\r\n", $sent, 'not "new entries only"');
    }

    /**
     * Several entries across several streams all come back, in order.
     */
    public function testEveryEntryOfEveryStreamIsReturned(): void
    {
        // Arrange
        $ingest = $this->ingest(['app:chat', 'app:presence']);
        $this->reply($this->resp([
            ['app:chat', [
                ['1-0', ['envelope', '{"event":"a"}']],
                ['2-0', ['envelope', '{"event":"b"}']],
            ]],
            ['app:presence', [
                ['3-0', ['envelope', '{"event":"c"}']],
            ]],
        ]));

        // Act
        $messages = $ingest->drain();

        // Assert
        $this->assertCount(3, $messages);
        $this->assertSame('app:presence', $messages[2]['channel']);
        // Each stream's own cursor moved to its own last id
        $this->assertSame(['app:chat' => '2-0', 'app:presence' => '3-0'], $ingest->cursors());
    }

    /**
     * A reply split across two reads is buffered until it is complete.
     *
     * A TCP read boundary can fall anywhere. Parsing half a reply and discarding
     * the rest would drop events under load and only under load.
     */
    public function testAReplySplitAcrossReadsIsAssembled(): void
    {
        // Arrange
        $ingest = $this->ingest();
        $bytes  = $this->entry('app:chat', '1-0', ['envelope' => '{"event":"split"}']);
        $this->reply(substr($bytes, 0, 20));

        // Act — nothing yet, and no data lost
        $this->assertSame([], $ingest->drain());
        $this->reply(substr($bytes, 20));
        $messages = $ingest->drain();

        // Assert
        $this->assertCount(1, $messages);
        $this->assertSame('{"event":"split"}', $messages[0]['message']);
    }

    /**
     * An entry written by something other than the driver is still delivered.
     *
     * Its fields become a JSON object rather than being dropped: a stream shared
     * with another producer is a reasonable thing to read, and silence would be
     * the worst possible response to it.
     */
    public function testAnEntryWithoutAnEnvelopeIsHandedOverAsJson(): void
    {
        // Arrange
        $ingest = $this->ingest();
        $this->reply($this->entry('app:chat', '1-0', ['type' => 'ping', 'by' => 'cron']));

        // Act
        $messages = $ingest->drain();

        // Assert
        $this->assertSame(['type' => 'ping', 'by' => 'cron'], json_decode($messages[0]['message'], true));
    }

    /**
     * An entry with no fields at all still moves the cursor.
     *
     * A cursor that stops moving asks for the same unusable entry for ever, which
     * is a loop rather than a dropped event.
     */
    public function testAnEmptyEntryStillAdvancesTheCursor(): void
    {
        // Arrange
        $ingest = $this->ingest();
        $this->reply($this->resp([['app:chat', [['9-0', []]]]]));

        // Act
        $messages = $ingest->drain();

        // Assert
        $this->assertSame([], $messages);
        $this->assertSame(['app:chat' => '9-0'], $ingest->cursors());
    }

    /**
     * A nil reply — what `BLOCK` returns when it times out — issues the next read.
     *
     * Without that, one timeout would end the flow of events permanently while
     * everything still looked connected.
     */
    public function testANilReplyIssuesTheNextRead(): void
    {
        // Arrange
        $ingest = $this->ingest();
        $this->sent();
        $this->reply("*-1\r\n");

        // Act
        $messages = $ingest->drain();

        // Assert
        $this->assertSame([], $messages);
        $this->assertStringContainsString('XREAD', $this->sent());
    }

    /**
     * Draining before connecting is harmless.
     *
     * The server calls `drain()` on a timer as well as on readability, and a
     * closed or not-yet-open ingest must not be a fatal error inside the loop.
     */
    public function testDrainingWithoutASocketReturnsNothing(): void
    {
        // Arrange
        $ingest = new RedisStreamSocket(['host' => 'x'], ['app:chat'], [], fn () => $this->pair[0]);

        // Act & Assert
        $this->assertSame([], $ingest->drain());
        $this->assertNull($ingest->getStream());
    }

    /**
     * Closing releases the socket but keeps the position.
     *
     * A caller that closes in order to reconnect wants to carry on where it was —
     * that is the entire advantage of reading a stream rather than subscribing.
     */
    public function testClosingKeepsTheCursorsAndDropsTheStream(): void
    {
        // Arrange
        $ingest = $this->ingest();
        $this->reply($this->entry('app:chat', '5-0', ['envelope' => '{"event":"x"}']));
        $ingest->drain();

        // Act
        $ingest->close();

        // Assert
        $this->assertNull($ingest->getStream());
        $this->assertSame(['app:chat' => '5-0'], $ingest->cursors());
    }

    /**
     * A factory that hands back something that is not a stream fails loudly.
     *
     * The opposite of this class's own bug report: a daemon that cannot reach
     * Redis must say so at startup rather than run with no events.
     */
    public function testAFailedConnectionThrows(): void
    {
        // Arrange
        $ingest = new RedisStreamSocket(['host' => 'x'], ['app:chat'], [], fn () => null);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not open a stream');
        $ingest->connect();
    }

    /**
     * With no streams to read, no command is issued.
     *
     * An `XREAD ... STREAMS` with no keys is a syntax error, and an ingest
     * configured with nothing to read is a configuration mistake that should not
     * also produce a protocol one.
     */
    public function testAnIngestWithNoStreamsIssuesNothing(): void
    {
        // Arrange & Act
        $this->ingest([]);

        // Assert
        $this->assertStringNotContainsString('XREAD', $this->sent());
    }

    /**
     * A reply whose shape is not what XREAD documents is skipped, not fatal.
     *
     * Redis will not send this, but a proxy, a version difference or a stream
     * shared with something exotic can — and an ingest that threw inside the
     * server's select loop would take the daemon down with it, which is a far
     * worse outcome than ignoring one malformed reply.
     */
    public function testAMalformedReplyIsSkipped(): void
    {
        // Arrange — a "stream" that is a bare string, and one whose entries are not
        // a list; then a well-formed entry behind them, which must still arrive
        $ingest = $this->ingest();
        $this->reply($this->resp([
            'not-a-stream',
            ['app:chat', 'not-a-list-of-entries'],
            ['app:chat', ['not-an-entry', ['1-0', ['envelope', '{"event":"ok"}']]]],
        ]));

        // Act
        $messages = $ingest->drain();

        // Assert — only the entry that made sense
        $this->assertCount(1, $messages);
        $this->assertSame('{"event":"ok"}', $messages[0]['message']);
    }

    /**
     * A header line that has not finished arriving is left in the buffer.
     *
     * The narrowest split there is: one byte, with no CRLF behind it yet. Parsing
     * it would consume a length prefix that is not complete.
     */
    public function testAnIncompleteHeaderLineIsHeld(): void
    {
        // Arrange — one complete reply, cut after its very first byte, so the
        // first read holds a type marker and no length behind it
        $ingest = $this->ingest();
        $bytes  = $this->entry('app:chat', '1-0', ['envelope' => '{"event":"late"}']);
        $this->reply(substr($bytes, 0, 1));

        // Act & Assert — nothing yet, and nothing lost
        $this->assertSame([], $ingest->drain());
        $this->reply(substr($bytes, 1));
        $messages = $ingest->drain();
        $this->assertCount(1, $messages);
        $this->assertSame('{"event":"late"}', $messages[0]['message']);
    }

    /**
     * The RESP types Redis can answer with, and none of them are entries.
     *
     * An integer reply (`:1`), a null bulk (`$-1`) and a byte that starts no RESP
     * value at all: each has to be consumed so the parser reaches whatever follows
     * it, rather than stalling on a buffer it cannot make sense of. A stalled
     * parser is a daemon that stops delivering while still looking connected.
     */
    public function testNonEntryRepliesAreConsumedWithoutStalling(): void
    {
        // Arrange
        $ingest = $this->ingest();
        $this->reply(
            ":1\r\n"                                                  // integer
            . "\$-1\r\n"                                              // null bulk
            . "?garbled\r\n"                                          // not RESP at all
            . $this->entry('app:chat', '1-0', ['envelope' => '{"event":"after"}'])
        );

        // Act
        $messages = $ingest->drain();

        // Assert — the entry behind all of it still arrives
        $this->assertCount(1, $messages);
        $this->assertSame('{"event":"after"}', $messages[0]['message']);
    }

    /**
     * `COUNT` follows the configured cap.
     *
     * It bounds one reply, not the stream: the rest arrives on the next read.
     */
    public function testTheEntryCountIsConfigurable(): void
    {
        // Arrange & Act
        $this->ingest(['app:chat'], [], ['host' => 'x', 'count' => 25]);

        // Assert
        $sent = $this->sent();
        $this->assertStringContainsString('COUNT', $sent);
        $this->assertStringContainsString('25', $sent);
    }
}
