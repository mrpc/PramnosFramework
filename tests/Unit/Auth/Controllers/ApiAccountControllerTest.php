<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\ApiAccount;
use Pramnos\User\User;

/**
 * Unit tests for the framework ApiAccount (token-based) controller.
 *
 * WHAT: the JSON contract of POST /account/login and /account/logout — the API
 *       counterpart to the web Account controller.
 * WHY:  a REST API login must NOT create a session; it must verify credentials
 *       statelessly and return a bearer token the client re-presents. The tests
 *       assert the right status codes, that a signed token is issued and stored,
 *       and that logout revokes the presented token. Credential verification, key
 *       material and the token store are stubbed via seams (no session/DB).
 */
#[CoversClass(ApiAccount::class)]
class ApiAccountControllerTest extends TestCase
{
    private TestableApiAccount $c;

    protected function setUp(): void
    {
        $_POST = [];
        unset($_SERVER['HTTP_ACCESSTOKEN']);
        $this->c = new TestableApiAccount(null);
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['HTTP_ACCESSTOKEN']);
    }

    // ── login() ─────────────────────────────────────────────────────────────────

    /** A non-POST request is rejected with 405. */
    public function testLoginRejectsNonPost(): void
    {
        $this->c->method = 'GET';

        $this->assertSame(405, $this->c->login()->getStatusCode());
    }

    /** Empty username/password → 400 (credentials never checked). */
    public function testLoginMissingCredentials(): void
    {
        $this->c->rawJson = json_encode(['username' => '', 'password' => '']);

        $res = $this->c->login();

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame([], $this->c->lastCreds, 'credentials must not be verified when missing');
    }

    /** Bad credentials → 401. */
    public function testLoginInvalidCredentials(): void
    {
        $this->c->rawJson      = json_encode(['username' => 'alice', 'password' => 'bad']);
        $this->c->verifyResult = null; // verification fails

        $res = $this->c->login();

        $this->assertSame(401, $res->getStatusCode());
        $this->assertStringContainsString('invalid_credentials', $res->getBody());
    }

    /** A successful login mints + stores a bearer token and returns it with the user. */
    public function testLoginIssuesBearerToken(): void
    {
        $user = $this->user(5, 'alice', 'alice@example.com');
        $this->c->rawJson      = json_encode(['username' => 'alice', 'password' => 'pw']);
        $this->c->verifyResult = $user;

        $res  = $this->c->login();
        $body = json_decode($res->getBody(), true);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('success', $body['status']);
        $this->assertSame('Bearer', $body['token_type']);
        $this->assertSame(5, $body['user']['id']);
        // A real signed JWT (header.payload.signature) was returned...
        $this->assertNotEmpty($body['access_token']);
        $this->assertSame(2, substr_count($body['access_token'], '.'), 'access_token is a JWT');
        // ...and persisted as an `auth` token so the API middleware accepts it.
        $this->assertCount(1, $user->addedTokens);
        $this->assertSame('auth', $user->addedTokens[0][0]);
        $this->assertSame($body['access_token'], $user->addedTokens[0][1]);
        // Default TTL is 0 → token never expires (no expiry persisted).
        $this->assertNull($user->addedTokens[0][3]);
        // Credentials were verified statelessly (no session establishment).
        $this->assertSame(['alice', 'pw'], $this->c->lastCreds);
    }

    /**
     * With a positive token TTL, the issued token carries an expiry both in the
     * JWT `exp` claim and in the persisted usertokens.expires column, so it stops
     * authenticating once past. TTL of 0 (the default) keeps the never-expires
     * behaviour — asserted in testLoginIssuesBearerToken.
     */
    public function testLoginWithTtlSetsTokenExpiry(): void
    {
        $user = $this->user(5, 'alice', 'alice@example.com');
        $this->c->rawJson      = json_encode(['username' => 'alice', 'password' => 'pw']);
        $this->c->verifyResult = $user;
        $this->c->ttl          = 3600;

        $before = time();
        $res  = $this->c->login();
        $after = time();
        $body = json_decode($res->getBody(), true);

        $this->assertSame(200, $res->getStatusCode());

        // Persisted expiry ≈ now + ttl.
        $expires = $user->addedTokens[0][3];
        $this->assertNotNull($expires);
        $this->assertGreaterThanOrEqual($before + 3600, $expires);
        $this->assertLessThanOrEqual($after + 3600, $expires);

        // The JWT itself carries a matching `exp` claim.
        $parts   = explode('.', $body['access_token']);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertSame($expires, $payload['exp']);
    }

    /** With no signing key configured, login fails cleanly with 500. */
    public function testLoginWithoutSigningKeyReturns500(): void
    {
        $this->c->rawJson      = json_encode(['username' => 'alice', 'password' => 'pw']);
        $this->c->verifyResult = $this->user(5, 'alice', 'alice@example.com');
        $this->c->key          = ''; // no key

        $res = $this->c->login();

        $this->assertSame(500, $res->getStatusCode());
        $this->assertStringContainsString('token_unavailable', $res->getBody());
    }

    /** Credentials fall back to form POST when there is no JSON body. */
    public function testLoginReadsFormPostWhenNoJsonBody(): void
    {
        $this->c->rawJson  = '';
        $_POST['username'] = 'bob';
        $_POST['password'] = 'secret';
        $this->c->verifyResult = $this->user(7, 'bob', 'bob@example.com');

        $res = $this->c->login();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(['bob', 'secret'], $this->c->lastCreds);
    }

    // ── logout() ────────────────────────────────────────────────────────────────

    /** logout() revokes the presented access token and returns 200 ok. */
    public function testLogoutRevokesPresentedToken(): void
    {
        $_SERVER['HTTP_ACCESSTOKEN'] = 'the.jwt.token';

        $res = $this->c->logout();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('ok', $res->getBody());
        $this->assertSame(['the.jwt.token'], $this->c->revoked);
    }

    /** logout() without a token is a no-op 200 (nothing to revoke). */
    public function testLogoutWithoutTokenIsNoop(): void
    {
        $res = $this->c->logout();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame([], $this->c->revoked);
    }

    private function user(int $id, string $username, string $email): StubApiUser
    {
        $user = new StubApiUser();
        $user->userid   = $id;
        $user->username = $username;
        $user->email    = $email;
        return $user;
    }

    // ────────────────────────────────────────────────────────────────────────
    // The second leg of an API login
    // ────────────────────────────────────────────────────────────────────────

    /*
     * `login2fa()` finishes a login that stopped for a second factor. Its five statements-worth of
     * branching had never executed: every test above drives `login()`, and the flow they build has
     * two-factor turned off, so the second leg was never reached from either direction.
     *
     * These build the flow's answer directly. What matters about this endpoint is what each of the
     * four possible answers becomes on the wire — a client has nothing else to go on.
     */

    /** A controller whose flow answers `completeTwoFactor()` as the test says. */
    private function secondLeg(\Pramnos\Auth\LoginFlowResult $answer, string $code = '123456'): object
    {
        return new class ($answer, $code) extends ApiAccount {
            /** @var list<string> Codes handed to the flow */
            public array $submitted = [];

            public string $method = 'POST';

            public function __construct(
                private readonly \Pramnos\Auth\LoginFlowResult $answer,
                private readonly string $code
            ) {
                parent::__construct();
            }

            protected function requestMethod(): string
            {
                return $this->method;
            }

            protected function input(string $key): mixed
            {
                return $key === 'code' ? $this->code : null;
            }

            protected function loginFlow(): \Pramnos\Auth\ApiLoginFlow
            {
                $outer = $this;

                return new class ($outer, $this->answer) extends \Pramnos\Auth\ApiLoginFlow {
                    public function __construct(
                        private readonly object $outer,
                        private readonly \Pramnos\Auth\LoginFlowResult $answer
                    ) {
                    }

                    public function completeTwoFactor(string $code): \Pramnos\Auth\LoginFlowResult
                    {
                        $this->outer->submitted[] = $code;

                        return $this->answer;
                    }
                };
            }

            protected function userFor(int $userId): \Pramnos\User\User
            {
                $user = new StubApiUser();
                $user->userid   = $userId;
                $user->username = 'someone';

                return $user;
            }

            protected function signingKey(): string
            {
                return '0123456789abcdef0123456789abcdef';
            }

            protected function audience(): string
            {
                return 'app-apikey';
            }
        };
    }

    /** The status and decoded body of a response. */
    private function readJson(mixed $response): array
    {
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);

        return [
            $response->getStatusCode(),
            json_decode((string) $response->getBody(), true) ?: [],
        ];
    }

    /**
     * The second leg is a POST, like the first.
     *
     * A code in a query string ends up in access logs, browser history and any proxy in between —
     * and a second factor is worth something for the ninety seconds it is valid.
     */
    public function testTheSecondLegRejectsAnythingButAPost(): void
    {
        // Arrange
        $controller = $this->secondLeg(\Pramnos\Auth\LoginFlowResult::success(7));
        $controller->method = 'GET';

        // Act
        [$status, $body] = $this->readJson($controller->login2fa());

        // Assert
        $this->assertSame(405, $status);
        $this->assertSame('method_not_allowed', $body['error'] ?? null);
        $this->assertSame([], $controller->submitted, 'the flow was asked to verify a GET');
    }

    /**
     * An empty code is refused before the flow is asked.
     *
     * Not just tidiness: reaching the flow with `''` would spend a verification attempt — and the
     * attempts are counted towards a lockout — on a submission that cannot possibly succeed. A
     * client with a bug in its form would lock its own users out.
     */
    public function testAnEmptyCodeIsRefusedWithoutSpendingAnAttempt(): void
    {
        // Arrange
        $controller = $this->secondLeg(\Pramnos\Auth\LoginFlowResult::success(7), '   ');

        // Act
        [$status, $body] = $this->readJson($controller->login2fa());

        // Assert
        $this->assertSame(400, $status);
        $this->assertSame('missing_code', $body['error'] ?? null);
        $this->assertSame([], $controller->submitted, 'an empty code was counted as an attempt');
    }

    /**
     * A locked account gets 429 and is told how long to wait.
     *
     * `429` rather than `401`, and `retry_after` with it, because a client that cannot tell "wrong
     * code" from "stop asking" will keep retrying — which is what the lockout exists to stop, and
     * what turns a lockout into a loop.
     */
    public function testALockedAccountIsToldHowLongToWait(): void
    {
        // Arrange
        $controller = $this->secondLeg(\Pramnos\Auth\LoginFlowResult::locked(90));

        // Act
        [$status, $body] = $this->readJson($controller->login2fa());

        // Assert
        $this->assertSame(429, $status);
        $this->assertSame('too_many_attempts', $body['error'] ?? null);
        $this->assertSame(90, $body['retry_after'] ?? null);
    }

    /**
     * A wrong code is 401, and says only that.
     *
     * The pending login is left where it is, server-side, so the person can try again with the
     * next code from their authenticator — which is the reason this is a second *request* rather
     * than a re-submission of the password.
     */
    public function testAWrongCodeIsAPlain401(): void
    {
        // Arrange
        $controller = $this->secondLeg(\Pramnos\Auth\LoginFlowResult::failed());

        // Act
        [$status, $body] = $this->readJson($controller->login2fa());

        // Assert
        $this->assertSame(401, $status);
        $this->assertSame('invalid_code', $body['error'] ?? null);
        $this->assertSame(['123456'], $controller->submitted, 'the code never reached the flow');
    }

    /**
     * A correct code answers with a bearer token, exactly like a login that needed no second
     * factor.
     *
     * The point of sharing `tokenResponse()` between the two legs: a client should not need two
     * code paths for the same outcome, and a second factor is a step in a login rather than a
     * different kind of login.
     */
    public function testACorrectCodeAnswersWithABearerToken(): void
    {
        // Arrange
        $controller = $this->secondLeg(\Pramnos\Auth\LoginFlowResult::success(4242));

        // Act
        $response = $controller->login2fa();
        [$status, $body] = $this->readJson($response);

        // Assert
        $this->assertSame(200, $status, 'a verified second factor should complete the login');
        $this->assertSame('Bearer', $body['token_type'] ?? null);
        $this->assertNotEmpty($body['access_token'] ?? '', 'no token was issued');
        $this->assertSame(
            2,
            substr_count((string) $body['access_token'], '.'),
            'the second leg should issue a signed JWT, like the first'
        );

        // The assertion that matters: the token belongs to the account the *second factor*
        // verified. A `login2fa()` that answered for whoever was pending, or for the id in the
        // request, would issue a working token for an account nobody proved they held.
        $this->assertSame(4242, $body['user']['id'] ?? null);
    }

}

/** ApiAccount with every external collaborator replaced by a settable double. */
class TestableApiAccount extends ApiAccount
{
    public string $method = 'POST';
    public string $rawJson = '';
    public ?User $verifyResult = null;
    public string $key = '0123456789abcdef0123456789abcdef'; // 32 bytes (HS256 needs >=256-bit)
    public string $aud = 'app-apikey';
    /** @var list<string> */
    public array $revoked = [];
    /** @var array{0:string,1:string}|array{} */
    public array $lastCreds = [];
    public int $ttl = 0;

    protected function requestMethod(): string { return $this->method; }
    protected function tokenTtl(): int { return $this->ttl; }
    protected function rawBody(): string { return $this->rawJson; }

    protected function verifyCredentials(string $username, string $password): ?User
    {
        $this->lastCreds = [$username, $password];
        return $this->verifyResult;
    }

    protected function signingKey(): string { return $this->key; }
    protected function audience(): string { return $this->aud; }
    protected function revokeToken(string $token): void { $this->revoked[] = $token; }

    /**
     * The login flow, with its two database-backed collaborators replaced.
     *
     * `login()` now goes through `ApiLoginFlow` — the same lockout, credentials
     * and second-factor sequence the HTML login uses — so a unit test has to
     * supply the parts that would otherwise reach for a connection. Credentials
     * still come from this class's own `verifyCredentials()`, which is what the
     * production seam does too.
     */
    protected function loginFlow(): \Pramnos\Auth\ApiLoginFlow
    {
        $lockout = new class extends \Pramnos\Auth\Loginlockout {
            public function __construct()
            {
            }

            public function getLockoutStatus(string $scope, string $identifier): array
            {
                return ['locked' => false, 'remaining' => 0];
            }

            public function recordFailedAttempt(string $scope, string $identifier): void
            {
            }

            public function clearSuccessfulLoginState(string $scope, string $identifier): void
            {
            }
        };

        $twoFactor = new class extends \Pramnos\Auth\TwoFactorAuthService {
            public function __construct()
            {
            }

            public function isEnabled(int $userId): bool
            {
                return false;
            }
        };

        return new class (
            function (string $username, string $password): array|false {
                $user = $this->verifyCredentialsForFlow($username, $password);
                $this->rememberUser($user);

                return $user === null
                    ? false
                    : ['status' => true, 'uid' => (int) $user->userid];
            },
            null,
            $lockout,
            $twoFactor
        ) extends \Pramnos\Auth\ApiLoginFlow {
            protected function establishSession(int $userId, bool $remember): bool
            {
                return true;
            }
        };
    }

    /** Bridge to the protected seam, callable from the flow's closure. */
    public function verifyCredentialsForFlow(string $username, string $password): ?User
    {
        return $this->verifyCredentials($username, $password);
    }

    /** @var User|null The user this double resolved */
    private ?User $doubleUser = null;

    /** Keep the resolved user so no row has to be loaded to answer with it. */
    public function rememberUser(?User $user): void
    {
        $this->doubleUser = $user;
    }

    protected function userFor(int $userId): User
    {
        return $this->doubleUser ?? parent::userFor($userId);
    }
}

/** User with a skipped constructor + a recording addToken (no DB). */
class StubApiUser extends User
{
    /** @var list<array{0:string,1:string,2:string,3:int|null}> */
    public array $addedTokens = [];

    public function __construct() {}

    public function addToken($tokentype, $token, $notes = '', $parentToken = null, $expires = null)
    {
        $this->addedTokens[] = [$tokentype, $token, $notes, $expires];
        return $this;
    }
}
