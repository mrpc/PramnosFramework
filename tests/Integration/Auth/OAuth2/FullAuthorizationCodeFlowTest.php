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

    /**
     * A public client — no secret, PKCE instead — completes the flow.
     *
     * This is what the discovery document promises when it lists `none` among the token
     * endpoint's auth methods, and what a client registered through `/oauth/register` is. An
     * MCP client (Claude.ai, ChatGPT) reads that promise and then does exactly this: the
     * exchange carries a `client_id` and a `code_verifier` and nothing else. Were the server
     * to demand a secret here, every remote connector would fail after the person had already
     * approved the consent screen.
     */
    public function testAPublicClientExchangesItsCodeWithPkceAndNoSecret(): void
    {
        // Arrange — a client registered the way dynamic registration registers one
        $clientId = 'e2e-public-' . bin2hex(random_bytes(6));
        $this->db->queryBuilder()->table('#PREFIX#applications')->insert([
            'name'            => 'Public e2e client',
            'apikey'          => $clientId,
            'apisecret'       => null,
            'callback'        => self::REDIRECT_URI,
            'status'          => 1,
            'is_confidential' => 0,
        ]);
        $server = $this->factory()->createAuthorizationServer();

        $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = [
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => self::REDIRECT_URI,
            'scope'                 => 'read',
            'state'                 => 'st',
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ];

        try {
            $authRequest = $server->validateAuthorizationRequest(
                (new ServerRequest('GET', 'https://self.test/oauth/authorize?' . http_build_query($query)))
                    ->withQueryParams($query)
            );
            $authRequest->setUser($this->approvingUser());
            $authRequest->setAuthorizationApproved(true);
            $redirect = $server->completeAuthorizationRequest($authRequest, new Psr7Response());
            parse_str((string) parse_url($redirect->getHeaderLine('Location'), PHP_URL_QUERY), $callback);

            // Act — the exchange a public client sends: no client_secret at all
            $form = [
                'grant_type'    => 'authorization_code',
                'client_id'     => $clientId,
                'code'          => $callback['code'],
                'redirect_uri'  => self::REDIRECT_URI,
                'code_verifier' => $verifier,
            ];
            $response = $server->respondToAccessTokenRequest(
                (new ServerRequest('POST', 'https://self.test/oauth/token'))->withParsedBody($form),
                new Psr7Response()
            );
            $tokens = json_decode((string) $response->getBody(), true);

            // Assert — a real signed token, which the resource server accepts
            $this->assertSame(200, $response->getStatusCode());
            $this->assertCount(3, explode('.', (string) $tokens['access_token']));
            $this->assertTrue($this->tokenIsAccepted((string) $tokens['access_token']));
        } finally {
            $this->db->queryBuilder()->table('#PREFIX#applications')->where('apikey', $clientId)->delete();
        }
    }

    /**
     * A code issued by the framework's own `/oauth/authorize` is redeemable at `/oauth/token`.
     *
     * The controller used to write a raw hex code to `usertokens` itself, while the token
     * endpoint hands every code to League's `AuthCodeGrant`, which decrypts it — so every code
     * the authorization endpoint ever issued was refused with *Cannot decrypt the authorization
     * code*, after the person had approved the consent screen. Nothing noticed because the other
     * tests here drive League's own authorize step, never the controller's.
     *
     * The exchange also carries `resource` (RFC 8707), as an MCP client's does, and the token's
     * `aud` must name it beside the client id — that is what lets the MCP endpoint refuse a
     * token issued for something else.
     */
    public function testACodeFromTheControllerIsRedeemedAndBoundToTheResource(): void
    {
        // Arrange — the controller and the token endpoint share one factory, as they share
        // one installation's keys in production
        $factory = $this->factory();
        $oauth   = (new \ReflectionClass(\Pramnos\Auth\Controllers\Oauth::class))->newInstanceWithoutConstructor();
        $oauth->application = $this->app;
        (new \ReflectionProperty($oauth, 'oauth2Factory'))->setValue($oauth, $factory);

        $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = (new \ReflectionMethod($oauth, 'generateAuthCode'))->invoke(
            $oauth, self::CLIENT_ID, self::USER_ID, 'read', self::REDIRECT_URI, $challenge, 'S256'
        );

        // Act — the exchange, for a resource
        $resource = 'https://self.test/api/1.0/mcp';
        $response = $factory->forResource($resource)->createAuthorizationServer()->respondToAccessTokenRequest(
            (new ServerRequest('POST', 'https://self.test/oauth/token'))->withParsedBody([
                'grant_type'    => 'authorization_code',
                'client_id'     => self::CLIENT_ID,
                'client_secret' => self::CLIENT_SECRET,
                'code'          => $code,
                'redirect_uri'  => self::REDIRECT_URI,
                'code_verifier' => $verifier,
            ]),
            new Psr7Response()
        );
        $tokens = json_decode((string) $response->getBody(), true);

        // Assert — redeemed, accepted, and audience-bound to both the client and the resource
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertTrue($this->tokenIsAccepted((string) $tokens['access_token']));

        $claims = json_decode((string) base64_decode(strtr(explode('.', $tokens['access_token'])[1], '-_', '+/')), true);
        $this->assertSame([self::CLIENT_ID, $resource], $claims['aud']);
    }

    /**
     * A token from the code flow authenticates an API call — through the middleware the API
     * actually runs, not through League's resource server.
     *
     * `AccessTokenRepository` stores a token by its `jti`, and `User::loadByToken()` looked it
     * up by its whole text, so every such token was verified, matched no row and left the call
     * anonymous: a remote MCP client signed in, consented, received a token and was refused
     * on its first call. The other tests here ask League's resource server whether it accepts
     * the token, which never touches `usertokens` — that is how this passed.
     *
     * Both middlewares are asked, because both read bearer tokens: `ApiAuthMiddleware` for the
     * API routes, `UnifiedAuthMiddleware` where a route opts into it.
     */
    public function testATokenFromTheCodeFlowAuthenticatesAnApiCall(): void
    {
        // Arrange — the middlewares read the installation's public key, so the server signs
        // with the installation's key pair; PreservesAppKeys removes what this creates.
        $keys = new class { use \Pramnos\Tests\Support\PreservesAppKeys { snapshotAppKeys as public; restoreAppKeys as public; } };
        $keys->snapshotAppKeys();

        // Loading the user reads `userdetails` too, and other tests drop it.
        $this->runMigrations([\Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class], $this->db);

        try {
            $factory = new OAuth2ServerFactory(
                $this->controller,
                OAuth2ServerFactory::defaultPrivateKeyPath(),
                OAuth2ServerFactory::defaultPublicKeyPath(),
                base64_encode(random_bytes(32))
            );
            $factory->generateKeyPair();
            $server = $factory->createAuthorizationServer();

            $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $query = [
                'response_type' => 'code', 'client_id' => self::CLIENT_ID, 'redirect_uri' => self::REDIRECT_URI,
                'scope' => 'read', 'state' => 's', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
            ];
            $authRequest = $server->validateAuthorizationRequest(
                (new ServerRequest('GET', 'https://self.test/oauth/authorize?' . http_build_query($query)))
                    ->withQueryParams($query)
            );
            $authRequest->setUser($this->approvingUser());
            $authRequest->setAuthorizationApproved(true);
            $redirect = $server->completeAuthorizationRequest($authRequest, new Psr7Response());
            parse_str((string) parse_url($redirect->getHeaderLine('Location'), PHP_URL_QUERY), $callback);

            $response = $server->respondToAccessTokenRequest(
                (new ServerRequest('POST', 'https://self.test/oauth/token'))->withParsedBody([
                    'grant_type' => 'authorization_code', 'client_id' => self::CLIENT_ID,
                    'client_secret' => self::CLIENT_SECRET, 'code' => $callback['code'],
                    'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => $verifier,
                ]),
                new Psr7Response()
            );
            $token = (string) json_decode((string) $response->getBody(), true)['access_token'];
            \Pramnos\User\User::clearUserCache();

            // Act — the call a client makes next, through each middleware
            $_SERVER['HTTP_APIKEY']        = 'k';
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

            \Pramnos\Http\RequestIdentity::reset();
            $api = (string) (new \Pramnos\Http\Middleware\ApiAuthMiddleware(fn (): bool => true))
                ->handle(\Pramnos\Http\Request::create('/1.0/me', 'GET'), fn (): string => 'reached');
            $apiUser = \Pramnos\Http\RequestIdentity::user();

            \Pramnos\Http\RequestIdentity::reset();
            $unified = (string) (new \Pramnos\Http\Middleware\UnifiedAuthMiddleware())
                ->handle(\Pramnos\Http\Request::create('/1.0/me', 'GET'), fn (): string => 'reached');
            $unifiedUser = \Pramnos\Http\RequestIdentity::user();

            // Assert — both reached the endpoint as the user who approved
            $this->assertSame('reached', $api, $api);
            $this->assertSame(self::USER_ID, (int) $apiUser?->userid);
            $this->assertSame('reached', $unified, $unified);
            $this->assertSame(self::USER_ID, (int) $unifiedUser?->userid);
        } finally {
            unset($_SERVER['HTTP_APIKEY'], $_SERVER['HTTP_AUTHORIZATION'], $_SESSION['usertoken']);
            \Pramnos\Http\RequestIdentity::reset();
            $keys->restoreAppKeys();
        }
    }

    /**
     * `/oauth/userinfo` answers for a token from the code flow, and not for a forged one.
     *
     * It looked the token up by its whole text, and `/oauth/token` stores it by its `jti`, so
     * it answered "Token expired or invalid" for the token this server had just issued —
     * neither OIDC way of reading the user's identity worked. Reported by msdauthserver.
     *
     * The `jti` fallback goes through League's resource server, which checks the signature
     * first: this endpoint checks nothing else, and a token with a real `jti` and a broken
     * signature must not be enough.
     */
    public function testUserinfoAnswersForACodeFlowTokenAndNotForAForgedOne(): void
    {
        // Arrange — a token with the openid scope, through the full flow
        $this->runMigrations([\Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class], $this->db);
        $factory = $this->factory();
        $server  = $factory->createAuthorizationServer();

        $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = [
            'response_type' => 'code', 'client_id' => self::CLIENT_ID, 'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'openid', 'state' => 's', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ];
        $authRequest = $server->validateAuthorizationRequest(
            (new ServerRequest('GET', 'https://self.test/oauth/authorize?' . http_build_query($query)))->withQueryParams($query)
        );
        $authRequest->setUser($this->approvingUser());
        $authRequest->setAuthorizationApproved(true);
        parse_str((string) parse_url(
            $server->completeAuthorizationRequest($authRequest, new Psr7Response())->getHeaderLine('Location'),
            PHP_URL_QUERY
        ), $callback);
        $token = (string) json_decode((string) $server->respondToAccessTokenRequest(
            (new ServerRequest('POST', 'https://self.test/oauth/token'))->withParsedBody([
                'grant_type' => 'authorization_code', 'client_id' => self::CLIENT_ID,
                'client_secret' => self::CLIENT_SECRET, 'code' => $callback['code'],
                'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => $verifier,
            ]),
            new Psr7Response()
        )->getBody(), true)['access_token'];

        $oauth = (new \ReflectionClass(\Pramnos\Auth\Controllers\Oauth::class))->newInstanceWithoutConstructor();
        $oauth->application = $this->app;
        (new \ReflectionProperty($oauth, 'oauth2Factory'))->setValue($oauth, $factory);
        $forged = substr($token, 0, -4) . (substr($token, -4) === 'AAAA' ? 'BBBB' : 'AAAA');

        try {
            // Act
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
            $real = $oauth->userinfo();
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $forged;
            $fake = $oauth->userinfo();
        } finally {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }

        // Assert
        $this->assertSame(200, $real->getStatusCode(), $real->getBody());
        $this->assertSame((string) self::USER_ID, (string) json_decode($real->getBody(), true)['sub']);
        $this->assertSame(401, $fake->getStatusCode(), 'a broken signature must not reach the jti lookup');
    }

    /**
     * With `openid`, the token response carries an ID token: signed with the server's key,
     * headed with the JWKS `kid`, and carrying the nonce the client sent to /oauth/authorize.
     *
     * The discovery document advertised ID tokens and none was ever issued, so an OpenID
     * Connect client got an access token and nothing that said who had signed in. The nonce is
     * what lets the client tie the answer to the request it made; it travels with the code,
     * since League's encrypted code has no room for it.
     */
    public function testOpenidGetsAnIdTokenCarryingTheNonce(): void
    {
        // Arrange — a code from the controller, for `openid email`, with a nonce
        $this->runMigrations([\Pramnos\Framework\Migrations\Auth\CreateUserdetailsTable::class], $this->db);
        $factory = $this->factory();
        $oauth   = (new \ReflectionClass(\Pramnos\Auth\Controllers\Oauth::class))->newInstanceWithoutConstructor();
        $oauth->application = $this->app;
        (new \ReflectionProperty($oauth, 'oauth2Factory'))->setValue($oauth, $factory);
        $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = (new \ReflectionMethod($oauth, 'generateAuthCode'))->invoke(
            $oauth, self::CLIENT_ID, self::USER_ID, 'openid email', self::REDIRECT_URI, $challenge, 'S256', 'n-0S6_WzA2Mj'
        );

        // Act — redeemed as the token endpoint redeems it
        $response = $factory->redeemingCode($code)->createAuthorizationServer()->respondToAccessTokenRequest(
            (new ServerRequest('POST', 'https://self.test/oauth/token'))->withParsedBody([
                'grant_type' => 'authorization_code', 'client_id' => self::CLIENT_ID,
                'client_secret' => self::CLIENT_SECRET, 'code' => $code,
                'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => $verifier,
            ]),
            new Psr7Response()
        );
        $tokens = json_decode((string) $response->getBody(), true);

        // Assert — present, verifiable with the server's public key, and saying the right things
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertArrayHasKey('id_token', $tokens);
        [$header] = explode('.', $tokens['id_token']);
        $this->assertSame('auth-key-1', json_decode((string) base64_decode(strtr($header, '-_', '+/')), true)['kid'], 'the kid the JWKS publishes');
        $claims = \Pramnos\Auth\JWT::decode($tokens['id_token'], (string) file_get_contents(self::$keyDir . '/public.key'), ['RS256']);
        $claims = (array) $claims;
        $this->assertSame((string) self::USER_ID, (string) $claims['sub']);
        $this->assertSame(self::CLIENT_ID, is_array($claims['aud']) ? $claims['aud'][0] : $claims['aud']);
        $this->assertSame('n-0S6_WzA2Mj', $claims['nonce']);
        $this->assertSame('e2e-oauth@example.test', $claims['email'], 'the scope claims, as userinfo gives them');
        $this->assertArrayHasKey('auth_time', $claims);
        $this->assertSame(defined('sURL') ? sURL : '', $claims['iss'], 'the issuer the discovery document names');
    }

    /**
     * Without `openid` there is no ID token: nobody asked for one.
     */
    public function testWithoutOpenidThereIsNoIdToken(): void
    {
        // Arrange
        $factory = $this->factory();
        $oauth   = (new \ReflectionClass(\Pramnos\Auth\Controllers\Oauth::class))->newInstanceWithoutConstructor();
        $oauth->application = $this->app;
        (new \ReflectionProperty($oauth, 'oauth2Factory'))->setValue($oauth, $factory);
        $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = (new \ReflectionMethod($oauth, 'generateAuthCode'))->invoke(
            $oauth, self::CLIENT_ID, self::USER_ID, 'read', self::REDIRECT_URI, $challenge, 'S256'
        );

        // Act
        $tokens = json_decode((string) $factory->redeemingCode($code)->createAuthorizationServer()->respondToAccessTokenRequest(
            (new ServerRequest('POST', 'https://self.test/oauth/token'))->withParsedBody([
                'grant_type' => 'authorization_code', 'client_id' => self::CLIENT_ID,
                'client_secret' => self::CLIENT_SECRET, 'code' => $code,
                'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => $verifier,
            ]),
            new Psr7Response()
        )->getBody(), true);

        // Assert
        $this->assertArrayHasKey('access_token', $tokens);
        $this->assertArrayNotHasKey('id_token', $tokens);
        $this->assertSame([], \Pramnos\Auth\OAuth2\Repositories\AuthCodeRepository::contextOf('no-such-code'));
        $this->assertSame($factory, $factory->redeemingCode('not-a-code'), 'a code that does not decrypt carries nothing');
    }

    /**
     * A client with its own lifetimes gets tokens that last that long — in the JWT, in the
     * response and in the stored rows alike.
     *
     * League sets the server's lifetime on each token before persisting it, and builds the
     * response from the entity after; the client's value is applied in between, so the three
     * places a lifetime shows cannot disagree.
     */
    public function testAClientsOwnLifetimesAreWhatItsTokensLast(): void
    {
        // Arrange — two minutes for access, ten for refresh
        $this->db->queryBuilder()->table('#PREFIX#applications')->where('apikey', self::CLIENT_ID)
            ->update(['access_token_ttl' => 120, 'refresh_token_ttl' => 600]);
        $factory = $this->factory();
        $oauth   = (new \ReflectionClass(\Pramnos\Auth\Controllers\Oauth::class))->newInstanceWithoutConstructor();
        $oauth->application = $this->app;
        (new \ReflectionProperty($oauth, 'oauth2Factory'))->setValue($oauth, $factory);
        $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = (new \ReflectionMethod($oauth, 'generateAuthCode'))->invoke(
            $oauth, self::CLIENT_ID, self::USER_ID, 'read', self::REDIRECT_URI, $challenge, 'S256'
        );
        $before = time();

        // Act
        $tokens = json_decode((string) $factory->redeemingCode($code)->createAuthorizationServer()->respondToAccessTokenRequest(
            (new ServerRequest('POST', 'https://self.test/oauth/token'))->withParsedBody([
                'grant_type' => 'authorization_code', 'client_id' => self::CLIENT_ID,
                'client_secret' => self::CLIENT_SECRET, 'code' => $code,
                'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => $verifier,
            ]),
            new Psr7Response()
        )->getBody(), true);

        // Assert
        $this->assertEqualsWithDelta(120, $tokens['expires_in'], 2, 'the response');
        $claims = json_decode((string) base64_decode(strtr(explode('.', $tokens['access_token'])[1], '-_', '+/')), true);
        $this->assertEqualsWithDelta($before + 120, (int) $claims['exp'], 2, 'the JWT');
        $rows = [];
        $result = $this->db->queryBuilder()->table('#PREFIX#usertokens')->select(['tokentype', 'expires'])
            ->where('userid', self::USER_ID)->whereIn('tokentype', ['access_token', 'refresh_token'])->get();
        while ($result && $result->fetch()) {
            $rows[$result->fields['tokentype']] = (int) $result->fields['expires'];
        }
        $this->assertEqualsWithDelta($before + 120, $rows['access_token'], 2, 'the stored access token');
        $this->assertEqualsWithDelta($before + 600, $rows['refresh_token'], 2, 'the stored refresh token');
    }

    /**
     * The token endpoint refuses a scope outside the client's Allowed Scopes, for every grant.
     *
     * Through `ScopeRepository::finalizeScopes()`, which every League grant calls — so a
     * refresh is held to the list as it is now: narrowing a client takes effect at its next
     * token rather than when its refresh token runs out. An empty list is no restriction.
     */
    public function testTheTokenEndpointHoldsEveryGrantToTheClientsScopes(): void
    {
        // Arrange — a token for `read user`, while the client may have both
        $this->db->queryBuilder()->table('#PREFIX#applications')->where('apikey', self::CLIENT_ID)->update(['scope' => 'read user']);
        $server = $this->factory()->createAuthorizationServer();
        $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = [
            'response_type' => 'code', 'client_id' => self::CLIENT_ID, 'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'read user', 'state' => 's', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ];
        $authRequest = $server->validateAuthorizationRequest(
            (new ServerRequest('GET', 'https://self.test/oauth/authorize?' . http_build_query($query)))->withQueryParams($query)
        );
        $authRequest->setUser($this->approvingUser());
        $authRequest->setAuthorizationApproved(true);
        parse_str((string) parse_url(
            $server->completeAuthorizationRequest($authRequest, new Psr7Response())->getHeaderLine('Location'),
            PHP_URL_QUERY
        ), $callback);
        $tokens = json_decode((string) $server->respondToAccessTokenRequest(
            (new ServerRequest('POST', 'https://self.test/oauth/token'))->withParsedBody([
                'grant_type' => 'authorization_code', 'client_id' => self::CLIENT_ID,
                'client_secret' => self::CLIENT_SECRET, 'code' => $callback['code'],
                'redirect_uri' => self::REDIRECT_URI, 'code_verifier' => $verifier,
            ]),
            new Psr7Response()
        )->getBody(), true);
        $this->assertArrayHasKey('refresh_token', $tokens, 'the code was redeemed within the list');

        // Act — the list narrows to `read`, and the client refreshes, and asks for `user` outright
        // (the same server: the client's row is read on every request, and a second factory
        // would encrypt with a key of its own)
        $this->db->queryBuilder()->table('#PREFIX#applications')->where('apikey', self::CLIENT_ID)->update(['scope' => 'read']);
        $refused = [];
        foreach ([
            ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']],
            ['grant_type' => 'client_credentials', 'scope' => 'user'],
        ] as $body) {
            try {
                $server->respondToAccessTokenRequest(
                    (new ServerRequest('POST', 'https://self.test/oauth/token'))->withParsedBody(
                        $body + ['client_id' => self::CLIENT_ID, 'client_secret' => self::CLIENT_SECRET]
                    ),
                    new Psr7Response()
                );
                $refused[] = 'issued';
            } catch (\League\OAuth2\Server\Exception\OAuthServerException $e) {
                $refused[] = $e->getErrorType();
            }
        }
        // Assert
        $this->assertSame(['invalid_scope', 'invalid_scope'], $refused);
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
    /**
     * The resource server accepts a token whose times are a little ahead of its own clock.
     *
     * With no leeway, a token is refused when the server checking it is a second behind the
     * one that issued it — a resource server on another host, or this host after its clock is
     * corrected backwards. That is what made this suite's PKCE exchange fail now and then on
     * a virtual machine: issued at one second, "not yet valid" the next. The framework's own
     * JWT middleware have always allowed 60 seconds; this is the same allowance.
     */
    public function testTheResourceServerToleratesASmallClockSkew(): void
    {
        // Act
        $server    = $this->factory()->createResourceServer();
        $validator = (new \ReflectionMethod($server, 'getAuthorizationValidator'))->invoke($server);
        $leeway    = (new \ReflectionProperty($validator, 'jwtValidAtDateLeeway'))->getValue($validator);

        // Assert
        $this->assertInstanceOf(\DateInterval::class, $leeway, 'tokens are checked against the clock with no allowance');
        $this->assertSame(OAuth2ServerFactory::CLOCK_LEEWAY_SECONDS, $leeway->s);
    }

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
            // The nonce and auth_time an authorization request leaves for its ID token.
            \Pramnos\Framework\Migrations\Auth\AddOidcContextToUsertokens::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class,
            // The client's redirect URI is longer than the original column, and the
            // server compares it character for character.
            \Pramnos\Framework\Migrations\Applications\WidenApplicationsCallback::class,
            // A public client is one with `is_confidential = 0` — what dynamic registration writes.
            \Pramnos\Framework\Migrations\AuthServer\AddIsConfidentialToApplications::class,
            // A client's own token lifetimes.
            \Pramnos\Framework\Migrations\AuthServer\AddTokenLifetimesToApplications::class,
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
