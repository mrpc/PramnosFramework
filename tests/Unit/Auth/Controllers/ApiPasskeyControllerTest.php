<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\ApiAccount;
use Pramnos\Auth\Controllers\ApiPasskey;
use Pramnos\Auth\Passkey\AuthenticationOptions;
use Pramnos\Auth\Passkey\PasskeyCredential;
use Pramnos\Auth\Passkey\PasskeyException;
use Pramnos\Auth\Passkey\PasskeyServiceInterface;
use Pramnos\Auth\Passkey\RegistrationOptions;
use Pramnos\Auth\Passkey\VerificationResult;

/**
 * Unit tests for the API passkey controller.
 *
 * WHAT: the two things that differ from the web {@see \Pramnos\Auth\Controllers\Passkey}
 *       controller it extends — a verified assertion answered with a bearer token
 *       instead of a session, and request fields read from a JSON body instead of
 *       a form — plus the method guard on `list()`.
 * WHY:  everything else is deliberately inherited. A WebAuthn ceremony is exact
 *       and its failures are opaque (`authentication_failed` names no field), so
 *       the value of this class is precisely that it does NOT reimplement one.
 *       These tests exist to keep it that way: they assert the seams behave, and
 *       the inherited ceremony is covered once, by the parent's tests.
 */
#[CoversClass(ApiPasskey::class)]
class ApiPasskeyControllerTest extends TestCase
{
    private TestableApiPasskey $controller;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
        $this->controller = new TestableApiPasskey(null);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
    }

    // ── sign-in answers with a token, not a session ──────────────────────────

    /**
     * A verified assertion is answered with a bearer token.
     *
     * The whole reason this class exists. The web controller establishes a
     * session here, which a SPA cannot use: its next call carries a header, not
     * a cookie. The envelope is deliberately the one `/account/login` returns so
     * a client stores it with the code it already has.
     */
    public function testAVerifiedAssertionIsAnsweredWithABearerToken(): void
    {
        // Arrange — a ceremony this session started, and an assertion that verifies.
        $_SESSION['passkey_login_challenge'] = 'login-chal';
        $_SESSION['passkey_login_userid']    = 42;

        // Act
        $response = $this->controller->login();

        // Assert — the token envelope, for the user the assertion identified.
        $body = json_decode($response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Bearer', $body['token_type'] ?? null);
        $this->assertSame(42, $body['user']['id'] ?? null);
        $this->assertSame(
            2,
            substr_count((string) ($body['access_token'] ?? ''), '.'),
            'a signed JWT, the same one /account/login issues'
        );

        // And it was persisted, or the token would be signed but unknown to
        // loadByToken() — a credential that verifies and authenticates nothing.
        $this->assertNotEmpty($this->controller->user->addedTokens, 'the token was never stored');
    }

    /**
     * No session is established, however the assertion turns out.
     *
     * A login cookie left behind by an API call is not a convenience: it answers
     * for later calls that presented no credential, which is exactly the
     * contamination `RequestIdentity` exists to prevent — and it would make
     * `logout` (which revokes a token) look broken.
     */
    public function testItNeverEstablishesASession(): void
    {
        // Arrange
        $_SESSION['passkey_login_challenge'] = 'login-chal';

        // Act
        $this->controller->login();

        // Assert — the parent's session seam was never reached.
        $this->assertNull($this->controller->loggedInUserId);
    }

    /**
     * A failed assertion issues no token.
     *
     * The inherited guard, asserted here because this subclass replaced what
     * happens on success: a refactor that moved the token minting one step too
     * early would mint one for a signature that did not verify.
     */
    public function testAFailedAssertionIssuesNoToken(): void
    {
        // Arrange
        $_SESSION['passkey_login_challenge'] = 'login-chal';
        $this->controller->service->throwOnAuth = true;

        // Act
        $response = $this->controller->login();

        // Assert
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->controller->user->addedTokens, 'a token was minted anyway');
    }

    /**
     * Minting is the framework's one implementation, not a copy of it.
     *
     * The signing key, the audience, the lifetime and the `usertokens` row are
     * decisions made once, in {@see \Pramnos\Auth\IssuesAccessTokens}. A second
     * implementation here would answer a client that could not tell the
     * difference — until an application overrode `tokenTtl()` and only half its
     * tokens expired.
     */
    public function testItMintsWithTheFrameworkTrait(): void
    {
        // Assert — the same trait ApiAccount uses, not a private copy.
        $this->assertContains(
            \Pramnos\Auth\IssuesAccessTokens::class,
            class_uses(ApiPasskey::class)
        );
        $this->assertContains(
            \Pramnos\Auth\IssuesAccessTokens::class,
            class_uses(ApiAccount::class)
        );
    }

    // ── fields arrive as JSON ────────────────────────────────────────────────

    /**
     * A field posted in a JSON body is read.
     *
     * Without this the SPA's `username` arrives empty, the ceremony silently
     * becomes usernameless, and the only symptom is that a user with one passkey
     * is never offered it.
     */
    public function testAFieldIsReadFromAJsonBody(): void
    {
        // Arrange
        $this->controller->body = '{"username":"ada@example.test"}';
        $this->controller->resolvedUserId = 7;

        // Act
        $this->controller->loginOptions();

        // Assert — the username reached resolveUserId(), so the ceremony is pinned.
        $this->assertSame('ada@example.test', $this->controller->resolvedUsername);
        $this->assertSame(7, $_SESSION['passkey_login_userid']);
    }

    /**
     * A JSON number is accepted where the parent expects a string.
     *
     * `{"id": 3}` is what any client that did not think about it sends, and the
     * parent's guard is `(int) $this->input('id') <= 0`. Casting here keeps every
     * downstream check identical to the web side.
     */
    public function testAJsonNumberIsAcceptedForAnIdField(): void
    {
        // Arrange
        $this->controller->body = '{"id": 3}';

        // Act
        $response = $this->controller->revoke();

        // Assert — 200, so the id was seen; an empty read would have been 400.
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A non-scalar JSON value falls through to the form, rather than becoming "Array".
     *
     * `{"name": {...}}` is a malformed request, and the honest answer is the one
     * an absent field gets — `invalid_request` — not a passkey renamed to the
     * word Array.
     */
    public function testANonScalarJsonValueIsIgnored(): void
    {
        // Arrange
        $this->controller->body = '{"id": 3, "name": {"deep": 1}}';

        // Act
        $response = $this->controller->rename();

        // Assert
        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * With no JSON body the form is read, exactly as on the web side.
     *
     * The same controller answers a form post — a project migrating a screen at
     * a time has both in flight — and a body that is not an object (`"null"`,
     * `[1,2]`, a proxy's HTML error page) must not be treated as one.
     */
    public function testItFallsBackToTheFormWhenTheBodyIsNotAJsonObject(): void
    {
        // Arrange
        $this->controller->body = '[1,2]';
        $_POST['id'] = '5';

        // Act
        $response = $this->controller->revoke();

        // Assert
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * The body is decoded once, however many fields are read.
     *
     * `rename()` reads two. Re-reading `php://input` is not merely wasteful: on a
     * real request the stream is consumed, so the second read is empty.
     */
    public function testTheBodyIsDecodedOnlyOnce(): void
    {
        // Arrange
        $this->controller->body = '{"id": 3, "name": "laptop"}';

        // Act
        $response = $this->controller->rename();

        // Assert — two fields read, one body read (the ceremony ones are separate).
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->controller->bodyReads);
    }

    // ── list() is a GET ──────────────────────────────────────────────────────

    /** A GET returns the caller's passkeys. */
    public function testListReturnsThePasskeysOnAGet(): void
    {
        // Arrange
        $this->controller->method = 'GET';
        $this->controller->service->list = [new PasskeyCredential(1, 42, 'cid', 'pk', 0)];

        // Act
        $response = $this->controller->list();

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('passkeys', $response->getBody());
    }

    /**
     * Any other method is refused.
     *
     * A REST client that mistyped this as a POST gets a straight answer rather
     * than a list it was not entitled to ask for that way.
     */
    public function testListRefusesAnyOtherMethod(): void
    {
        // Arrange
        $this->controller->method = 'POST';

        // Act
        $response = $this->controller->list();

        // Assert
        $this->assertSame(405, $response->getStatusCode());
    }
}

/** User with a skipped constructor and a recording addToken (no database). */
class StubPasskeyUser extends \Pramnos\User\User
{
    /** @var list<array{0:string,1:string}> */
    public array $addedTokens = [];

    public function __construct() {}

    public function addToken($tokentype, $token, $notes = '', $parentToken = null, $expires = null)
    {
        $this->addedTokens[] = [$tokentype, $token];

        return $this;
    }
}

/** In-memory passkey service for the API controller's tests. */
class ApiStubPasskeyService implements PasskeyServiceInterface
{
    public array $list = [];
    public bool $throwOnAuth = false;

    public function beginRegistration(int $userId, ?string $label = null): RegistrationOptions
    {
        return new RegistrationOptions('reg-chal', '{}', $userId);
    }

    public function finishRegistration(int $userId, RegistrationOptions $options, string $clientResponse): PasskeyCredential
    {
        return new PasskeyCredential(1, $userId, 'cid', 'pk', 0);
    }

    public function beginAuthentication(?int $userId = null): AuthenticationOptions
    {
        return new AuthenticationOptions('login-chal', '{}', $userId);
    }

    public function finishAuthentication(AuthenticationOptions $options, string $clientResponse): VerificationResult
    {
        if ($this->throwOnAuth) {
            throw new PasskeyException('nope');
        }

        return new VerificationResult(42, new PasskeyCredential(1, 42, 'cid', 'pk', 1), 1);
    }

    public function listCredentials(int $userId): array
    {
        return $this->list;
    }

    public function renameCredential(int $userId, int $credentialId, string $name): bool
    {
        return true;
    }

    public function revokeCredential(int $userId, int $credentialId): bool
    {
        return true;
    }

    public function hasCredentials(int $userId): bool
    {
        return $this->list !== [];
    }
}

/**
 * The API passkey controller with its environment seams replaced.
 *
 * `input()` and `jsonBody()` are deliberately NOT overridden: they are what these
 * tests are about. The body, the method and the current user are.
 */
class TestableApiPasskey extends ApiPasskey
{
    public string $body = '{}';
    public string $method = 'POST';
    public int $bodyReads = 0;
    public ?int $userId = 42;
    public ?int $resolvedUserId = null;
    public ?string $resolvedUsername = null;
    public ?int $loggedInUserId = null;
    public ApiStubPasskeyService $service;
    public StubPasskeyUser $user;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        parent::__construct($application);
        $this->service = new ApiStubPasskeyService();
        $this->user    = new StubPasskeyUser();
        $this->user->userid   = 42;
        $this->user->username = 'ada';
    }

    /** 32 bytes: HS256 needs at least a 256-bit key. */
    protected function signingKey(): string
    {
        return '0123456789abcdef0123456789abcdef';
    }

    protected function audience(): string
    {
        return 'app-apikey';
    }

    protected function userFor(int $userId): \Pramnos\User\User
    {
        return $this->user;
    }

    protected function service(): PasskeyServiceInterface
    {
        return $this->service;
    }

    protected function currentUserId(): ?int
    {
        return $this->userId;
    }

    protected function resolveUserId(string $username): ?int
    {
        $this->resolvedUsername = $username;

        return $this->resolvedUserId;
    }

    protected function establishSession(int $userId): bool
    {
        $this->loggedInUserId = $userId;

        return true;
    }

    protected function requestMethod(): string
    {
        return $this->method;
    }

    protected function rawRequestBody(): string
    {
        $this->bodyReads++;

        return $this->body;
    }
}
