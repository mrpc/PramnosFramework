<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Apps\AppRegistryInterface;
use Pramnos\Broadcasting\Apps\BroadcastApp;
use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\Http\ServerApi;
use Pramnos\Broadcasting\LocalBroadcastServer;

/**
 * The Pusher-compatible HTTP API.
 *
 * Most of these are refusals, because a signed publish endpoint is either correct
 * or it is a way to broadcast into somebody else's application. The signature must
 * cover the method, the path, the parameters *and* the body — a signature over an
 * unbound body authenticates who sent the request and says nothing about what it
 * contained.
 */
#[CoversClass(ServerApi::class)]
class ServerApiTest extends TestCase
{
    // Public because the anonymous registry class below cannot reach a private
    // constant of its enclosing scope.
    public const KEY    = 'api-key';
    public const SECRET = 'api-secret';
    public const APP_ID = '7';

    /** @var list<array{channel:string,event:string,except:?string}> */
    private array $published = [];

    /** A server that records broadcasts instead of writing to sockets. */
    private function server(array $subscriptions = [], array $presence = []): LocalBroadcastServer
    {
        $server = new class('key', null, new AllowAllAuthorizer()) extends LocalBroadcastServer {
            /** @var list<array{channel:string,event:string,except:?string}> */
            public array $sent = [];

            public function broadcast(string $channel, string $event, $data): void
            {
                $this->sent[] = ['channel' => $channel, 'event' => $event, 'except' => null];
            }

            public function broadcastExcept(
                string $channel,
                string $event,
                $data,
                ?string $exceptSocketId
            ): void {
                $this->sent[] = ['channel' => $channel, 'event' => $event, 'except' => $exceptSocketId];
            }
        };

        if ($subscriptions !== []) {
            (new \ReflectionProperty(LocalBroadcastServer::class, 'subscriptions'))
                ->setValue($server, $subscriptions);
        }
        if ($presence !== []) {
            (new \ReflectionProperty(LocalBroadcastServer::class, 'presence'))
                ->setValue($server, $presence);
        }

        return $server;
    }

    private function registry(string $appId = self::APP_ID): AppRegistryInterface
    {
        return new class($appId) implements AppRegistryInterface {
            public function __construct(private string $appId)
            {
            }

            public function findByKey(string $key): ?BroadcastApp
            {
                return match ($key) {
                    ServerApiTest::KEY => new BroadcastApp(
                        ServerApiTest::KEY,
                        ServerApiTest::SECRET,
                        $this->appId,
                        'Api'
                    ),
                    'keyless' => new BroadcastApp('keyless', '', $this->appId, 'No secret'),
                    default   => null,
                };
            }

            public function defaultApp(): ?BroadcastApp
            {
                return null;
            }
        };
    }

    /**
     * Sign a request the way every Pusher server SDK does.
     *
     * @param array<string,string> $extra
     * @return array<string,string>
     */
    private function signedQuery(string $method, string $path, string $body = '', array $extra = []): array
    {
        $query = array_merge([
            'auth_key'       => self::KEY,
            'auth_timestamp' => (string) time(),
            'auth_version'   => '1.0',
        ], $extra);

        if ($body !== '') {
            $query['body_md5'] = md5($body);
        }

        ksort($query);

        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        $query['auth_signature'] = hash_hmac(
            'sha256',
            strtoupper($method) . "\n" . $path . "\n" . implode('&', $pairs),
            self::SECRET
        );

        return $query;
    }

    // -------------------------------------------------------------------------
    // Publishing
    // -------------------------------------------------------------------------

    /**
     * A signed POST /events publishes to the named channel.
     *
     * This is the whole point of the API: something that is not a PHP request —
     * a deploy script, a service in another language — can now get an event in
     * without speaking Redis and knowing the envelope format.
     */
    public function testPublishesASignedEvent(): void
    {
        // Arrange
        $server = $this->server();
        $api    = new ServerApi($server, $this->registry());
        $body   = (string) json_encode(['name' => 'deploy.done', 'channel' => 'ops', 'data' => ['v' => 3]]);
        $path   = '/apps/' . self::APP_ID . '/events';

        // Act
        $result = $api->handle('POST', $path, $this->signedQuery('POST', $path, $body), $body);

        // Assert
        $this->assertSame(200, $result['status']);
        $this->assertSame([['channel' => 'ops', 'event' => 'deploy.done', 'except' => null]], $server->sent);
    }

    /**
     * `channels` publishes to each one; `socket_id` is honoured.
     *
     * The events API is the other half of toOthers() for a caller that is not a
     * PHP request, so the exclusion has to reach the fan-out from here too.
     */
    public function testPublishesToManyChannelsAndHonoursSocketId(): void
    {
        // Arrange
        $server = $this->server();
        $api    = new ServerApi($server, $this->registry());
        $body   = (string) json_encode([
            'name'      => 'e',
            'channels'  => ['a', 'b'],
            'socket_id' => '12.34',
        ]);
        $path = '/apps/' . self::APP_ID . '/events';

        // Act
        $result = $api->handle('POST', $path, $this->signedQuery('POST', $path, $body), $body);

        // Assert
        $this->assertSame(200, $result['status']);
        $this->assertSame(['a', 'b'], array_column($server->sent, 'channel'));
        $this->assertSame(['12.34', '12.34'], array_column($server->sent, 'except'));
    }

    /**
     * A batch is validated in full before anything is published.
     *
     * A batch that fails half-way has delivered some of its events and reported an
     * error, which leaves the caller unable to retry safely — so an invalid item
     * anywhere means nothing goes out.
     */
    public function testBatchIsAllOrNothing(): void
    {
        // Arrange
        $server = $this->server();
        $api    = new ServerApi($server, $this->registry());
        $body   = (string) json_encode(['batch' => [
            ['name' => 'ok', 'channel' => 'a'],
            ['name' => '', 'channel' => 'b'],        // invalid
        ]]);
        $path = '/apps/' . self::APP_ID . '/batch_events';

        // Act
        $result = $api->handle('POST', $path, $this->signedQuery('POST', $path, $body), $body);

        // Assert
        $this->assertSame(400, $result['status']);
        $this->assertSame([], $server->sent, 'nothing may have been published');
        $this->assertStringContainsString('batch[1]', $result['body']['error']);
    }

    /**
     * A valid batch publishes every item.
     */
    public function testPublishesAValidBatch(): void
    {
        // Arrange
        $server = $this->server();
        $api    = new ServerApi($server, $this->registry());
        $body   = (string) json_encode(['batch' => [
            ['name' => 'one', 'channel' => 'a'],
            ['name' => 'two', 'channel' => 'b', 'socket_id' => '1.2'],
        ]]);
        $path = '/apps/' . self::APP_ID . '/batch_events';

        // Act
        $result = $api->handle('POST', $path, $this->signedQuery('POST', $path, $body), $body);

        // Assert
        $this->assertSame(200, $result['status']);
        $this->assertSame(['one', 'two'], array_column($server->sent, 'event'));
        $this->assertSame([null, '1.2'], array_column($server->sent, 'except'));
    }

    /**
     * A malformed body, a missing event name and a missing channel are each a 400.
     */
    public function testRejectsMalformedPublishBodies(): void
    {
        $path = '/apps/' . self::APP_ID . '/events';

        foreach (['not json', '[]', '{}', '{"name":"e"}', '{"channel":"a"}'] as $body) {
            // Arrange
            $server = $this->server();
            $api    = new ServerApi($server, $this->registry());

            // Act
            $result = $api->handle('POST', $path, $this->signedQuery('POST', $path, $body), $body);

            // Assert
            $this->assertSame(400, $result['status'], 'body: ' . $body);
            $this->assertSame([], $server->sent);
        }
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    /**
     * An unsigned request is refused.
     */
    public function testUnsignedRequestIsRefused(): void
    {
        // Arrange
        $api  = new ServerApi($this->server(), $this->registry());
        $path = '/apps/' . self::APP_ID . '/channels';

        // Act
        $result = $api->handle('GET', $path, []);

        // Assert
        $this->assertSame(401, $result['status']);
    }

    /**
     * A tampered parameter invalidates the signature.
     *
     * The signature covers every query parameter except itself, so adding or
     * changing one after signing is exactly what must fail.
     */
    public function testTamperedParameterInvalidatesTheSignature(): void
    {
        // Arrange
        $api   = new ServerApi($this->server(), $this->registry());
        $path  = '/apps/' . self::APP_ID . '/channels';
        $query = $this->signedQuery('GET', $path);
        $query['filter_by_prefix'] = 'presence-';   // added after signing

        // Act
        $result = $api->handle('GET', $path, $query);

        // Assert
        $this->assertSame(401, $result['status']);
    }

    /**
     * A body that does not match its body_md5 is refused, and so is a body sent
     * without one.
     *
     * The second case is the important one: without body_md5 the signature says
     * who sent the request and nothing about what they sent, so anybody who can
     * replay a signed URL could substitute an arbitrary payload.
     */
    public function testBodyMustBeBoundToTheSignature(): void
    {
        // Arrange
        $path = '/apps/' . self::APP_ID . '/events';
        $body = (string) json_encode(['name' => 'e', 'channel' => 'a']);
        $api  = new ServerApi($this->server(), $this->registry());

        // Act — signed for this body, then the body is swapped
        $swapped = $api->handle(
            'POST',
            $path,
            $this->signedQuery('POST', $path, $body),
            (string) json_encode(['name' => 'other', 'channel' => 'elsewhere'])
        );

        // Act — signed with no body_md5 at all, then a body is attached
        $unbound = $api->handle('POST', $path, $this->signedQuery('POST', $path), $body);

        // Assert
        $this->assertSame(401, $swapped['status'], 'a swapped body must not verify');
        $this->assertSame(401, $unbound['status'], 'a body with no body_md5 must not verify');
    }

    /**
     * A stale or absent timestamp is refused.
     *
     * A replay window rather than a nonce store: a daemon has nowhere durable to
     * remember nonces, and ten minutes is what every Pusher SDK already assumes.
     */
    public function testStaleTimestampIsRefused(): void
    {
        // Arrange
        $api  = new ServerApi($this->server(), $this->registry());
        $path = '/apps/' . self::APP_ID . '/channels';

        foreach ([time() - 601, time() + 601, 0] as $timestamp) {
            $query = $this->signedQuery('GET', $path, '', ['auth_timestamp' => (string) $timestamp]);

            // Act & Assert
            $this->assertSame(401, $api->handle('GET', $path, $query)['status'], 'ts: ' . $timestamp);
        }
    }

    /**
     * A timestamp inside the window is accepted, so the check is a window and not
     * an equality test.
     */
    public function testTimestampInsideTheWindowIsAccepted(): void
    {
        // Arrange
        $api   = new ServerApi($this->server(), $this->registry());
        $path  = '/apps/' . self::APP_ID . '/channels';
        $query = $this->signedQuery('GET', $path, '', ['auth_timestamp' => (string) (time() - 300)]);

        // Act & Assert
        $this->assertSame(200, $api->handle('GET', $path, $query)['status']);
    }

    /**
     * An unknown key and an app with no secret are refused identically.
     */
    public function testUnknownKeyAndKeylessAppAreRefused(): void
    {
        // Arrange
        $api  = new ServerApi($this->server(), $this->registry());
        $path = '/apps/' . self::APP_ID . '/channels';

        foreach (['ghost', 'keyless'] as $key) {
            $query = $this->signedQuery('GET', $path, '', ['auth_key' => $key]);

            // Act & Assert
            $this->assertSame(401, $api->handle('GET', $path, $query)['status'], 'key: ' . $key);
        }
    }

    /**
     * A valid key may not act on another app's path.
     *
     * Without this check the signature would verify and the *path* would choose
     * the target, so any tenant could publish into any other tenant's channels.
     */
    public function testAppKeyCannotActOnAnotherAppsPath(): void
    {
        // Arrange
        $api  = new ServerApi($this->server(), $this->registry());
        $path = '/apps/99/channels';        // the key belongs to app 7

        // Act
        $result = $api->handle('GET', $path, $this->signedQuery('GET', $path));

        // Assert
        $this->assertSame(401, $result['status']);
    }

    /**
     * The signature covers the method, so a signed GET cannot be replayed as a
     * POST.
     */
    public function testSignatureCoversTheMethod(): void
    {
        // Arrange
        $api  = new ServerApi($this->server(), $this->registry());
        $path = '/apps/' . self::APP_ID . '/channels';

        // Act — signed as GET, sent as POST
        $result = $api->handle('POST', $path, $this->signedQuery('GET', $path));

        // Assert
        $this->assertSame(401, $result['status']);
    }

    /**
     * A path outside /apps/{id}/… is a 404, and unauthenticated — there is nothing
     * to authenticate against.
     */
    public function testUnknownPathIsNotFound(): void
    {
        // Arrange
        $api = new ServerApi($this->server(), $this->registry());

        // Act & Assert
        $this->assertSame(404, $api->handle('GET', '/health', [])['status']);
        $this->assertSame(404, $api->handle('GET', '/apps/7', [])['status']);
    }

    /**
     * A known app but an unknown resource is a 404 after authentication.
     */
    public function testUnknownResourceIsNotFound(): void
    {
        // Arrange
        $api  = new ServerApi($this->server(), $this->registry());
        $path = '/apps/' . self::APP_ID . '/nonsense';

        // Act & Assert
        $this->assertSame(404, $api->handle('GET', $path, $this->signedQuery('GET', $path))['status']);
    }

    // -------------------------------------------------------------------------
    // Occupancy
    // -------------------------------------------------------------------------

    /**
     * GET /channels lists occupied channels, with presence user counts on request.
     *
     * Occupancy was previously unobservable from outside the process: the daemon
     * held it in memory and had no way to answer a question about it.
     */
    public function testListsOccupiedChannelsWithUserCounts(): void
    {
        // Arrange
        $server = $this->server(
            ['ops' => [1 => 1], 'presence-room' => [1 => 1, 2 => 2]],
            ['presence-room' => [1 => ['user_id' => '7'], 2 => ['user_id' => '7']]]
        );
        $api  = new ServerApi($server, $this->registry());
        $path = '/apps/' . self::APP_ID . '/channels';
        $query = $this->signedQuery('GET', $path, '', ['info' => 'user_count']);

        // Act
        $result = $api->handle('GET', $path, $query);

        // Assert
        $this->assertSame(200, $result['status']);
        $this->assertArrayHasKey('ops', $result['body']['channels']);
        $this->assertSame([], $result['body']['channels']['ops'], 'no user_count on a public channel');
        // Two connections, one person — the count deduplicates by user.
        $this->assertSame(1, $result['body']['channels']['presence-room']['user_count']);
    }

    /**
     * filter_by_prefix narrows the listing.
     */
    public function testFiltersChannelsByPrefix(): void
    {
        // Arrange
        $server = $this->server(['ops' => [1 => 1], 'presence-room' => [1 => 1]]);
        $api    = new ServerApi($server, $this->registry());
        $path   = '/apps/' . self::APP_ID . '/channels';
        $query  = $this->signedQuery('GET', $path, '', ['filter_by_prefix' => 'presence-']);

        // Act
        $result = $api->handle('GET', $path, $query);

        // Assert
        $this->assertSame(['presence-room'], array_keys($result['body']['channels']));
    }

    /**
     * GET /channels/{name} reports occupancy, and the counts when asked.
     */
    public function testReportsOneChannelsOccupancy(): void
    {
        // Arrange
        $server = $this->server(
            ['presence-room' => [1 => 1, 2 => 2]],
            ['presence-room' => [1 => ['user_id' => '7'], 2 => ['user_id' => '9']]]
        );
        $api   = new ServerApi($server, $this->registry());
        $path  = '/apps/' . self::APP_ID . '/channels/presence-room';
        $query = $this->signedQuery('GET', $path, '', ['info' => 'user_count,subscription_count']);

        // Act
        $result = $api->handle('GET', $path, $query);

        // Assert
        $this->assertTrue($result['body']['occupied']);
        $this->assertSame(2, $result['body']['subscription_count']);
        $this->assertSame(2, $result['body']['user_count']);
    }

    /**
     * An empty channel reports occupied = false rather than 404.
     */
    public function testEmptyChannelIsReportedAsUnoccupied(): void
    {
        // Arrange
        $api   = new ServerApi($this->server(), $this->registry());
        $path  = '/apps/' . self::APP_ID . '/channels/nobody-here';
        $query = $this->signedQuery('GET', $path);

        // Act
        $result = $api->handle('GET', $path, $query);

        // Assert
        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['body']['occupied']);
    }

    /**
     * user_count is refused on a channel that has no notion of users.
     *
     * Answering with the subscription count would let a caller believe it had
     * deduplicated people when it had counted connections.
     */
    public function testUserCountIsRefusedOnNonPresenceChannels(): void
    {
        // Arrange
        $api   = new ServerApi($this->server(['ops' => [1 => 1]]), $this->registry());
        $path  = '/apps/' . self::APP_ID . '/channels/ops';
        $query = $this->signedQuery('GET', $path, '', ['info' => 'user_count']);

        // Act
        $result = $api->handle('GET', $path, $query);

        // Assert
        $this->assertSame(400, $result['status']);
        $this->assertStringContainsString('presence channels', $result['body']['error']);
    }

    /**
     * GET /channels/{name}/users lists distinct users.
     */
    public function testListsPresenceUsers(): void
    {
        // Arrange
        $server = $this->server(
            ['presence-room' => [1 => 1, 2 => 2, 3 => 3]],
            ['presence-room' => [
                1 => ['user_id' => '7'],
                2 => ['user_id' => '7'],       // same person, second tab
                3 => ['user_id' => '9'],
            ]]
        );
        $api   = new ServerApi($server, $this->registry());
        $path  = '/apps/' . self::APP_ID . '/channels/presence-room/users';
        $query = $this->signedQuery('GET', $path);

        // Act
        $result = $api->handle('GET', $path, $query);

        // Assert
        $this->assertSame(200, $result['status']);
        $this->assertEqualsCanonicalizing(
            [['id' => '7'], ['id' => '9']],
            $result['body']['users'],
            'three connections, two people'
        );
    }

    /**
     * Users are refused on a non-presence channel.
     */
    public function testUsersAreRefusedOnNonPresenceChannels(): void
    {
        // Arrange
        $api   = new ServerApi($this->server(), $this->registry());
        $path  = '/apps/' . self::APP_ID . '/channels/ops/users';
        $query = $this->signedQuery('GET', $path);

        // Act & Assert
        $this->assertSame(400, $api->handle('GET', $path, $query)['status']);
    }

    /**
     * An app with no id in the registry is not held to the path check.
     *
     * The config registry leaves `app_id` empty when it is not configured, and a
     * single-app deployment has nothing to disambiguate — so requiring a match
     * would break the simplest setup for no security gain.
     */
    public function testAppWithoutAnIdSkipsThePathCheck(): void
    {
        // Arrange
        $api   = new ServerApi($this->server(), $this->registry(appId: ''));
        $path  = '/apps/anything/channels';
        $query = $this->signedQuery('GET', $path);

        // Act & Assert
        $this->assertSame(200, $api->handle('GET', $path, $query)['status']);
    }

    // -------------------------------------------------------------------------
    // Metrics
    // -------------------------------------------------------------------------

    /**
     * GET /metrics reports levels and counters together.
     *
     * Both kinds are needed: a gauge that only reports "now" cannot answer "is this
     * getting worse", and `client_events_refused` beside `client_events_relayed` is
     * the only way to see a rate limit working at all — refusals are silent on the
     * wire by design, so without the counter a throttled client looks exactly like a
     * quiet one.
     */
    public function testReportsMetrics(): void
    {
        // Arrange
        $server = $this->server(
            ['ops' => [1 => 1], 'presence-room' => [2 => 2]],
            ['presence-room' => [2 => ['user_id' => '7']]]
        );
        $api   = new ServerApi($server, $this->registry());
        $path  = '/apps/' . self::APP_ID . '/metrics';
        $query = $this->signedQuery('GET', $path);

        // Act
        $result = $api->handle('GET', $path, $query);

        // Assert
        $this->assertSame(200, $result['status']);
        $body = $result['body'];

        foreach ([
            'connections_total', 'messages_sent', 'client_events_relayed',
            'client_events_refused', 'webhook_events_queued', 'connections_current',
            'channels_occupied', 'subscriptions_current', 'presence_channels',
            'uptime_seconds',
        ] as $key) {
            $this->assertArrayHasKey($key, $body, 'missing metric: ' . $key);
            $this->assertIsInt($body[$key]);
        }

        $this->assertSame(2, $body['channels_occupied']);
        $this->assertSame(2, $body['subscriptions_current']);
        $this->assertSame(1, $body['presence_channels']);
    }

    /**
     * Metrics need the same signature as everything else — a connection count is a
     * useful thing for an outsider to know about a server.
     */
    public function testMetricsRequireAuthentication(): void
    {
        // Arrange
        $api = new ServerApi($this->server(), $this->registry());

        // Act & Assert
        $this->assertSame(401, $api->handle('GET', '/apps/' . self::APP_ID . '/metrics', [])['status']);
    }
}
