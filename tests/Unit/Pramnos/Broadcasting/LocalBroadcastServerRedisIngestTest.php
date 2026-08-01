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

    /**
     * An ingest router re-routes one incoming message to per-recipient channels,
     * so a direct message reaches only the intended recipient's channel and never
     * a bystander's — the mechanism that keeps DMs private over a shared socket.
     */
    public function testIngestRouterFansOutOnlyToRoutedChannels(): void
    {
        $redisPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $bobPair   = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $carolPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        foreach ([$redisPair[0], $redisPair[1], $bobPair[0], $bobPair[1], $carolPair[0], $carolPair[1]] as $s) {
            stream_set_blocking($s, false);
        }

        $ingest = new RedisSubscriberSocket(['host' => 'x'], ['chat:private_messages'], fn () => $redisPair[0]);
        $ingest->connect();

        $server = new LocalBroadcastServer();
        $server->useRedisIngest($ingest);
        // Route a DM only to its sender's + recipient's private channels.
        $server->useIngestRouter(function (string $channel, string $event, $payload): array {
            if ($channel !== 'chat:private_messages') {
                return [[$channel, $event, $payload]];
            }
            return [
                ['private-pm-' . $payload['to_username'],   'private', $payload],
                ['private-pm-' . $payload['from_username'], 'private', $payload],
            ];
        });

        // Bob is the recipient; Carol is an unrelated bystander.
        $refClients = new \ReflectionProperty($server, 'clients');
        $refClients->setValue($server, [
            1 => ['socket' => $bobPair[1],   'state' => 'connected', 'buffer' => '', 'channels' => ['private-pm-bob'],   'socketId' => '1.2', 'pingAt' => time() + 30],
            2 => ['socket' => $carolPair[1], 'state' => 'connected', 'buffer' => '', 'channels' => ['private-pm-carol'], 'socketId' => '2.3', 'pingAt' => time() + 30],
        ]);
        $refSubs = new \ReflectionProperty($server, 'subscriptions');
        $refSubs->setValue($server, [
            'private-pm-bob'   => [1 => 1],
            'private-pm-carol' => [2 => 2],
        ]);

        $dm = json_encode(['from_username' => 'alice', 'to_username' => 'bob', 'body' => 'secret']);
        fwrite($redisPair[1], $this->resp(['message', 'chat:private_messages', $dm]));

        (new \ReflectionMethod($server, 'drainRedisIngest'))->invoke($server);

        $bobFrame   = (string) fread($bobPair[0], 8192);
        $carolFrame = (string) fread($carolPair[0], 8192);

        $this->assertStringContainsString('secret', $bobFrame, 'recipient must receive the DM');
        $this->assertStringContainsString('private-pm-bob', $bobFrame);
        $this->assertSame('', $carolFrame, 'a bystander must receive nothing');

        foreach ([$redisPair[0], $redisPair[1], $bobPair[0], $bobPair[1], $carolPair[0], $carolPair[1]] as $s) {
            @fclose($s);
        }
    }
}
