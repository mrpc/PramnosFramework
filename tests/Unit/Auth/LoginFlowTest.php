<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Auth;
use Pramnos\Auth\EmailSecondFactor;
use Pramnos\Auth\LoginFlow;
use Pramnos\Auth\NewDeviceAuthLink;
use Pramnos\Auth\SecondFactorRegistry;
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

        // …and with an empty factor registry. The flow asks the registry what an account
        // is enrolled in, so a test that did not clear it would inherit whichever doubles
        // the previous one registered — and the real built-ins, which want a database.
        SecondFactorRegistry::reset();

        /*
         * An application that allows both factors.
         *
         * Declared rather than relied upon: the registry honours
         * `auth.twofactor_methods` whenever an application exists, and one left in the
         * registry by another test class made the email cases here fail with nothing in
         * this file changed — the factor was being filtered out by somebody else's
         * configuration. Saying what this test needs makes it independent of run order and
         * matches what production does.
         */
        $this->savedInstances = $this->installApplication(['totp', 'email']);
        $this->flow = new TestableLoginFlow();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        SecondFactorRegistry::reset();

        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))
                ->setValue(null, $this->savedInstances);
            $this->savedInstances = null;
        }
    }

    /** @var array<string,mixed>|null */
    private ?array $savedInstances = null;

    /**
     * Install an application declaring the given second-factor methods.
     *
     * @param  list<string>        $methods
     * @param  array<string,mixed> $security The `auth.security` switches to declare
     * @return array<string,mixed> The registry as it was, for tearDown
     */
    private function installApplication(array $methods, array $security = []): array
    {
        $stub = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['auth' => [
            'twofactor_methods' => $methods,
            'security'          => $security,
        ]];

        $reflection = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $saved = $reflection->getValue() ?? [];
        $instances = $saved;
        $instances['default'] = $stub;
        $reflection->setValue(null, $instances);

        return $saved;
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

    // ── login-method tagging (activity-log distinguishability) ───────────────────

    /**
     * A straight password login (no step-up) tags the session as `password`
     * BEFORE establishing it — so the activity log can distinguish it from a
     * step-up. The tag must be set, and set exactly once.
     */
    public function testPasswordLoginTagsMethodPassword(): void
    {
        // Arrange — good credentials, no second factor.
        $this->flow->fakeAuth->response     = $this->successResponse(7);
        $this->flow->fakeTwoFactor->enabled = false;

        // Act
        $this->flow->attempt('alice', 'secret');

        // Assert — the login was tagged 'password' once, before loginById ran.
        $this->assertSame(['password'], $this->flow->fakeAuth->methodCalls);
    }

    /**
     * Completing a pending login with a correct 2FA code tags the session as
     * `twofactor`, so a TOTP/backup-code step-up is recorded distinctly from a
     * plain password login.
     */
    public function testTwoFactorCompletionTagsMethodTwofactor(): void
    {
        // Arrange — a pending step-up, correct code.
        $this->givenPending(7, true, 'alice');
        $this->flow->fakeTwoFactor->verifies = true;

        // Act
        $this->flow->completeTwoFactor('123456');

        // Assert
        $this->assertSame(['twofactor'], $this->flow->fakeAuth->methodCalls);
    }

    /**
     * Completing a pending login with a matching passkey tags the session as
     * `passkey`, distinguishing a passkey step-up in the activity log.
     */
    public function testPasskeyCompletionTagsMethodPasskey(): void
    {
        // Arrange — a pending step-up for the same user the passkey verifies.
        $this->givenPending(7, true, 'alice');

        // Act
        $this->flow->completePasskey(7);

        // Assert
        $this->assertSame(['passkey'], $this->flow->fakeAuth->methodCalls);
    }

    /**
     * A failed completion (wrong 2FA code) never tags a login method, because no
     * session is established — the tag is only set on the finish path.
     */
    public function testFailedStepUpNeverTagsMethod(): void
    {
        $this->givenPending(7, false, 'alice');
        $this->flow->fakeTwoFactor->verifies = false;

        $this->flow->completeTwoFactor('000000');

        $this->assertSame([], $this->flow->fakeAuth->methodCalls, 'no tag without a session');
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

    // ── The email second factor ────────────────────────────────────────────────

    /**
     * An account whose only second factor is email still gets a step-up.
     *
     * The case the feature exists for: no authenticator app, no passkey, nothing set up
     * in advance. The step-up decision asked only about TOTP, so such an account went
     * straight through on a password alone.
     */
    public function testEmailOnlyAccountStopsForStepUp(): void
    {
        // Arrange
        $this->flow->fakeAuth->response       = $this->successResponse(7);
        $this->flow->fakeTwoFactor->enabled   = false;
        $this->flow->fakeEmailFactor->enabled = true;

        // Act
        $result = $this->flow->attempt('alice', 'secret', true);

        // Assert
        $this->assertTrue($result->needsStepUp());
        $this->assertSame(['email'], $result->stepUpMethods);
        $this->assertSame([], $this->flow->fakeAuth->loginArgs, 'no session before the code');
    }

    /**
     * With both, the authenticator app is offered first and email second.
     *
     * The order is the policy: a screen renders them in the order it is given, and an
     * account that went to the trouble of enrolling an app must not be nudged towards
     * the weaker channel.
     */
    public function testTheAppRanksAboveEmail(): void
    {
        // Arrange
        $this->flow->fakeAuth->response       = $this->successResponse(7);
        $this->flow->fakeTwoFactor->enabled   = true;
        $this->flow->fakeEmailFactor->enabled = true;

        // Act
        $result = $this->flow->attempt('alice', 'secret');

        // Assert
        $this->assertSame(['twofactor', 'email'], $result->stepUpMethods);
    }

    /**
     * No factor at all is still no step-up, whatever the email flag says.
     */
    public function testEmailFactorOffMeansNoStepUp(): void
    {
        // Arrange
        $this->flow->fakeAuth->response       = $this->successResponse(7);
        $this->flow->fakeTwoFactor->enabled   = false;
        $this->flow->fakeEmailFactor->enabled = false;

        // Act & Assert
        $this->assertTrue($this->flow->attempt('alice', 'secret')->isSuccess());
    }

    /**
     * A code is **not** sent when the password is accepted — only when asked for.
     *
     * Otherwise an account with an app and email as a fallback receives mail on every
     * sign-in it never reads, and each of somebody else's failed password attempts sends
     * one too.
     */
    public function testNoCodeIsSentUntilItIsAskedFor(): void
    {
        // Arrange
        $this->flow->fakeAuth->response       = $this->successResponse(7);
        $this->flow->fakeEmailFactor->enabled = true;

        // Act
        $this->flow->attempt('alice', 'secret');

        // Assert
        $this->assertSame(0, $this->flow->fakeEmailFactor->sent);

        // …and it is sent when the screen asks
        $this->assertTrue($this->flow->sendEmailCode());
        $this->assertSame(1, $this->flow->fakeEmailFactor->sent);
        $this->assertSame([7], $this->flow->fakeEmailFactor->sentTo);
    }

    /**
     * Nothing is sent without a pending login, or for an account without the factor.
     *
     * The first is an endpoint reachable before authentication that would otherwise mail
     * anybody's account on request; the second would tell a caller which accounts have
     * the factor.
     */
    public function testSendingRefusesWithoutAPendingLogin(): void
    {
        // Act & Assert — no pending login at all
        $this->assertFalse($this->flow->sendEmailCode());
        $this->assertSame(0, $this->flow->fakeEmailFactor->sent);

        // …and pending, but the account does not have the factor
        $this->flow->fakeAuth->response       = $this->successResponse(7);
        $this->flow->fakeTwoFactor->enabled   = true;
        $this->flow->fakeEmailFactor->enabled = false;
        $this->flow->attempt('alice', 'secret');

        $this->assertFalse($this->flow->sendEmailCode());
        $this->assertSame(0, $this->flow->fakeEmailFactor->sent);
    }

    /**
     * A correct emailed code finishes the login and is tagged as its own method.
     *
     * Tagged `email` rather than `twofactor` so an audit can tell which factor actually
     * carried a login — they are not equally strong, and a log that calls them the same
     * thing cannot answer that afterwards.
     */
    public function testACorrectEmailCodeFinishesTheLogin(): void
    {
        // Arrange
        $this->flow->fakeAuth->response       = $this->successResponse(7);
        $this->flow->fakeEmailFactor->enabled = true;
        $this->flow->fakeEmailFactor->accepts = true;
        $this->flow->attempt('alice', 'secret', true);

        // Act
        $result = $this->flow->completeEmailCode(' 123456 ');

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame(7, $result->userId);
        $this->assertSame(['123456'], $this->flow->fakeEmailFactor->verified, 'trimmed');
        $this->assertSame(['email'], $this->flow->fakeAuth->methodCalls);
        $this->assertArrayNotHasKey('loginflow_pending_userid', $_SESSION);
    }

    /**
     * A wrong code leaves the pending login alone so the person can retry.
     *
     * The attempt cap lives in the factor, not here: this flow must not decide that a
     * mistyped code ends the login, or a fat finger would mean starting again.
     */
    public function testAWrongEmailCodeKeepsThePendingLogin(): void
    {
        // Arrange
        $this->flow->fakeAuth->response       = $this->successResponse(7);
        $this->flow->fakeEmailFactor->enabled = true;
        $this->flow->fakeEmailFactor->accepts = false;
        $this->flow->attempt('alice', 'secret', true);

        // Act
        $result = $this->flow->completeEmailCode('000000');

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertSame(7, $_SESSION['loginflow_pending_userid']);
        $this->assertSame([], $this->flow->fakeAuth->loginArgs);
    }

    /**
     * And an emailed code cannot start a login that was never begun.
     */
    public function testAnEmailCodeWithoutAPendingLoginFails(): void
    {
        // Arrange
        $this->flow->fakeEmailFactor->accepts = true;

        // Act & Assert
        $this->assertFalse($this->flow->completeEmailCode('123456')->isSuccess());
        $this->assertSame([], $this->flow->fakeEmailFactor->verified, 'nothing is even checked');
    }

    /**
     * The screen can ask whether a code is already outstanding without sending one.
     */
    public function testTheFlowReportsAnOutstandingCode(): void
    {
        // Arrange
        $this->flow->fakeAuth->response       = $this->successResponse(7);
        $this->flow->fakeEmailFactor->enabled = true;
        $this->flow->attempt('alice', 'secret');

        // Act & Assert
        $this->assertFalse($this->flow->hasLiveEmailCode());
        $this->flow->fakeEmailFactor->live = true;
        $this->assertTrue($this->flow->hasLiveEmailCode());
        $this->assertSame(0, $this->flow->fakeEmailFactor->sent, 'asking must not send');
    }


    // ── The new-device auth link ───────────────────────────────────────────────

    /**
     * A link is mailed the moment a step-up that demands one begins.
     *
     * The one method the person cannot start themselves: a screen saying "we have emailed
     * you a link" with no mail sent is a dead end. Sent from `beginStepUp()` rather than
     * from the renderer, so a refresh does not reissue it and invalidate the link the
     * person is holding.
     */
    public function testTheLinkIsSentWhenTheStepUpBegins(): void
    {
        // Arrange — the site demands a link, so the flow's own step-up list carries it
        $this->flow->fakeAuth->response = $this->successResponse(7);
        $this->flow->demand             = ['authlink'];

        // Act
        $result = $this->flow->attempt('alice', 'secret', true);

        // Assert
        $this->assertTrue($result->needsStepUp());
        $this->assertSame(['authlink'], $result->stepUpMethods);
        $this->assertSame(1, $this->flow->fakeAuthLink->sent);
        $this->assertSame([7], $this->flow->fakeAuthLink->sentTo);
        $this->assertSame([], $this->flow->fakeAuth->loginArgs, 'no session until the link is opened');
    }

    /**
     * Opening a valid link finishes the login, and is tagged as the link.
     */
    public function testOpeningTheLinkFinishesTheLogin(): void
    {
        // Arrange
        $this->flow->fakeAuth->response      = $this->successResponse(7);
        $this->flow->demand                  = ['authlink'];
        $this->flow->attempt('alice', 'secret', true);
        $this->flow->fakeAuthLink->resolves  = 7;

        // Act
        $result = $this->flow->completeAuthLink('a-token');

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame(['authlink'], $this->flow->fakeAuth->methodCalls);
        $this->assertArrayNotHasKey('loginflow_pending_userid', $_SESSION);
    }

    /**
     * The link works in a browser that never saw the password leg.
     *
     * People read mail on a phone and click there. A flow that only completed in the
     * original browser would drop them back on the password form with no way to explain
     * why, and the token is the authorisation — the pending session is a convenience.
     */
    public function testTheLinkWorksWithNoPendingSession(): void
    {
        // Arrange — nothing pending at all
        $this->flow->fakeAuthLink->resolves = 7;

        // Act
        $result = $this->flow->completeAuthLink('a-token');

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame(7, $result->userId);
    }

    /**
     * A spent or unknown token establishes nothing.
     */
    public function testAnUnknownLinkTokenFails(): void
    {
        // Arrange
        $this->flow->fakeAuthLink->resolves = null;

        // Act
        $result = $this->flow->completeAuthLink('nonsense');

        // Assert
        $this->assertFalse($result->isSuccess());
        $this->assertSame([], $this->flow->fakeAuth->loginArgs);
        $this->assertSame(['nonsense'], $this->flow->fakeAuthLink->consumed);
    }

    /**
     * Resending refuses without a pending login.
     *
     * Otherwise the endpoint mails a sign-in link to any account on request, which is
     * worse than the password it is meant to protect.
     */
    public function testResendingTheLinkNeedsAPendingLogin(): void
    {
        // Act & Assert
        $this->assertFalse($this->flow->sendAuthLink());
        $this->assertSame(0, $this->flow->fakeAuthLink->sent);
    }


    // ── The privileged-account floor ───────────────────────────────────────────

    /**
     * An administrator with no second factor is asked for a mailed code.
     *
     * `require_second_factor_from_usertype` makes the factor a condition of the privilege
     * rather than a preference of the person holding it — the account with the most to
     * lose is the one least likely to have volunteered. It cannot lock them out: the
     * demand resolves to the one factor every account can satisfy.
     */
    public function testAPrivilegedAccountIsAskedForAFactor(): void
    {
        // Arrange — the floor is 90, and this account is 90 with nothing enrolled
        $this->installApplication(['totp', 'email'], ['require_second_factor_from_usertype' => 90]);
        $this->flow->fakeAuth->response = $this->successResponse(7);
        $this->flow->usertype           = 90;

        // Act
        $result = $this->flow->attempt('alice', 'secret');

        // Assert
        $this->assertTrue($result->needsStepUp());
        $this->assertSame(['email'], $result->stepUpMethods);
    }

    /**
     * The demanded code can actually be sent — the account never enrolled it.
     *
     * This is the lockout the floor was one line away from being, and it was live. The floor
     * puts an administrator with nothing set up on the step-up page and demands a mailed
     * code, which is the one demand every account can satisfy. `sendFactorChallenge()` then
     * refused to send it, because the check was `isEnrolledFor()` and the account had never
     * enrolled the email factor: the page offered a button that could not work, there was no
     * other way in, and nothing said why.
     *
     * So a demand authorises a send, and the demand is carried in the pending state rather
     * than recomputed — only the login that started knows what it demanded, and the
     * new-device policy can demand the same method from the same kind of account.
     */
    public function testTheFloorsDemandedCodeCanBeSent(): void
    {
        // Arrange — nothing enrolled, and the floor demands a mailed code
        $this->installApplication(['totp', 'email'], ['require_second_factor_from_usertype' => 90]);
        $this->flow->fakeAuth->response       = $this->successResponse(7);
        $this->flow->fakeEmailFactor->enabled = false;
        $this->flow->usertype                 = 90;
        $result = $this->flow->attempt('alice', 'secret');
        $this->assertSame(['email'], $result->stepUpMethods, 'the demand is the precondition');

        // Act
        $sent = $this->flow->sendFactorChallenge('email');

        // Assert
        $this->assertTrue($sent, 'a demanded factor has to be sendable, or the demand is a wall');
        $this->assertSame(1, $this->flow->fakeEmailFactor->sent);

        // …and what the step-up can complete includes it, so a screen asking "what is
        // available here" is not told "nothing" on exactly these accounts.
        $names = array_map(
            static fn ($factor): string => $factor->name(),
            $this->flow->pendingFactors()
        );
        $this->assertContains('email', $names);
    }

    /**
     * An account below the floor is unaffected.
     */
    public function testAnOrdinaryAccountIsNotAskedByTheFloor(): void
    {
        // Arrange
        $this->installApplication(['totp', 'email'], ['require_second_factor_from_usertype' => 90]);
        $this->flow->fakeAuth->response = $this->successResponse(7);
        $this->flow->usertype           = 50;

        // Act & Assert
        $this->assertTrue($this->flow->attempt('alice', 'secret')->isSuccess());
    }

    /**
     * With a factor already enrolled, the floor adds nothing.
     *
     * The floor exists to cover accounts with *nothing*; an administrator who has enrolled
     * an authenticator app must be asked for the app, not handed a weaker channel as well.
     */
    public function testTheFloorDoesNotWeakenAnEnrolledAccount(): void
    {
        // Arrange
        $this->installApplication(['totp', 'email'], ['require_second_factor_from_usertype' => 90]);
        $this->flow->fakeAuth->response     = $this->successResponse(7);
        $this->flow->fakeTwoFactor->enabled = true;
        $this->flow->usertype               = 99;

        // Act
        $result = $this->flow->attempt('alice', 'secret');

        // Assert
        $this->assertSame(['twofactor'], $result->stepUpMethods);
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
    /** Every login-method tag LoginFlow set before establishing the session. */
    public array $methodCalls = [];

    /**
     * Capture the activity-log method tag LoginFlow sets just before the session
     * bootstrap, so tests can assert each completion path (password / twofactor /
     * passkey) labels the login correctly. Delegates to the real setter so the
     * production semantics are still exercised.
     */
    public function setLoginMethod(?string $method): void
    {
        $this->methodCalls[] = $method;
        parent::setLoginMethod($method);
    }

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

/** LoginFlow with every collaborator replaced by in-memory doubles. */
class TestableLoginFlow extends LoginFlow
{
    public FakeAuth $fakeAuth;
    public FakeLockout $fakeLockout;
    public FakeTwoFactor $fakeTwoFactor;
    public FakePasskeys $fakePasskeys;
    public FakeEmailFactor $fakeEmailFactor;
    public FakeAuthLink $fakeAuthLink;

    /**
     * What the site demands of a new device, stubbed.
     *
     * The real answer comes from a setting plus a query against the activity log; this
     * flow's job is what it *does* with the answer, so the answer is injected.
     *
     * @var string[]
     */
    public array $demand = [];

    public function __construct()
    {
        $this->fakeAuth        = new FakeAuth();
        $this->fakeLockout     = new FakeLockout();
        $this->fakeTwoFactor   = new FakeTwoFactor();
        $this->fakePasskeys    = new FakePasskeys();
        $this->fakeEmailFactor = new FakeEmailFactor();
        $this->fakeAuthLink    = new FakeAuthLink();

        // The doubles, behind the real adaptors, in the real registry — so the flow runs
        // its production path and the test still controls the answers.
        SecondFactorRegistry::register(
            new \Pramnos\Auth\Factors\TotpSecondFactor($this->fakeTwoFactor)
        );
        SecondFactorRegistry::register(
            new \Pramnos\Auth\Factors\EmailCodeSecondFactor($this->fakeEmailFactor)
        );

        // Inject through the real constructor so the default verifyCredentials()
        // and establishSession() bodies run against the fakes (real coverage).
        parent::__construct(
            $this->fakeAuth,
            $this->fakeLockout,
            $this->fakeTwoFactor,
            $this->fakePasskeys,
            $this->fakeEmailFactor,
            $this->fakeAuthLink
        );
    }

    /** The account's usertype, without a database. */
    public int $usertype = 0;

    protected function usertypeOf(int $userId): int
    {
        return $this->usertype;
    }

    protected function stepUpMethods(int $userId): array
    {
        $methods = parent::stepUpMethods($userId);
        foreach ($this->demand as $method) {
            if (!in_array($method, $methods, true)) {
                $methods[] = $method;
            }
        }

        return $methods;
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

/**
 * The email second factor, without a database or a mail server.
 *
 * Extends the real class so the flow is exercised through its real type, with the
 * constructor skipped — the production one resolves the database singleton, which is
 * exactly what a unit test must not touch.
 */
class FakeEmailFactor extends EmailSecondFactor
{
    public bool $enabled = false;
    public bool $accepts = false;
    public bool $live    = false;
    public int  $sent    = 0;
    /** @var list<int> */
    public array $sentTo = [];
    /** @var list<string> */
    public array $verified = [];

    public function __construct()
    {
    }

    public function isEnabledFor(int $userId): bool
    {
        return $this->enabled;
    }

    public function send(int $userId, string $purpose = self::PURPOSE_LOGIN): bool
    {
        $this->sent++;
        $this->sentTo[] = $userId;

        return true;
    }

    public function hasLiveCode(int $userId, string $purpose = self::PURPOSE_LOGIN): bool
    {
        return $this->live;
    }

    public function verify(int $userId, string $code, string $purpose = self::PURPOSE_LOGIN): bool
    {
        $this->verified[] = $code;

        return $this->accepts;
    }
}

/**
 * The new-device auth link, without a mailer or a token store.
 */
class FakeAuthLink extends NewDeviceAuthLink
{
    public int $sent = 0;
    /** @var list<int> */
    public array $sentTo = [];
    /** The user id a token resolves to, or null for "not a valid token". */
    public ?int $resolves = null;
    /** @var list<string> */
    public array $consumed = [];

    public function __construct()
    {
    }

    public function send(int $userId, string $returnUrl = ''): bool
    {
        $this->sent++;
        $this->sentTo[] = $userId;

        return true;
    }

    public function consume(string $token): ?int
    {
        $this->consumed[] = $token;

        return $this->resolves;
    }
}
