<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use Pramnos\Application\Application;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Application\Settings;
use Pramnos\Auth\ActivityLog;
use Pramnos\Auth\LoginFlow;
use Pramnos\Auth\LoginFlowResult;
use Pramnos\Auth\Loginlockout;
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
    private int $uid = 0;
    private string $username = '';

    protected function setUp(): void
    {
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

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
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
