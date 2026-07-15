<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Passkey;
use Pramnos\Auth\Passkey\AuthenticationOptions;
use Pramnos\Auth\Passkey\PasskeyCredential;
use Pramnos\Auth\Passkey\PasskeyException;
use Pramnos\Auth\Passkey\PasskeyServiceInterface;
use Pramnos\Auth\Passkey\RegistrationOptions;
use Pramnos\Auth\Passkey\VerificationResult;

/**
 * Unit tests for the Passkey ceremony/management controller.
 *
 * WHAT: the HTTP-facing branches — auth gating, the in-flight-ceremony session
 *       correlation, success/failure JSON, and passwordless session
 *       establishment — with the service, current user, and request body
 *       replaced by seams so no live WebAuthn device, DB, or php://input is
 *       needed.
 * WHY:  the controller is the untrusted edge; it must refuse a finish with no
 *       matching ceremony, never establish a session on a failed assertion, and
 *       only ever act for the logged-in user. These are security branches, not
 *       cosmetics.
 */
class PasskeyControllerTest extends TestCase
{
    private TestablePasskeyController $controller;

    protected function setUp(): void
    {
        $this->controller = new TestablePasskeyController(null);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ── registration ─────────────────────────────────────────────────────────

    public function testRegisterOptionsRequiresLogin(): void
    {
        $this->controller->userId = null; // not logged in
        $r = $this->controller->registerOptions();
        $this->assertSame(401, $r->getStatusCode());
    }

    public function testRegisterOptionsStoresChallengeAndReturnsOptions(): void
    {
        // Arrange
        $this->controller->userId = 42;

        // Act
        $r = $this->controller->registerOptions();

        // Assert — options returned and the challenge remembered for finish.
        $this->assertSame(200, $r->getStatusCode());
        $this->assertStringContainsString('options', $r->getBody());
        $this->assertSame('reg-chal', $_SESSION['passkey_reg_challenge']);
    }

    public function testRegisterWithoutCeremonyFails(): void
    {
        $this->controller->userId = 42; // logged in but no begin call happened
        $r = $this->controller->register();
        $this->assertSame(400, $r->getStatusCode());
        $this->assertStringContainsString('no_ceremony', $r->getBody());
    }

    public function testRegisterSuccess(): void
    {
        // Arrange — a ceremony is in flight.
        $this->controller->userId = 42;
        $_SESSION['passkey_reg_challenge'] = 'reg-chal';
        $this->controller->service->registerResult =
            new PasskeyCredential(1, 42, 'cid', 'pk', 0, null, [], 'Key');

        // Act
        $r = $this->controller->register();

        // Assert — success and the challenge is cleared (single-use).
        $this->assertSame(200, $r->getStatusCode());
        $this->assertStringContainsString('"status":"ok"', $r->getBody());
        $this->assertArrayNotHasKey('passkey_reg_challenge', $_SESSION);
    }

    public function testRegisterVerificationFailureReturns400(): void
    {
        $this->controller->userId = 42;
        $_SESSION['passkey_reg_challenge'] = 'reg-chal';
        $this->controller->service->throwOnRegister = true;

        $r = $this->controller->register();

        $this->assertSame(400, $r->getStatusCode());
        $this->assertStringContainsString('registration_failed', $r->getBody());
    }

    // ── authentication ─────────────────────────────────────────────────────────

    public function testLoginOptionsUsernameless(): void
    {
        // No username → usernameless ceremony (null user id stored).
        $r = $this->controller->loginOptions();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('login-chal', $_SESSION['passkey_login_challenge']);
        $this->assertNull($_SESSION['passkey_login_userid']);
    }

    public function testLoginOptionsWithUsername(): void
    {
        // With a username the controller resolves and pins the user id.
        $this->controller->inputs['username'] = 'alice';
        $this->controller->resolvedUserId = 42;
        $r = $this->controller->loginOptions();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame(42, $_SESSION['passkey_login_userid']);
    }

    public function testLoginWithoutCeremonyFails(): void
    {
        $r = $this->controller->login();
        $this->assertSame(400, $r->getStatusCode());
        $this->assertStringContainsString('no_ceremony', $r->getBody());
    }

    public function testLoginSuccessEstablishesSession(): void
    {
        // Arrange — a login ceremony is in flight.
        $_SESSION['passkey_login_challenge'] = 'login-chal';
        $_SESSION['passkey_login_userid'] = 42;
        $this->controller->service->authResult =
            new VerificationResult(42, new PasskeyCredential(1, 42, 'cid', 'pk', 5), 5);
        $this->controller->sessionEstablished = true;

        // Act
        $r = $this->controller->login();

        // Assert
        $this->assertSame(200, $r->getStatusCode());
        $this->assertStringContainsString('"user_id":42', $r->getBody());
        $this->assertSame(42, $this->controller->loggedInUserId, 'Session established for the verified user');
        $this->assertArrayNotHasKey('passkey_login_challenge', $_SESSION);
    }

    public function testLoginFailedAssertionReturns401AndNoSession(): void
    {
        $_SESSION['passkey_login_challenge'] = 'login-chal';
        $_SESSION['passkey_login_userid'] = null;
        $this->controller->service->throwOnAuth = true;

        $r = $this->controller->login();

        $this->assertSame(401, $r->getStatusCode());
        $this->assertStringContainsString('authentication_failed', $r->getBody());
        $this->assertNull($this->controller->loggedInUserId, 'No session on failed assertion');
    }

    public function testLoginVerifiedButSessionBootstrapFails(): void
    {
        // The assertion verifies, but loginById() fails (e.g. inactive user).
        $_SESSION['passkey_login_challenge'] = 'login-chal';
        $_SESSION['passkey_login_userid'] = 42;
        $this->controller->service->authResult =
            new VerificationResult(42, new PasskeyCredential(1, 42, 'cid', 'pk', 5), 5);
        $this->controller->sessionEstablished = false; // loginById returns false

        $r = $this->controller->login();

        $this->assertSame(401, $r->getStatusCode());
        $this->assertStringContainsString('login_failed', $r->getBody());
    }

    /**
     * The REAL controller (no seams overridden) treats a request with no logged-in
     * session as unauthorized — exercising the actual currentUserId()/input() code.
     */
    public function testRealControllerUnauthenticatedIsRejected(): void
    {
        // Arrange — a genuine controller with an empty session.
        $_SESSION = [];
        $controller = new Passkey(null);

        // Act & Assert — no current user → 401 on an auth-only action.
        $this->assertSame(401, $controller->registerOptions()->getStatusCode());
        $this->assertSame(401, $controller->list()->getStatusCode());
    }

    /** The real input() seam reads POST then GET. */
    public function testRealInputReadsRequestSuperglobals(): void
    {
        $_POST = ['a' => '  hello  '];
        $_GET  = ['b' => 'world'];
        $controller = new class (null) extends Passkey {
            public function readInput(string $k): string { return $this->input($k); }
        };
        $this->assertSame('hello', $controller->readInput('a'), 'POST wins and is trimmed');
        $this->assertSame('world', $controller->readInput('b'), 'falls back to GET');
        $this->assertSame('', $controller->readInput('missing'));
        $_POST = [];
        $_GET = [];
    }

    // ── management ─────────────────────────────────────────────────────────────

    public function testListRequiresLogin(): void
    {
        $this->controller->userId = null;
        $this->assertSame(401, $this->controller->list()->getStatusCode());
    }

    /** Every auth-only action rejects an unauthenticated caller with 401. */
    public function testAllManagementActionsRequireLogin(): void
    {
        $this->controller->userId = null;
        $this->assertSame(401, $this->controller->register()->getStatusCode());
        $this->controller->inputs = ['id' => '5', 'name' => 'x'];
        $this->assertSame(401, $this->controller->rename()->getStatusCode());
        $this->controller->inputs = ['id' => '5'];
        $this->assertSame(401, $this->controller->revoke()->getStatusCode());
    }

    public function testListReturnsPasskeys(): void
    {
        $this->controller->userId = 42;
        $this->controller->service->list = [new PasskeyCredential(1, 42, 'cid', 'pk', 0, null, [], 'Key')];
        $r = $this->controller->list();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertStringContainsString('passkeys', $r->getBody());
        $this->assertStringNotContainsString('public_key', $r->getBody(), 'COSE key never exposed');
    }

    public function testRenameInvalidRequest(): void
    {
        $this->controller->userId = 42;
        // Missing id/name.
        $this->assertSame(400, $this->controller->rename()->getStatusCode());
    }

    public function testRenameOkAndNotFound(): void
    {
        $this->controller->userId = 42;
        $this->controller->inputs = ['id' => '5', 'name' => 'New'];
        $this->controller->service->renameOk = true;
        $this->assertSame(200, $this->controller->rename()->getStatusCode());

        $this->controller->service->renameOk = false;
        $this->assertSame(404, $this->controller->rename()->getStatusCode());
    }

    public function testRevokeInvalidAndOk(): void
    {
        $this->controller->userId = 42;
        $this->assertSame(400, $this->controller->revoke()->getStatusCode(), 'No id');

        $this->controller->inputs = ['id' => '5'];
        $this->controller->service->revokeOk = true;
        $this->assertSame(200, $this->controller->revoke()->getStatusCode());

        $this->controller->service->revokeOk = false;
        $this->assertSame(404, $this->controller->revoke()->getStatusCode());
    }
}

/** In-memory PasskeyServiceInterface double for controller tests. */
class StubPasskeyService implements PasskeyServiceInterface
{
    public ?PasskeyCredential $registerResult = null;
    public ?VerificationResult $authResult = null;
    public array $list = [];
    public bool $renameOk = true;
    public bool $revokeOk = true;
    public bool $throwOnRegister = false;
    public bool $throwOnAuth = false;

    public function beginRegistration(int $userId, ?string $label = null): RegistrationOptions
    {
        return new RegistrationOptions('reg-chal', '{"rp":{"id":"x"}}', $userId);
    }

    public function finishRegistration(int $userId, RegistrationOptions $options, string $clientResponse): PasskeyCredential
    {
        if ($this->throwOnRegister) {
            throw new PasskeyException('nope');
        }
        return $this->registerResult ?? new PasskeyCredential(1, $userId, 'cid', 'pk', 0);
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
        return $this->authResult ?? new VerificationResult(42, new PasskeyCredential(1, 42, 'cid', 'pk', 1), 1);
    }

    public function listCredentials(int $userId): array
    {
        return $this->list;
    }

    public function renameCredential(int $userId, int $credentialId, string $name): bool
    {
        return $this->renameOk;
    }

    public function revokeCredential(int $userId, int $credentialId): bool
    {
        return $this->revokeOk;
    }

    public function hasCredentials(int $userId): bool
    {
        return $this->list !== [];
    }
}

/** Passkey controller with its seams overridden for isolated testing. */
class TestablePasskeyController extends Passkey
{
    public ?int $userId = 42;
    public ?int $resolvedUserId = null;
    public bool $sessionEstablished = true;
    public ?int $loggedInUserId = null;
    public array $inputs = [];
    public StubPasskeyService $service;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        parent::__construct($application);
        $this->service = new StubPasskeyService();
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
        return $this->resolvedUserId;
    }

    protected function establishSession(int $userId): bool
    {
        if ($this->sessionEstablished) {
            $this->loggedInUserId = $userId;
        }
        return $this->sessionEstablished;
    }

    protected function input(string $key): string
    {
        return (string) ($this->inputs[$key] ?? '');
    }

    protected function rawRequestBody(): string
    {
        return '{}';
    }
}
