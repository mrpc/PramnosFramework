<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Auth\SecurityPolicy;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * Changing a password from inside the account — 18 statements that had never run.
 *
 * Every branch is a refusal or a consequence, and the two worth the class are at opposite ends of
 * the method:
 *
 *   - **the current password is asked for**, so a session somebody else is holding cannot change
 *     the password out from under its owner. It is the same re-authentication the second-factor
 *     screen does for the same reason;
 *   - **the other sessions are ended, and the current one is not.** People change a password
 *     *because* they think somebody else has it, and leaving the other sessions alive means the
 *     other person keeps the account while the owner believes they have just taken it back —
 *     worse than not offering the change, because it manufactures confidence. Signing the person
 *     out of their own browser is the opposite failure: it reads as the change not having worked.
 *
 * Between them, the password history: refused *after* the policy and *before* the write, so the
 * message is about the password rather than about the attempt, and the hash remembered is the one
 * the account is moving **away** from.
 *
 * Both backends: {@see AccountChangePasswordPostgreSQLTest} re-runs it. The history is an insert
 * and a trimmed read, and the session revocation is an update scoped by two columns.
 */
#[CoversClass(Account::class)]
class AccountChangePasswordTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    private array $originalInfo = [];

    private const OLD = 'the-old-password-3!';

    private const NEW = 'a-brand-new-password-9!';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        $application = Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        User::setupDb();

        /*
         * Dropped before it is migrated, for the reason written up this morning: the table
         * survives between runs, more than one test creates it, and `runMigrations()` is a no-op
         * when it already exists — so a shape left by whoever went first would decide whether the
         * writes below succeed, silently.
         */
        $this->db->query(
            'DROP TABLE IF EXISTS '
            . $this->db->schema()->quoteTable('authserver.user_activity_log')
        );
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class,
            \Pramnos\Framework\Migrations\AuthServer\CreatePasswordHistoryTable::class,
            \Pramnos\Framework\Migrations\Core\CreateSessionsTable::class,
        ], $this->db);
        \Pramnos\Auth\ActivityLog::resetTableCache();

        $this->originalInfo = (array) $application->applicationInfo;
        $application->applicationInfo['features'] = ['auth', 'authserver'];

        /*
         * And the registry, which is loaded from configuration rather than read off
         * `applicationInfo`. `ActivityLog::record()` asks the registry, so declaring the feature
         * on the application alone leaves the log a silent no-op — and a test asserting that
         * something was recorded would then be asserting that nothing was.
         */
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['auth', 'authserver']);

        $user = new User();
        $user->username = 'changepw_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.test';
        $user->save();
        $this->uid = (int) $user->userid;

        $withPassword = new User($this->uid);
        $withPassword->setPassword(self::OLD);
        $withPassword->save();

        \Pramnos\Http\RequestIdentity::seal(new User($this->uid), 'test');

        $_POST = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        \Pramnos\Http\RequestIdentity::reset();

        $application = Application::currentInstance();
        if (is_object($application)) {
            $application->applicationInfo = $this->originalInfo;
        }

        if ($this->uid > 0) {
            foreach (
                [
                    'authserver.password_history',
                    'authserver.user_activity_log',
                    '#PREFIX#sessions',
                    '#PREFIX#userdetails',
                    '#PREFIX#users',
                ] as $table
            ) {
                try {
                    $this->db->queryBuilder()->table($table)->where('userid', $this->uid)->delete();
                } catch (\Throwable $exception) {
                    // Nothing to undo.
                }
            }
        }

        $_POST = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::resetInstance();
        User::clearUserCache();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── The refusals ──────────────────────────────────────────────────────────

    /**
     * Without the current password, nothing changes.
     *
     * The re-authentication. A session is a bearer credential that lives in a browser somebody
     * may have walked away from, and a password change is how an account is taken over
     * permanently — this is the step that costs an attacker something they have to already know.
     */
    public function testTheCurrentPasswordIsRequired(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->postWithToken([
            'current_password' => 'not-the-password',
            'new_password'     => self::NEW,
            'confirm_password' => self::NEW,
        ]);

        // Act
        $probe->changepassword();

        // Assert
        $this->assertTrue($this->stillHas(self::OLD), 'the password changed without the old one');
        $this->assertNotSame([], $probe->errors);
        $this->assertSame([], $probe->messages);
    }

    /** A POST with no anti-CSRF token changes nothing. */
    public function testAPostWithoutTheTokenChangesNothing(): void
    {
        // Arrange
        $probe = $this->probe();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'current_password' => self::OLD,
            'new_password'     => self::NEW,
            'confirm_password' => self::NEW,
        ];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->changepassword();

        // Assert
        $this->assertTrue($this->stillHas(self::OLD));
        $this->assertNotSame([], $probe->errors);
    }

    /** A new password that does not match its confirmation is refused. */
    public function testAMismatchedConfirmationIsRefused(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->postWithToken([
            'current_password' => self::OLD,
            'new_password'     => self::NEW,
            'confirm_password' => self::NEW . 'x',
        ]);

        // Act
        $probe->changepassword();

        // Assert
        $this->assertTrue($this->stillHas(self::OLD));
        $this->assertNotSame([], $probe->errors);
    }

    // ── The history ───────────────────────────────────────────────────────────

    /**
     * A password this account has used before is refused, when the policy asks.
     *
     * Only meaningful where there is a reason to change: in that situation the first instinct is
     * the password the person already knows, which looks like a change and is not one.
     */
    public function testAPasswordUsedBeforeIsRefused(): void
    {
        // Arrange — remember one, then try to come back to it.
        Application::currentInstance()->applicationInfo['auth']['security']['password_history'] = 3;
        $this->assertSame(3, SecurityPolicy::passwordHistory(), 'precondition: history is on');

        $probe = $this->probe();
        $this->postWithToken([
            'current_password' => self::OLD,
            'new_password'     => self::NEW,
            'confirm_password' => self::NEW,
        ]);
        $probe->changepassword();
        $this->assertTrue($this->stillHas(self::NEW), 'precondition: the first change went through');

        // Act — back to the old one.
        $again = $this->probe();
        $this->postWithToken([
            'current_password' => self::NEW,
            'new_password'     => self::OLD,
            'confirm_password' => self::OLD,
        ]);
        $again->changepassword();

        // Assert
        $this->assertTrue($this->stillHas(self::NEW), 'a previously used password was accepted');
        $this->assertNotSame([], $again->errors);
    }

    /**
     * What is remembered is the hash being moved away from, not the new one.
     *
     * Remembering the new hash would refuse the password the account has *right now* on the next
     * change — which reads as the history being broken, and is the mistake the ordering of those
     * two lines exists to avoid.
     */
    public function testTheHashRememberedIsThePreviousOne(): void
    {
        // Arrange
        Application::currentInstance()->applicationInfo['auth']['security']['password_history'] = 3;
        $probe = $this->probe();
        $this->postWithToken([
            'current_password' => self::OLD,
            'new_password'     => self::NEW,
            'confirm_password' => self::NEW,
        ]);

        // Act
        $probe->changepassword();

        // Assert
        $history = new \Pramnos\Auth\PasswordHistory();
        $this->assertTrue(
            $history->wasUsedBefore($this->uid, self::OLD),
            'the password just moved away from is not in the history'
        );
        $this->assertFalse(
            $history->wasUsedBefore($this->uid, self::NEW),
            'the password the account now has was remembered as a previous one'
        );
    }

    /** With history off, coming back to an old password is allowed. */
    public function testWithHistoryOffAnOldPasswordIsAllowed(): void
    {
        // Arrange — the default: nothing remembered.
        $this->assertSame(0, SecurityPolicy::passwordHistory(), 'precondition: history is off');

        $probe = $this->probe();
        $this->postWithToken([
            'current_password' => self::OLD,
            'new_password'     => self::NEW,
            'confirm_password' => self::NEW,
        ]);
        $probe->changepassword();

        // Act
        $again = $this->probe();
        $this->postWithToken([
            'current_password' => self::NEW,
            'new_password'     => self::OLD,
            'confirm_password' => self::OLD,
        ]);
        $again->changepassword();

        // Assert
        $this->assertTrue($this->stillHas(self::OLD));
    }

    // ── The other sessions ────────────────────────────────────────────────────

    /**
     * With the policy on, the other devices are signed out and this one is not.
     *
     * Both halves matter and they fail differently. Leaving the others alive gives the owner
     * false confidence that they have taken the account back; signing this one out reads as the
     * change not having worked, and the person cannot tell whether to try again.
     */
    public function testTheOtherSessionsEndAndThisOneDoesNot(): void
    {
        // Arrange
        Application::currentInstance()
            ->applicationInfo['auth']['security']['revoke_sessions_on_password_change'] = true;
        $this->assertTrue(SecurityPolicy::revokesSessionsOnPasswordChange());

        $mine   = md5(session_id());
        $theirs = 'other-device-' . bin2hex(random_bytes(4));
        $this->seedSession($mine);
        $this->seedSession($theirs);

        $probe = $this->probe();
        $this->postWithToken([
            'current_password' => self::OLD,
            'new_password'     => self::NEW,
            'confirm_password' => self::NEW,
        ]);

        // Act
        $probe->changepassword();

        // Assert
        $this->assertTrue($this->stillHas(self::NEW), 'the change did not go through');
        $this->assertSame(1, $this->logoutFlag($theirs), 'the other device was left signed in');
        $this->assertSame(0, $this->logoutFlag($mine), 'the change signed the owner out of this browser');
        $this->assertStringContainsString(
            'signed out',
            implode(' ', $probe->messages),
            'the message does not say the other sessions ended'
        );
    }

    /**
     * With the policy off — the default — nothing else is signed out.
     *
     * For an application that treats a password change as routine hygiene rather than as a
     * response to compromise, signing every device out is a support call.
     */
    public function testWithThePolicyOffNothingElseIsSignedOut(): void
    {
        // Arrange
        $this->assertFalse(SecurityPolicy::revokesSessionsOnPasswordChange(), 'precondition: off');

        $theirs = 'other-device-' . bin2hex(random_bytes(4));
        $this->seedSession($theirs);

        $probe = $this->probe();
        $this->postWithToken([
            'current_password' => self::OLD,
            'new_password'     => self::NEW,
            'confirm_password' => self::NEW,
        ]);

        // Act
        $probe->changepassword();

        // Assert
        $this->assertTrue($this->stillHas(self::NEW));
        $this->assertSame(0, $this->logoutFlag($theirs), 'a device was signed out without being asked');
        $this->assertStringNotContainsString('signed out', implode(' ', $probe->messages));
    }

    /** The change is recorded, because "when did my password change" is a security question. */
    public function testTheChangeIsRecorded(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->postWithToken([
            'current_password' => self::OLD,
            'new_password'     => self::NEW,
            'confirm_password' => self::NEW,
        ]);

        // Act
        $probe->changepassword();

        // Assert
        $this->assertSame(
            1,
            (int) $this->db->queryBuilder()->table('authserver.user_activity_log')
                ->where('userid', $this->uid)->where('action', 'password_changed')->count(),
            'the password change is not in the activity log'
        );
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** The controller with only the things that would end the process replaced. */
    private function probe(): object
    {
        return new class ($this->db) extends Account {
            public array $errors = [];

            public array $messages = [];

            public array $redirects = [];

            public function __construct(\Pramnos\Database\Database $db)
            {
                $app = Application::getInstance();
                $app->database     = $db;
                $this->application = $app;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }

            protected function addError($error)
            {
                $this->errors[] = (string) $error;

                return $this;
            }

            protected function addMessage($message)
            {
                $this->messages[] = (string) $message;

                return $this;
            }
        };
    }

    /** Whether the account's password is still the given one. */
    private function stillHas(string $password): bool
    {
        User::clearUserCache();

        return (new User($this->uid))->verifyPassword($password);
    }

    private function seedSession(string $sid): void
    {
        // Every NOT NULL column named: on PostgreSQL an omitted one with no default is a
        // violation rather than an empty string.
        $this->db->queryBuilder()->table('#PREFIX#sessions')->insert([
            'visitorid' => 'visitor-' . bin2hex(random_bytes(4)),
            'sid'       => $sid,
            'userid'    => $this->uid,
            'logout'    => 0,
            'time'      => time(),
            'agent'     => 'phpunit',
            'url'       => '/',
            'history'   => '',
        ]);
    }

    private function logoutFlag(string $sid): int
    {
        $row = $this->db->queryBuilder()->table('#PREFIX#sessions')
            ->select(['logout'])->where('sid', $sid)->first();

        return (int) ($row->fields['logout'] ?? -1);
    }

    /** A POST carrying the session's anti-CSRF token. */
    private function postWithToken(array $fields): void
    {
        $session = \Pramnos\Http\Session::getInstance();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $fields + [$session->getToken() => $session->getFingerprint()];
        \Pramnos\Http\Request::resetInstance();
    }
}
