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
#[CoversClass(User::class)]
class AccountChangePasswordTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    /** @var list<int> Applications a test declared, removed in tearDown */
    private array $appIds = [];

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
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class,
            \Pramnos\Framework\Migrations\Applications\CreateApplicationSettingsTable::class,
        ], $this->db);
        \Pramnos\Auth\ActivityLog::resetTableCache();
        \Pramnos\Auth\ApplicationSettings::reset();

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

        foreach ($this->appIds as $appId) {
            $this->db->queryBuilder()->table('applications.application_settings')->where('appid', $appId)->delete();
            $this->db->queryBuilder()->table('#PREFIX#applications')->where('appid', $appId)->delete();
        }
        \Pramnos\Auth\ApplicationSettings::reset();

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
        // The session id belongs to the process, so a class before this one in the same
        // worker may have recorded this very session already — under another user.
        $this->db->queryBuilder()->table('#PREFIX#sessions')->where('sid', $mine)->delete();
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

    // ── When the password last changed ────────────────────────────────────────

    /**
     * A first password records nothing; a change does, and `/me` reads it back.
     *
     * The client requirement this serves: on first sign-in, send the user to change the
     * password an administrator gave them, and let them in once they have. So the password set
     * on an account that had none must leave the date empty, and the user's own change must
     * fill it.
     */
    public function testAChangeIsDatedAndAFirstPasswordIsNot(): void
    {
        // Arrange — setUp gave the account its first password
        User::clearUserCache();
        $this->assertNull((new User($this->uid))->getPasswordChangeDate(), 'a first password counted as a change');
        $before = time();
        $probe  = $this->probe();
        $this->postWithToken([
            'current_password' => self::OLD,
            'new_password'     => self::NEW,
            'confirm_password' => self::NEW,
        ]);

        // Act
        $probe->changepassword();

        // Assert — stored in userdetails, so a fresh load finds it
        User::clearUserCache();
        $changed = (new User($this->uid))->getPasswordChangeDate();
        $this->assertNotNull($changed, 'the change was not dated');
        $this->assertGreaterThanOrEqual($before, $changed);
    }

    /**
     * Setting the password an account already has is not a change.
     *
     * An administrator re-saving a form, or a script re-applying a known password, must not
     * tell a client that the user has changed theirs.
     */
    public function testSettingTheSamePasswordAgainIsNotAChange(): void
    {
        // Arrange
        $user = new User($this->uid);

        // Act
        $user->setPassword(self::OLD);
        $user->save();

        // Assert
        User::clearUserCache();
        $this->assertNull((new User($this->uid))->getPasswordChangeDate());
    }

    /**
     * A new account given a password before its first save records nothing.
     *
     * `save()` rehashes that password through `setPassword()` once the id exists, over an MD5
     * placeholder of the same password. Read as a change, every new account would arrive
     * already "changed", and the first-login requirement would never fire.
     */
    public function testANewAccountsPasswordIsNotAChange(): void
    {
        // Arrange
        $user = new User();
        $user->username = 'newpw_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.test';
        $user->setPassword(self::OLD);

        try {
            // Act
            $user->save();

            // Assert
            User::clearUserCache();
            $fresh = new User((int) $user->userid);
            $this->assertTrue($fresh->verifyPassword(self::OLD), 'precondition: the password was stored');
            $this->assertNull($fresh->getPasswordChangeDate());
        } finally {
            $this->db->queryBuilder()->table('#PREFIX#userdetails')->where('userid', (int) $user->userid)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', (int) $user->userid)->delete();
        }
    }

    /**
     * A change made before the date was kept is read from the activity log.
     *
     * Those users changed their password through this page, which has always logged
     * `password_changed`; answering null would send them to change it again.
     */
    public function testAnEarlierChangeIsReadFromTheActivityLog(): void
    {
        // Arrange — only the log knows
        \Pramnos\Auth\ActivityLog::record($this->uid, 'password_changed');
        User::clearUserCache();

        // Act
        $changed = (new User($this->uid))->getPasswordChangeDate();

        // Assert — the entry was written moments ago
        $this->assertNotNull($changed);
        $this->assertLessThan(300, abs(time() - $changed), 'not the time the entry was written');
    }

    /**
     * Without an activity log, a password that was never dated reads as never changed.
     *
     * An application without the auth server's tables still serves `/me`; the fallback must
     * answer null there rather than fail the profile.
     */
    public function testWithoutAnActivityLogTheAnswerIsNull(): void
    {
        // Arrange
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable('authserver.user_activity_log'));
        \Pramnos\Database\SchemaBuilder::forgetWhatExists($this->db);
        User::clearUserCache();

        // Act
        $changed = (new User($this->uid))->getPasswordChangeDate();

        // Assert
        $this->assertNull($changed);
    }

    /** An account that has not been saved has no history to read. */
    public function testAnUnsavedAccountHasNoChange(): void
    {
        // Act & Assert
        $this->assertNull((new User())->getPasswordChangeDate());
    }

    /**
     * An activity log the read cannot use answers null, and the profile is still served.
     *
     * An application may own a table of that name in another shape; `/me` must not fail
     * because of it.
     */
    public function testAnUnreadableActivityLogAnswersNull(): void
    {
        // Arrange — the table exists, without the column the read orders by
        $table = $this->db->schema()->quoteTable('authserver.user_activity_log');
        $this->db->query('DROP TABLE IF EXISTS ' . $table);
        $this->db->query('CREATE TABLE ' . $table . ' (userid integer, action varchar(50))');
        \Pramnos\Database\SchemaBuilder::forgetWhatExists($this->db);
        User::clearUserCache();

        // Act
        $changed = (new User($this->uid))->getPasswordChangeDate();

        // Assert
        $this->assertNull($changed);
    }

    // ── Returning to the client ───────────────────────────────────────────────

    /**
     * Declare an application the way its edit screen would: its URL, its callbacks and,
     * optionally, CORS origins.
     */
    private function declareApplication(
        string $url,
        string $callback = '',
        string $corsOrigins = '',
        int $status = 1,
        string $apikey = ''
    ): int {
        $apikey = $apikey !== '' ? $apikey : 'key_' . bin2hex(random_bytes(6));
        $this->db->queryBuilder()->table('#PREFIX#applications')->insert([
            'name'       => 'client ' . $apikey,
            'apikey'     => $apikey,
            'url'        => $url,
            'callback'   => $callback,
            'status'     => $status,
            'accesstype' => 1,
        ]);
        $appId = (int) $this->db->queryBuilder()->table('#PREFIX#applications')
            ->where('apikey', $apikey)->value('appid');
        $this->appIds[] = $appId;

        if ($corsOrigins !== '') {
            $errors = \Pramnos\Auth\ApplicationSettings::save(
                $appId,
                ['cors_enabled' => true, 'cors_origins' => $corsOrigins] + \Pramnos\Auth\ApplicationSettings::DEFAULTS
                    + ['allowed_ips' => '', 'blocked_ips' => '']
            );
            $this->assertSame([], $errors, 'precondition: the CORS origins were saved');
        }

        return $appId;
    }

    /** Change the password with `returnUrl` posted, and say where the controller sent the user. */
    private function changeReturningTo(string $returnUrl, string $clientId = ''): string
    {
        // Back to the old password, so each call is one change from the same starting point
        $user = new User($this->uid);
        $user->setPassword(self::OLD);
        $user->save();
        User::clearUserCache();

        $probe = $this->probe();
        $this->postWithToken([
            'current_password' => self::OLD,
            'new_password'     => self::NEW,
            'confirm_password' => self::NEW,
            'returnUrl'        => $returnUrl,
        ] + ($clientId !== '' ? ['client_id' => $clientId] : []));
        $probe->changepassword();

        return (string) end($probe->redirects);
    }

    /**
     * An origin no application declares is ignored, and the page returns where it always did.
     *
     * Redirecting a freshly authenticated user to an address taken from the query string is
     * only safe for addresses somebody vouched for, and the applications' own registrations
     * are where that vouching already happens.
     */
    public function testAnUndeclaredOriginIsIgnored(): void
    {
        // Arrange
        $this->declareApplication('https://client.example');

        // Act
        $target = $this->changeReturningTo('https://elsewhere.example/welcome');

        // Assert
        $this->assertSame(sURL . 'Account/security', $target);
    }

    /** An application's URL is an origin it may be returned to, with no message left behind. */
    public function testAnApplicationsUrlIsReturnedTo(): void
    {
        // Arrange
        $this->declareApplication('https://client.example/app');

        // Act
        $target = $this->changeReturningTo('https://client.example/welcome?step=2');

        // Assert
        $this->assertTrue($this->stillHas(self::NEW), 'precondition: the change went through');
        $this->assertSame('https://client.example/welcome?step=2', $target);
    }

    /**
     * Every callback counts, and so does every CORS origin.
     *
     * The callback field is a list (commas or whitespace), and a single-page client that calls
     * the API from the browser declares its origin as a CORS origin rather than as a callback.
     */
    public function testCallbacksAndCorsOriginsAreDeclaredToo(): void
    {
        // Arrange
        $this->declareApplication(
            'https://client.example',
            "https://cb-one.example/oauth, https://cb-two.example/oauth\nhttps://cb-three.example/x",
            "https://spa.example"
        );

        // Act & Assert
        foreach (['https://cb-two.example/after', 'https://cb-three.example/', 'https://spa.example/home'] as $declared) {
            $this->assertSame($declared, $this->changeReturningTo($declared), $declared . ' was not returned to');
        }
    }

    /**
     * Only scheme and host matching exactly admit an address.
     *
     * A lookalike host, a subdomain or the other scheme of a declared origin is somebody else's
     * site, and a redirect there right after a password change is a phishing page's opening.
     */
    public function testAnotherOriginIsNotReturnedTo(): void
    {
        // Arrange
        $this->declareApplication('https://client.example');

        foreach (['https://client.example.evil.test/', 'https://evil.client.example/', 'http://client.example/', 'https://client.example@evil.test/'] as $refused) {
            // Act
            $target = $this->changeReturningTo($refused);

            // Assert
            $this->assertSame(sURL . 'Account/security', $target, $refused . ' was returned to');
        }
    }

    /**
     * The port counts only where the declared address names one.
     *
     * `http://localhost` is how a developer declares whatever port their client runs on; a
     * declared port means exactly that port. The scheme's default port is the same origin.
     */
    public function testThePortCountsOnlyWhereTheDeclaredAddressNamesOne(): void
    {
        // Arrange
        $this->declareApplication('http://localhost', 'https://staging.example:8443/cb https://prod.example:443/cb');

        // Act & Assert
        $this->assertSame('http://localhost:5173/cb', $this->changeReturningTo('http://localhost:5173/cb'));
        $this->assertSame(sURL . 'Account/security', $this->changeReturningTo('https://staging.example:9443/'), 'another port was admitted');
        $this->assertSame('https://staging.example:8443/', $this->changeReturningTo('https://staging.example:8443/'));
        $this->assertSame('https://prod.example/home', $this->changeReturningTo('https://prod.example/home'), 'the default port is the same origin');
    }

    /**
     * An inactive application declares nothing.
     *
     * Switching an application off is how an administrator withdraws it; its addresses must not
     * keep working as redirect targets.
     */
    public function testAnInactiveApplicationDeclaresNothing(): void
    {
        // Arrange — beside an active application, so the declarations are in force
        $this->declareApplication('https://retired.example', '', '', 0);
        $this->declareApplication('https://active.example');

        // Act & Assert
        $this->assertSame(sURL . 'Account/security', $this->changeReturningTo('https://retired.example/'));
    }

    /**
     * With a `client_id`, only that application's addresses count.
     *
     * The client says who it is, so an address another application declared is not one it
     * may send the user to.
     */
    public function testAClientIdLimitsItToThatApplication(): void
    {
        // Arrange
        $this->declareApplication('https://one.example', '', '', 1, 'client-one');
        $this->declareApplication('https://two.example', '', '', 1, 'client-two');

        // Act & Assert
        $this->assertSame('https://one.example/x', $this->changeReturningTo('https://one.example/x', 'client-one'));
        $this->assertSame(sURL . 'Account/security', $this->changeReturningTo('https://two.example/x', 'client-one'), "another application's address was admitted");
    }

    /**
     * With nothing declared anywhere, any `http(s)` address is accepted.
     *
     * An installation whose applications have not filled in their addresses still gets the
     * user back to the client; declaring one is what narrows it.
     */
    public function testWithNothingDeclaredAnyHttpAddressIsAccepted(): void
    {
        // Arrange — no application at all
        // Act & Assert
        $this->assertSame('https://anything.example/x', $this->changeReturningTo('https://anything.example/x'));
        $this->assertSame(sURL . 'Account/security', $this->changeReturningTo('javascript:alert(1)'), 'a non-http address was accepted');
    }

    /**
     * An application that declares nothing accepts any `http(s)` address for its client_id.
     *
     * Its client named itself and has no addresses to be held to; another application's
     * declarations do not apply to it.
     */
    public function testAClientThatDeclaresNothingAcceptsAnyAddress(): void
    {
        // Arrange — one application declares nothing, another declares an origin
        $this->declareApplication('', '', '', 1, 'client-bare');
        $this->declareApplication('https://other.example', '', '', 1, 'client-other');

        // Act & Assert
        $this->assertSame('https://anything.example/x', $this->changeReturningTo('https://anything.example/x', 'client-bare'));
    }

    /**
     * A client_id that names no active application accepts nothing.
     *
     * Otherwise "nothing declared" would be reachable by inventing a client_id, and every
     * address would be one made-up parameter away. An inactive application is the same.
     */
    public function testAnUnknownClientIdAcceptsNothing(): void
    {
        // Arrange
        $this->declareApplication('', '', '', 0, 'client-retired');

        // Act & Assert
        $this->assertSame(sURL . 'Account/security', $this->changeReturningTo('https://anything.example/x', 'no-such-client'));
        $this->assertSame(sURL . 'Account/security', $this->changeReturningTo('https://anything.example/x', 'client-retired'));
    }

    /**
     * Only `http(s)` is ever accepted, whatever is declared.
     *
     * The redirect has a `<script>` fallback, so `javascript:` must stay out; and an address
     * with a control character inside is read differently by the browser than by the parser.
     */
    public function testOnlyHttpIsEverAccepted(): void
    {
        // Arrange
        $this->declareApplication('https://evil.test');

        // Act & Assert
        foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.test/', "https://evil\t.test/"] as $refused) {
            $this->assertSame(sURL . 'Account/security', $this->changeReturningTo($refused), $refused . ' was returned to');
        }
    }

    /**
     * A failed attempt goes back to the form still carrying the address and the client.
     *
     * Otherwise one mistyped current password strands the user on this server, and the client
     * never learns the change happened.
     */
    public function testAFailedAttemptKeepsTheAddressAndTheClient(): void
    {
        // Arrange
        $this->declareApplication('https://client.example', '', '', 1, 'client-kept');
        $probe = $this->probe();
        $this->postWithToken([
            'current_password' => 'not-the-password',
            'new_password'     => self::NEW,
            'confirm_password' => self::NEW,
            'returnUrl'        => 'https://client.example/welcome',
            'client_id'        => 'client-kept',
        ]);

        // Act
        $probe->changepassword();

        // Assert
        $this->assertSame(
            sURL . 'Account/changepassword?returnUrl=' . rawurlencode('https://client.example/welcome') . '&client_id=client-kept',
            end($probe->redirects)
        );
    }

    /**
     * The form page lets its submission reach the client, and remembers where it returns.
     *
     * Chrome applies `form-action` to the redirect after the POST and cancels a cross-origin
     * 302 silently, so the origin has to be in the policy of the page holding the form. And a
     * form copied into an application before it carried the address posts only its fields, so
     * the address and the client are kept in the session for the POST.
     */
    public function testTheFormPageAllowsTheClientAndRemembersIt(): void
    {
        // Arrange
        $this->declareApplication('http://localhost', '', '', 1, 'client-local');
        $this->declareApplication('http://localhost:3000', '', '', 1, 'client-other');
        $_GET = ['returnUrl' => 'http://localhost:3000/after', 'client_id' => 'client-local'];

        try {
            // Act — the form page, then a POST that repeats nothing
            $page = $this->probe();
            $page->changepassword();
            $_GET = [];
            $remembered = $_SESSION[Account::PASSWORD_RETURN_SESSION_KEY] ?? null;
            $user = new User($this->uid);
            $user->setPassword(self::OLD);
            $user->save();
            $post = $this->probe();
            $this->postWithToken([
                'current_password' => self::OLD,
                'new_password'     => self::NEW,
                'confirm_password' => self::NEW,
            ]);
            $post->changepassword();

            // Assert
            $this->assertStringContainsString('http://localhost:3000', Application::currentInstance()->cspPolicy());
            $this->assertSame('http://localhost:3000/after', $page->view->returnUrl, 'the view is not told the address');
            $this->assertSame(['url' => 'http://localhost:3000/after', 'client_id' => 'client-local'], $remembered);
            $this->assertSame('http://localhost:3000/after', end($post->redirects), 'the remembered address was not used');
            $this->assertArrayNotHasKey(Account::PASSWORD_RETURN_SESSION_KEY, $_SESSION, 'the address outlived the change');
        } finally {
            $_GET = [];
        }
    }

    /**
     * A remembered client still limits the remembered address.
     *
     * The POST reads both from the session; reading only the address would widen the check to
     * every application.
     */
    public function testARememberedClientStillLimitsTheAddress(): void
    {
        // Arrange — remembered for client-one, an address only client-two declares
        $this->declareApplication('https://one.example', '', '', 1, 'client-one');
        $this->declareApplication('https://two.example', '', '', 1, 'client-two');
        $_SESSION[Account::PASSWORD_RETURN_SESSION_KEY] = ['url' => 'https://two.example/x', 'client_id' => 'client-one'];
        $probe = $this->probe();
        $this->postWithToken([
            'current_password' => self::OLD,
            'new_password'     => self::NEW,
            'confirm_password' => self::NEW,
        ]);

        // Act
        $probe->changepassword();

        // Assert
        $this->assertSame(sURL . 'Account/security', end($probe->redirects));
    }

    /**
     * Opening the form without an address forgets a remembered one.
     *
     * Otherwise a later, unrelated change would send the user to whichever client sent them
     * here last.
     */
    public function testTheFormPageWithoutAnAddressForgetsTheRememberedOne(): void
    {
        // Arrange
        $_SESSION[Account::PASSWORD_RETURN_SESSION_KEY] = ['url' => 'https://client.example/old', 'client_id' => ''];

        // Act
        $this->probe()->changepassword();

        // Assert
        $this->assertArrayNotHasKey(Account::PASSWORD_RETURN_SESSION_KEY, $_SESSION);
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

            public ?object $view = null;

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }

            /** The form page without a theme: what the controller handed the view is kept. */
            function &getView($name = '', $type = '', $args = array())
            {
                $this->view = new class {
                    public $routeBase = null;

                    public $returnUrl = null;

                    public function display($name = '')
                    {
                        return '';
                    }
                };

                return $this->view;
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
