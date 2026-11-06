<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Broadcasting\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Broadcasting\Apps\ConfigAppRegistry;
use Pramnos\Broadcasting\Auth\AllowAllAuthorizer;
use Pramnos\Broadcasting\Http\ApiClient;
use Pramnos\Broadcasting\Http\ServerApi;
use Pramnos\Broadcasting\LocalBroadcastServer;

/**
 * The application asking the broadcasting server who is in a presence channel — what `@here` needs.
 *
 * Why it matters: who is connected lives only in the server's memory, and the only way to it from a
 * request is the server's signed HTTP API. A signature the server refuses is not an error anybody
 * sees — `@here` silently falls back to telling everybody — so the client is tested against the
 * framework's own server, not against a copy of its rules.
 */
#[CoversClass(ApiClient::class)]
final class ApiClientTest extends TestCase
{
    /** @var array<string, mixed> The broadcasting configuration of an application on broadcast:serve */
    private const CONFIG = [
        'transport' => 'websocket',
        'websocket' => ['host' => 'rt.test', 'port' => 6001],
        'http_api'  => ['enabled' => true],
        'pusher'    => ['app_key' => 'k1', 'app_secret' => 's1', 'app_id' => '9'],
    ];

    /** @var list<string> The URLs the client asked */
    private array $asked = [];

    protected function tearDown(): void
    {
        ApiClient::$fetch = null;
    }

    /** Answer the client's GETs with the framework's own server API, holding these presence members. */
    private function serve(array $presence): void
    {
        $server = new LocalBroadcastServer('k1', null, new AllowAllAuthorizer());
        (new \ReflectionProperty(LocalBroadcastServer::class, 'presence'))->setValue($server, $presence);
        $api = new ServerApi($server, new ConfigAppRegistry(self::CONFIG));
        ApiClient::$fetch = function (string $url) use ($api): ?string {
            $this->asked[] = $url;
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            $answer = $api->handle('GET', $parts['path'], $query);

            return $answer['status'] === 200 ? json_encode($answer['body']) : null;
        };
    }

    /**
     * The server accepts the client's signature and answers who is in the channel — each person once,
     * however many tabs they have open.
     */
    public function testTheServerAcceptsItAndAnswersWhoIsThere(): void
    {
        // Arrange — maria in two tabs, nikos in one
        $this->serve(['presence-chat' => [1 => ['user_id' => '7'], 2 => ['user_id' => '7'], 3 => ['user_id' => '12']]]);

        // Act
        $users = ApiClient::fromConfig(self::CONFIG)?->presenceUsers('presence-chat');

        // Assert
        $this->assertSame(['7', '12'], $users);
        $this->assertStringStartsWith('http://rt.test:6001/apps/9/channels/presence-chat/users?', $this->asked[0]);
    }

    /**
     * A wrong secret is refused by the server, and a server that does not answer is null, so a caller
     * can tell "nobody is there" from "could not ask".
     */
    public function testARefusalOrNoAnswerIsNull(): void
    {
        // Arrange
        $this->serve(['presence-chat' => [1 => ['user_id' => '7']]]);
        $wrong = self::CONFIG;
        $wrong['pusher']['app_secret'] = 'not-it';

        // Act
        $refused = ApiClient::fromConfig($wrong)?->presenceUsers('presence-chat');
        ApiClient::$fetch = static fn (string $url): ?string => null;
        $down = ApiClient::fromConfig(self::CONFIG)?->presenceUsers('presence-chat');
        ApiClient::$fetch = static fn (string $url): ?string => 'not json';
        $garbled = ApiClient::fromConfig(self::CONFIG)?->presenceUsers('presence-chat');

        // Assert
        $this->assertNull($refused, 'the server refused the signature');
        $this->assertNull($down);
        $this->assertNull($garbled);
    }

    /**
     * Where it asks: `http_api.url` first; the built-in server's host and port only when its API is
     * on, over https when it terminates TLS; Pusher's own API host, or the host of a Pusher-compatible
     * server; nothing without a key and a secret, or for a transport with no API.
     */
    public function testWhereItAsks(): void
    {
        // Arrange
        $urls = [];
        ApiClient::$fetch = static function (string $url) use (&$urls): ?string {
            $urls[] = (string) strstr($url, '/apps/', true);

            return '{"users":[]}';
        };
        $configs = [
            'explicit' => ['http_api' => ['url' => 'http://127.0.0.1:6001/'], 'transport' => 'websocket'] + self::CONFIG,
            'tls'      => ['websocket' => ['host' => 'rt.test', 'port' => 443, 'tls' => ['local_cert' => '/c.pem']]] + self::CONFIG,
            'cloud'    => ['transport' => 'pusher', 'pusher' => ['app_key' => 'k', 'app_secret' => 's', 'cluster' => 'eu']],
            'reverb'   => ['transport' => 'pusher', 'pusher' => ['app_key' => 'k', 'app_secret' => 's', 'host' => 'reverb.test', 'port' => 8080, 'scheme' => 'http']],
        ];

        // Act
        foreach ($configs as $config) {
            ApiClient::fromConfig($config)?->presenceUsers('presence-chat');
        }
        $apiOff   = ApiClient::fromConfig(['http_api' => ['enabled' => false]] + self::CONFIG);
        $noSecret = ApiClient::fromConfig(['pusher' => ['app_key' => 'k1']] + self::CONFIG);
        $sse      = ApiClient::fromConfig(['transport' => 'sse'] + self::CONFIG);

        // Assert
        $this->assertSame(['http://127.0.0.1:6001', 'https://rt.test:443', 'https://api-eu.pusher.com', 'http://reverb.test:8080'], $urls);
        $this->assertNull($apiOff, 'broadcast:serve without its HTTP API');
        $this->assertNull($noSecret, 'nothing to sign with');
        $this->assertNull($sse, 'a transport with no API');
    }
}
