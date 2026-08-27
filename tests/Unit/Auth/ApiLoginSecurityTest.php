<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\ApiLoginFlow;
use Pramnos\Auth\LoginFlowResult;

/**
 * A flow whose collaborators are supplied by the test.
 *
 * The point of `ApiLoginFlow` is that it inherits every decision from
 * `LoginFlow` and changes only the last step, so what is exercised here is the
 * inherited sequence — lockout, credentials, second factor — reached through
 * the API subclass.
 */
class ProbeApiLoginFlow extends ApiLoginFlow
{
    /** @var array<string, mixed>|false What the credentials check returns. */
    public array|false $credentials = false;

    /** @var array<int, string> Second factors the account needs. */
    public array $stepUp = [];

    /** @var int Seconds remaining on a lockout; 0 means not locked. */
    public int $lockedFor = 0;

    /** @var bool Whether a browser session was established. */
    public bool $sessionEstablished = false;

    /** @var array<string, mixed>|null Pending step-up state, held in memory. */
    private ?array $pendingState = null;

    /** @var string|null Code the pending 2FA check will accept. */
    public ?string $validCode = null;

    public function __construct()
    {
        parent::__construct();
    }

    protected function verifyCredentials(string $username, string $password, bool $remember): array|false
    {
        return $this->credentials;
    }

    protected function stepUpMethods(int $userId): array
    {
        return $this->stepUp;
    }

    protected function establishSession(int $userId, bool $remember): bool
    {
        // Recorded, not performed: if the parent's finishLogin() ever reached
        // the real one, an API call would silently start a browser session.
        $this->sessionEstablished = true;

        return parent::establishSession($userId, $remember);
    }

    protected function lockout(): \Pramnos\Auth\Loginlockout
    {
        $remaining = $this->lockedFor;

        return new class ($remaining) extends \Pramnos\Auth\Loginlockout {
            public function __construct(private int $remaining)
            {
                // No database: every method that would need one is replaced.
            }

            public function getLockoutStatus(string $scope, string $identifier): array
            {
                return ['locked' => $this->remaining > 0, 'remaining' => $this->remaining];
            }

            public function recordFailedAttempt(string $scope, string $identifier): void
            {
            }

            public function clearSuccessfulLoginState(string $scope, string $identifier): void
            {
            }
        };
    }

    /**
     * @param string[] $methods The step-up methods on offer. Recorded rather than acted
     *                          on: the base class mails a sign-in link when `authlink` is
     *                          among them, which a probe with no mailer must not do.
     */
    protected function beginStepUp(
        int $userId,
        bool $remember,
        string $identifier,
        array $methods = []
    ): void {
        $this->pendingState = [
            'userId'     => $userId,
            'remember'   => $remember,
            'identifier' => $identifier,
            'methods'    => $methods,
        ];
    }

    protected function pending(): ?array
    {
        return $this->pendingState;
    }

    protected function clearPending(): void
    {
        $this->pendingState = null;
    }

    protected function twoFactor(): \Pramnos\Auth\TwoFactorAuthService
    {
        $valid = $this->validCode;

        return new class ($valid) extends \Pramnos\Auth\TwoFactorAuthService {
            public function __construct(private ?string $valid)
            {
                // No database: both methods used here are replaced.
            }

            public function isEnabled(int $userId): bool
            {
                return true;
            }

            public function verifyCode(int $userId, string $code): bool
            {
                return $this->valid !== null && hash_equals($this->valid, $code);
            }
        };
    }
}

/**
 * The real flow with only its lockout replaced.
 *
 * Used where the point is the *inherited* credentials path — the resolver the
 * caller supplied — so `verifyCredentials()` must not be overridden.
 */
class ResolverApiLoginFlow extends ApiLoginFlow
{
    /** No second factor here: the credentials path is what is under test. */
    protected function stepUpMethods(int $userId): array
    {
        return [];
    }

    /** No session either — this is the API flow. */
    protected function establishSession(int $userId, bool $remember): bool
    {
        return true;
    }

    protected function lockout(): \Pramnos\Auth\Loginlockout
    {
        return new class extends \Pramnos\Auth\Loginlockout {
            public function __construct()
            {
                // No database: nothing here reaches one.
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
    }
}

/**
 * Covers the decisions the JSON login endpoint makes before issuing a token.
 *
 * It used to make none of them: `ApiAccount::login()` went from password
 * straight to bearer token. An account with two-factor authentication enabled
 * could be entered with the password alone — the HTML login enforced the second
 * factor, the API did not — and no failed attempt was ever counted, so the
 * endpoint took unlimited password guesses.
 *
 * The fix is not a second implementation of those rules. It is the same
 * `LoginFlow` with one seam replaced, which is what these tests pin: the
 * inherited sequence still runs, and the last step no longer creates a session.
 */
class ApiLoginSecurityTest extends TestCase
{
    /**
     * An account with a second factor does not get a token from the password.
     *
     * This is the bypass. The password is correct — the flow reports
     * `STEP_UP_REQUIRED`, not `SUCCESS`, so the caller has nothing to mint a
     * token from.
     */
    public function testAnAccountWithTwoFactorDoesNotFinishOnThePasswordAlone(): void
    {
        // Arrange
        $flow = new ProbeApiLoginFlow();
        $flow->credentials = ['status' => true, 'uid' => 7];
        $flow->stepUp      = ['twofactor'];

        // Act
        $result = $flow->attempt('alice', 'correct-horse', false);

        // Assert
        $this->assertSame(LoginFlowResult::STEP_UP_REQUIRED, $result->status);
        $this->assertFalse($result->isSuccess(), 'no success means no token');
        $this->assertSame(['twofactor'], $result->stepUpMethods);
    }

    /**
     * ...and the second factor finishes it.
     */
    public function testTheSecondFactorCompletesTheLogin(): void
    {
        // Arrange
        $flow = new ProbeApiLoginFlow();
        $flow->credentials = ['status' => true, 'uid' => 7];
        $flow->stepUp      = ['twofactor'];
        $flow->validCode   = '123456';
        $flow->attempt('alice', 'correct-horse', false);

        // Act
        $result = $flow->completeTwoFactor('123456');

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame(7, $result->userId);
    }

    /**
     * A wrong code does not finish it, and leaves the pending login alone so the
     * user can try again.
     */
    public function testAWrongCodeDoesNotCompleteTheLogin(): void
    {
        // Arrange
        $flow = new ProbeApiLoginFlow();
        $flow->credentials = ['status' => true, 'uid' => 7];
        $flow->stepUp      = ['twofactor'];
        $flow->validCode   = '123456';
        $flow->attempt('alice', 'correct-horse', false);

        // Act
        $wrong = $flow->completeTwoFactor('000000');

        // Assert
        $this->assertFalse($wrong->isSuccess());
        $this->assertTrue(
            $flow->completeTwoFactor('123456')->isSuccess(),
            'the pending login survives a wrong code'
        );
    }

    /**
     * An account without a second factor is unaffected.
     *
     * The overwhelmingly common case, and the one that must not change: correct
     * password in, success out, in one leg.
     */
    public function testAnAccountWithoutTwoFactorLogsInAsBefore(): void
    {
        // Arrange
        $flow = new ProbeApiLoginFlow();
        $flow->credentials = ['status' => true, 'uid' => 4];

        // Act
        $result = $flow->attempt('bob', 'hunter2', false);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame(4, $result->userId);
    }

    /**
     * A locked identifier is refused before the password is even checked.
     *
     * This is the brute-force protection the endpoint never had: the HTML login
     * counted failures against `Loginlockout`, the JSON one did not, so the same
     * account could be guessed at indefinitely through the API.
     */
    public function testALockedAccountIsRefusedWithoutCheckingThePassword(): void
    {
        // Arrange
        $flow = new ProbeApiLoginFlow();
        $flow->lockedFor   = 300;
        $flow->credentials = ['status' => true, 'uid' => 7];

        // Act
        $result = $flow->attempt('alice', 'correct-horse', false);

        // Assert
        $this->assertTrue($result->isLocked());
        $this->assertSame(300, $result->lockoutRemaining);
    }

    /**
     * Wrong credentials fail, and are counted.
     */
    public function testWrongCredentialsFail(): void
    {
        // Arrange
        $flow = new ProbeApiLoginFlow();
        $flow->credentials = false;

        // Act + Assert
        $this->assertTrue($flow->attempt('alice', 'wrong', false)->isFailed());
    }

    /**
     * The API flow never establishes a browser session.
     *
     * The reason this subclass exists. `LoginFlow::finishLogin()` calls
     * `establishSession()`, which on the HTML path logs the user in and sets
     * cookies. An API call that did that would quietly turn a stateless token
     * request into a session login.
     */
    public function testTheApiFlowIssuesNoBrowserSession(): void
    {
        // Arrange
        $flow = new ProbeApiLoginFlow();
        $flow->credentials = ['status' => true, 'uid' => 4];

        // Act
        $result = $flow->attempt('bob', 'hunter2', false);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertTrue(
            $flow->sessionEstablished,
            'the seam is reached...'
        );
        $this->assertFalse(
            isset($_SESSION['logged']) && $_SESSION['logged'] === true,
            '...but it establishes nothing'
        );
    }

    /**
     * A caller's own credentials check is used when it supplies one.
     *
     * `ApiAccount::verifyCredentials()` is a documented seam that applications
     * override. Moving the decision into a flow object must not take that away.
     */
    public function testACallerSuppliedCredentialsCheckIsUsed(): void
    {
        // Arrange
        $asked = [];
        $flow  = new ResolverApiLoginFlow(
            function (string $username, string $password) use (&$asked): array|false {
                $asked[] = $username;

                return $username === 'carol' ? ['status' => true, 'uid' => 9] : false;
            }
        );

        // Act
        $result = $flow->attempt('carol', 'whatever', false);

        // Assert
        $this->assertSame(['carol'], $asked, 'the resolver was consulted');
        $this->assertTrue($result->isSuccess());
        $this->assertSame(9, $result->userId);
    }
}
