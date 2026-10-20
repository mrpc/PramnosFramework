<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Drivers\RedisDriver;
use Pramnos\Broadcasting\LocalBroadcastServer;
use Pramnos\Broadcasting\RedisSubscriberSocket;
use Pramnos\Console\Commands\BroadcastServe;
use Pramnos\Http\WebSocketClient;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A notification published on a user's channel reaches that user's open page, through a
 * `broadcast:serve` started with no options at all — the way the orchestrator starts it.
 *
 * Nobody lists users: the daemon subscribes to every user's channel as one pattern, takes the
 * Redis prefix off on delivery, and binds, keys and ingests from app.php alone. Each half of
 * that failing looks the same from a browser — a bell that only moves on its poll.
 *
 * Requires the Docker Redis container (host: redis, port 6379) and pcntl.
 */
#[CoversClass(BroadcastServe::class)]
#[CoversClass(LocalBroadcastServer::class)]
#[CoversClass(RedisSubscriberSocket::class)]
final class BroadcastServeNotificationIngestTest extends TestCase
{
    /** @var int The forked daemon's pid, 0 when none */
    private int $pid = 0;

    /** @var string A prefix of this run's own, so nothing else on the Redis is heard */
    private string $prefix = '';

    /** Skip without Redis or fork; pick this run's prefix. */
    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !extension_loaded('redis')) {
            $this->markTestSkipped('needs pcntl and the redis extension');
        }
        $probe = new \Redis();
        if (!@$probe->connect('redis', 6379, 1.0)) {
            $this->markTestSkipped('No Redis server on redis:6379.');
        }
        $probe->close();
        $this->prefix = 'bsni' . bin2hex(random_bytes(3)) . ':';
    }

    /** Stop the daemon and put the application singleton back. */
    protected function tearDown(): void
    {
        if ($this->pid > 0) {
            posix_kill($this->pid, SIGKILL);
            pcntl_waitpid($this->pid, $status);
        }
        $ref = new \ReflectionClass(\Pramnos\Application\Application::class);
        $ref->getProperty('appInstances')->setValue(null, []);
        $ref->getProperty('lastUsedApplication')->setValue(null, null);
    }

    /**
     * `notification.created` published on `private-users.42` arrives at a client subscribed to
     * `private-users.42`, with the daemon configured only from `broadcasting` in app.php.
     */
    public function testANotificationReachesTheUsersPageThroughADaemonConfiguredFromAppPhp(): void
    {
        // Arrange — a free port, a daemon on it from config only, a page subscribed
        $port   = $this->freePort();
        $config = [
            'default'   => 'redis',
            'redis'     => ['host' => 'redis', 'port' => 6379, 'prefix' => $this->prefix],
            'websocket' => ['listen_port' => $port, 'app_key' => 'itest-key'],
        ];
        $this->startDaemon($config);
        $page = $this->connectPage('ws://127.0.0.1:' . $port . '/app/itest-key?protocol=7');
        $page->send((string) json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => 'private-users.42']]));
        $this->awaitMessage($page, 'pusher_internal:subscription_succeeded');
        $this->awaitPatternSubscriber();

        // Act — what DatabaseChannel does for a new notification
        (new RedisDriver($config['redis']))->broadcast('private-users.42', 'notification.created', ['id' => 'n-1']);

        // Assert — on the channel the page knows, not the Redis key
        $message = json_decode($this->awaitMessage($page, 'notification.created'), true);
        $this->assertSame('private-users.42', $message['channel'] ?? null);
        $this->assertSame(['id' => 'n-1'], is_array($message['data'] ?? null) ? $message['data'] : json_decode((string) ($message['data'] ?? ''), true));
        $page->close();
    }

    /** A port nothing listens on, found by binding port 0 and letting it go. */
    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $port = (int) explode(':', (string) stream_socket_get_name($socket, false))[1];
        fclose($socket);

        return $port;
    }

    /**
     * Fork `broadcast:serve` with no options, under an application whose `broadcasting` is
     * $config.
     *
     * @param array<string, mixed> $config
     */
    private function startDaemon(array $config): void
    {
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');
        if ($pid === 0) {
            $app = new class($config) extends \Pramnos\Application\Application {
                /** @param array<string, mixed> $broadcasting */
                public function __construct(array $broadcasting)
                {
                    $this->_data['container'] = new \Pramnos\Application\Container();
                    $this->applicationInfo    = ['broadcasting' => $broadcasting, 'features' => ['broadcasting']];
                }
            };
            $ref = new \ReflectionClass(\Pramnos\Application\Application::class);
            $ref->getProperty('appInstances')->setValue(null, ['default' => $app]);
            $ref->getProperty('lastUsedApplication')->setValue(null, 'default');
            try {
                (new CommandTester(new BroadcastServe()))->execute([]);
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }
        $this->pid = $pid;
    }

    /** Connect a page, retrying while the daemon is still starting. */
    private function connectPage(string $url): WebSocketClient
    {
        $deadline = microtime(true) + 10;
        while (true) {
            try {
                $client = new WebSocketClient($url, [], 2.0);
                $client->connect();
                stream_set_blocking($client->stream(), false);

                return $client;
            } catch (\RuntimeException $e) {
                if (microtime(true) > $deadline) {
                    throw $e;
                }
                usleep(100_000);
            }
        }
    }

    /** Wait until the daemon's PSUBSCRIBE is on the server, so the publish has a listener. */
    private function awaitPatternSubscriber(): void
    {
        $redis = new \Redis();
        $redis->connect('redis', 6379, 1.0);
        $deadline = microtime(true) + 10;
        while ((int) $redis->rawCommand('PUBSUB', 'NUMPAT') === 0 && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $redis->close();
    }

    /** The first message naming $event, or a failure after ten seconds. */
    private function awaitMessage(WebSocketClient $client, string $event): string
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            foreach ($client->read() as $message) {
                if (str_contains($message, '"' . $event . '"')) {
                    return $message;
                }
            }
            usleep(20_000);
        }
        $this->fail('no ' . $event . ' within ten seconds');
    }
}
