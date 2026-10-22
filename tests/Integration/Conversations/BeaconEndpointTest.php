<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Pramnos\Conversations\Beacon\Config;
use Pramnos\Conversations\Beacon\EmergencyKey;
use Pramnos\Conversations\Beacon\Endpoint;
use Pramnos\Conversations\Beacon\Signature;
use Pramnos\Conversations\Beacon\Store;

/**
 * The beacon's endpoints over a live Redis — what it is while the primary is down: the watcher's
 * health and alarms, the primary's sync and the import back, and a person with an emergency key
 * reading and writing the emergency channel and their direct messages. Nothing answers without
 * its signature or its key, and the beacon's own messages are written once per outage.
 *
 * Requires a live Redis (`REDIS_HOST` or `pramnos_redis`); database 11 is used and flushed.
 */
#[CoversClass(Endpoint::class)]
#[CoversClass(Store::class)]
#[Group('redis')]
class BeaconEndpointTest extends TestCase
{
    private \Redis $redis;

    /** The team's key pair. */
    private array $pair = [];

    /** What the beacon pushed: [title, body]. */
    private array $pushed = [];

    /** A Redis of its own, and a beacon's settings. */
    protected function setUp(): void
    {
        if (!class_exists('\Redis')) {
            $this->markTestSkipped('The redis extension is not loaded.');
        }
        $this->redis = new \Redis();
        try {
            $this->redis->connect(getenv('REDIS_HOST') ?: 'pramnos_redis', 6379, 2.0);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis not reachable: ' . $e->getMessage());
        }
        $this->redis->select(11);
        $this->redis->flushDB();
        $this->pair = EmergencyKey::generate();
        Config::use(['role' => 'beacon', 'secret' => 'sync-secret', 'watcher_secret' => 'watch-secret', 'signing_key' => $this->pair['signing_key'],
            'kid' => 'k1', 'public_keys' => ['k1' => $this->pair['public_key']], 'origins' => ['https://www.example.com']]);
    }

    /** Flush and forget. */
    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->redis->flushDB();
        }
        Config::use(null);
    }

    /** The endpoint on this Redis, pushing into the test. */
    private function endpoint(?callable $probe = null): Endpoint
    {
        return new Endpoint(new Store($this->redis, 'test:'), $probe, function (string $title, string $body): void {
            $this->pushed[] = [$title, $body];
        });
    }

    /** A signed request. @return array{status: int, body: mixed, headers: array<string, string>} */
    private function signed(string $method, string $path, string $secret, array $json = [], array $query = [], int $now = 1_800_000_000, array $headers = []): array
    {
        $body   = $method === 'GET' ? '' : (string) json_encode($json);
        $signed = array_change_key_case(Signature::headers($secret, $body, $now), CASE_LOWER);
        $answer = $this->endpoint()->handle($method, $path, $signed + $headers, $body, $query, $now);

        return ['status' => $answer['status'], 'body' => json_decode($answer['body'], true), 'headers' => $answer['headers']];
    }

    /** A request with a person's emergency key. @return array{status: int, body: mixed} */
    private function as(string $key, string $method, string $path, array $json = [], array $query = [], int $now = 1_800_000_000): array
    {
        $answer = $this->endpoint()->handle($method, $path, ['authorization' => 'Bearer ' . $key], $method === 'GET' ? '' : (string) json_encode($json), $query, $now);

        return ['status' => $answer['status'], 'body' => json_decode($answer['body'], true)];
    }

    /**
     * The team is synced; a person with a key starts a session, writes to the emergency channel
     * (once, however often retried) and to a direct message, reacts, reads; a dm not theirs, an
     * empty message, a key of somebody gone or a forged one are refused.
     */
    public function testAPersonOnTheBeacon(): void
    {
        // Arrange
        $synced = $this->signed('POST', 'sync', 'sync-secret', ['people' => [['id' => 5, 'name' => 'Ελένη'], ['id' => 7, 'name' => 'Μαρία'], ['id' => 0]],
            'revoked' => ['gone-jti'], 'push' => [['user' => 5, 'endpoint' => 'https://push.example/1']]]);
        $eleni = EmergencyKey::mint(5, 'Ελένη', 'phone', 1_800_000_000);
        $nikos = EmergencyKey::mint(9, 'Νίκος', 'phone', 1_800_000_000);

        // Act
        $session = $this->as($eleni, 'POST', 'session');
        $first   = $this->as($eleni, 'POST', 'messages', ['c' => 'emergency', 'body' => 'Πέσαμε;', 'client_id' => 'c1']);
        $retry   = $this->as($eleni, 'POST', 'messages', ['c' => 'emergency', 'body' => 'Πέσαμε;', 'client_id' => 'c1']);
        $dm      = $this->as($eleni, 'POST', 'messages', ['c' => 'dm:5-7', 'body' => 'Μαρία, πάρε τηλέφωνο', 'client_id' => 'c2']);
        $this->as($eleni, 'POST', 'react', ['c' => 'emergency', 'id' => $first['body']['id'], 'emoji' => '👍']);
        $this->as($eleni, 'POST', 'react', ['c' => 'emergency', 'id' => $first['body']['id'], 'emoji' => '👀']);
        $this->as($eleni, 'POST', 'react', ['c' => 'emergency', 'id' => $first['body']['id'], 'emoji' => '👀', 'on' => false]);
        $read    = $this->as($eleni, 'GET', 'messages', [], ['c' => 'emergency']);
        $after   = $this->as($eleni, 'GET', 'messages', [], ['c' => 'emergency', 'after' => $first['body']['id']]);
        $refused = [
            $this->as($eleni, 'GET', 'messages', [], ['c' => 'dm:7-9'])['status'],
            $this->as($eleni, 'POST', 'messages', ['c' => 'emergency', 'body' => ' '])['status'],
            $this->as($eleni, 'POST', 'react', ['c' => 'emergency', 'id' => 'x', 'emoji' => ''])['status'],
            $this->as($nikos, 'POST', 'session')['status'],
            $this->as('forged.key', 'POST', 'session')['status'],
            $this->as($eleni, 'DELETE', 'messages', ['c' => 'emergency'])['status'],
            $this->as($eleni, 'GET', 'messages', [], ['c' => 'dm:5-5'])['status'],
        ];

        // Assert
        $this->assertSame(['people' => 2], $synced['body'], 'nobody without an id');
        $this->assertSame(['id' => 5, 'name' => 'Ελένη'], $session['body']['me']);
        $this->assertSame(['emergency', 'dm:5-7'], $session['body']['conversations']);
        $this->assertSame($first['body']['id'], $retry['body']['id'], 'a retry is the same message');
        $this->assertSame(200, $dm['status']);
        $this->assertSame([['Πέσαμε;', 5, ['👍' => [5]]]], array_map(static fn (array $m): array => [$m['body'], $m['author'], $m['reactions']], $read['body']['messages']));
        $this->assertSame([], $after['body']['messages']);
        $this->assertSame([403, 422, 422, 401, 401, 405, 403], $refused);
        $this->assertSame(2, (new Store($this->redis, 'test:'))->pending(), 'both wait for the primary');
        $this->assertSame([['user' => 5, 'endpoint' => 'https://push.example/1']], (new Store($this->redis, 'test:'))->subscriptions());
        $this->assertTrue((new Store($this->redis, 'test:'))->admits(['u' => 5, 'jti' => 'x']));
        $this->assertFalse((new Store($this->redis, 'test:'))->admits(['u' => 5, 'jti' => 'gone-jti']), 'a revoked key');
    }

    /**
     * The watcher: the primary down — told once, a push and a line; down again — nothing new;
     * back — a line with how long; back again — nothing. The beacon's health tells the watcher
     * what it needs to see a stale beacon.
     */
    public function testTheWatchersAlarm(): void
    {
        // Arrange
        Config::use(Config::get() + ['words' => ['down' => 'Πέσαμε στις %s: %s.', 'back' => 'Γυρίσαμε στις %s, μετά από %s λεπτά.', 'title' => 'Πέσαμε', 'body' => '%s %s']]);
        $this->signed('POST', 'sync', 'sync-secret', ['people' => [['id' => 5, 'name' => 'Ελένη']]], [], 1_799_999_900);

        // Act
        $down     = $this->signed('POST', 'alert', 'watch-secret', ['down' => ['site', 'admin'], 'since' => 1_800_000_000]);
        $again    = $this->signed('POST', 'alert', 'watch-secret', ['down' => ['site'], 'since' => 1_800_000_060]);
        $health   = $this->signed('GET', 'health', 'watch-secret', [], [], 1_800_000_100);
        $back     = $this->signed('POST', 'alert', 'watch-secret', ['down' => []], [], 1_800_001_800);
        $backTwice = $this->signed('POST', 'alert', 'watch-secret', ['down' => []], [], 1_800_001_860);
        $lines    = array_column((new Store($this->redis, 'test:'))->read('emergency'), 'body');

        // Assert
        $this->assertSame([true, false, true, false], [$down['body']['told'], $again['body']['told'], $back['body']['told'], $backTwice['body']['told']]);
        $this->assertCount(1, $this->pushed, 'one push per outage');
        $this->assertSame('Πέσαμε', $this->pushed[0][0]);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('site, admin', $lines[0]);
        $this->assertStringContainsString('30 λεπτά', $lines[1], 'how long it lasted');
        $this->assertSame(['ok' => true, 'role' => 'beacon', 'primary' => 'down', 'since' => 1_800_000_000, 'pending' => 1, 'last_sync_at' => 1_799_999_900, 'people' => 1],
            $health['body']);
    }

    /**
     * The primary takes what was written, after a cursor, and acknowledges it: it leaves the log,
     * and the conversations keep it. Nothing is answered without the right signature, nothing but
     * the health on a primary, and a broken store is a 503 that leaks nothing.
     */
    public function testExportAcknowledgeAndRefusals(): void
    {
        // Arrange
        $store = new Store($this->redis, 'test:');
        $store->sync([['id' => 5, 'name' => 'Ελένη']], [], [], 1_800_000_000);
        $a = $store->write('emergency', 5, 'ένα', 'c1', 1_800_000_000);
        $b = $store->write('emergency', 5, 'δύο', 'c2', 1_800_000_001);
        $store->react($a, 5, '👍', true);

        // Act
        $all    = $this->signed('GET', 'export', 'sync-secret');
        $after  = $this->signed('GET', 'export', 'sync-secret', [], ['after' => $a]);
        $ack    = $this->signed('POST', 'ack', 'sync-secret', ['cursor' => $a]);
        $left   = $this->signed('GET', 'export', 'sync-secret');
        $wrong  = [
            $this->signed('GET', 'export', 'watch-secret')['status'],
            $this->signed('POST', 'ack', 'watch-secret', ['cursor' => $b])['status'],
            $this->signed('POST', 'sync', 'watch-secret')['status'],
            $this->signed('POST', 'alert', 'sync-secret', ['down' => ['x']])['status'],
            $this->signed('GET', 'health', 'sync-secret')['status'],
            $this->signed('GET', 'nothing', 'sync-secret')['status'],
        ];
        $options = $this->endpoint()->handle('OPTIONS', 'messages', ['origin' => 'https://www.example.com'], '');
        $foreign = $this->endpoint()->handle('OPTIONS', 'messages', ['origin' => 'https://evil.example'], '');
        Config::use(['role' => 'primary', 'watcher_secret' => 'watch-secret']);
        $primary = [$this->signed('POST', 'sync', 'sync-secret')['status'], $this->signed('GET', 'health', 'watch-secret')['status']];
        $broken  = (new Endpoint(new Store(new \Redis(), 'test:')))->handle('GET', 'health', array_change_key_case(Signature::headers('watch-secret', '', 1_800_000_000), CASE_LOWER), '', [], 1_800_000_000);
        Config::use(['role' => 'beacon', 'watcher_secret' => 'watch-secret']);
        $beaconBroken = (new Endpoint(new Store(new \Redis(), 'test:')))->handle('GET', 'health', array_change_key_case(Signature::headers('watch-secret', '', 1_800_000_000), CASE_LOWER), '', [], 1_800_000_000);

        // Assert
        $this->assertSame(['ένα', 'δύο'], array_column($all['body']['messages'], 'body'));
        $this->assertSame(['👍' => [5]], $all['body']['messages'][0]['reactions']);
        $this->assertSame(['δύο'], array_column($after['body']['messages'], 'body'));
        $this->assertSame(1, $ack['body']['taken']);
        $this->assertSame(['δύο'], array_column($left['body']['messages'], 'body'));
        $this->assertCount(2, $store->read('emergency'), 'the conversation keeps it');
        $this->assertSame([401, 401, 401, 401, 401, 404], $wrong);
        $this->assertSame([204, 'https://www.example.com'], [$options['status'], $options['headers']['Access-Control-Allow-Origin']]);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $foreign['headers']);
        $this->assertSame([404, 503], $primary, 'a primary is no beacon; its health without a database is down');
        $this->assertSame([503, '{"error":"unavailable"}'], [$beaconBroken['status'], $beaconBroken['body']], 'a broken store leaks nothing');
        $this->assertSame(503, $broken['status']);
    }

    /**
     * Served from PHP's request: the path after `/beacon/`, the headers, the body — the answer
     * printed, with its CORS for an origin served.
     */
    public function testServedFromTheRequest(): void
    {
        // Arrange
        $store = new Store($this->redis, 'test:');
        $store->sync([['id' => 5, 'name' => 'Ελένη']], [], [], time());
        $key = EmergencyKey::mint(5, 'Ελένη', 'phone');
        $_SERVER['REQUEST_URI']        = '/beacon/session?x=1';
        $_SERVER['REQUEST_METHOD']     = 'POST';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
        $_SERVER['HTTP_ORIGIN']        = 'https://www.example.com';

        // Act
        ob_start();
        (new Endpoint($store))->serve();
        $printed = (string) ob_get_clean();
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_ORIGIN']);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Assert
        $this->assertSame(['id' => 5, 'name' => 'Ελένη'], json_decode($printed, true)['me']);
    }

    /**
     * The primary's health, for the watcher: up with its database and daemon, 503 when either is
     * down, with each part said.
     */
    public function testThePrimarysHealth(): void
    {
        // Arrange
        Config::use(['role' => 'primary', 'watcher_secret' => 'watch-secret']);
        $headers = array_change_key_case(Signature::headers('watch-secret', '', 1_800_000_000), CASE_LOWER);

        // Act
        $up   = $this->endpoint(static fn (): array => ['db' => true, 'redis' => true, 'daemon' => true, 'queue_lag' => 3])->handle('GET', 'health', $headers, '', [], 1_800_000_000);
        $down = $this->endpoint(static fn (): array => ['db' => false, 'redis' => true, 'daemon' => true, 'queue_lag' => null])->handle('GET', 'health', $headers, '', [], 1_800_000_000);

        // Assert
        $this->assertSame([200, ['ok' => true, 'role' => 'primary', 'db' => true, 'redis' => true, 'daemon' => true, 'queue_lag' => 3]], [$up['status'], json_decode($up['body'], true)]);
        $this->assertSame([503, false], [$down['status'], json_decode($down['body'], true)['ok']]);
        $this->assertSame('no-store', $up['headers']['Cache-Control']);
    }
}
