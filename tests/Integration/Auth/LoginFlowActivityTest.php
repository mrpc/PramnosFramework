<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use Pramnos\Addon\Addon;
use Pramnos\Application\Application;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Application\Settings;
use Pramnos\Auth\ActivityLog;
use Pramnos\Auth\LoginFlow;
use Pramnos\Auth\LoginFlowResult;
use Pramnos\Auth\Loginlockout;
use Pramnos\Auth\TwoFactorAuthService;
use Pramnos\Auth\Passkey\AuthenticationOptions;
use Pramnos\Auth\Passkey\PasskeyCredential;
use Pramnos\Auth\Passkey\PasskeyServiceInterface;
use Pramnos\Auth\Passkey\RegistrationOptions;
use Pramnos\Auth\Passkey\VerificationResult;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * A LoginFlow whose credential check always fails — lets us drive the
 * failed-login branch without a real Auth backend.
 */
class FailingLoginFlow extends LoginFlow
{
    protected function verifyCredentials(string $username, string $password, bool $remember): array|false
    {
        return false;
    }
}

/**
 * A lockout double that starts unlocked and flips to locked once a failed
 * attempt is recorded — so attempt() passes the opening gate but then observes
 * a lockout it must attribute to the account.
 */
class FlippingLockout extends Loginlockout
{
    public bool $locked = false;

    public function getLockoutStatus(string $scope, string $identifier): array
    {
        return ['locked' => $this->locked, 'remaining' => $this->locked ? 30 : 0];
    }

    public function recordFailedAttempt(string $scope, string $identifier): void
    {
        $this->locked = true;
    }

    public function clearSuccessfulLoginState(string $scope, string $identifier): void
    {
    }
}

/**
 * A LoginFlow whose password check always succeeds for a fixed account — lets us
 * drive the successful-login branches (and the step-up completions) against the
 * real session/activity lifecycle without a real password backend.
 */
class SucceedingLoginFlow extends LoginFlow
{
    public int $uid = 0;
    public string $username = '';

    protected function verifyCredentials(string $username, string $password, bool $remember): array|false
    {
        return [
            'status'   => true,
            'uid'      => $this->uid,
            'username' => $this->username,
            'email'    => $this->username . '@example.com',
            'auth'     => 'hash',
            'remember' => $remember,
        ];
    }
}

/** A lockout double that is always unlocked and records nothing. */
class OpenLockout extends Loginlockout
{
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
}

/** A 2FA double with settable enabled/verifies state and no database. */
class StubTwoFactor extends TwoFactorAuthService
{
    public function __construct(public bool $enabled = false, public bool $verifies = true)
    {
        // Skip parent to avoid a DB connection.
    }

    public function isEnabled(int $userId): bool
    {
        return $this->enabled;
    }

    public function verifyCode(int $userId, string $code): bool
    {
        return $this->verifies;
    }
}

/** A passkey double whose only meaningful behaviour is hasCredentials(). */
class StubPasskeys implements PasskeyServiceInterface
{
    public function __construct(public bool $has = false)
    {
    }

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

/**
 * Integration test for LoginFlow's failed-login activity attribution — the
 * branch that records `login_failed` (and `account_locked` when the failure
 * trips the lockout) against the real account behind a username, resolved via
 * the real database.
 *
 * Runs on MySQL only (the authserver.user_activity_log schema qualifier maps to
 * the authserver_ prefix there).
 */
class LoginFlowActivityTest extends BaseTestCase
{
    private \Pramnos\Database\Database $db;
    private string $table;
    private array $enabledSnapshot = [];
    private array $addonSnapshot = [];
    private int $uid = 0;
    private string $username = '';

    protected function setUp(): void
    {
        // The flow asks SecondFactorRegistry what an account is enrolled in, so a stub
        // handed to the constructor is no longer what decides. Cleared here and registered
        // per test, or the real built-ins answer — against this test's own database.
        \Pramnos\Auth\SecondFactorRegistry::reset();

        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = null;
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('LoginFlowActivityTest runs on MySQL only.');
        }

        $this->table = $this->db->prefix . 'authserver_user_activity_log';

        $prop = new \ReflectionProperty(FeatureRegistry::class, 'enabled');
        $this->enabledSnapshot = $prop->getValue();
        FeatureRegistry::loadFromConfig(['auth']);

        User::setupDb();
        $this->db->query("DROP TABLE IF EXISTS `{$this->table}`");
        $this->runMigrations(
            [\Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class],
            $this->db
        );
        ActivityLog::resetTableCache();

        // Seed a real account so resolveUserId() can attribute the failure.
        $u = new User();
        $this->username = 'lockme_' . bin2hex(random_bytes(4));
        $u->username = $this->username;
        $u->email    = $this->username . '@example.com';
        $u->setPassword('Secr3t!pass');
        $u->save();
        $this->uid = (int) $u->userid;

        // Force the built-in login lifecycle (executeDefaultLogin, which writes
        // the 'login' activity row) by clearing any registered user addon — an
        // addon would take the triggerLogin() addon path instead.
        $addonProp = new \ReflectionProperty(Addon::class, '_addons');
        $this->addonSnapshot = $addonProp->getValue();
        $addonProp->setValue(null, []);

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $addonProp = new \ReflectionProperty(Addon::class, '_addons');
        $addonProp->setValue(null, $this->addonSnapshot);
        if ($this->uid > 0) {
            $this->db->queryBuilder()->table('authserver.user_activity_log')->where('userid', $this->uid)->delete();
            $this->db->queryBuilder()->table('users')->where('userid', $this->uid)->delete();
        }
        $prop = new \ReflectionProperty(FeatureRegistry::class, 'enabled');
        $prop->setValue(null, $this->enabledSnapshot);
        ActivityLog::resetTableCache();
        $_SESSION = [];
    }

    /** Count activity-log rows of a given action for the seeded user. */
    private function actionCount(string $action): int
    {
        return (int) $this->db->queryBuilder()
            ->table('authserver.user_activity_log')
            ->where('userid', $this->uid)
            ->where('action', $action)
            ->count();
    }

    /**
     * The `method` recorded in the details of the seeded user's `login` row, or
     * null when there is no login row (or it carried no details).
     */
    private function loginMethodOf(): ?string
    {
        $row = $this->db->queryBuilder()
            ->table('authserver.user_activity_log')
            ->where('userid', $this->uid)
            ->where('action', 'login')
            ->first();
        if (!$row || $row->numRows === 0) {
            return null;
        }
        $details = $row->fields['details'] ?? null;
        if ($details === null || $details === '') {
            return null;
        }
        $decoded = json_decode((string) $details, true);
        return is_array($decoded) ? ($decoded['method'] ?? null) : null;
    }

    /**
     * A straight password login (no second factor) records a single `login`
     * activity row whose details tag the method as `password` — the default the
     * built-in lifecycle uses when nothing set a step-up method.
     */
    public function testPasswordLoginRecordsMethodPassword(): void
    {
        // Arrange — succeeding credentials, 2FA disabled → no step-up.
        $flow = new SucceedingLoginFlow(null, new OpenLockout(), new StubTwoFactor(false), new StubPasskeys(false));
        $flow->uid      = $this->uid;
        $flow->username = $this->username;

        // Act
        $result = $flow->attempt($this->username, 'whatever', false);

        // Assert — logged in, one login row, tagged 'password'.
        $this->assertTrue($result->isSuccess(), 'password login must succeed');
        $this->assertSame(1, $this->actionCount('login'), 'exactly one login row');
        $this->assertSame('password', $this->loginMethodOf(), 'password login tagged as password');
    }

    /**
     * A login completed through the 2FA step-up records the `login` row with
     * method `twofactor`, distinguishing it from a plain password login.
     */
    public function testTwoFactorLoginRecordsMethodTwofactor(): void
    {
        // Arrange — 2FA enabled forces a step-up; the code verifies.
        $twoFactor = new StubTwoFactor(true, true);
        \Pramnos\Auth\SecondFactorRegistry::register(
            new \Pramnos\Auth\Factors\TotpSecondFactor($twoFactor)
        );

        $flow = new SucceedingLoginFlow(null, new OpenLockout(), $twoFactor, new StubPasskeys(false));
        $flow->uid      = $this->uid;
        $flow->username = $this->username;

        // Act — password leg stops for step-up, then the TOTP code completes it.
        $step = $flow->attempt($this->username, 'whatever', false);
        $this->assertTrue($step->needsStepUp(), 'a 2FA account must stop for a step-up');
        $result = $flow->completeTwoFactor('123456');

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $this->actionCount('login'));
        $this->assertSame('twofactor', $this->loginMethodOf(), '2FA step-up tagged as twofactor');
    }

    /**
     * A login completed through a passkey step-up records the `login` row with
     * method `passkey`.
     */
    public function testPasskeyLoginRecordsMethodPasskey(): void
    {
        // Arrange — 2FA enabled + a registered passkey offers the passkey step-up.
        $twoFactor = new StubTwoFactor(true, true);
        \Pramnos\Auth\SecondFactorRegistry::register(
            new \Pramnos\Auth\Factors\TotpSecondFactor($twoFactor)
        );

        $flow = new SucceedingLoginFlow(null, new OpenLockout(), $twoFactor, new StubPasskeys(true));
        $flow->uid      = $this->uid;
        $flow->username = $this->username;

        // Act — password leg stops for step-up, then the passkey completes it.
        $step = $flow->attempt($this->username, 'whatever', false);
        $this->assertTrue($step->needsStepUp());
        $this->assertTrue($step->allowsStepUpMethod('passkey'), 'passkey must be offered');
        $result = $flow->completePasskey($this->uid);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $this->actionCount('login'));
        $this->assertSame('passkey', $this->loginMethodOf(), 'passkey step-up tagged as passkey');
    }

    /**
     * A failed attempt against a known account records `login_failed`, and — when
     * the failure trips the lockout — also `account_locked`, both attributed to
     * the resolved user id.
     */
    public function testFailedLoginRecordsLoginFailedAndAccountLocked(): void
    {
        $lockout = new FlippingLockout();
        $flow    = new FailingLoginFlow(null, $lockout, null, null);

        $result = $flow->attempt($this->username, 'wrong-password');

        $this->assertInstanceOf(LoginFlowResult::class, $result);
        $this->assertTrue($result->isFailed(), 'a wrong password must yield a failed result');
        $this->assertSame(1, $this->actionCount('login_failed'), 'login_failed must be recorded for the account');
        $this->assertSame(1, $this->actionCount('account_locked'),
            'account_locked must be recorded when the failure trips the lockout');
    }

    /**
     * A failed attempt against an UNKNOWN identifier leaves no activity trail
     * (nothing to attribute) — the resolveUserId === null branch.
     */
    public function testFailedLoginForUnknownUserRecordsNothing(): void
    {
        $lockout = new FlippingLockout();
        $flow    = new FailingLoginFlow(null, $lockout, null, null);

        $result = $flow->attempt('no_such_user_' . bin2hex(random_bytes(3)), 'whatever');

        $this->assertTrue($result->isFailed());
        // No rows for our seeded user, and none for the unknown identifier either.
        $this->assertSame(0, $this->actionCount('login_failed'));
        $total = (int) $this->db->queryBuilder()->table('authserver.user_activity_log')->count();
        $this->assertSame(0, $total, 'an unknown identifier must leave no activity trail');
    }
}
