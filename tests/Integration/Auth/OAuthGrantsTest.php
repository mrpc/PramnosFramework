<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\Oauth;
use Pramnos\Auth\JWT;
use Pramnos\Auth\OAuth2\GrantPolicy;
use Pramnos\Auth\OAuth2\Grants\DeviceCodeGrant;
use Pramnos\Auth\OAuth2\Grants\JwtBearerGrant;
use Pramnos\Auth\OAuth2\Grants\TokenExchangeGrant;
use Pramnos\Auth\OAuth2\JwtAssertion;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Schema;

/**
 * The device, jwt-bearer and token-exchange grants, through the real token endpoint.
 *
 * The discovery document advertised the device flow, the device authorization endpoint and the
 * `/device` page wrote their rows — and no grant read them, so a device could start the flow and
 * never finish it. The jwt-bearer grant and token exchange were grant types an application could
 * be given in policy, with nothing behind them. Each test below drives `/oauth/token` with what a
 * client sends and reads back what it would receive.
 */
#[CoversClass(DeviceCodeGrant::class)]
#[CoversClass(JwtBearerGrant::class)]
#[CoversClass(TokenExchangeGrant::class)]
#[CoversClass(GrantPolicy::class)]
#[CoversClass(\Pramnos\Auth\OAuth2\Grants\AuthenticatesClient::class)]
class OAuthGrantsTest extends BaseTestCase
{
    use \Pramnos\Tests\Support\PreservesAppKeys;

    private const APP = 990501;

    private const OTHER_APP = 990502;

    private const USER = 990503;

    /** One RSA pair for the class: the client's own signing key. */
    private static ?array $clientKey = null;

    private Database $db;

    private ?Database $previous = null;

    private Oauth $controller;

    /** The engine under test: the suite's own connection here, PostgreSQL in the subclass. */
    protected function connection(): Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        return $db;
    }

    protected function setUp(): void
    {
        $this->snapshotAppKeys();
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');

        $this->previous = Factory::getDatabase();
        $this->db       = $this->connection();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;

        foreach (['users', 'applications', 'usertokens', 'authserver.oauth2_device_codes',
                  'applications.oauth2_application_grants', 'authserver.jwt_replay_prevention',
                  'authserver.loginlockouts'] as $table) {
            Schema::table($table, $this->db);
        }
        $this->cleanUp();

        if (self::$clientKey === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$clientKey = [$private, openssl_pkey_get_details($key)['key']];
        }

        $this->db->queryBuilder()->table('users')->insert([
            'userid' => self::USER, 'username' => 'grantee', 'email' => 'grantee@example.com', 'active' => 1,
            'password' => \Pramnos\Auth\PasswordHash::make('right-password'),
            'usertype' => 0, 'sex' => 0, 'birthdate' => 0, 'modified' => 0,
        ]);
        foreach ([[self::APP, 'grants-app'], [self::OTHER_APP, 'other-app']] as [$appId, $clientId]) {
            $this->db->queryBuilder()->table('applications')->insert([
                'appid' => $appId, 'name' => $clientId, 'status' => 1, 'apikey' => $clientId,
                'apisecret' => 'secret', 'public_key' => self::$clientKey[1], 'scope' => 'openid profile email',
            ]);
        }

        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->controller = new Oauth(new Application());
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        $_POST   = [];
        $_SERVER = [];
        $singleton = &Factory::getDatabase();
        $singleton = $this->previous;
        $this->restoreAppKeys();
    }

    /** Remove this class's rows. */
    private function cleanUp(): void
    {
        $this->db->queryBuilder()->table('applications.oauth2_application_grants')->whereIn('appid', [self::APP, self::OTHER_APP])->delete();
        $this->db->queryBuilder()->table('authserver.oauth2_device_codes')->whereIn('client_id', ['grants-app', 'other-app'])->delete();
        $this->db->queryBuilder()->table('usertokens')->where('userid', self::USER)->delete();
        $this->db->queryBuilder()->table('usertokens')->whereIn('applicationid', [self::APP, self::OTHER_APP])->delete();
        $this->db->queryBuilder()->table('applications')->whereIn('appid', [self::APP, self::OTHER_APP])->delete();
        $this->db->queryBuilder()->table('users')->where('userid', self::USER)->delete();
        $this->db->queryBuilder()->table('authserver.loginlockouts')->where('lookupvalue', 'grantee')->delete();
    }

    /**
     * POST to the token endpoint as the client `grants-app`.
     *
     * @return array{0: int, 1: array<string, mixed>} The status and the decoded body
     */
    private function token(array $params, string $clientId = 'grants-app', ?string $secret = 'secret'): array
    {
        $_POST = $params + ['client_id' => $clientId] + ($secret !== null ? ['client_secret' => $secret] : []);
        $response = $this->controller->token();

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true) ?? []];
    }

    /** A device authorization for `grants-app`. Returns the device code. */
    private function startDevice(string $clientId = 'grants-app'): string
    {
        $_POST = ['client_id' => $clientId, 'scope' => 'openid profile'];

        return json_decode((string) $this->controller->deviceauthorization()->getBody(), true)['device_code'];
    }

    /** What the `/device` page writes when the user answers. */
    private function answer(string $deviceCode, string $status): void
    {
        $this->db->queryBuilder()->table('authserver.oauth2_device_codes')->where('device_code', $deviceCode)
            ->update(['status' => $status, 'user_id' => self::USER, 'authorized_at' => time()]);
    }

    /** A poll of the token endpoint with a device code. */
    private function poll(string $deviceCode, string $grantType = 'urn:ietf:params:oauth:grant-type:device_code'): array
    {
        return $this->token(['grant_type' => $grantType, 'device_code' => $deviceCode]);
    }

    /** An assertion signed with the client's key: a jwt-bearer grant's, or a client authentication's. */
    private function assertion(array $override = []): string
    {
        return JWT::encode(array_merge([
            'iss' => 'grants-app', 'sub' => 'grantee@example.com', 'aud' => JwtAssertion::audiences()[0],
            'iat' => time(), 'exp' => time() + 60, 'jti' => bin2hex(random_bytes(8)),
        ], $override), self::$clientKey[0], 'RS256');
    }

    /** Whether a stored access token is active, found as introspection finds it. */
    private function isActive(string $accessToken): bool
    {
        $jti = JWT::decodeUnverified($accessToken)->jti ?? $accessToken;

        return (int) $this->db->queryBuilder()->table('usertokens')
            ->where('token_lookup', \Pramnos\User\Token::lookup((string) $jti))->value('status') === 1;
    }

    // ── Device code ──────────────────────────────────────────────────────────

    /**
     * The whole device flow: wait, slow down, then tokens once the user approves — once.
     */
    public function testTheDeviceFlowCompletes(): void
    {
        // Arrange
        $deviceCode = $this->startDevice();

        // Act + Assert — the user has not answered
        [$status, $body] = $this->poll($deviceCode);
        $this->assertSame([400, 'authorization_pending'], [$status, $body['error']]);
        // Polled again at once: faster than the interval
        $this->assertSame('slow_down', $this->poll($deviceCode)[1]['error']);

        // The user approves on /device
        $this->answer($deviceCode, 'authorized');
        [$status, $body] = $this->poll($deviceCode);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertArrayHasKey('access_token', $body);
        $this->assertArrayHasKey('refresh_token', $body);
        $this->assertSame((string) self::USER, JWT::decodeUnverified($body['access_token'])->sub);

        // A device code is good for one set of tokens.
        $this->assertSame('invalid_grant', $this->poll($deviceCode)[1]['error']);
    }

    /**
     * Refused, expired, and somebody else's device code each have their own answer.
     */
    public function testTheDeviceFlowsOtherEndings(): void
    {
        // Arrange
        $denied  = $this->startDevice();
        $expired = $this->startDevice();
        $theirs  = $this->startDevice('other-app');
        $this->answer($denied, 'denied');
        $this->db->queryBuilder()->table('authserver.oauth2_device_codes')->where('device_code', $expired)->update(['expires_at' => time() - 1]);
        $this->answer($theirs, 'authorized');

        // Act + Assert
        $this->assertSame('access_denied', $this->poll($denied)[1]['error']);
        $this->assertSame('expired_token', $this->poll($expired)[1]['error']);
        $this->assertSame('invalid_grant', $this->poll($theirs)[1]['error'], 'issued to another client');
    }

    /**
     * The bare `device_code` grant type is accepted, as older clients of this server send it.
     */
    public function testTheBareGrantTypeNameIsAccepted(): void
    {
        // Arrange
        $deviceCode = $this->startDevice();
        $this->answer($deviceCode, 'authorized');

        // Act
        [$status] = $this->poll($deviceCode, 'device_code');

        // Assert
        $this->assertSame(200, $status);
    }

    /**
     * An application whose policy disables the device grant cannot use it.
     */
    public function testThePolicyCanWithholdTheDeviceGrant(): void
    {
        // Arrange
        GrantPolicy::disable(self::APP, 'device_code');
        $deviceCode = $this->startDevice();
        $this->answer($deviceCode, 'authorized');

        // Act
        [$status, $body] = $this->poll($deviceCode);

        // Assert
        $this->assertSame([400, 'unauthorized_client'], [$status, $body['error']]);
    }

    // ── JWT bearer ───────────────────────────────────────────────────────────

    /**
     * Without the policy row, the jwt-bearer grant is refused: it is never a default.
     */
    public function testTheJwtBearerGrantIsOptIn(): void
    {
        // Act
        [$status, $body] = $this->token(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $this->assertion()]);

        // Assert
        $this->assertSame([400, 'unauthorized_client'], [$status, $body['error']]);
    }

    /**
     * Enabled, a signed assertion naming a user by email gets a token for that user — once.
     */
    public function testTheJwtBearerGrantIssuesForTheNamedUser(): void
    {
        // Arrange
        GrantPolicy::enable(self::APP, 'jwt_bearer');
        $assertion = $this->assertion(['scope' => 'ignored']);

        // Act
        [$status, $body]   = $this->token(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion, 'scope' => 'profile']);
        [$again, $replay]  = $this->token(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]);
        [, $byUsername]    = $this->token(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $this->assertion(['sub' => 'grantee'])]);

        // Assert
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame((string) self::USER, JWT::decodeUnverified($body['access_token'])->sub);
        $this->assertArrayNotHasKey('refresh_token', $body, 'the service asserts again rather than refreshing');
        $this->assertSame([400, 'invalid_grant'], [$again, $replay['error']], 'a replayed assertion');
        $this->assertArrayHasKey('access_token', $byUsername, 'sub by username');
    }

    /**
     * An assertion that is not the client's, or names nobody, is refused.
     */
    public function testAJwtBearerAssertionThatDoesNotCheckOutIsRefused(): void
    {
        // Arrange
        GrantPolicy::enable(self::APP, 'jwt_bearer');

        // Act + Assert
        $this->assertSame('invalid_grant', $this->token(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $this->assertion(['sub' => 'nobody@example.com'])])[1]['error']);
        $this->assertSame('invalid_grant', $this->token(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $this->assertion(['iss' => 'other-app'])])[1]['error']);
        $this->assertSame('invalid_request', $this->token(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer'])[1]['error']);
    }

    /**
     * The client may authenticate with an assertion of its own instead of its secret.
     */
    public function testTheClientMayAuthenticateWithAnAssertion(): void
    {
        // Arrange
        GrantPolicy::enable(self::APP, 'jwt_bearer');
        $clientAssertion = $this->assertion(['sub' => 'grants-app']);

        // Act
        [$status, $body] = $this->token([
            'grant_type'            => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'             => $this->assertion(),
            'client_assertion'      => $clientAssertion,
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        ], 'grants-app', null);
        [$wrong] = $this->token([
            'grant_type'            => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'             => $this->assertion(),
            'client_assertion'      => $this->assertion(),
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        ], 'grants-app', null);

        // Assert
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(401, $wrong, 'a client assertion must be about the client');
    }

    /**
     * A wrong secret is refused before anything else is looked at.
     */
    public function testAWrongSecretIsRefused(): void
    {
        // Arrange
        GrantPolicy::enable(self::APP, 'jwt_bearer');

        // Act
        [$status, $body] = $this->token(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $this->assertion()], 'grants-app', 'wrong');

        // Assert
        $this->assertSame([401, 'invalid_client'], [$status, $body['error']]);
    }

    // ── Token exchange ───────────────────────────────────────────────────────

    /** An access token for the user, issued to `grants-app` through the device flow. */
    private function userToken(string $clientId = 'grants-app'): string
    {
        $deviceCode = $this->startDevice($clientId);
        $this->answer($deviceCode, 'authorized');
        $_POST = ['grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $deviceCode, 'client_id' => $clientId, 'client_secret' => 'secret'];

        return json_decode((string) $this->controller->token()->getBody(), true)['access_token'];
    }

    /**
     * An access token is exchanged for a sixty-day one with no refresh token, and stops working.
     */
    public function testAnAccessTokenIsExchangedForALongLivedOne(): void
    {
        // Arrange
        $short = $this->userToken();

        // Act
        [$status, $body] = $this->token([
            'grant_type'         => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token'      => $short,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
        ]);

        // Assert
        $this->assertSame(200, $status, json_encode($body));
        $this->assertEqualsWithDelta(5184000, $body['expires_in'], 5);
        $this->assertArrayNotHasKey('refresh_token', $body);
        $this->assertSame((string) self::USER, JWT::decodeUnverified($body['access_token'])->sub);
        $this->assertFalse($this->isActive($short), 'the token exchanged is revoked');
        $this->assertTrue($this->isActive($body['access_token']));
    }

    /**
     * `exchange_token`, the older name, works; another client's token and a spent one do not.
     */
    public function testTheExchangeAliasAndItsRefusals(): void
    {
        // Arrange
        $mine   = $this->userToken();
        $theirs = $this->userToken('other-app');

        // Act
        [$status]      = $this->token(['grant_type' => 'exchange_token', 'subject_token' => $mine]);
        [, $spent]     = $this->token(['grant_type' => 'exchange_token', 'subject_token' => $mine]);
        [, $foreign]   = $this->token(['grant_type' => 'exchange_token', 'subject_token' => $theirs]);
        [, $wrongType] = $this->token(['grant_type' => 'exchange_token', 'subject_token' => $theirs, 'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token']);

        // Assert
        $this->assertSame(200, $status);
        $this->assertSame('invalid_grant', $spent['error'], 'already exchanged');
        $this->assertSame('invalid_grant', $foreign['error'], 'issued to another client');
        $this->assertSame('invalid_request', $wrongType['error']);
        $this->assertTrue($this->isActive($theirs), 'the other client\'s token is untouched');
    }

    /**
     * Disabling a grant keeps the row, and the grant stops working.
     */
    public function testAGrantCanBeDisabledAgain(): void
    {
        // Arrange
        GrantPolicy::enable(self::APP, 'jwt_bearer');
        GrantPolicy::disable(self::APP, 'jwt_bearer');

        // Act + Assert
        $this->assertFalse(GrantPolicy::allows(self::APP, 'jwt_bearer'));
        $this->assertTrue(GrantPolicy::allows(self::OTHER_APP, 'device_code'), 'no rows: the defaults');
    }

    /**
     * A row overrides its one grant and leaves the others to the defaults: enabling jwt_bearer
     * must not silently take authorization_code away, and disabling password must take only
     * password.
     */
    public function testARowOverridesOnlyItsOwnGrant(): void
    {
        // Arrange
        GrantPolicy::enable(self::APP, 'jwt_bearer');
        GrantPolicy::disable(self::APP, 'password');

        // Act
        $effective = GrantPolicy::effective(self::APP);

        // Assert
        $this->assertTrue(GrantPolicy::allows(self::APP, 'jwt_bearer'));
        $this->assertFalse(GrantPolicy::allows(self::APP, 'password'));
        $this->assertTrue(GrantPolicy::allows(self::APP, 'authorization_code'), 'no row: the default still applies');
        $this->assertTrue(GrantPolicy::allows(self::APP, 'refresh_token'));
        $this->assertContains('jwt_bearer', $effective);
        $this->assertNotContains('password', $effective);
        $this->assertContains('client_credentials', $effective);
    }

    // ── Password grant and the account lockout ───────────────────────────────

    /**
     * The password grant shares the login form's lockout. Before, a failure at /oauth/token was
     * never counted and a locked account was never refused there, so the token endpoint was an
     * unlimited password-guessing oracle beside a form that locked after three tries.
     */
    public function testThePasswordGrantCountsTowardAndHonoursTheLockout(): void
    {
        // Arrange — three wrong passwords lock the account (Loginlockout::DEFAULT_STEPS)
        $params = ['grant_type' => 'password', 'username' => 'grantee', 'scope' => 'profile'];
        for ($i = 0; $i < 3; $i++) {
            [$status, $body] = $this->token($params + ['password' => 'wrong']);
            $this->assertSame([400, 'invalid_grant'], [$status, $body['error']]);
        }

        // Act — now the right password
        $_POST    = $params + ['password' => 'right-password', 'client_id' => 'grants-app', 'client_secret' => 'secret'];
        $response = $this->controller->token();
        $body     = json_decode((string) $response->getBody(), true);

        // Assert — refused while locked, and told when to try again
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('invalid_grant', $body['error']);
        $this->assertStringContainsString('locked', $body['error_description']);
        $retryAfter = (int) $response->getHeaderLine('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
    }

    /**
     * A successful password grant clears the failures before it, as a successful sign-in does:
     * two mistakes and a success leave no count for the next mistake to add to.
     */
    public function testASuccessfulPasswordGrantClearsTheFailures(): void
    {
        // Arrange
        $params = ['grant_type' => 'password', 'username' => 'grantee', 'scope' => 'profile'];
        $this->token($params + ['password' => 'wrong']);
        $this->token($params + ['password' => 'wrong']);

        // Act
        [$status, $body] = $this->token($params + ['password' => 'right-password']);

        // Assert
        $this->assertSame(200, $status, json_encode($body));
        $this->assertArrayHasKey('access_token', $body);
        // No failure count left behind.
        $this->assertSame(0, $this->db->queryBuilder()->table('authserver.loginlockouts')->where('lookupvalue', 'grantee')->count());
    }

    // ── Public clients and introspection ─────────────────────────────────────

    /** POST to the introspection endpoint. */
    private function introspect(array $params): array
    {
        $_POST    = $params;
        $response = $this->controller->introspect();

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true) ?? []];
    }

    /**
     * A public client (is_confidential = 0) need not send the secret it has on file — every copy
     * of the app holds it — but one it sends is still checked, a confidential client still has
     * to send its own, and client_credentials stays closed to a public client.
     */
    public function testAPublicClientNeedNotSendTheSecretItHas(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('applications')->where('appid', self::OTHER_APP)->update(['is_confidential' => 0]);
        $password = ['grant_type' => 'password', 'username' => 'grantee', 'password' => 'right-password', 'scope' => 'profile'];

        // Act
        [$publicBare, $body] = $this->token($password, 'other-app', null);
        $publicWrong       = $this->token($password, 'other-app', 'wrong')[1]['error'] ?? null;
        $confidentialBare  = $this->token($password, 'grants-app', null)[1]['error'] ?? null;
        [$clientCredentials] = $this->token(['grant_type' => 'client_credentials'], 'other-app', null);

        // Assert
        $this->assertSame(200, $publicBare, json_encode($body));
        $this->assertSame('invalid_client', $publicWrong, 'a secret it does send is checked');
        // Refused by its authentication-method policy before credentials are looked at: a
        // confidential client may not authenticate with `none`.
        $this->assertSame('unauthorized_client', $confidentialBare);
        $this->assertNotSame(200, $clientCredentials, 'client_credentials authenticates the application itself');
    }

    /**
     * Introspection authenticates the client as revocation does: a client assertion is accepted,
     * a bare client_id only from a public client.
     */
    public function testIntrospectionAuthenticatesTheClientAsRevocationDoes(): void
    {
        // Arrange
        $token = $this->userToken();
        $this->db->queryBuilder()->table('applications')->where('appid', self::OTHER_APP)->update(['is_confidential' => 0]);

        // Act
        [$byAssertion, $body] = $this->introspect([
            'token' => $token, 'client_id' => 'grants-app',
            'client_assertion' => $this->assertion(['sub' => 'grants-app']),
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        ]);
        [$confidentialBare] = $this->introspect(['token' => $token, 'client_id' => 'grants-app']);
        [$publicBare]       = $this->introspect(['token' => $token, 'client_id' => 'other-app']);

        // Assert
        $this->assertSame(200, $byAssertion);
        $this->assertTrue($body['active']);
        $this->assertSame(401, $confidentialBare);
        $this->assertSame(200, $publicBare);
    }
}
