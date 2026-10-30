<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Http\Client as Http;
use Pramnos\Http\ClientResponse;
use Pramnos\Llm\Chat\BaseAssistant;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Mcp\Connections;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * The MCP servers a person connects themselves — on MySQL and PostgreSQL.
 *
 * What holds: off by default; a server with a token is connected, its tools offered in the
 * person's own conversations under its name and run on it with the token; a server that wants a
 * sign-in sends the person to its authorization server — found, registered with, asked with
 * PKCE — and the code they come back with becomes tokens, refreshed when they run out; the
 * secrets are kept encrypted in the person's setting; an address on the server's own network is
 * never reached; and nobody else's conversations see them.
 *
 * Addresses are public IP literals: the SSRF guard resolves a name before any fake answers.
 */
#[CoversClass(Connections::class)]
#[CoversClass(\Pramnos\Llm\Mcp\HttpTransport::class)]
#[CoversClass(\Pramnos\Llm\Mcp\Client::class)]
#[CoversClass(\Pramnos\Llm\Chat\Api\ChatController::class)]
class ConnectionsTest extends LlmTestCase
{
    use ChatDatabase;

    private const SERVER = 'https://93.184.215.14/mcp';
    private const AUTH   = 'https://93.184.215.15';

    /** @var list<int> */
    private array $users = [];

    /** @var list<array{url: string, auth: string, method: string, body: array<string, mixed>}> What the fake servers were asked */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        \Pramnos\Cache\Cache::getInstance('llm')->clear();
    }

    protected function tearDown(): void
    {
        if ($this->users !== []) {
            $this->db->queryBuilder()->table('#PREFIX#usersettings')->whereIn('userid', $this->users)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->whereIn('userid', $this->users)->delete();
        }
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** A person whose settings can hold connections. */
    private function person(string $type, string $host, int $port): Scope
    {
        $this->connectDatabase($type, $host, $port);
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Core\CreateUsersettingsTable::class], $this->db);
        $mark = 'mc' . bin2hex(random_bytes(4));
        $this->db->queryBuilder()->table('#PREFIX#users')->insert(['username' => $mark, 'email' => $mark . '@example.com', 'password' => 'x', 'regdate' => time()]);
        $id = (int) $this->db->getInsertId();
        $this->users[] = $id;
        if ($id < 2) {
            // Account 1 keeps no settings (User::getSetting()): a fresh table's first row is it.
            return $this->person($type, $host, $port);
        }
        \Pramnos\User\User::clearUserCache();
        // Ids start again with each class's tables: a user cached under this id is somebody else.
        $this->db->cacheflush('userlist');

        return new Scope($id, null);
    }

    /** The model's fake, as answer() set it, to keep beside a server's. */
    private function provider(): \Closure
    {
        return function (Http $request): ClientResponse {
            $this->sent[] = ['url' => $request->url(), 'body' => (array) json_decode((string) $request->requestBody(), true), 'raw' => (string) $request->requestBody()];

            return ClientResponse::make((string) json_encode(self::says('Hi')), 200);
        };
    }

    /**
     * An MCP server that wants `Bearer $want` — 401 the MCP way without it — and offers one
     * tool that reads and one that writes.
     */
    private function server(string ...$want): \Closure
    {
        return function (Http $request) use ($want): ClientResponse {
            $body = json_decode((string) $request->requestBody(), true) ?: [];
            $auth = $request->requestHeaders()['Authorization'] ?? '';
            $this->asked[] = ['url' => $request->url(), 'auth' => $auth, 'method' => (string) ($body['method'] ?? ''), 'body' => $body];
            if (!in_array(substr($auth, 7), $want, true) || !str_starts_with($auth, 'Bearer ')) {
                return ClientResponse::make('', 401, ['WWW-Authenticate' => 'Bearer resource_metadata="' . self::AUTH . '/resource"']);
            }
            $result = match ($body['method'] ?? '') {
                'initialize' => ['protocolVersion' => '2025-06-18', 'capabilities' => ['tools' => []], 'serverInfo' => ['name' => 'fake']],
                'tools/list' => ['tools' => [
                    ['name' => 'search_issues', 'description' => 'Find issues', 'inputSchema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]], 'annotations' => ['readOnlyHint' => true]],
                    ['name' => 'close_issue', 'description' => 'Close one', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]],
                ]],
                'tools/call' => ['content' => [['type' => 'text', 'text' => 'Found 3 for ' . ($body['params']['arguments']['q'] ?? '')]]],
                default      => [],
            };

            return isset($body['id']) ? ClientResponse::make(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => $result]) : ClientResponse::make('', 202);
        };
    }

    /**
     * With a token: connected, listed without its secret, its tools offered in the person's own
     * conversation under its name — reading idempotent, writing held — and run on the server
     * with the token. The token is kept encrypted. Off, there is nothing to connect to.
     */
    #[DataProvider('databases')]
    public function testAServerWithAToken(string $type, string $host, int $port): void
    {
        // Arrange
        $scope = $this->person($type, $host, $port);
        $this->answer([[200, self::says('Hi')]]);
        $provider = $this->provider();
        Http::fake([self::SERVER => $this->server('tok-1'), '*' => $provider]);
        $off = Connections::on();
        $this->configure(['chat' => ['connections' => true]]);
        $connections = new Connections($scope);

        // Act
        $wrong   = $connections->add('github', self::SERVER, 'nope');
        $made    = $connections->add('GitHub', self::SERVER, 'tok-1');
        $tools   = (new Connections($scope))->tools();
        $ran     = ($tools['github__search_issues']['run'])(['q' => 'login']);
        (new Conversations(new BaseAssistant()))->as($scope)->ask(null, 'Hi');
        $offered = array_map(static fn (array $t): string => $t['function']['name'], (array) $this->sent[0]['body']['tools']);
        $stored  = (string) (new \Pramnos\User\User($scope->userId))->getSetting(Connections::SETTING, '');

        // Assert
        $this->assertFalse($off, 'off by default');
        $this->assertSame(['error' => 'The server did not take that token.'], $wrong);
        $this->assertSame(['connected' => 'github'], $made, 'the name is lower-cased');
        $this->assertSame([['name' => 'github', 'url' => self::SERVER, 'auth' => 'token', 'tools' => 2]], array_map(
            static fn (array $c): array => array_diff_key($c, ['added_at' => 1]), $connections->all()));
        $this->assertSame('Found 3 for login', $ran);
        $this->assertSame('Bearer tok-1', end($this->asked)['auth']);
        $this->assertTrue($tools['github__search_issues']['idempotent']);
        $this->assertTrue($tools['github__close_issue']['writes']);
        $this->assertTrue($tools['github__close_issue']['untrusted']);
        $this->assertSame(['github'], $tools['github__close_issue']['skills']);
        $this->assertContains('github__search_issues', $offered);
        $this->assertStringStartsWith('enc:v1:', $stored, 'the token is not kept in the clear');
        $this->assertStringNotContainsString('tok-1', $stored);
        $this->assertTrue($connections->remove('github'));
        $this->assertFalse($connections->remove('github'));
        $this->assertSame([], (new Connections($scope))->all());
    }

    /**
     * Refused before anything is asked: a bad name, an address that is not https, more than
     * `MAX`; and an address on the server's own network never reached, by the guard.
     */
    #[DataProvider('databases')]
    public function testWhatIsRefused(string $type, string $host, int $port): void
    {
        // Arrange
        $scope = $this->person($type, $host, $port);
        $this->configure(['chat' => ['connections' => true]]);
        Http::fake([self::SERVER . '*' => $this->server('t')]);
        $connections = new Connections($scope);

        // Act / Assert
        $this->assertStringStartsWith('A name is', $connections->add('9lives', self::SERVER, 't')['error']);
        $this->assertSame('A server is an https:// address.', $connections->add('plain', 'http://93.184.215.14/mcp', 't')['error']);
        $this->assertStringContainsString('could not be reached', $connections->add('meta', 'https://169.254.169.254/mcp', 't')['error']);
        for ($i = 0; $i < Connections::MAX; $i++) {
            $this->assertSame(['connected' => 's' . $i], $connections->add('s' . $i, self::SERVER . '?n=' . $i, 't'));
        }
        $this->assertStringStartsWith('Up to ' . Connections::MAX, $connections->add('more', self::SERVER, 't')['error']);
        $this->assertSame(['connected' => 's0'], $connections->add('s0', self::SERVER, 't'), 'the same name again replaces it');
    }

    /**
     * A server that wants a sign-in: its authorization server found through the 401's resource
     * metadata, this application registered there, the person sent with PKCE; back with the code,
     * the tokens are had and the server connected; a token about to run out is refreshed before a
     * call; and a state this page did not start is refused.
     */
    #[DataProvider('databases')]
    public function testSigningInToAServer(string $type, string $host, int $port): void
    {
        // Arrange
        $scope  = $this->person($type, $host, $port);
        $this->configure(['chat' => ['connections' => true]]);
        $tokens = [];
        Http::fake([
            self::SERVER                                         => $this->server('access-1', 'access-2'),
            self::AUTH . '/resource'                             => ClientResponse::make(['authorization_servers' => [self::AUTH]]),
            self::AUTH . '/.well-known/oauth-authorization-server' => ClientResponse::make([
                'authorization_endpoint' => self::AUTH . '/authorize', 'token_endpoint' => self::AUTH . '/token', 'registration_endpoint' => self::AUTH . '/register',
            ]),
            self::AUTH . '/register' => ClientResponse::make(['client_id' => 'cid-9'], 201),
            self::AUTH . '/token'    => function (Http $request) use (&$tokens): ClientResponse {
                parse_str((string) $request->requestBody(), $form);
                $tokens[] = $form;

                return ClientResponse::make($form['grant_type'] === 'authorization_code'
                    ? ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 30]
                    : ['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]);
            },
        ]);
        $connections = new Connections($scope, 'https://app.test/chat/connected');

        // Act
        $started = $connections->add('notes', self::SERVER);
        parse_str((string) parse_url($started['authorize'], PHP_URL_QUERY), $query);
        $stranger = $connections->finish('not-ours', 'code');
        $done     = $connections->finish($query['state'], 'the-code');
        $ran      = ((new Connections($scope))->tools()['notes__search_issues']['run'])(['q' => 'x']);

        // Assert
        $this->assertStringStartsWith(self::AUTH . '/authorize?', $started['authorize']);
        $this->assertSame(['code', 'cid-9', 'https://app.test/chat/connected', 'S256', self::SERVER], [$query['response_type'], $query['client_id'], $query['redirect_uri'], $query['code_challenge_method'], $query['resource']]);
        $this->assertStringStartsWith('That sign-in is not one', $stranger['error']);
        $this->assertSame(['connected' => 'notes'], $done);
        $this->assertSame('authorization_code', $tokens[0]['grant_type']);
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $tokens[0]['code_verifier'], true)), '+/', '-_'), '='), $query['code_challenge'], 'PKCE');
        $this->assertSame(['refresh_token', 'refresh-1'], [$tokens[1]['grant_type'], $tokens[1]['refresh_token']], 'thirty seconds left: refreshed first');
        $this->assertSame('Found 3 for x', $ran);
        $this->assertSame('Bearer access-2', end($this->asked)['auth'], 'the call went with the refreshed token');
        $this->assertSame('oauth', (new Connections($scope))->all()[0]['auth']);
        $this->assertStringStartsWith('That sign-in is not one', $connections->finish($query['state'], 'again')['error'], 'a state is used once');
    }

    /**
     * The ways a connection fails without failing an answer: a sign-in that cannot be found,
     * registered with or finished says why; the tools are asked again once the cache lets them go,
     * a server that cannot answer costs its own tools; refresh asks again; a refresh that fails
     * keeps the old token; and a tool of a removed connection says so.
     */
    #[DataProvider('databases')]
    public function testWhenThingsFail(string $type, string $host, int $port): void
    {
        // Arrange
        $scope = $this->person($type, $host, $port);
        $this->configure(['chat' => ['connections' => true]]);
        $down  = false;
        $serve = $this->server('t', 'stale');
        // Nothing unanswered reaches the network: the catch-all says 404.
        $fakes = static function (array $auth) use (&$down, $serve): array {
            return [self::SERVER => function (Http $r) use (&$down, $serve): ClientResponse {
                return $down ? ClientResponse::make('', 503) : $serve($r);
            }] + $auth + ['*' => ClientResponse::make('', 404)];
        };
        $connections = new Connections($scope, 'https://app.test/chat/connected');

        // Act / Assert — a 401 naming no metadata: the origin's well-known address, not there
        Http::fake([self::SERVER => ClientResponse::make('', 401), '*' => ClientResponse::make('', 404)]);
        $this->assertSame('The server\'s sign-in could not be found: It answered HTTP 404.', $connections->add('a', self::SERVER, '')['error']);
        // An authorization server that registers nobody
        Http::fake($fakes([
            self::AUTH . '/resource' => ClientResponse::make(['authorization_servers' => [self::AUTH]]),
            self::AUTH . '/.well-known/oauth-authorization-server' => ClientResponse::make(['authorization_endpoint' => 'x', 'token_endpoint' => 'y']),
        ]));
        $this->assertStringContainsString('offers no client registration', $connections->add('a', self::SERVER, '')['error']);
        Http::fake($fakes([
            self::AUTH . '/resource' => ClientResponse::make(['authorization_servers' => [self::AUTH]]),
            self::AUTH . '/.well-known/oauth-authorization-server' => ClientResponse::make(['authorization_endpoint' => self::AUTH . '/authorize?x=1', 'token_endpoint' => self::AUTH . '/token', 'registration_endpoint' => self::AUTH . '/register']),
            self::AUTH . '/register' => ClientResponse::make(['nothing' => true], 201),
        ]));
        $this->assertSame(['error' => 'The server\'s sign-in did not register this application.'], $connections->add('a', self::SERVER, ''));
        // Registered, but the token endpoint gives nothing
        Http::fake($fakes([
            self::AUTH . '/resource' => ClientResponse::make(['authorization_servers' => [self::AUTH]]),
            self::AUTH . '/.well-known/oauth-authorization-server' => ClientResponse::make(['authorization_endpoint' => self::AUTH . '/authorize?x=1', 'token_endpoint' => self::AUTH . '/token', 'registration_endpoint' => self::AUTH . '/register']),
            self::AUTH . '/register' => ClientResponse::make(['client_id' => 'c'], 201),
            self::AUTH . '/token' => ClientResponse::make(['error' => 'invalid_grant'], 400),
        ]));
        $started = $connections->add('a', self::SERVER, '');
        $this->assertStringStartsWith(self::AUTH . '/authorize?x=1&', $started['authorize'], 'an endpoint with a query keeps it');
        parse_str((string) parse_url($started['authorize'], PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('The sign-in could not be finished', $connections->finish($query['state'], 'c')['error']);

        // A connection whose tools the cache let go, asked again; then a server that is down
        $this->assertSame(['connected' => 'b'], $connections->add('b', self::SERVER, 't'));
        $letGo = static fn () => \Pramnos\Cache\Cache::getInstance('llm')->delete('mcp-tools-' . $scope->userId . '-b');
        $letGo();
        $this->assertArrayHasKey('b__search_issues', (new Connections($scope))->tools(), 'asked again');
        $letGo();
        $down = true;
        $this->assertSame([], (new Connections($scope))->tools(), 'down: its tools, not the answer');
        $this->assertStringContainsString('503', (string) $connections->refresh('b'));
        $down = false;
        $this->assertNull($connections->refresh('b'));
        $this->assertSame('There is no such connection.', $connections->refresh('nope'));
        $run = (new Connections($scope))->tools()['b__search_issues']['run'];
        $connections->remove('b');
        $this->assertSame('Error: the connection b was removed.', $run(['q' => 'x']));

        // A sign-in whose refresh fails keeps the token it had
        $saved = json_decode(\Pramnos\Security\Encrypter::decrypt((string) (new \Pramnos\User\User($scope->userId))->getSetting(Connections::SETTING)), true);
        $saved['servers']['c'] = ['url' => self::SERVER, 'auth' => 'oauth', 'token' => 'stale', 'added_at' => time(),
            'oauth' => ['client_id' => 'c', 'token_endpoint' => self::AUTH . '/token', 'refresh_token' => 'r', 'expires_at' => time() - 5]];
        (new \Pramnos\User\User($scope->userId))->setSetting(Connections::SETTING, \Pramnos\Security\Encrypter::encrypt((string) json_encode($saved)));
        Http::fake($fakes([self::AUTH . '/token' => ClientResponse::make('', 500)]));
        $this->assertSame('Found 3 for y', ((new Connections($scope))->tools()['c__search_issues']['run'])(['q' => 'y']));
        $this->assertSame('Bearer stale', end($this->asked)['auth']);
    }

    /**
     * Through the JSON API: listed, connected and removed — and refused where the application does
     * not offer it.
     */
    #[DataProvider('databases')]
    public function testTheApi(string $type, string $host, int $port): void
    {
        // Arrange
        $scope = $this->person($type, $host, $port);
        Http::fake([self::SERVER => $this->server('t')]);
        $api = new class ($scope) extends \Pramnos\Llm\Chat\Api\ChatController {
            public array $body = [];

            public function __construct(private readonly Scope $me)
            {
            }

            protected function scope(): ?Scope
            {
                return $this->me;
            }

            protected function refusal(string $action): ?array
            {
                return null;
            }

            protected function input(): array
            {
                return $this->body;
            }
        };

        // Act
        $off = $api->connections();
        $this->configure(['chat' => ['connections' => true]]);
        $api->body = ['name' => 'gh', 'url' => self::SERVER, 'token' => 't'];
        $made   = $api->connect();
        $listed = $api->connections();
        $api->body = ['name' => 'bad name', 'url' => self::SERVER];
        $bad    = $api->connect();
        $gone   = $api->disconnect('gh');
        $again  = $api->disconnect('gh');

        // Assert
        $this->assertSame(404, $off['status']);
        $this->assertSame([201, ['connected' => 'gh']], [$made['status'], $made['data']]);
        $this->assertSame('gh', $listed['data']['connections'][0]['name']);
        $this->assertSame(422, $bad['status']);
        $this->assertSame([200, 404], [$gone['status'], $again['status']]);
    }
}
