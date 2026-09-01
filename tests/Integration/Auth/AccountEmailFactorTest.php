<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Auth\EmailSecondFactor;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * Turning sign-in codes by email on and off — 59 statements, the largest unexecuted method in
 * the account screen.
 *
 * {@see EmailSecondFactorTest} covers the service: the code, its TTL, the attempt limit, the
 * resend accounting. This is the screen on top of it, and screens are where the decisions about
 * *who may change what* live. Three of them, each the sort that is only ever wrong once:
 *
 *   - **Turning the factor off asks for the password.** A second factor that a stolen session can
 *     remove is not a second factor. Everything else on this screen protects the account; this is
 *     the one action that weakens it, so it re-authenticates.
 *   - **Nothing happens on a GET, or without the anti-CSRF token.** A link in an email that turns
 *     off somebody's second factor is the whole reason the token exists.
 *   - **A refused send says which refusal it was.** The rate limit and a broken mailer produce
 *     the same `false`, and the message used to be "we could not email you a code — check the
 *     address on your profile". Somebody told that presses the button again, sees it again, and
 *     concludes nothing is being sent — while the code is already in their inbox.
 *
 * Both backends: {@see AccountEmailFactorPostgreSQLTest} re-runs it. The enable and disable go
 * through an upsert on `authserver.user_twofactor`, and the code store is deleted and rewritten
 * per send.
 */
#[CoversClass(Account::class)]
class AccountEmailFactorTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    private const PASSWORD = 'the-real-password-7!';

    /** The application's own settings, restored in tearDown. */
    private array $originalInfo = [];

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
         * The activity log is dropped before it is migrated, and that is not tidiness.
         *
         * The table survives between runs, several tests create it, and `runMigrations()` is a
         * no-op when it already exists — so whichever shape got there first is the shape this
         * test would run against. One of those shapes had no `details` column, which makes every
         * `ActivityLog::record()` insert fail into the logger's own catch and
         * `EmailSecondFactor::recentSends()` return "nothing recent". The resend limit then does
         * not apply, and a test asserting that it does passes or fails by ordering.
         */
        $this->db->query(
            'DROP TABLE IF EXISTS '
            . $this->db->schema()->quoteTable('authserver.user_activity_log')
        );
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateUserTwofactorTable::class,
            \Pramnos\Framework\Migrations\AuthServer\AddEmailFactorToUserTwofactor::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateTwofactorEmailCodesTable::class,
            // The resend accounting reads the activity log — the same rows that audit the sends.
            // Without the table the limit silently does not apply, and the message this test is
            // most interested in is the one the limit produces.
            \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class,
        ], $this->db);
        \Pramnos\Auth\ActivityLog::resetTableCache();

        /*
         * The method has to be declared available, and the feature enabled with it.
         *
         * `isAvailable()` reads `auth.twofactor_methods`; `ActivityLog::record()` gates on the
         * `auth` feature, and the resend accounting reads what it writes — so without the feature
         * the log is a silent no-op and the rate-limit branch below can never be reached.
         */
        $this->originalInfo = (array) $application->applicationInfo;
        $application->applicationInfo['features'] = ['auth', 'authserver'];
        $application->applicationInfo['auth']['twofactor_methods'] = ['totp', 'email'];
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['auth', 'authserver']);

        $user = new User();
        $user->username = 'emailfactor_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.test';
        $user->save();
        $this->uid = (int) $user->userid;

        // `setPassword()` hashes onto the loaded row rather than writing, and the hash is
        // salted with the real id — so the row has to exist first and the save is what persists.
        $withPassword = new User($this->uid);
        $withPassword->setPassword(self::PASSWORD);
        $withPassword->save();

        \Pramnos\Http\RequestIdentity::seal(new User($this->uid), 'test');

        $_POST = [];
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
                    'authserver.twofactor_email_codes',
                    'authserver.user_twofactor',
                    'authserver.user_activity_log',
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

    // ── Who may reach it at all ───────────────────────────────────────────────

    /**
     * A GET changes nothing, token or not.
     *
     * A link somebody follows — from an email, a prefetch, a chat client unfurling a URL — must
     * not be able to touch a second factor.
     */
    public function testAGetChangesNothing(): void
    {
        // Arrange
        $probe = $this->probe();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = ['enable' => '1'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->emailfactor();

        // Assert
        $this->assertFalse($this->isEnabled(), 'a GET enrolled a second factor');
        $this->assertSame(0, $this->codeCount(), 'a GET sent a code');
        $this->assertStringContainsString('security', $probe->redirects[0] ?? '');
    }

    /**
     * A POST without the anti-CSRF token changes nothing.
     *
     * The form this action serves sits behind a session, so a cross-site POST carries the
     * cookie. The token is the only thing separating "the account holder pressed a button" from
     * "the account holder loaded a page somebody else wrote".
     */
    public function testAPostWithoutTheTokenChangesNothing(): void
    {
        // Arrange
        $probe = $this->probe();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['enable' => '1'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->emailfactor();

        // Assert
        $this->assertSame(0, $this->codeCount(), 'a request with no token sent a code');
        $this->assertFalse($this->isEnabled());
    }

    /**
     * With the method not allowed by configuration, the answer says so and nothing is written.
     *
     * `auth.twofactor_methods` is how an installation says which factors it offers. A screen
     * that enrolled somebody in a method the sign-in flow will not accept would produce an
     * account that cannot sign in — the worst possible outcome of a security setting.
     */
    public function testAMethodTheInstallationDoesNotOfferIsRefused(): void
    {
        // Arrange
        Application::currentInstance()->applicationInfo['auth']['twofactor_methods'] = ['totp'];
        $this->assertFalse(EmailSecondFactor::isAvailable(), 'precondition: not offered');

        $probe = $this->probe();
        $this->postWithToken([]);

        // Act
        $probe->emailfactor();

        // Assert
        $this->assertSame(0, $this->codeCount());
        $this->assertNotSame('', (string) ($_SESSION['account_error'] ?? ''));
    }

    // ── Enrolling ─────────────────────────────────────────────────────────────

    /**
     * The first press sends a code and says so; it does not enrol anybody.
     *
     * Proving the mailbox is the whole of step one. Enrolling on the press and asking for the
     * code afterwards would enrol an account whose address is a typo, and the person would then
     * be asked at every sign-in for a code sent somewhere they cannot read.
     */
    public function testTheFirstPressSendsACodeWithoutEnrolling(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->postWithToken([]);

        // Act
        $probe->emailfactor();

        // Assert
        $this->assertSame(1, $this->codeCount(), 'no code was stored');
        $this->assertFalse($this->isEnabled(), 'the account was enrolled before proving the mailbox');
        $this->assertNotSame('', (string) ($_SESSION['account_success'] ?? ''));
    }

    /** A wrong code enrols nobody and says the code was wrong. */
    public function testAWrongCodeEnrolsNobody(): void
    {
        // Arrange — a code is outstanding.
        $probe = $this->probe();
        $this->postWithToken([]);
        $probe->emailfactor();
        $_SESSION = [];

        // Act
        $this->postWithToken(['code' => '000000']);
        $probe->emailfactor();

        // Assert
        $this->assertFalse($this->isEnabled(), 'a wrong code enrolled the account');
        $this->assertNotSame('', (string) ($_SESSION['account_error'] ?? ''));
    }

    /**
     * The right code enrols the account, and says so.
     *
     * Whether a notice goes out is {@see \Pramnos\Auth\SecurityChangeNotifier}'s subject, and
     * it has its own — including that it does nothing at all unless
     * `auth.security.notify_security_changes` is on, which is off by default. What this asserts
     * is the screen's half: the enrolment is written, and the message the person is left looking
     * at is the successful one rather than the "that could not be changed" branch beside it.
     */
    public function testTheRightCodeEnrols(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->postWithToken([]);
        $probe->emailfactor();
        $code = $this->outstandingCode();

        // Act
        $this->postWithToken(['code' => $code]);
        $probe->emailfactor();

        // Assert
        $this->assertTrue($this->isEnabled(), 'the right code did not enrol the account');
        $this->assertNotSame('', (string) ($_SESSION['account_success'] ?? ''));
        $this->assertSame('', (string) ($_SESSION['account_error'] ?? ''));
    }

    // ── Dropping it ───────────────────────────────────────────────────────────

    /**
     * Turning the factor off without the password changes nothing.
     *
     * The assertion this file exists for. A second factor that a stolen session can remove is
     * not a second factor — the attacker is already inside, and every other control on the
     * screen assumes the session belongs to its owner. This one does not.
     */
    public function testTurningItOffNeedsThePassword(): void
    {
        // Arrange
        $this->enable();
        $probe = $this->probe();
        $this->postWithToken(['enable' => '0', 'password' => 'not-the-password']);

        // Act
        $probe->emailfactor();

        // Assert
        $this->assertTrue($this->isEnabled(), 'a wrong password turned off the second factor');
        $this->assertNotSame('', (string) ($_SESSION['account_error'] ?? ''));
        $this->assertSame('', (string) ($_SESSION['account_success'] ?? ''));
    }

    /** An empty password is not a password. */
    public function testTurningItOffWithNoPasswordChangesNothing(): void
    {
        // Arrange
        $this->enable();
        $probe = $this->probe();
        $this->postWithToken(['enable' => '0']);

        // Act
        $probe->emailfactor();

        // Assert
        $this->assertTrue($this->isEnabled());
    }

    /** With the right password it comes off. */
    public function testTheRightPasswordTurnsItOff(): void
    {
        // Arrange
        $this->enable();
        $probe = $this->probe();
        $this->postWithToken(['enable' => '0', 'password' => self::PASSWORD]);

        // Act
        $probe->emailfactor();

        // Assert
        $this->assertFalse($this->isEnabled(), 'the right password did not turn the factor off');
        $this->assertNotSame('', (string) ($_SESSION['account_success'] ?? ''));
    }

    // ── The two refusals that look alike ──────────────────────────────────────

    /**
     * A second press inside the resend window says how long to wait.
     *
     * Reported as "the rate limit does not work". It does — but the message was "we could not
     * email you a code — check the address on your profile", which reads as a broken mailer.
     * Somebody told that presses again, sees it again, and concludes nothing is being sent,
     * while the code is already in their inbox. Both refusals are the same `false` from
     * `send()`, so the message has to come from asking a second question.
     */
    public function testASecondPressTooSoonSaysHowLongToWait(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->postWithToken([]);
        $probe->emailfactor();
        $this->assertNotSame('', (string) ($_SESSION['account_success'] ?? ''), 'precondition: sent');
        $this->assertSame(
            1,
            $this->sendsLogged(),
            'the send was not recorded, so the resend window has nothing to measure from'
        );
        $_SESSION = [];

        // Act
        $this->postWithToken([]);
        $probe->emailfactor();

        // Assert
        $error = (string) ($_SESSION['account_error'] ?? '');
        $this->assertNotSame('', $error, 'a second press inside the window was not refused');
        $this->assertStringNotContainsStringIgnoringCase(
            'check the address',
            $error,
            'the rate limit is being reported as a broken mailer'
        );
        $this->assertMatchesRegularExpression(
            '~\d+~',
            $error,
            'the refusal does not say how long to wait'
        );
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * The controller with the two things a test cannot let happen replaced.
     *
     * Only `redirect()`, which would end the process. Everything else — the CSRF check, the
     * password check, the code store, the enrolment write — is real. The notifier is real too and
     * does nothing, because `auth.security.notify_security_changes` is off unless an installation
     * turns it on; that switch is {@see \Pramnos\Auth\SecurityChangeNotifier}'s subject.
     */
    private function probe(): object
    {
        return new class ($this->db) extends Account {
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
        };
    }

    /** Enrol the fixture account directly, so a test about removal starts from enrolled. */
    private function enable(): void
    {
        (new EmailSecondFactor())->setEnabledFor($this->uid, true);
        $this->assertTrue($this->isEnabled(), 'precondition: the factor is on');
    }

    private function isEnabled(): bool
    {
        return (new EmailSecondFactor())->isEnabledFor($this->uid);
    }

    /**
     * How many sends the activity log has recorded for this account.
     *
     * The resend window is measured from these rows and nothing else, so a limit that appears
     * not to work is usually a log that was never written — which `ActivityLog::record()` does
     * silently when the `auth` feature is off or the table is missing. Asserted rather than
     * assumed, because the alternative is a rate-limit test that passes by not limiting.
     */
    private function sendsLogged(): int
    {
        return (int) $this->db->queryBuilder()
            ->table('authserver.user_activity_log')
            ->where('userid', $this->uid)
            ->where('action', 'twofactor_email_code_sent')
            ->count();
    }

    private function codeCount(): int
    {
        return (int) $this->db->queryBuilder()
            ->table('authserver.twofactor_email_codes')
            ->where('userid', $this->uid)
            ->count();
    }

    /**
     * Make the outstanding code a known one, and return it.
     *
     * The store keeps an HMAC and nothing else — by design, and the reason the code cannot be
     * read back. The first version of this searched all million candidates, which is honest and
     * costs a second of CPU on each lane for a fact this test does not need: *which* six digits
     * were generated is `EmailSecondFactor`'s subject and has its own tests. What is under test
     * here is the screen's branch on a code that verifies, so the hash of a code this test
     * chooses is written over the one that was sent, and `verify()` then runs for real against it.
     */
    private function outstandingCode(string $code = '424242'): string
    {
        $factor = new EmailSecondFactor();
        $hasher = new \ReflectionMethod(EmailSecondFactor::class, 'hash');

        $updated = $this->db->queryBuilder()
            ->table('authserver.twofactor_email_codes')
            ->where('userid', $this->uid)
            ->where('purpose', EmailSecondFactor::PURPOSE_ENROL)
            ->update(['code_hash' => (string) $hasher->invoke($factor, $code, $this->uid)]);

        $this->assertSame(1, $this->codeCount(), 'no code is outstanding to replace');

        return $code;
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
