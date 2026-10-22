<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Console\Commands\ConversationsBeacon;
use Pramnos\Conversations\Beacon\Config;
use Pramnos\Conversations\Beacon\EmergencyKey;
use Pramnos\Conversations\Beacon\Endpoint;
use Pramnos\Conversations\Beacon\Primary;
use Pramnos\Conversations\Beacon\Store;
use Pramnos\Conversations\Client;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Http\Client as Http;
use Pramnos\Http\ClientResponse;
use Pramnos\Http\Request;
use Symfony\Component\Console\Application as Console;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The primary and its beacon, on each backend with a live Redis between them: the team synced to
 * the beacon; what was written there during an outage taken back once — the emergency channel into
 * its channel, direct messages into the two people's own, reactions with them — and acknowledged;
 * a device given its key; the drill; the command around it all; a beacon away is said, not thrown.
 */
#[CoversClass(Primary::class)]
#[CoversClass(ConversationsBeacon::class)]
#[CoversClass(Client::class)]
#[CoversClass(\Pramnos\Application\Controllers\Conversations::class)]
#[Group('redis')]
class BeaconPrimaryTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    private \Redis $redis;

    /** @var array<string, mixed> The primary's settings */
    private array $primary = [];

    /** @var array<string, mixed> The beacon's settings */
    private array $beacon = [];

    /** A beacon on Redis database 12, reached through a fake HTTP client. */
    private function beaconUp(): void
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
        $this->redis->select(12);
        $this->redis->flushDB();
        $pair          = EmergencyKey::generate();
        $this->primary = ['role' => 'primary', 'secret' => 's', 'watcher_secret' => 'w', 'signing_key' => $pair['signing_key'], 'kid' => 'k',
            'beacon_url' => 'https://beacon.test', 'urls' => ['https://chat.test', 'https://beacon.test'], 'channel' => 'έκτακτο', 'drill_url' => 'https://watcher.test/drill'];
        $this->beacon  = ['role' => 'beacon', 'secret' => 's', 'watcher_secret' => 'w', 'public_keys' => ['k' => $pair['public_key']]];
        Config::use($this->primary);
        $store = new Store($this->redis, 'test:');
        Http::fake([
            'https://beacon.test/beacon/*' => function (Http $request) use ($store): ClientResponse {
                $path = (string) preg_replace('#^https://beacon\.test/beacon/([^?]*).*$#', '$1', $request->url());
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $headers = array_change_key_case($request->requestHeaders(), CASE_LOWER);
                Config::use($this->beacon);
                $answer = (new Endpoint($store, null, static fn () => null))->handle($request->method(), $path, $headers, (string) $request->requestBody(), $query);
                Config::use($this->primary);

                return new ClientResponse($answer['status'], $answer['body']);
            },
            'https://watcher.test/*' => fn (): ClientResponse => new ClientResponse(202, '{}'),
        ]);
    }

    /** Fakes, settings and Redis cleared, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        Primary::subscriptionsFrom(null);
        Http::resetFakes();
        Config::use(null);
        if (isset($this->redis)) {
            $this->redis->flushDB();
        }
        $_GET = [];
        Request::setRawInput(null);
        unset(Application::getInstance()->applicationInfo['features']);
        $this->probeTearDown();
    }

    /**
     * The team goes to the beacon; what is written there comes back once — the emergency channel
     * made here and its writers in it, a dm between its two, the beacon's own lines signed,
     * reactions of members — and leaves the beacon's log.
     */
    #[DataProvider('databases')]
    public function testSyncAndImport(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $this->beaconUp();
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $store = new Store($this->redis, 'test:');

        // Act
        $synced = Primary::sync();
        $first  = $store->write('emergency', $eleni, 'Πέσαμε;', 'c1', 1_800_000_000);
        $store->write('emergency', 0, '⚠ Down since 03:10.', 'alert', 1_800_000_010);
        $store->write('dm:' . min($eleni, $maria) . '-' . max($eleni, $maria), $maria, 'Το βλέπω', 'c2', 1_800_000_020);
        $store->write('nowhere', $eleni, 'x', 'c3', 1_800_000_030);
        $store->react($first, $maria, '👍', true);
        $store->react($first, $this->ids['tasos'], '👀', true);
        $imported = Primary::import();
        $again    = Primary::import();
        $channel  = Conversations::bySlug('έκτακτο');
        $lines    = Messages::list($channel['id']);

        // Assert
        $this->assertTrue($synced['ok']);
        $this->assertGreaterThanOrEqual(4, $synced['people'], 'the team: whoever may use the conversations');
        $this->assertSame(['ok' => true, 'imported' => 3], $imported, 'nowhere is nowhere');
        $this->assertSame(['ok' => true, 'imported' => 0], $again, 'once');
        $this->assertSame(0, $store->pending(), 'acknowledged');
        $this->assertSame(['Πέσαμε;', '⚠ Down since 03:10.'], array_column($lines, 'body'));
        $this->assertSame([1_800_000_000, 'Beacon'], [$lines[0]['at'], $lines[1]['card']['from']]);
        $this->assertContains($eleni, Conversations::members($channel['id']), 'a writer joins the channel');
        $this->assertSame([['emoji' => '👍', 'count' => 1, 'people' => [$maria]]], $lines[0]['reactions'], 'whoever reacted and may use them; not a translator');
        $dm = Conversations::create('dm', null, [$maria], $eleni);
        $this->assertSame(['Το βλέπω'], array_column(Messages::list($dm), 'body'));
    }

    /**
     * The team's push subscriptions go with it; an acknowledgement lost is a message offered
     * again and taken once; a beacon nobody named, or a request that throws, is nothing synced.
     */
    #[DataProvider('databases')]
    public function testSubscriptionsALostAckAndNoBeacon(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        $this->beaconUp();
        $eleni = $this->ids['eleni'];
        Primary::subscriptionsFrom(static fn (int $id): array => $id === $eleni
            ? [['endpoint' => 'https://push.example/e', 'p256dh' => 'p', 'auth_secret' => 'a', 'content_encoding' => 'aes128gcm']] : []);
        $store = new Store($this->redis, 'test:');
        $store->write('emergency', $eleni, 'μία φορά', 'c1', 1_800_000_000);
        $acks  = 0;
        $real  = (new \ReflectionProperty(Http::class, 'fakes'))->getValue();
        Http::fake(['https://beacon.test/beacon/ack' => function () use (&$acks): ClientResponse {
            $acks++;

            return new ClientResponse(500, 'lost');
        }] + $real);

        // Act
        Primary::sync();
        $first  = Primary::import();
        $second = Primary::import();
        Config::use(['role' => 'primary', 'beacon_url' => '']);
        $nowhere = Primary::sync();
        Config::use($this->primary);
        Http::fake(['https://beacon.test/*' => static fn (): ClientResponse => throw new \RuntimeException('connection reset')]);
        $thrown = Primary::import();

        // Assert
        $this->assertSame([['user' => $eleni, 'endpoint' => 'https://push.example/e', 'p256dh' => 'p', 'auth_secret' => 'a', 'content_encoding' => 'aes128gcm']],
            $store->subscriptions());
        $this->assertSame([1, 0], [$first['imported'], $second['imported']], 'offered again, taken once');
        $this->assertSame(2, $acks);
        $this->assertCount(1, Messages::list((int) Conversations::bySlug('έκτακτο')['id']));
        $this->assertFalse($nowhere['ok']);
        $this->assertSame(['ok' => false, 'imported' => 0, 'why' => 'the beacon did not answer'], $thrown);
    }

    /**
     * A device is given its key, which the beacon reads; the client is told where the beacon is;
     * the drill asks the watcher and tells the person's browser; without a beacon here, or with a
     * beacon away, the command says so and does not fail.
     */
    #[DataProvider('databases')]
    public function testKeysDrillAndTheCommand(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        $this->beaconUp();
        $page = new HeldPage(Application::getInstance());
        $page->asking = $this->user('eleni');
        $_GET = ['device' => 'phone-1'];
        Request::setRawInput('');
        $console = new Console();
        $console->add(new ConversationsBeacon());
        $run = static function (array $args) use ($console): array {
            $tester = new CommandTester($console->find('conversations:beacon'));
            $status = $tester->execute($args);

            return [$status, trim($tester->getDisplay())];
        };
        $fake = \Pramnos\Broadcasting\Testing\FakeDriver::swap();

        // Act
        $answer  = json_decode((string) $page->emergencykey()->getBody(), true);
        Config::use($this->beacon);
        $claims  = EmergencyKey::verify($answer['key']);
        Config::use($this->primary);
        $props   = Client::props($this->user('eleni'));
        $synced  = $run(['what' => 'sync']);
        $import  = $run(['what' => 'import']);
        $drill   = $run(['what' => 'test', 'person' => (string) $this->ids['nikos']]);
        $nobody  = $run(['what' => 'test']);
        $wrong   = $run(['what' => 'dance']);
        $keys    = $run(['what' => 'keys']);
        Http::fake(['https://beacon.test/*' => fn (): ClientResponse => new ClientResponse(502, 'bad gateway')]);
        $away    = $run(['what' => 'sync']);
        $awayImport = $run(['what' => 'import']);
        Config::use(['role' => 'beacon']);
        $notHere = $run(['what' => 'sync']);
        $noKey   = json_decode((string) (function () {
            $held = new HeldPage(Application::getInstance());
            $held->asking = $this->user('eleni');

            return $held->emergencykey()->getBody();
        })(), true);
        \Pramnos\Broadcasting\Testing\FakeDriver::restore();

        // Assert
        $this->assertSame([$this->ids['eleni'], 'Ελένη Σ.', 'phone-1'], [$claims['u'], $claims['n'], $claims['d']]);
        $this->assertSame(['https://chat.test', 'https://beacon.test'], $answer['urls']);
        $this->assertSame(['urls' => ['https://chat.test', 'https://beacon.test'], 'channel' => 'έκτακτο'], $props['beacon']);
        $this->assertSame([0, 'The beacon knows'], [$synced[0], substr($synced[1], 0, 16)]);
        $this->assertSame([0, '0 messages taken from the beacon.'], $import);
        $this->assertStringContainsString('answered 202', $drill[1]);
        $this->assertTrue($fake->hasBroadcast('private-users.' . $this->ids['nikos'], 'conversation.drill'), 'the browser is sent to the beacon');
        $this->assertSame(2, $nobody[0]);
        $this->assertSame(2, $wrong[0]);
        $this->assertStringContainsString('signing_key', $keys[1]);
        $this->assertSame([0, 'Not synced: the beacon did not answer.'], $away, 'said, not thrown');
        $this->assertStringContainsString('Not imported', $awayImport[1]);
        $this->assertStringContainsString('No beacon here', $notHere[1]);
        $this->assertSame('There is no beacon here.', $noKey['error']);
    }

    /**
     * A primary without its signing key — none set, or one that is not a key — is not a beacon to
     * the browsers: the client is not told of one, and asking for an emergency key is a 404 that
     * says so, not a 500 on every page.
     */
    #[DataProvider('databases')]
    public function testAPrimaryWithoutItsSigningKeyHasNoBeacon(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        $this->beaconUp();
        $ask = function (): array {
            $held = new HeldPage(Application::getInstance());
            $held->asking = $this->user('eleni');
            $response = $held->emergencykey();

            return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
        };
        $_GET = ['device' => 'phone-1'];
        Request::setRawInput('');
        $without = $this->primary;
        unset($without['signing_key']);

        // Act
        Config::use($without);
        $noneProps = Client::props($this->user('eleni'));
        $none      = $ask();
        Config::use(['signing_key' => base64_encode('not a key')] + $this->primary);
        $badProps  = Client::props($this->user('eleni'));
        $bad       = $ask();
        Config::use($this->primary);
        $ready     = Config::ready();

        // Assert
        $this->assertNull($noneProps['beacon'], 'no signing key: the browsers are not told of a beacon');
        $this->assertSame([404, 'There is no beacon here.'], [$none[0], $none[1]['error']]);
        $this->assertNull($badProps['beacon'], 'a key that is not one');
        $this->assertSame(404, $bad[0]);
        $this->assertTrue($ready, 'with its key, it is');
    }
}
