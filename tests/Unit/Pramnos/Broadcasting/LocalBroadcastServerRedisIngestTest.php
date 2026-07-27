<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\LocalBroadcastServer;
use Pramnos\Broadcasting\RedisSubscriberSocket;

/**
 * Verifies that a message arriving on the Redis ingest is fanned out to WS
 * clients subscribed to that channel — the non-blocking Redis-in-select path.
 *
 * A stream_socket_pair stands in for the Redis connection; another for the WS
 * client. We inject a subscribed client, publish a RESP `message`, invoke the
 * private drainRedisIngest(), and assert the client received a matching frame.
 */
#[CoversClass(LocalBroadcastServer::class)]
class LocalBroadcastServerRedisIngestTest extends TestCase
{
    private function resp(array $items): string
    {
        $s = '*' . count($items) . "\r\n";
        foreach ($items as $it) {
            $s .= '$' . strlen($it) . "\r\n" . $it . "\r\n";
        }
        return $s;
    }

    public function testRedisMessageFansOutToSubscribedClient(): void
    {
        $redisPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $wsPair    = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        foreach ([$redisPair[0], $redisPair[1], $wsPair[0], $wsPair[1]] as $s) {
            stream_set_blocking($s, false);
        }

        $ingest = new RedisSubscriberSocket(['host' => 'x'], ['chat:updates'], fn () => $redisPair[0]);
        $ingest->connect();

        $server = new LocalBroadcastServer();
        $server->useRedisIngest($ingest);

        // Inject a connected client subscribed to chat:updates.
        $refClients = new \ReflectionProperty($server, 'clients');
        $refClients->setValue($server, [
            1 => ['socket' => $wsPair[1], 'state' => 'connected', 'buffer' => '', 'channels' => ['chat:updates'], 'socketId' => '1.2', 'pingAt' => time() + 30],
        ]);
        $refSubs = new \ReflectionProperty($server, 'subscriptions');
        $refSubs->setValue($server, ['chat:updates' => [1 => 1]]);

        // Publish an enveloped message to the Redis side.
        $envelope = json_encode(['event' => 'message.created', 'payload' => ['body' => 'hi']]);
        fwrite($redisPair[1], $this->resp(['message', 'chat:updates', $envelope]));

        // Drain the ingest → should broadcast to the WS client.
        (new \ReflectionMethod($server, 'drainRedisIngest'))->invoke($server);

        $frame = fread($wsPair[0], 8192);
        $this->assertNotEmpty($frame, 'client should have received a websocket frame');
        $this->assertStringContainsString('message.created', $frame);
        $this->assertStringContainsString('chat:updates', $frame);
        $this->assertStringContainsString('hi', $frame);

        foreach ([$redisPair[0], $redisPair[1], $wsPair[0], $wsPair[1]] as $s) {
            @fclose($s);
        }
    }
}
