<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Auth\PusherAuthorizer;
use Pramnos\Broadcasting\LocalBroadcastServer;

/**
 * Server-level enforcement of the ConnectionAuthorizer inside LocalBroadcastServer.
 *
 * Uses the same stream_socket_pair + reflection harness as the main server test:
 * inject a client, feed bytes, invoke readClient(), then inspect the server's
 * client/subscription state and the bytes written back to the client. Verifies a
 * wrong app key is rejected at handshake, and private-channel subscriptions are
 * gated by a valid Pusher signature.
 */
#[CoversClass(LocalBroadcastServer::class)]
class LocalBroadcastServerAuthTest extends TestCase
{
    private function maskedFrame(string $payload): string
    {
        $frame = chr(0x81);
        $len   = strlen($payload);
        if ($len <= 125) {
            $frame .= chr(0x80 | $len);
        } else {
            // 16-bit extended length (RFC 6455): required once payload > 125 bytes,
            // e.g. a subscribe carrying a 64-hex-char HMAC auth signature.
            $frame .= chr(0x80 | 126) . pack('n', $len);
        }
        $mask  = "\x01\x02\x03\x04";
        $frame .= $mask;
        for ($i = 0; $i < $len; $i++) {
            $frame .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }
        return $frame;
    }

    public function testHandshakeRejectsWrongAppKey(): void
    {
        $server = new LocalBroadcastServer('right-key', null, new PusherAuthorizer('right-key', 'shh'));

        $sockets      = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientSocket = $sockets[0];
        $serverSocket = $sockets[1];

        $refClients = new \ReflectionProperty($server, 'clients');
        $refClients->setValue($server, [
            1 => ['socket' => $serverSocket, 'state' => 'handshaking', 'buffer' => '', 'channels' => [], 'socketId' => '1.2', 'pingAt' => time() + 30],
        ]);

        fwrite($clientSocket, "GET /app/WRONG-key?protocol=7 HTTP/1.1\r\n"
            . "Host: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");

        (new \ReflectionMethod($server, 'readClient'))->invoke($server, $serverSocket);

        $clients  = $refClients->getValue($server);
        $this->assertArrayNotHasKey(1, $clients, 'unauthorized client must be disconnected');

        $response = fread($clientSocket, 8192);
        $this->assertStringContainsString('401', $response);
        $this->assertStringNotContainsString('101 Switching Protocols', $response);

        fclose($clientSocket);
    }

    public function testPrivateSubscribeRejectedWithoutValidSignature(): void
    {
        $server = new LocalBroadcastServer('key', null, new PusherAuthorizer('key', 'shh'));

        $sockets      = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientSocket = $sockets[0];
        $serverSocket = $sockets[1];

        $refClients = new \ReflectionProperty($server, 'clients');
        $refClients->setValue($server, [
            1 => ['socket' => $serverSocket, 'state' => 'connected', 'buffer' => '', 'channels' => [], 'socketId' => '1.2', 'pingAt' => time() + 30],
        ]);

        $payload = json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => 'private-room', 'auth' => 'key:bad']]);
        fwrite($clientSocket, $this->maskedFrame($payload));

        (new \ReflectionMethod($server, 'readClient'))->invoke($server, $serverSocket);

        $clients = $refClients->getValue($server);
        $this->assertNotContains('private-room', $clients[1]['channels']);

        $refSubs = new \ReflectionProperty($server, 'subscriptions');
        $this->assertArrayNotHasKey('private-room', $refSubs->getValue($server));

        $this->assertStringContainsString('subscription_error', fread($clientSocket, 8192));

        fclose($clientSocket);
    }

    public function testPrivateSubscribeAcceptedWithValidSignature(): void
    {
        $server = new LocalBroadcastServer('key', null, new PusherAuthorizer('key', 'shh'));

        $sockets      = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientSocket = $sockets[0];
        $serverSocket = $sockets[1];

        $refClients = new \ReflectionProperty($server, 'clients');
        $refClients->setValue($server, [
            1 => ['socket' => $serverSocket, 'state' => 'connected', 'buffer' => '', 'channels' => [], 'socketId' => '1.2', 'pingAt' => time() + 30],
        ]);

        $auth    = 'key:' . hash_hmac('sha256', '1.2:private-room', 'shh');
        $payload = json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => 'private-room', 'auth' => $auth]]);
        fwrite($clientSocket, $this->maskedFrame($payload));

        (new \ReflectionMethod($server, 'readClient'))->invoke($server, $serverSocket);

        $clients = $refClients->getValue($server);
        $this->assertContains('private-room', $clients[1]['channels']);
        $this->assertStringContainsString('subscription_succeeded', fread($clientSocket, 8192));

        fclose($clientSocket);
    }
}
