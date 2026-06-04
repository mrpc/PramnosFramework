<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\LocalBroadcastServer;

#[CoversClass(LocalBroadcastServer::class)]
class LocalBroadcastServerTest extends TestCase
{
    private string $tempLogFile;

    protected function setUp(): void
    {
        $this->tempLogFile = sys_get_temp_dir() . '/local_broadcast_test_' . uniqid() . '.log';
        file_put_contents($this->tempLogFile, '');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempLogFile)) {
            unlink($this->tempLogFile);
        }
    }

    public function testConstructorAndProperties(): void
    {
        $server = new LocalBroadcastServer('my-app-key', $this->tempLogFile);
        
        $ref = new \ReflectionProperty($server, 'appKey');
        $this->assertSame('my-app-key', $ref->getValue($server));

        $refLog = new \ReflectionProperty($server, 'logFile');
        $this->assertSame($this->tempLogFile, $refLog->getValue($server));
    }

    public function testRegisterTickCallback(): void
    {
        $server = new LocalBroadcastServer();
        $called = false;
        $server->onTick(function() use (&$called) {
            $called = true;
        });

        $ref = new \ReflectionProperty($server, 'tickCallback');
        $cb = $ref->getValue($server);
        $this->assertNotNull($cb);
        $cb(0, 0);
        $this->assertTrue($called);
    }

    public function testProcessHandshakeAndConnection(): void
    {
        $server = new LocalBroadcastServer('test-key');

        // Create stream socket pair
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientSocket = $sockets[0];
        $serverSocket = $sockets[1];

        // Setup client state inside server
        $refClients = new \ReflectionProperty($server, 'clients');
        $clients = [
            1 => [
                'socket'   => $serverSocket,
                'state'    => 'handshaking',
                'buffer'   => '',
                'channels' => [],
                'socketId' => '1.2',
                'pingAt'   => time() + 30,
            ]
        ];
        $refClients->setValue($server, $clients);

        // Write handshake headers to clientSocket
        $handshakeReq = "GET /app/test-key?protocol=7 HTTP/1.1\r\n"
            . "Host: localhost:6001\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n";
        fwrite($clientSocket, $handshakeReq);

        // Read client on server side
        $methodReadClient = new \ReflectionMethod($server, 'readClient');
        $methodReadClient->invoke($server, $serverSocket);

        // Verify state transitioned to connected
        $clientsState = $refClients->getValue($server);
        $this->assertSame('connected', $clientsState[1]['state']);

        // Read client response
        $response = fread($clientSocket, 8192);
        $this->assertStringContainsString('101 Switching Protocols', $response);
        $this->assertStringContainsString('pusher:connection_established', $response);

        // Clean up sockets
        fclose($clientSocket);
        fclose($serverSocket);
    }

    public function testWebSocketFramingAndSubscriptions(): void
    {
        $server = new LocalBroadcastServer('test-key');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientSocket = $sockets[0];
        $serverSocket = $sockets[1];

        // Setup client state
        $refClients = new \ReflectionProperty($server, 'clients');
        $clients = [
            1 => [
                'socket'   => $serverSocket,
                'state'    => 'connected',
                'buffer'   => '',
                'channels' => [],
                'socketId' => '1.2',
                'pingAt'   => time() + 30,
            ]
        ];
        $refClients->setValue($server, $clients);

        // Send a subscribe message frame
        // Pusher subscribe payload
        $payload = json_encode([
            'event' => 'pusher:subscribe',
            'data'  => [
                'channel' => 'my-channel'
            ]
        ]);

        // Frame the payload (unmasked for simplicity of test, but RFC6455 requires client frames to be masked)
        // Let's create a masked frame to test masking logic too!
        $frame = chr(0x81); // Fin=1, Opcode=1 (Text)
        $len = strlen($payload);
        $frame .= chr(0x80 | $len); // Mask=1, length
        $mask = "\x01\x02\x03\x04";
        $frame .= $mask;
        
        $maskedPayload = '';
        for ($i = 0; $i < $len; $i++) {
            $maskedPayload .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }
        $frame .= $maskedPayload;

        fwrite($clientSocket, $frame);

        // Read client on server side
        $methodReadClient = new \ReflectionMethod($server, 'readClient');
        $methodReadClient->invoke($server, $serverSocket);

        // Verify subscription succeeded
        $clientsState = $refClients->getValue($server);
        $this->assertContains('my-channel', $clientsState[1]['channels']);

        $refSubs = new \ReflectionProperty($server, 'subscriptions');
        $this->assertArrayHasKey('my-channel', $refSubs->getValue($server));

        // Test broadcast
        $server->broadcast('my-channel', 'test-event', ['some' => 'data']);

        // Read broadcast frame on client side
        $clientData = fread($clientSocket, 8192);
        $this->assertStringContainsString('test-event', $clientData);
        $this->assertStringContainsString('my-channel', $clientData);

        // Clean up sockets
        fclose($clientSocket);
        fclose($serverSocket);
    }

    public function testPollLogFile(): void
    {
        $server = new LocalBroadcastServer('test-key', $this->tempLogFile);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientSocket = $sockets[0];
        $serverSocket = $sockets[1];

        // Setup client state subscribed to "my-channel"
        $refClients = new \ReflectionProperty($server, 'clients');
        $refClients->setValue($server, [
            1 => [
                'socket'   => $serverSocket,
                'state'    => 'connected',
                'buffer'   => '',
                'channels' => ['my-channel'],
                'socketId' => '1.2',
                'pingAt'   => time() + 30,
            ]
        ]);

        $refSubs = new \ReflectionProperty($server, 'subscriptions');
        $refSubs->setValue($server, [
            'my-channel' => [1 => 1]
        ]);

        // Append a line to the log file
        $logData = json_encode([
            'channel' => 'my-channel',
            'event' => 'LogEvent',
            'payload' => ['hello' => 'world']
        ]) . "\n";
        file_put_contents($this->tempLogFile, $logData);

        // Poll log file
        $methodPoll = new \ReflectionMethod($server, 'pollLogFile');
        $methodPoll->invoke($server);

        // Check if client received the broadcasted event
        $clientData = fread($clientSocket, 8192);
        $this->assertStringContainsString('LogEvent', $clientData);
        $this->assertStringContainsString('hello', $clientData);

        // Test file rotation / shrinkage
        file_put_contents($this->tempLogFile, ''); // Empty file
        $methodPoll->invoke($server); // Should not warning/crash

        fclose($clientSocket);
        fclose($serverSocket);
    }

    public function testShutdownAndStop(): void
    {
        $server = new LocalBroadcastServer();
        $server->stop();
        
        $refRunning = new \ReflectionProperty($server, 'running');
        $this->assertFalse($refRunning->getValue($server));

        // Call shutdown via Reflection
        $methodShutdown = new \ReflectionMethod($server, 'shutdown');
        $methodShutdown->invoke($server);
        
        // Assert server socket is cleared
        $refSock = new \ReflectionProperty($server, 'serverSocket');
        $this->assertNull($refSock->getValue($server));
    }

    public function testRunServerLoop(): void
    {
        $server = new LocalBroadcastServer('test-key');
        
        // Use onTick callback to stop the loop immediately on the first iteration
        $server->onTick(function () use ($server) {
            $server->stop();
        });

        // Run the server on a high port (e.g., random high port 26001)
        // This will bind, do one loop iteration (which calls pollLogFile, sendKeepalives etc.),
        // run the tick callback, stop, and shutdown cleanly.
        $server->run('127.0.0.1', 26001);

        $refRunning = new \ReflectionProperty($server, 'running');
        $this->assertFalse($refRunning->getValue($server));
    }

    public function testLargePayloadBroadcasts(): void
    {
        $server = new LocalBroadcastServer('test-key');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientSocket = $sockets[0];
        $serverSocket = $sockets[1];

        $refClients = new \ReflectionProperty($server, 'clients');
        $refClients->setValue($server, [
            1 => [
                'socket'   => $serverSocket,
                'state'    => 'connected',
                'buffer'   => '',
                'channels' => ['my-channel'],
                'socketId' => '1.2',
                'pingAt'   => time() + 30,
            ]
        ]);

        $refSubs = new \ReflectionProperty($server, 'subscriptions');
        $refSubs->setValue($server, [
            'my-channel' => [1 => 1]
        ]);

        // 1. Medium payload (between 126 and 65535 bytes)
        $mediumPayload = str_repeat('a', 500);
        $server->broadcast('my-channel', 'medium', $mediumPayload);
        $data1 = fread($clientSocket, 8192);
        $this->assertStringContainsString('medium', $data1);

        // 2. Large payload (>= 65536 bytes)
        $largePayload = str_repeat('b', 70000);
        $server->broadcast('my-channel', 'large', $largePayload);
        // Read loop to fetch all bytes of large frame
        $data2 = '';
        while (strlen($data2) < 70000) {
            $chunk = fread($clientSocket, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data2 .= $chunk;
        }
        $this->assertStringContainsString('large', $data2);

        fclose($clientSocket);
        fclose($serverSocket);
    }

    public function testPingAndUnsubscribe(): void
    {
        $server = new LocalBroadcastServer('test-key');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientSocket = $sockets[0];
        $serverSocket = $sockets[1];

        $refClients = new \ReflectionProperty($server, 'clients');
        $refClients->setValue($server, [
            1 => [
                'socket'   => $serverSocket,
                'state'    => 'connected',
                'buffer'   => '',
                'channels' => ['my-channel'],
                'socketId' => '1.2',
                'pingAt'   => time() + 30,
            ]
        ]);
        $refSubs = new \ReflectionProperty($server, 'subscriptions');
        $refSubs->setValue($server, [
            'my-channel' => [1 => 1]
        ]);

        // 1. Send ping (using dynamic length mapping)
        $pingMsg = json_encode(['event' => 'pusher:ping', 'data' => []]);
        $pingFrame = chr(0x81) . chr(0x80 | strlen($pingMsg)) . "\x01\x02\x03\x04";
        $maskedPing = '';
        for ($i = 0; $i < strlen($pingMsg); $i++) {
            $maskedPing .= chr(ord($pingMsg[$i]) ^ ord("\x01\x02\x03\x04"[$i % 4]));
        }
        fwrite($clientSocket, $pingFrame . $maskedPing);

        $methodReadClient = new \ReflectionMethod($server, 'readClient');
        $methodReadClient->invoke($server, $serverSocket);

        $res1 = fread($clientSocket, 8192);
        $this->assertStringContainsString('pusher:pong', $res1);

        // 2. Send unsubscribe
        $unsubMsg = json_encode(['event' => 'pusher:unsubscribe', 'data' => ['channel' => 'my-channel']]);
        $unsubFrame = chr(0x81) . chr(0x80 | strlen($unsubMsg)) . "\x01\x02\x03\x04";
        $maskedUnsub = '';
        for ($i = 0; $i < strlen($unsubMsg); $i++) {
            $maskedUnsub .= chr(ord($unsubMsg[$i]) ^ ord("\x01\x02\x03\x04"[$i % 4]));
        }
        fwrite($clientSocket, $unsubFrame . $maskedUnsub);
        $methodReadClient->invoke($server, $serverSocket);

        // Verify unsubscribed
        $this->assertEmpty($refSubs->getValue($server)['my-channel'] ?? []);

        fclose($clientSocket);
        fclose($serverSocket);
    }

    public function testSendKeepalives(): void
    {
        $server = new LocalBroadcastServer('test-key');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientSocket = $sockets[0];
        $serverSocket = $sockets[1];

        $refClients = new \ReflectionProperty($server, 'clients');
        $refClients->setValue($server, [
            1 => [
                'socket'   => $serverSocket,
                'state'    => 'connected',
                'buffer'   => '',
                'channels' => [],
                'socketId' => '1.2',
                'pingAt'   => time() - 10, // expired!
            ]
        ]);

        // Call protected sendKeepalives
        $method = new \ReflectionMethod($server, 'sendKeepalives');
        $method->invoke($server);

        // Client should receive ping
        $res = fread($clientSocket, 8192);
        $this->assertStringContainsString('pusher:ping', $res);

        fclose($clientSocket);
        fclose($serverSocket);
    }

    public function testBindErrorThrowsRuntimeException(): void
    {
        $server1 = new LocalBroadcastServer('test-key');
        // Start server 1 on random port
        $server1Socket = stream_socket_server("tcp://127.0.0.1:26002", $errno, $errstr);
        $this->assertNotFalse($server1Socket);

        $server2 = new LocalBroadcastServer('test-key');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot bind on 127.0.0.1:26002');
        $server2->run('127.0.0.1', 26002);

        fclose($server1Socket);
    }

    public function testRunInitWithLogFileExists(): void
    {
        $tempFile = $this->tempLogFile;
        file_put_contents($tempFile, "hello\nworld\n");

        $server = new LocalBroadcastServer('test-key', $tempFile);
        $server->onTick(function () use ($server) {
            $server->stop();
        });

        // Run server and stop immediately
        $server->run('127.0.0.1', 26003);

        $refOffset = new \ReflectionProperty($server, 'logOffset');
        $this->assertSame(12, $refOffset->getValue($server)); // "hello\nworld\n" is 12 bytes
    }

    public function testAcceptClientFailsReturns(): void
    {
        $server = new LocalBroadcastServer('test-key');
        // Set serverSocket to null or invalid resource
        $refSock = new \ReflectionProperty($server, 'serverSocket');
        // Let's create a temporary file pointer as a dummy resource that is NOT a server socket,
        // so stream_socket_accept fails on it.
        $dummy = fopen('php://temp', 'r');
        $refSock->setValue($server, $dummy);

        $methodAccept = new \ReflectionMethod($server, 'acceptClient');
        $methodAccept->invoke($server);

        $refClients = new \ReflectionProperty($server, 'clients');
        $this->assertEmpty($refClients->getValue($server));

        fclose($dummy);
    }

    public function testInvalidHandshakeReturns400(): void
    {
        $server = new LocalBroadcastServer('test-key');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientSocket = $sockets[0];
        $serverSocket = $sockets[1];

        $refClients = new \ReflectionProperty($server, 'clients');
        $refClients->setValue($server, [
            1 => [
                'socket'   => $serverSocket,
                'state'    => 'handshaking',
                'buffer'   => '',
                'channels' => [],
                'socketId' => '1.2',
                'pingAt'   => time() + 30,
            ]
        ]);

        // Send bad handshake req (missing Upgrade headers)
        $handshakeReq = "GET /app/test-key?protocol=7 HTTP/1.1\r\nHost: localhost:6001\r\n\r\n";
        fwrite($clientSocket, $handshakeReq);

        $methodReadClient = new \ReflectionMethod($server, 'readClient');
        $methodReadClient->invoke($server, $serverSocket);

        // Client should receive 400 Bad Request
        $response = fread($clientSocket, 8192);
        $this->assertStringContainsString('400 Bad Request', $response);

        // Client should be disconnected
        $this->assertEmpty($refClients->getValue($server));

        fclose($clientSocket);
    }

    public function testHandleInvalidTextMessageAndInvalidSubscribe(): void
    {
        $server = new LocalBroadcastServer('test-key');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientSocket = $sockets[0];
        $serverSocket = $sockets[1];

        $refClients = new \ReflectionProperty($server, 'clients');
        $refClients->setValue($server, [
            1 => [
                'socket'   => $serverSocket,
                'state'    => 'connected',
                'buffer'   => '',
                'channels' => [],
                'socketId' => '1.2',
                'pingAt'   => time() + 30,
            ]
        ]);

        $methodReadClient = new \ReflectionMethod($server, 'readClient');

        // 1. Invalid JSON
        $msg = "invalid json";
        $frame = chr(0x81) . chr(0x80 | strlen($msg)) . "\x01\x02\x03\x04";
        $masked = '';
        for ($i = 0; $i < strlen($msg); $i++) {
            $masked .= chr(ord($msg[$i]) ^ ord("\x01\x02\x03\x04"[$i % 4]));
        }
        fwrite($clientSocket, $frame . $masked);
        $methodReadClient->invoke($server, $serverSocket); // Should not crash

        // 2. Empty channel in subscribe
        $msg2 = json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => '']]);
        $frame2 = chr(0x81) . chr(0x80 | strlen($msg2)) . "\x01\x02\x03\x04";
        $masked2 = '';
        for ($i = 0; $i < strlen($msg2); $i++) {
            $masked2 .= chr(ord($msg2[$i]) ^ ord("\x01\x02\x03\x04"[$i % 4]));
        }
        fwrite($clientSocket, $frame2 . $masked2);
        $methodReadClient->invoke($server, $serverSocket); // Should ignore

        // Check clients state
        $clientsState = $refClients->getValue($server);
        $this->assertEmpty($clientsState[1]['channels']);

        fclose($clientSocket);
        fclose($serverSocket);
    }

    public function testWebSocketOpcodesCloseAndPing(): void
    {
        $server = new LocalBroadcastServer('test-key');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientSocket = $sockets[0];
        $serverSocket = $sockets[1];

        $refClients = new \ReflectionProperty($server, 'clients');
        $refClients->setValue($server, [
            1 => [
                'socket'   => $serverSocket,
                'state'    => 'connected',
                'buffer'   => '',
                'channels' => [],
                'socketId' => '1.2',
                'pingAt'   => time() + 30,
            ]
        ]);

        $methodReadClient = new \ReflectionMethod($server, 'readClient');

        // 1. WebSocket Ping opcode (0x9)
        // Opcode 9, mask bit set, len 4, mask key. Mask payload "ping"
        $mask = "\x01\x02\x03\x04";
        $payload = "ping";
        $maskedPing = '';
        for ($i = 0; $i < 4; $i++) {
            $maskedPing .= chr(ord($payload[$i]) ^ ord($mask[$i]));
        }
        $pingFrame = chr(0x89) . chr(0x84) . $mask . $maskedPing;
        fwrite($clientSocket, $pingFrame);
        $methodReadClient->invoke($server, $serverSocket);

        // Read response (pong opcode 0xA, masked/unmasked send is unmasked from server chr(0x80 | 0xA) = chr(0x8A))
        $res = fread($clientSocket, 8192);
        $this->assertSame(chr(0x8A) . chr(4) . "ping", substr($res, 0, 6));

        // 2. WebSocket Close opcode (0x8)
        $closeFrame = chr(0x88) . chr(0x80) . "\x01\x02\x03\x04";
        fwrite($clientSocket, $closeFrame);
        $methodReadClient->invoke($server, $serverSocket);

        // Verify client disconnected
        $this->assertEmpty($refClients->getValue($server));

        fclose($clientSocket);
    }

    public function testSendKeepalivesSkipsNonConnected(): void
    {
        $server = new LocalBroadcastServer('test-key');
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $clientSocket = $sockets[0];
        $serverSocket = $sockets[1];

        $refClients = new \ReflectionProperty($server, 'clients');
        $refClients->setValue($server, [
            1 => [
                'socket'   => $serverSocket,
                'state'    => 'handshaking', // not connected
                'buffer'   => '',
                'channels' => [],
                'socketId' => '1.2',
                'pingAt'   => time() - 10,
            ]
        ]);

        $method = new \ReflectionMethod($server, 'sendKeepalives');
        $method->invoke($server);

        // Set client socket to non-blocking so fread returns immediately if empty
        stream_set_blocking($clientSocket, false);
        $res = fread($clientSocket, 8192);
        $this->assertSame('', $res); // No ping sent

        fclose($clientSocket);
        fclose($serverSocket);
    }
}
