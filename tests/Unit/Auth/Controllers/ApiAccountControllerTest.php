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
        // Credentials were verified statelessly (no session establishment).
        $this->assertSame(['alice', 'pw'], $this->c->lastCreds);
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

    protected function requestMethod(): string { return $this->method; }
    protected function rawBody(): string { return $this->rawJson; }

    protected function verifyCredentials(string $username, string $password): ?User
    {
        $this->lastCreds = [$username, $password];
        return $this->verifyResult;
    }

    protected function signingKey(): string { return $this->key; }
    protected function audience(): string { return $this->aud; }
    protected function revokeToken(string $token): void { $this->revoked[] = $token; }
}

/** User with a skipped constructor + a recording addToken (no DB). */
class StubApiUser extends User
{
    /** @var list<array{0:string,1:string,2:string}> */
    public array $addedTokens = [];

    public function __construct() {}

    public function addToken($tokentype, $token, $notes = '', $parentToken = null)
    {
        $this->addedTokens[] = [$tokentype, $token, $notes];
        return $this;
    }
}
