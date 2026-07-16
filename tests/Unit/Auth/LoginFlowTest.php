<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Auth;
use Pramnos\Auth\LoginFlow;
use Pramnos\Auth\LoginFlowResult;
use Pramnos\Auth\Loginlockout;
use Pramnos\Auth\TwoFactorAuthService;
use Pramnos\Auth\Passkey\AuthenticationOptions;
use Pramnos\Auth\Passkey\PasskeyCredential;
use Pramnos\Auth\Passkey\PasskeyServiceInterface;
use Pramnos\Auth\Passkey\RegistrationOptions;
use Pramnos\Auth\Passkey\VerificationResult;

/**
 * Unit tests for the LoginFlow orchestrator.
 *
 * WHAT: the password → lockout → step-up → session state machine, driven with
 *       in-memory doubles for every collaborator (Auth, Loginlockout,
 *       TwoFactorAuthService, PasskeyService) injected through the constructor,
 *       plus the server-side pending-step-up session state.
 * WHY:  this is the flow a scaffolded authserver adopts verbatim, so every
 *       branch is a security decision — a lockout must short-circuit before any
 *       password check, a failed password must count toward the lockout, a
 *       correct password with 2FA enabled must NOT establish a session until the
 *       second factor passes, a step-up must be completable only by the very
 *       user who passed the password leg, and a stale pending step-up must
 *       expire. The password must never be persisted anywhere between legs.
 */
class LoginFlowTest extends TestCase
{
    private TestableLoginFlow $flow;

    protected function setUp(): void
    {
        // Each test starts with an empty session (pending-step-up state lives here).
        $_SESSION = [];
        $this->flow = new TestableLoginFlow();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ── attempt(): lockout gate ────────────────────────────────────────────────

    /**
     * An active lockout short-circuits BEFORE credentials are ever checked, so a
     * locked-out attacker cannot even probe whether a password is right, and the
     * remaining seconds are surfaced to the caller.
     */
    public function testAttemptReturnsLockedWhenLockoutActive(): void
    {
        // Arrange — the lockout reports an active lock with 42s remaining.
        $this->flow->fakeLockout->status = ['locked' => true, 'remaining' => 42];
        $this->flow->fakeAuth->response  = $this->successResponse(7); // would succeed if reached

        // Act
        $result = $this->flow->attempt('alice', 'secret');

        // Assert — locked result, correct remaining, and credentials never touched.
        $this->assertTrue($result->isLocked());
        $this->assertSame(42, $result->lockoutRemaining);
        $this->assertSame([], $this->flow->fakeAuth->verifyArgs, 'password must not be checked while locked');
    }

    // ── attempt(): bad credentials ─────────────────────────────────────────────

    /**
     * A wrong password returns FAILED and records exactly one failed attempt
     * against the normalised identifier so the progressive lockout can escalate.
     */
    public function testAttemptFailedCredentialsRecordsAttempt(): void
    {
        // Arrange — credential check fails.
        $this->flow->fakeAuth->response = false;

        // Act
        $result = $this->flow->attempt('Alice', 'wrong');

        // Assert — FAILED, no session, one recorded failure keyed by the lower-cased id.
        $this->assertTrue($result->isFailed());
        $this->assertSame([], $this->flow->fakeAuth->loginArgs, 'no session on bad credentials');
        $this->assertSame([['identifier', 'alice']], $this->flow->fakeLockout->recorded);
    }

    /**
     * A response array that lacks the success flag / uid is treated exactly like
     * a false return — defensive against a driver that returns a malformed shape.
     */
    public function testAttemptTreatsMalformedResponseAsFailure(): void
    {
        // Arrange — truthy array but missing 'status'/'uid'.
        $this->flow->fakeAuth->response = ['something' => 'else'];

        // Act
        $result = $this->flow->attempt('alice', 'x');

        // Assert
        $this->assertTrue($result->isFailed());
        $this->assertSame([['identifier', 'alice']], $this->flow->fakeLockout->recorded);
    }

    // ── attempt(): success without step-up ──────────────────────────────────────

    /**
     * Correct password + no second factor logs the user straight in: the failure
     * counter is cleared and the session is established with the remember flag.
     */
    public function testAttemptSuccessNoStepUp(): void
    {
        // Arrange — good credentials, 2FA disabled.
        $this->flow->fakeAuth->response      = $this->successResponse(7);
        $this->flow->fakeTwoFactor->enabled  = false;

        // Act
        $result = $this->flow->attempt('alice', 'secret', false);

        // Assert — SUCCESS with the right user id, counter cleared, session established.
        $this->assertTrue($result->isSuccess());
        $this->assertSame(7, $result->userId);
        $this->assertSame([['identifier', 'alice']], $this->flow->fakeLockout->cleared);
        $this->assertSame([7, false], $this->flow->fakeAuth->loginArgs, 'remember flag propagates');
        $this->assertArrayNotHasKey('loginflow_pending_userid', $_SESSION, 'no pending state on direct login');
    }

    /**
     * verifyCredentials must NOT establish a session by itself; only loginById
     * (via establishSession) does — proving the password leg is session-free.
     */
    public function testAttemptVerifiesWithoutEstablishingUntilFinish(): void
    {
        $this->flow->fakeAuth->response = $this->successResponse(7);
        $this->flow->fakeTwoFactor->enabled = false;

        $this->flow->attempt('alice', 'secret', true);

        // The plaintext password reached verifyCredentials, never loginById.
        $this->assertSame(['alice', 'secret', false, true], $this->flow->fakeAuth->verifyArgs);
        $this->assertSame([7, true], $this->flow->fakeAuth->loginArgs);
    }

    /**
     * If the session bootstrap is refused at the finish (e.g. the account went
     * inactive between the password check and here), the flow reports FAILED
     * rather than a phantom success.
     */
    public function testAttemptSuccessButSessionRefusedIsFailure(): void
    {
        $this->flow->fakeAuth->response     = $this->successResponse(7);
        $this->flow->fakeTwoFactor->enabled = false;
        $this->flow->fakeAuth->loginReturn  = false; // loginById refuses

        $result = $this->flow->attempt('alice', 'secret');

        $this->assertTrue($result->isFailed());
    }

    // ── attempt(): step-up required ─────────────────────────────────────────────

    /**
     * Correct password with 2FA enabled must stop short of a session: it stashes
     * the pending user server-side and asks for a second factor. No session, no
     * cleared counter yet.
     */
    public function testAttemptWith2faStopsForStepUp(): void
    {
        // Arrange
        $this->flow->fakeAuth->response     = $this->successResponse(7);
        $this->flow->fakeTwoFactor->enabled = true;

        // Act
        $result = $this->flow->attempt('alice', 'secret', true);

        // Assert — step-up required, TOTP offered, pending state stored, no login yet.
        $this->assertTrue($result->needsStepUp());
        $this->assertSame(7, $result->userId);
        $this->assertSame(['twofactor'], $result->stepUpMethods);
        $this->assertSame([], $this->flow->fakeAuth->loginArgs, 'no session before step-up passes');
        $this->assertSame(7, $_SESSION['loginflow_pending_userid']);
        $this->assertTrue($_SESSION['loginflow_pending_remember']);
        $this->assertSame('alice', $_SESSION['loginflow_pending_identifier']);
    }

    /**
     * When the user also has a passkey, it is offered as an ALTERNATIVE second
     * factor alongside TOTP.
     */
    public function testAttemptWith2faAndPasskeyOffersBoth(): void
    {
        $this->flow->fakeAuth->response     = $this->successResponse(7);
        $this->flow->fakeTwoFactor->enabled = true;
        $this->flow->fakePasskeys->has      = true;

        $result = $this->flow->attempt('alice', 'secret');

        $this->assertSame(['twofactor', 'passkey'], $result->stepUpMethods);
        $this->assertTrue($result->allowsStepUpMethod('passkey'));
    }

    /**
     * A registered passkey WITHOUT 2FA does not force a step-up — passkeys are a
     * passwordless primary method, not an implicit mandatory second factor.
     */
    public function testPasskeyAloneDoesNotForceStepUp(): void
    {
        $this->flow->fakeAuth->response     = $this->successResponse(7);
        $this->flow->fakeTwoFactor->enabled = false;
        $this->flow->fakePasskeys->has      = true;

        $result = $this->flow->attempt('alice', 'secret');

        $this->assertTrue($result->isSuccess(), 'passkey alone → straight login');
    }

    // ── completeTwoFactor() ──────────────────────────────────────────────────────

    /**
     * The happy step-up path: after a pending 2FA login, a correct code clears
     * the pending state, clears the lockout counter, and establishes the session
     * with the remember flag captured at the password leg.
     */
    public function testCompleteTwoFactorSuccess(): void
    {
        // Arrange — a pending step-up as attempt() would have created.
        $this->givenPending(7, true, 'alice');
        $this->flow->fakeTwoFactor->verifies = true;

        // Act
        $result = $this->flow->completeTwoFactor('123456');

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame(7, $result->userId);
        $this->assertSame([7, '123456'], $this->flow->fakeTwoFactor->verifyCalls[0]);
        $this->assertSame([7, true], $this->flow->fakeAuth->loginArgs);
        $this->assertSame([['identifier', 'alice']], $this->flow->fakeLockout->cleared);
        $this->assertArrayNotHasKey('loginflow_pending_userid', $_SESSION, 'pending cleared on success');
    }

    /**
     * A wrong 2FA code fails but LEAVES the pending state intact so the user can
     * retry without re-entering their password.
     */
    public function testCompleteTwoFactorWrongCodeKeepsPending(): void
    {
        $this->givenPending(7, false, 'alice');
        $this->flow->fakeTwoFactor->verifies = false;

        $result = $this->flow->completeTwoFactor('000000');

        $this->assertTrue($result->isFailed());
        $this->assertSame(7, $_SESSION['loginflow_pending_userid'], 'still pending for retry');
        $this->assertSame([], $this->flow->fakeAuth->loginArgs, 'no session on wrong code');
    }

    /**
     * Calling completeTwoFactor with no pending step-up (direct hit / expired)
     * fails without touching the 2FA service.
     */
    public function testCompleteTwoFactorWithoutPendingFails(): void
    {
        $result = $this->flow->completeTwoFactor('123456');

        $this->assertTrue($result->isFailed());
        $this->assertSame([], $this->flow->fakeTwoFactor->verifyCalls);
    }

    // ── completePasskey() ────────────────────────────────────────────────────────

    /**
     * A passkey step-up succeeds only when the verified passkey belongs to the
     * pending user; it then finishes the login exactly like the TOTP path.
     */
    public function testCompletePasskeyMatchingUserSucceeds(): void
    {
        $this->givenPending(7, true, 'alice');

        $result = $this->flow->completePasskey(7);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([7, true], $this->flow->fakeAuth->loginArgs);
        $this->assertArrayNotHasKey('loginflow_pending_userid', $_SESSION);
    }

    /**
     * A passkey that verifies a DIFFERENT user than the one who passed the
     * password leg must never complete this login — otherwise anyone with any
     * valid passkey could ride someone else's password step.
     */
    public function testCompletePasskeyMismatchedUserFails(): void
    {
        $this->givenPending(7, true, 'alice');

        $result = $this->flow->completePasskey(9); // different user

        $this->assertTrue($result->isFailed());
        $this->assertSame([], $this->flow->fakeAuth->loginArgs);
        $this->assertSame(7, $_SESSION['loginflow_pending_userid'], 'pending untouched on mismatch');
    }

    public function testCompletePasskeyWithoutPendingFails(): void
    {
        $result = $this->flow->completePasskey(7);
        $this->assertTrue($result->isFailed());
    }

    // ── pending TTL, pendingUserId(), cancel() ───────────────────────────────────

    /**
     * A pending step-up older than the TTL is treated as absent AND scrubbed, so
     * a stale half-login can never be completed later.
     */
    public function testExpiredPendingIsClearedAndUnusable(): void
    {
        // Arrange — pending stamped well beyond the 300s default TTL.
        $this->givenPending(7, true, 'alice');
        $_SESSION['loginflow_pending_time'] = time() - 400;
        $this->flow->fakeTwoFactor->verifies = true; // would succeed if not expired

        // Act
        $result = $this->flow->completeTwoFactor('123456');

        // Assert — failure, and the stale keys are gone.
        $this->assertTrue($result->isFailed());
        $this->assertArrayNotHasKey('loginflow_pending_userid', $_SESSION);
        $this->assertNull($this->flow->pendingUserId());
    }

    /** pendingUserId() reflects the in-flight step-up user, or null when none. */
    public function testPendingUserIdReflectsState(): void
    {
        $this->assertNull($this->flow->pendingUserId());
        $this->givenPending(7, true, 'alice');
        $this->assertSame(7, $this->flow->pendingUserId());
    }

    /** cancel() abandons a pending step-up (user backed out to the login form). */
    public function testCancelClearsPending(): void
    {
        $this->givenPending(7, true, 'alice');
        $this->flow->cancel();
        $this->assertNull($this->flow->pendingUserId());
    }

    // ── default seams / wiring ────────────────────────────────────────────────

    /**
     * With no collaborators injected, each seam lazily resolves a real framework
     * instance — proving the zero-config path a scaffolded app relies on wires
     * itself up. (Runs against the live test DB/config like the rest of the suite.)
     */
    public function testDefaultSeamsResolveRealCollaborators(): void
    {
        $flow = new ExposedLoginFlow(); // no args → lazy defaults

        $this->assertInstanceOf(Auth::class, $flow->auth());
        $this->assertInstanceOf(Loginlockout::class, $flow->lockout());
        $this->assertInstanceOf(TwoFactorAuthService::class, $flow->twoFactor());
        $this->assertInstanceOf(PasskeyServiceInterface::class, $flow->passkeys());
        $this->assertSame(300, $flow->stepUpTtlPublic(), 'default step-up window is 5 minutes');
    }

    // ── LoginFlowResult value object ─────────────────────────────────────────────

    /** The result VO's predicates and negative-clamping behave as documented. */
    public function testLoginFlowResultShape(): void
    {
        $ok = LoginFlowResult::success(5);
        $this->assertTrue($ok->isSuccess());
        $this->assertFalse($ok->isFailed() || $ok->isLocked() || $ok->needsStepUp());

        $failed = LoginFlowResult::failed();
        $this->assertTrue($failed->isFailed());

        // A negative "remaining" is clamped to 0 so callers never see nonsense.
        $locked = LoginFlowResult::locked(-5);
        $this->assertTrue($locked->isLocked());
        $this->assertSame(0, $locked->lockoutRemaining);

        $step = LoginFlowResult::stepUpRequired(5, ['twofactor']);
        $this->assertTrue($step->needsStepUp());
        $this->assertTrue($step->allowsStepUpMethod('twofactor'));
        $this->assertFalse($step->allowsStepUpMethod('passkey'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** A successful driver/addon login-response array for user $uid. */
    private function successResponse(int $uid): array
    {
        return ['status' => true, 'uid' => $uid, 'username' => 'alice', 'email' => 'a@b.c', 'auth' => 'hash'];
    }

    /** Seed a pending step-up exactly as attempt() would. */
    private function givenPending(int $userId, bool $remember, string $identifier): void
    {
        $_SESSION['loginflow_pending_userid']     = $userId;
        $_SESSION['loginflow_pending_remember']   = $remember;
        $_SESSION['loginflow_pending_identifier'] = $identifier;
        $_SESSION['loginflow_pending_time']       = time();
    }
}

/** In-memory Auth double: verifyCredentials/loginById record args and return canned values. */
class FakeAuth extends Auth
{
    /** @var array<string,mixed>|false */
    public $response = false;
    public bool $loginReturn = true;
    public array $verifyArgs = [];
    public array $loginArgs = [];

    public function verifyCredentials(
        string $username,
        string $password,
        bool $encryptedPassword = false,
        bool $remember = false,
        bool $validate = true
    ): array|false {
        $this->verifyArgs = [$username, $password, $encryptedPassword, $remember];
        return $this->response;
    }

    public function loginById(int $userId, bool $remember = true): bool
    {
        $this->loginArgs = [$userId, $remember];
        return $this->loginReturn;
    }
}

/** In-memory Loginlockout double recording every interaction. */
class FakeLockout extends Loginlockout
{
    /** @var array{locked:bool,remaining:int} */
    public array $status = ['locked' => false, 'remaining' => 0];
    public array $recorded = [];
    public array $cleared = [];

    public function getLockoutStatus(string $scope, string $identifier): array
    {
        return $this->status;
    }

    public function recordFailedAttempt(string $scope, string $identifier): void
    {
        $this->recorded[] = [$scope, $identifier];
    }

    public function clearSuccessfulLoginState(string $scope, string $identifier): void
    {
        $this->cleared[] = [$scope, $identifier];
    }
}

/** In-memory TwoFactorAuthService double (no DB — constructor skipped). */
class FakeTwoFactor extends TwoFactorAuthService
{
    public bool $enabled = false;
    public bool $verifies = false;
    public array $verifyCalls = [];

    public function __construct()
    {
        // Intentionally skip parent to avoid a DB connection.
    }

    public function isEnabled(int $userId): bool
    {
        return $this->enabled;
    }

    public function verifyCode(int $userId, string $code): bool
    {
        $this->verifyCalls[] = [$userId, $code];
        return $this->verifies;
    }
}

/** In-memory PasskeyServiceInterface double; only hasCredentials() is exercised. */
class FakePasskeys implements PasskeyServiceInterface
{
    public bool $has = false;

    public function beginRegistration(int $userId, ?string $label = null): RegistrationOptions
    {
        return new RegistrationOptions('c', '{}', $userId);
    }

    public function finishRegistration(int $userId, RegistrationOptions $options, string $clientResponse): PasskeyCredential
    {
        return new PasskeyCredential(1, $userId, 'cid', 'pk', 0);
    }

    public function beginAuthentication(?int $userId = null): AuthenticationOptions
    {
        return new AuthenticationOptions('c', '{}', $userId);
    }

    public function finishAuthentication(AuthenticationOptions $options, string $clientResponse): VerificationResult
    {
        return new VerificationResult(1, new PasskeyCredential(1, 1, 'cid', 'pk', 1), 1);
    }

    public function listCredentials(int $userId): array
    {
        return [];
    }

    public function renameCredential(int $userId, int $credentialId, string $name): bool
    {
        return false;
    }

    public function revokeCredential(int $userId, int $credentialId): bool
    {
        return false;
    }

    public function hasCredentials(int $userId): bool
    {
        return $this->has;
    }
}

/** LoginFlow with all four collaborators replaced by in-memory doubles. */
class TestableLoginFlow extends LoginFlow
{
    public FakeAuth $fakeAuth;
    public FakeLockout $fakeLockout;
    public FakeTwoFactor $fakeTwoFactor;
    public FakePasskeys $fakePasskeys;

    public function __construct()
    {
        $this->fakeAuth      = new FakeAuth();
        $this->fakeLockout   = new FakeLockout();
        $this->fakeTwoFactor = new FakeTwoFactor();
        $this->fakePasskeys  = new FakePasskeys();

        // Inject through the real constructor so the default verifyCredentials()
        // and establishSession() bodies run against the fakes (real coverage).
        parent::__construct(
            $this->fakeAuth,
            $this->fakeLockout,
            $this->fakeTwoFactor,
            $this->fakePasskeys
        );
    }
}

/** Exposes the protected seams so the lazy-default wiring can be asserted. */
class ExposedLoginFlow extends LoginFlow
{
    public function auth(): Auth
    {
        return parent::auth();
    }

    public function lockout(): Loginlockout
    {
        return parent::lockout();
    }

    public function twoFactor(): TwoFactorAuthService
    {
        return parent::twoFactor();
    }

    public function passkeys(): PasskeyServiceInterface
    {
        return parent::passkeys();
    }

    public function stepUpTtlPublic(): int
    {
        return $this->stepUpTtl();
    }
}
