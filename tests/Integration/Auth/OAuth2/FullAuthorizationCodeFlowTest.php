<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth\OAuth2;

use League\OAuth2\Server\AuthorizationServer;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Auth\OAuth2\Client\Connection;
use Pramnos\Auth\OAuth2\Client\ConnectionStore;
use Pramnos\Auth\OAuth2\Client\OAuthClient;
use Pramnos\Auth\OAuth2\Client\Provider;
use Pramnos\Auth\OAuth2\Entities\UserEntity;
use Pramnos\Auth\OAuth2\OAuth2ServerFactory;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Http\Client;
use Pramnos\Http\ClientResponse;

/**
 * The whole authorization-code flow, end to end, against a real OAuth2 server.
 *
 * Every other test of the client half asserts against a **fake response** — a payload this
 * repository wrote, describing what it believes a provider sends. That proves the client is
 * self-consistent and nothing more. A response shape that is subtly wrong is wrong in the
 * test and in the code at once, and the two agree all the way to production.
 *
 * This test removes that circularity by using the only RFC-compliant OAuth2 server
 * available here: **the framework's own**. `league/oauth2-server` is already a dependency
 * because the framework is an authorization server, so the client half can be pointed at
 * the server half and made to complete a genuine flow —
 *
 *   authorize → consent → real authorization code → real token exchange → real JWT →
 *   real refresh → a new real JWT
 *
 * — with real rows in `usertokens`, real RSA signatures, and real expiry handling. Nothing
 * about the protocol is simulated.
 *
 * **What is still faked, and why that is honest.** `Client::fake()` intercepts the outbound
 * HTTP so the request is handed to the league server in this process instead of travelling
 * over a socket. The transport is replaced; the server is not. What that leaves untested is
 * the socket itself — TLS, redirects, timeouts — which is `Pramnos\Http\Client`'s own
 * responsibility and has its own tests, not this subsystem's.
 *
 * The flow is also exercised **both ways round**: the server's own `AuthCodeGrant` validates
 * the `redirect_uri`, the `client_secret` and the PKCE challenge that the client produced.
 * A failure here is a disagreement between the two halves, and either half could be the one
 * that is wrong — which is the point.
 *
 * **It rebuilds `applications`, and only `applications`.** Other tests create that table
 * with hand-rolled DDL and leave it behind, and a migration is a no-op when the table
 * exists — so whichever ran first would otherwise decide the shape this one gets, and the
 * one that arrives is missing `callback`, the registered redirect URI the server compares
 * the client's against. `OAuth2ClientSecretRequiredTest` established the pattern for the
 * same reason.
 *
 * **What it must not rebuild is `usertokens`.** An earlier version did, and `usertokens`
 * carries a foreign key to `applications`: dropping the child left the constraint dangling
 * and thirty-eight tests with nothing to do with OAuth2 failed afterwards, each pointing
 * at its own tables. Rebuilding the parent is survivable because it is put back in the
 * same breath; rebuilding the child is not.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class FullAuthorizationCodeFlowTest extends TestCase
{
    private const CLIENT_ID     = 'e2e-client-id';
    private const CLIENT_SECRET = 'e2e-client-secret';
    private const REDIRECT_URI  = 'https://app.test/connect/callback';
    private const USER_ID       = 4242;


    private Database $db;
    private Application $app;
    private Controller $controller;
    private AuthorizationServer $server;
    private ?string $originalAppKey = null;

    /**
     * The RSA key pair, generated once for the class.
     *
     * 2048-bit generation is a few hundred milliseconds and the keys are read-only once
     * written, which is exactly the case the Testing Guide says belongs in
     * `setUpBeforeClass()` rather than in `setUp()`.
     */
    private static string $keyDir = '';

    /**
     * A key pair of this class's own, so the test neither reads nor writes the
     * installation's — `app/keys/` belongs to whatever is deployed here.
     *
     * `generateKeyPair()` is the framework's own generator, so the signatures below are
     * produced exactly as a real server produces them.
     */
    public static function setUpBeforeClass(): void
    {
        self::$keyDir = sys_get_temp_dir() . '/pramnos-oauth-e2e-' . bin2hex(random_bytes(6));
        @mkdir(self::$keyDir, 0700, true);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ((array) glob(self::$keyDir . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir(self::$keyDir);
    }

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');

        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect(true);
        }

        $this->originalAppKey = getenv('APP_KEY') ?: null;
        putenv('APP_KEY=base64:' . base64_encode(random_bytes(32)));

        $this->app        = $this->makeApp();
        $this->controller = $this->makeController();

        $this->migrate();
        $this->registerUser();
        $this->registerClient();

        $this->server = $this->factory()->createAuthorizationServer();

        $this->bridgeHttpToTheServer();
    }

    protected function tearDown(): void
    {
        Client::resetFakes();

        $this->db->queryBuilder()->table('#PREFIX#usertokens')->where('userid', self::USER_ID)->delete();
        $this->db->queryBuilder()->table('#PREFIX#applications')->where('apikey', self::CLIENT_ID)->delete();
        $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', self::USER_ID)->delete();
        $this->db->schema()->dropTableIfExists('#PREFIX#oauthconnections');

        if ($this->originalAppKey === null) {
            putenv('APP_KEY');
        } else {
            putenv('APP_KEY=' . $this->originalAppKey);
        }

        parent::tearDown();
    }

    /**
     * The whole thing: authorize, consent, exchange, store, use, refresh.
     *
     * One test rather than six, because the steps are not independent — each consumes
     * something the previous one produced, and a code or a token that only exists because
     * a fixture created it would not prove the halves agree. The assertions in between are
     * where the interesting failures would show.
     */
    public function testAUserConnectsAnAccountAndTheTokenIsRefreshed(): void
    {
        $provider = $this->provider();
        $client   = new OAuthClient($provider);

        // ── 1. The application sends the user to the provider ────────────────────────
        [$url, $state, $verifier] = $client->authorizationUrl();

        // ── 2. The provider — the framework's own server — reads that URL ────────────
        // If the client built it wrongly, this is where it fails: the server validates the
        // client id, the redirect URI and the PKCE challenge before anything is issued.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $authRequest = $this->server->validateAuthorizationRequest(
            new ServerRequest('GET', $url, [], null, '1.1', [], $query)
        );

        $this->assertSame(self::CLIENT_ID, $authRequest->getClient()->getIdentifier());
        $this->assertSame(self::REDIRECT_URI, $authRequest->getRedirectUri());

        // ── 3. The user approves ─────────────────────────────────────────────────────
        $authRequest->setUser($this->approvingUser());
        $authRequest->setAuthorizationApproved(true);

        $redirect = $this->server->completeAuthorizationRequest($authRequest, new Psr7Response());

        // The provider redirects back with a code and the state it was given.
        parse_str(
            (string) parse_url($redirect->getHeaderLine('Location'), PHP_URL_QUERY),
            $callback
        );
        $this->assertNotEmpty($callback['code'], 'the server must issue an authorization code');

        // ── 4. The application checks the state it stored ────────────────────────────
        $this->assertTrue(
            $client->verifyState($state, $callback['state']),
            'the state must survive the round trip through the provider unchanged'
        );

        // ── 5. …and exchanges the code ───────────────────────────────────────────────
        // A real exchange: the server checks the client secret and the PKCE verifier
        // against the challenge from step 1, and signs a JWT with the key pair above.
        $tokens = $client->exchange($callback['code'], $verifier);

        $this->assertNotSame('', $tokens->accessToken);
        $this->assertNotSame('', $tokens->refreshToken);
        $this->assertNotNull($tokens->expiresAt);
        // Three dot-separated segments: this is a signed JWT, not a string the test wrote.
        $this->assertCount(3, explode('.', $tokens->accessToken));

        // ── 6. The connection is stored ──────────────────────────────────────────────
        $store = new ConnectionStore($this->db);
        $store->save(self::USER_ID, $provider->name, $tokens, 'acct-1', 'E2E Account');

        $connection = $store->find(self::USER_ID, $provider->name, 'acct-1');
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame($tokens->accessToken, $connection->accessToken);
        $this->assertFalse($connection->isDead());

        // …and it came back out of the database, through encryption, byte for byte. A
        // token that survives a round trip but not intact is a token every API call
        // rejects, for a reason nothing in this subsystem would report.
        $this->assertSame(
            $tokens->accessToken,
            $connection->accessToken,
            'the stored access token must decrypt to exactly what the server issued'
        );

        // ── 7. The token the server issued is one the server accepts ────────────────
        // The proof that step 5 produced a usable credential rather than a well-shaped
        // string: the framework's own resource server validates the signature and expiry.
        $this->assertTrue($this->tokenIsAccepted($connection->accessToken));

        // ── 8. The scheduled refresh renews it ──────────────────────────────────────
        $refreshed = $store->refresh($connection, $client);

        $this->assertNotSame(
            $connection->accessToken,
            $refreshed->accessToken,
            'a refresh must produce a new access token, not return the old one'
        );
        $this->assertNotSame('', $refreshed->refreshToken);
        $this->assertTrue($this->tokenIsAccepted($refreshed->accessToken));

        // And the new tokens are what is on disk, which is what the next run will present.
        $stored = $store->find(self::USER_ID, $provider->name, 'acct-1');
        $this->assertSame($refreshed->accessToken, $stored->accessToken);
        $this->assertSame($refreshed->refreshToken, $stored->refreshToken);
    }

    /**
     * A tampered `state` is refused by the application before the code is spent.
     *
     * The login-CSRF defence, exercised against a genuine code rather than an invented
     * one — so what is asserted is that the check happens *first*, while the code is still
     * valid and would otherwise have been exchanged successfully.
     */
    public function testATamperedStateIsRejectedWhileTheCodeIsStillValid(): void
    {
        $client = new OAuthClient($this->provider());

        // Arrange — a real, unspent authorization code.
        [$url, $state, $verifier] = $client->authorizationUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $authRequest = $this->server->validateAuthorizationRequest(
            new ServerRequest('GET', $url, [], null, '1.1', [], $query)
        );
        $authRequest->setUser($this->approvingUser());
        $authRequest->setAuthorizationApproved(true);
        $redirect = $this->server->completeAuthorizationRequest($authRequest, new Psr7Response());
        parse_str((string) parse_url($redirect->getHeaderLine('Location'), PHP_URL_QUERY), $callback);

        // Act & Assert — an attacker's state against our stored one.
        $this->assertFalse($client->verifyState($state, 'not-the-state-we-issued'));

        // The code really was good: had the application not checked, it would have worked.
        $tokens = $client->exchange($callback['code'], $verifier);
        $this->assertNotSame('', $tokens->accessToken);
    }

    /**
     * The PKCE verifier has to be the one that produced the challenge.
     *
     * Asserted against the server's own check rather than our own, because ours cannot
     * fail it — the client sends whatever verifier it is given. This is the property an
     * interceptor with a stolen code runs into, and it only exists if the challenge the
     * client computed in step 1 is genuinely the SHA-256 of the verifier it kept.
     */
    public function testAWrongPkceVerifierIsRefusedByTheServer(): void
    {
        $client = new OAuthClient($this->provider());

        // Arrange
        [$url] = $client->authorizationUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $authRequest = $this->server->validateAuthorizationRequest(
            new ServerRequest('GET', $url, [], null, '1.1', [], $query)
        );
        $authRequest->setUser($this->approvingUser());
        $authRequest->setAuthorizationApproved(true);
        $redirect = $this->server->completeAuthorizationRequest($authRequest, new Psr7Response());
        parse_str((string) parse_url($redirect->getHeaderLine('Location'), PHP_URL_QUERY), $callback);

        // Act & Assert — somebody else's verifier for our challenge.
        $wrong = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->expectException(\Pramnos\Auth\OAuth2\Client\OAuthClientException::class);
        $client->exchange($callback['code'], $wrong);
    }

    // -------------------------------------------------------------------------
    // The bridge, and the fixtures
    // -------------------------------------------------------------------------

    /**
     * Hand the client's outbound token requests to the league server in this process.
     *
     * The one piece of stagecraft in this file, and it replaces the socket only: the
     * request the client actually built is translated into PSR-7 and answered by
     * `respondToAccessTokenRequest()`, which is the same method the framework's own
     * `/oauth/token` controller calls.
     *
     * League signals a refusal by throwing `OAuthServerException`, which carries the
     * RFC 6749 error payload — converted back into a response here so the client sees what
     * a real provider would send it, rather than an exception from the wrong layer.
     */
    private function bridgeHttpToTheServer(): void
    {
        Client::fake([
            'https://self.test/*' => function (Client $request): ClientResponse {
                $body    = (string) (new \ReflectionProperty(Client::class, 'body'))->getValue($request);
                $headers = (array) (new \ReflectionProperty(Client::class, 'headers'))->getValue($request);
                $url     = (string) (new \ReflectionProperty(Client::class, 'url'))->getValue($request);

                parse_str($body, $form);

                $psrRequest = new ServerRequest('POST', $url, $headers, $body);
                $psrRequest = $psrRequest->withParsedBody($form);

                /*
                 * The client authenticated with a Basic header, which is where league
                 * looks for `PHP_AUTH_USER` / `PHP_AUTH_PW` rather than at the header —
                 * the same asymmetry `ClientCredentialsAuthTrait` documents for the
                 * inbound side. Decoded here so the server sees the credentials the
                 * client genuinely sent.
                 */
                $authorization = $headers['Authorization'] ?? '';
                if (preg_match('/^Basic\s+(.+)$/i', $authorization, $matches)) {
                    $decoded = base64_decode($matches[1], true);
                    if ($decoded !== false && str_contains($decoded, ':')) {
                        [$id, $secret] = explode(':', $decoded, 2);
                        $psrRequest = $psrRequest->withParsedBody(
                            $form + ['client_id' => $id, 'client_secret' => $secret]
                        );
                    }
                }

                try {
                    $response = $this->server->respondToAccessTokenRequest($psrRequest, new Psr7Response());
                } catch (\League\OAuth2\Server\Exception\OAuthServerException $exception) {
                    $response = $exception->generateHttpResponse(new Psr7Response());
                }

                return ClientResponse::make(
                    (string) $response->getBody(),
                    $response->getStatusCode()
                );
            },
        ]);
    }

    /**
     * A server factory over this class's key pair.
     *
     * `generateKeyPair()` returns early when both files exist, so the first call writes
     * them and every later one is a no-op — which is what makes one pair per class rather
     * than one per test, without the callers having to know.
     */
    private function factory(): OAuth2ServerFactory
    {
        $factory = new OAuth2ServerFactory(
            $this->controller,
            self::$keyDir . '/private.key',
            self::$keyDir . '/public.key',
            base64_encode(random_bytes(32))
        );
        $factory->generateKeyPair();

        return $factory;
    }

    /** Does the framework's own resource server accept this token? */
    private function tokenIsAccepted(string $accessToken): bool
    {
        try {
            $this->factory()->createResourceServer()->validateAuthenticatedRequest(
                new ServerRequest('GET', 'https://self.test/api/me', [
                    'Authorization' => 'Bearer ' . $accessToken,
                ])
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function provider(): Provider
    {
        return new Provider(
            name: 'self',
            authorizeUrl: 'https://self.test/oauth/authorize',
            tokenUrl: 'https://self.test/oauth/token',
            clientId: self::CLIENT_ID,
            clientSecret: self::CLIENT_SECRET,
            redirectUri: self::REDIRECT_URI,
            scopes: ['read', 'user'],
            usePkce: true,
        );
    }

    /**
     * The user who approves the consent screen.
     *
     * `UserEntity` has **no constructor** — it is hydrated with `setIdentifier()`, because
     * the password grant builds it from a repository lookup. `new UserEntity($id)` is
     * accepted by PHP and discards the argument, so the identifier stays null, reaches
     * `persistNewAuthCode()` as `(int) null` and the authorization code is written against
     * user 0. The only thing that objects is the foreign key, several layers down, with a
     * message about `usertokens` that names neither the entity nor the user.
     */
    private function approvingUser(): UserEntity
    {
        $user = new UserEntity();
        $user->setIdentifier((string) self::USER_ID);

        return $user;
    }

    /**
     * A real user row, because `usertokens.userid` is a real foreign key.
     *
     * Worth the two lines rather than working around: the constraint is the server's, and
     * a flow that issued a code for a user who does not exist would be proving something
     * nobody wants proved.
     */
    private function registerUser(): void
    {
        $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', self::USER_ID)->delete();

        $this->db->queryBuilder()->table('#PREFIX#users')->insert([
            'userid'    => self::USER_ID,
            'username'  => 'e2e-oauth-user',
            'email'     => 'e2e-oauth@example.test',
            'active'    => 1,
            // Declared NOT NULL with no default in the canonical schema, so they are
            // supplied rather than left to the engine — MySQL in a permissive mode would
            // invent them and PostgreSQL would refuse, which is a difference this test
            // has no interest in discovering.
            'usertype'  => 0,
            'sex'       => 0,
            'birthdate' => 0,
            'modified'  => time(),
            'regdate'   => time(),
        ]);
    }

    /** The client, as an application row — which is how this server stores one. */
    private function registerClient(): void
    {
        $this->db->queryBuilder()->table('#PREFIX#applications')->where('apikey', self::CLIENT_ID)->delete();

        $this->db->queryBuilder()->table('#PREFIX#applications')->insert([
            'name'      => 'End-to-end test client',
            'apikey'    => self::CLIENT_ID,
            'apisecret' => self::CLIENT_SECRET,
            'callback'  => self::REDIRECT_URI,
            'status'    => 1,
        ]);
    }

    /**
     * The four tables this flow touches, built from their canonical migrations.
     *
     * `applications` is dropped and rebuilt, the way `OAuth2ClientSecretRequiredTest`
     * already does: other tests create it with hand-rolled DDL and leave it behind, and a
     * migration is a no-op when the table exists — so whichever ran first would otherwise
     * decide the shape this gets, and the one that arrives is missing `callback`. That
     * column is the registered redirect URI the server compares the client's against,
     * which is the check this test exists to exercise.
     */
    private function migrate(): void
    {
        $this->db->schema()->dropTableIfExists('#PREFIX#applications');

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUsertokensTable::class,
            // `token_lookup` is how a token is found without a full-table scan over
            // hashes; `User\Token::storageFor()` writes it on every insert, so the
            // server cannot persist an authorization code without the column.
            \Pramnos\Framework\Migrations\Auth\AddTokenLookupToUsertokens::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class,
            // The client's redirect URI is longer than the original column, and the
            // server compares it character for character.
            \Pramnos\Framework\Migrations\Applications\WidenApplicationsCallback::class,
            \Pramnos\Framework\Migrations\Oauth\CreateOauthconnectionsTable::class,
        ], $this->db);

        foreach (['users', 'usertokens', 'applications', 'oauthconnections'] as $table) {
            $this->assertTrue(
                $this->db->schema()->hasTable('#PREFIX#' . $table),
                $table . ' is required for this flow'
            );
        }
    }

    /**
     * Run a list of migration classes against this test's connection.
     *
     * The same helper `Framework\Testing\BaseTestCase` offers, inlined because this class
     * extends PHPUnit's TestCase directly — it needs a connection of its own rather than
     * the singleton, which other tests in this run will have pointed elsewhere.
     *
     * @param list<class-string> $migrationClasses
     */
    private function runMigrations(array $migrationClasses, Database $db): void
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $db;

        foreach ($migrationClasses as $class) {
            (new $class($app))->up();
        }
    }

    private function makeApp(): Application
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $app */
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;

        return $app;
    }

    private function makeController(): Controller
    {
        $controller = $this->getMockBuilder(Controller::class)
            ->disableOriginalConstructor()
            ->getMock();
        $controller->application = $this->app;

        return $controller;
    }
}
