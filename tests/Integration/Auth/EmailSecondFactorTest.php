<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\EmailSecondFactor;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * The email second factor against a real store.
 *
 * What makes a six-digit code safe is not how it is hashed — 10^6 possibilities is
 * nothing — it is the three limits, and all three live in this class rather than in the
 * callers. So all three are tested here: **ten minutes**, **five attempts**, **single
 * use**. Each of them is the whole security of the method when the other two are absent.
 *
 * Mail is not sent from these tests. `send()` is split so the store is written before
 * anything is handed to a mailer, and what these assert is the store — the row, the
 * hash, the expiry — plus the fact that a code the person is holding is invalidated when
 * a newer one is issued.
 *
 * Runs on MySQL only, like the other tests that touch `authserver.*`: on this connection
 * that prefix resolves to `authserver_` in the same database, which is what the fixture
 * below builds from the real migrations.
 */
#[CoversClass(EmailSecondFactor::class)]
class EmailSecondFactorTest extends BaseTestCase
{
    private $db;
    private int $uid = 0;
    private ?array $savedInstances = null;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('EmailSecondFactorTest runs on MySQL only.');
        }

        User::setupDb();
        $this->buildTables();

        // The method has to be available or every call returns false by policy — which is
        // itself asserted in EmailSecondFactorConfigTest, not here.
        $this->allowEmailMethod();

        $user = new User();
        $user->username = 'emailfactor_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.com';
        $user->save();
        $this->uid = (int) $user->userid;
    }

    protected function tearDown(): void
    {
        foreach ([
            'authserver.twofactor_email_codes',
            'authserver.user_twofactor',
            'authserver.user_activity_log',
        ] as $table) {
            try {
                $this->db->queryBuilder()->table($table)->where('userid', $this->uid)->delete();
            } catch (\Throwable $exception) {
                // The table may not exist on an installation mid-migration; nothing to undo.
            }
        }

        if ($this->uid > 0) {
            try {
                $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $this->uid)->delete();
            } catch (\Throwable $exception) {
                // As above.
            }
        }

        if ($this->savedInstances !== null) {
            $reflection = new \ReflectionProperty(Application::class, 'appInstances');
            $reflection->setValue(null, $this->savedInstances);
            $this->savedInstances = null;
        }

        parent::tearDown();
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** Built from the real migrations, so the test cannot pass against a schema nobody ships. */
    private function buildTables(): void
    {
        $prefix = $this->db->prefix;
        foreach (['authserver_user_twofactor', 'authserver_twofactor_email_codes'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $prefix . 'authserver_user_activity_log`');

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateUserTwofactorTable::class,
            \Pramnos\Framework\Migrations\AuthServer\AddEmailFactorToUserTwofactor::class,
            \Pramnos\Framework\Migrations\AuthServer\CreateTwofactorEmailCodesTable::class,
            // The send accounting reads the activity log — the same rows that audit the
            // sends. Without the table the limit silently does not apply, which is the
            // documented failure mode in production and a test that proves nothing here.
            \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class,
        ], $this->db);
        \Pramnos\Auth\ActivityLog::resetTableCache();

        // The attempt log is written best-effort by the service; created so the write
        // succeeds rather than being swallowed, since a silently unwritten security log is
        // the thing this test would otherwise hide.
        $this->db->query(
            'CREATE TABLE IF NOT EXISTS `' . $prefix . 'authserver_twofactor_attempts` ('
            . '`id` bigint NOT NULL AUTO_INCREMENT,'
            . '`userid` bigint NOT NULL,'
            . '`success` tinyint NOT NULL DEFAULT 0,'
            . '`ip_address` varchar(45) DEFAULT NULL,'
            . '`code_used` varchar(32) DEFAULT NULL,'
            . '`user_agent` text,'
            . '`attempt_time` datetime DEFAULT NULL,'
            . 'PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** Declare the method available for the duration of one test. */
    private function allowEmailMethod(): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        // `features` matters as much as the methods: `ActivityLog::record()` gates on the
        // `auth` feature being enabled, and the send accounting reads the rows it writes.
        // Without it the log is silently a no-op and the rate limit cannot be observed.
        $stub->applicationInfo = [
            'features' => ['auth', 'authserver'],
            'auth'     => ['twofactor_methods' => ['totp', 'email']],
        ];

        $reflection = new \ReflectionProperty(Application::class, 'appInstances');
        $instances  = $reflection->getValue() ?? [];
        $this->savedInstances = $instances;
        $instances['default'] = $stub;
        $reflection->setValue(null, $instances);

        // The registry is loaded from configuration, not read off applicationInfo, so
        // declaring the feature on the stub is not enough on its own.
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['auth', 'authserver']);
    }

    /** The live row for the fixture user, straight from the table. */
    private function storedRow(): ?array
    {
        $result = $this->db->queryBuilder()
            ->table('authserver.twofactor_email_codes')
            ->where('userid', $this->uid)
            ->orderBy('id', 'desc')
            ->first();

        return $result === null || ($result->numRows ?? 0) === 0 ? null : $result->fields;
    }

    /**
     * The HMAC the service would store for a code, computed the same way.
     *
     * This is how most of these tests get a code they know: they *insert* the row rather
     * than asking the service to issue one. Recovering an issued code means searching the
     * whole six-digit space, which costs about a second — fine once, absurd in every test.
     */
    private function hashFor(string $code): string
    {
        return hash_hmac(
            'sha256',
            $code,
            (string) Settings::getSetting('securitySalt') . '|' . $this->uid
        );
    }

    /**
     * Put a code of our choosing in the store, as `send()` would have.
     *
     * @param int $expiresIn Seconds from now; negative for an already-expired row.
     */
    private function storeCode(string $code, int $expiresIn = 600, int $attempts = 0): void
    {
        $this->db->queryBuilder()
            ->table('authserver.twofactor_email_codes')
            ->where('userid', $this->uid)
            ->delete();

        $this->db->queryBuilder()
            ->table('authserver.twofactor_email_codes')
            ->insert([
                'userid'     => $this->uid,
                'purpose'    => EmailSecondFactor::PURPOSE_LOGIN,
                'code_hash'  => $this->hashFor($code),
                'expires_at' => time() + $expiresIn,
                'attempts'   => $attempts,
                'created_at' => time(),
            ]);
    }

    /**
     * The code that was issued, recovered by matching the stored HMAC.
     *
     * The service never returns the code — it mails it — so a test either reads the mail
     * or does what an attacker with the table cannot afford to: search the whole
     * six-digit space. Used **once**, in the test whose point is that the stored value
     * really is an HMAC of a six-digit code rather than the code itself.
     */
    private function recoverCode(string $hash): ?string
    {
        $key = (string) Settings::getSetting('securitySalt') . '|' . $this->uid;
        for ($candidate = 0; $candidate < 1000000; $candidate++) {
            $code = str_pad((string) $candidate, 6, '0', STR_PAD_LEFT);
            if (hash_equals($hash, hash_hmac('sha256', $code, $key))) {
                return $code;
            }
        }

        return null;
    }

    // ── The account's own switch ───────────────────────────────────────────────

    /**
     * An account can take the factor without ever having enrolled in TOTP.
     *
     * The main case: the row does not exist yet, so turning the email factor on has to
     * create it. Writing only an UPDATE here would silently do nothing for exactly the
     * accounts this feature is for.
     */
    public function testTurningItOnCreatesTheRowWhenThereIsNone(): void
    {
        // Arrange
        $factor = new EmailSecondFactor($this->db);
        $this->assertFalse($factor->isEnabledFor($this->uid));

        // Act
        $this->assertTrue($factor->setEnabledFor($this->uid, true));

        // Assert
        $this->assertTrue($factor->isEnabledFor($this->uid));

        // …and off again, without removing the account's TOTP state
        $this->assertTrue($factor->setEnabledFor($this->uid, false));
        $this->assertFalse($factor->isEnabledFor($this->uid));
    }

    // ── Issuing ───────────────────────────────────────────────────────────────

    /**
     * Issuing stores an HMAC and an expiry, and never the code.
     */
    public function testIssuingStoresAHashAndAnExpiry(): void
    {
        // Arrange
        $factor = new EmailSecondFactor($this->db);

        // Act
        $this->assertTrue($factor->send($this->uid));

        // Assert
        $row = $this->storedRow();
        $this->assertNotNull($row);
        $this->assertSame(64, strlen((string) $row['code_hash']), 'sha256 hex');
        $this->assertGreaterThan(time() + EmailSecondFactor::TTL - 30, (int) $row['expires_at']);
        $this->assertSame(0, (int) $row['attempts']);

        $code = $this->recoverCode((string) $row['code_hash']);
        $this->assertNotNull($code, 'the stored value must be an HMAC of a six-digit code');
        $this->assertStringNotContainsString($code, (string) $row['code_hash']);
    }

    /**
     * Asking again replaces the code rather than adding one.
     *
     * A person who clicks "send it again" three times must not end up with three codes
     * that all work — that would multiply the guessing surface for no gain.
     */
    public function testAskingAgainInvalidatesThePreviousCode(): void
    {
        // Arrange — a code we know, then a fresh one from the service
        $factor = new EmailSecondFactor($this->db);
        $this->storeCode('123456');
        $firstId = (int) $this->storedRow()['id'];

        // Act
        $this->assertTrue($factor->send($this->uid));

        // Assert — one row, a different one, and the code we knew is dead
        $rows = $this->db->queryBuilder()
            ->table('authserver.twofactor_email_codes')
            ->where('userid', $this->uid)
            ->get();
        $this->assertSame(1, (int) ($rows->numRows ?? 0), 'never two live codes');
        $this->assertNotSame($firstId, (int) $this->storedRow()['id']);
        $this->assertFalse($factor->verify($this->uid, '123456'));
    }

    /**
     * An account with no deliverable address gets no code, and no row.
     *
     * A stored code nobody can receive is a login that cannot be completed and an
     * attempt counter an attacker can exhaust on the owner's behalf.
     */
    public function testAnAccountWithNoAddressGetsNothing(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->uid)->update(['email' => 'not-an-address']);
        $cache = new \ReflectionProperty(User::class, '_usercache');
        $cache->setValue(null, null);

        // Act
        $sent = (new EmailSecondFactor($this->db))->send($this->uid);

        // Assert
        $this->assertFalse($sent);
        $this->assertNull($this->storedRow());
    }

    // ── Verifying ─────────────────────────────────────────────────────────────

    /**
     * A correct code works exactly once.
     */
    public function testACodeWorksOnceAndThenIsGone(): void
    {
        // Arrange
        $factor = new EmailSecondFactor($this->db);
        $this->storeCode('424242');
        $code = '424242';

        // Act & Assert
        $this->assertTrue($factor->verify($this->uid, $code));
        $this->assertNull($this->storedRow(), 'a spent code is deleted, not marked');
        $this->assertFalse($factor->verify($this->uid, $code), 'and cannot be replayed');
    }

    /**
     * An expired code is refused even though it is the right code.
     */
    public function testAnExpiredCodeIsRefused(): void
    {
        // Arrange — the right code, stored already expired
        $factor = new EmailSecondFactor($this->db);
        $this->storeCode('424242', -1);

        // Act & Assert
        $this->assertFalse($factor->verify($this->uid, '424242'));
    }

    /**
     * Wrong guesses run out, and the code dies rather than merely being refused.
     *
     * Destroyed on the last attempt on purpose: leaving it alive would let an attacker
     * keep guessing against a code the owner is still holding, and the owner would have
     * no way to tell.
     */
    public function testTheAttemptCapDestroysTheCode(): void
    {
        // Arrange
        $factor = new EmailSecondFactor($this->db);
        $this->storeCode('424242');

        // Act — exhaust the cap with a code that is not the stored one
        for ($attempt = 0; $attempt < EmailSecondFactor::MAX_ATTEMPTS; $attempt++) {
            $this->assertFalse($factor->verify($this->uid, '000000'));
        }
        $code = '424242';

        // Assert — the right code no longer works either
        $this->assertNull($this->storedRow());
        $this->assertFalse($factor->verify($this->uid, $code));
    }

    /**
     * A code issued for one account does not verify for another.
     *
     * The HMAC is keyed by the user id, so this is what that key buys: a row copied
     * between accounts is worthless.
     */
    public function testACodeIsBoundToItsAccount(): void
    {
        // Arrange
        $factor = new EmailSecondFactor($this->db);
        $this->storeCode('424242');
        $row  = $this->storedRow();
        $code = '424242';

        $other = new User();
        $other->username = 'emailfactor_other_' . bin2hex(random_bytes(4));
        $other->email    = $other->username . '@example.com';
        $other->save();
        $otherId = (int) $other->userid;

        // The same stored hash, moved onto the other account
        $this->db->queryBuilder()
            ->table('authserver.twofactor_email_codes')
            ->insert([
                'userid'     => $otherId,
                'purpose'    => EmailSecondFactor::PURPOSE_LOGIN,
                'code_hash'  => (string) $row['code_hash'],
                'expires_at' => time() + 600,
                'attempts'   => 0,
                'created_at' => time(),
            ]);

        try {
            // Act & Assert
            $this->assertFalse($factor->verify($otherId, $code));
            $this->assertTrue($factor->verify($this->uid, $code), 'and still works for its owner');
        } finally {
            $this->db->queryBuilder()->table('authserver.twofactor_email_codes')
                ->where('userid', $otherId)->delete();
            $this->db->queryBuilder()->table('#PREFIX#users')->where('userid', $otherId)->delete();
        }
    }

    /**
     * An empty code is refused without consuming anything.
     *
     * A form that submits nothing must not spend an attempt — otherwise five stray
     * submissions destroy a code the person is about to type.
     */
    public function testAnEmptyCodeSpendsNothing(): void
    {
        // Arrange
        $factor = new EmailSecondFactor($this->db);
        $this->storeCode('424242');
        $code = '424242';

        // Act
        $this->assertFalse($factor->verify($this->uid, ''));
        $this->assertFalse($factor->verify($this->uid, '   '));

        // Assert
        $this->assertSame(0, (int) $this->storedRow()['attempts']);
        $this->assertTrue($factor->verify($this->uid, $code));
    }

    /**
     * `hasLiveCode()` answers without issuing one.
     */
    public function testTheLiveCodeProbeDoesNotIssueAnything(): void
    {
        // Arrange
        $factor = new EmailSecondFactor($this->db);

        // Act & Assert
        $this->assertFalse($factor->hasLiveCode($this->uid));
        $this->assertNull($this->storedRow());

        $factor->send($this->uid);
        $this->assertTrue($factor->hasLiveCode($this->uid));
    }

    /**
     * Expired rows are pruned, live ones are not.
     */
    public function testCleanupRemovesOnlyExpiredCodes(): void
    {
        // Arrange
        $factor = new EmailSecondFactor($this->db);
        $factor->send($this->uid);
        $live = $this->storedRow();

        $this->db->queryBuilder()
            ->table('authserver.twofactor_email_codes')
            ->insert([
                'userid'     => $this->uid,
                'purpose'    => 'stale',
                'code_hash'  => str_repeat('a', 64),
                'expires_at' => time() - 60,
                'attempts'   => 0,
                'created_at' => time() - 700,
            ]);

        // Act
        $factor->cleanupExpired();

        // Assert
        $this->assertFalse($factor->hasLiveCode($this->uid, 'stale'));
        $this->assertTrue($factor->hasLiveCode($this->uid), 'the live code survives');
        $this->assertSame((int) $live['id'], (int) $this->storedRow()['id']);
    }

    // ── How often a code may be sent ──────────────────────────────────────────

    /**
     * A second send straight after the first is refused.
     *
     * Reported from the screen: "send another code" sent one mail per click, so ten clicks
     * sent ten mails — to somebody who may not have asked for any of them, since that
     * button sits on a step-up anybody holding a correct password can reach.
     */
    public function testASecondSendStraightAfterIsRefused(): void
    {
        // Arrange
        $factor = new EmailSecondFactor($this->db);
        $this->assertTrue($factor->send($this->uid));

        // Assert the accounting row exists at all — without it the limit cannot apply,
        // and a failure here means the log, not the limit.
        $rows = $this->db->queryBuilder()
            ->table('authserver.user_activity_log')
            ->where('userid', $this->uid)
            ->where('action', 'twofactor_email_code_sent')
            ->get();
        $this->assertGreaterThan(0, (int) ($rows->numRows ?? 0), 'the send must be recorded');

        // Act & Assert
        $this->assertFalse($factor->send($this->uid), 'a second send must wait');
        $this->assertGreaterThan(0, $factor->secondsUntilResend($this->uid));
    }

    /**
     * A refused send leaves the code the person is holding alive.
     *
     * The important half. Refusing *after* generating a new code would invalidate the one
     * already in their inbox, so clicking twice would leave them unable to sign in with
     * either — worse than the nuisance the limit exists to stop.
     */
    public function testARefusedSendDoesNotInvalidateTheLiveCode(): void
    {
        // Arrange — a known code, then a refused send
        $factor = new EmailSecondFactor($this->db);
        $this->storeCode('424242');
        $this->db->queryBuilder()->table('authserver.user_activity_log')->insert([
            'userid'     => $this->uid,
            'action'     => 'twofactor_email_code_sent',
            'details'    => json_encode(['purpose' => EmailSecondFactor::PURPOSE_LOGIN]),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        // Act
        $this->assertFalse($factor->send($this->uid));

        // Assert
        $this->assertTrue($factor->verify($this->uid, '424242'));
    }

    /**
     * Once the gap has passed, a send is allowed again — up to the window's count.
     *
     * A gap rather than a daily cap: the honest case is somebody who did not receive the
     * first one, and they must be able to try again shortly rather than be locked out of
     * their own login.
     */
    public function testAfterTheGapASendIsAllowedUntilTheWindowIsFull(): void
    {
        // Arrange — four sends, all older than the gap but inside the window
        $factor = new EmailSecondFactor($this->db);
        for ($i = 0; $i < 4; $i++) {
            $this->db->queryBuilder()->table('authserver.user_activity_log')->insert([
                'userid'     => $this->uid,
                'action'     => 'twofactor_email_code_sent',
                'details'    => json_encode(['purpose' => EmailSecondFactor::PURPOSE_LOGIN]),
                'created_at' => gmdate('Y-m-d H:i:s', time() - 300 - $i),
            ]);
        }

        // Act & Assert — the fifth is allowed, and it fills the window
        $this->assertTrue($factor->maySend($this->uid));
        $this->assertSame(0, $factor->secondsUntilResend($this->uid));

        $this->db->queryBuilder()->table('authserver.user_activity_log')->insert([
            'userid'     => $this->uid,
            'action'     => 'twofactor_email_code_sent',
            'details'    => json_encode(['purpose' => EmailSecondFactor::PURPOSE_LOGIN]),
            'created_at' => gmdate('Y-m-d H:i:s', time() - 299),
        ]);

        $this->assertFalse($factor->maySend($this->uid), 'five in the window is the limit');
        $this->assertGreaterThan(0, $factor->secondsUntilResend($this->uid));
    }

    /**
     * Enrolment and login sends do not consume each other's allowance.
     *
     * They are different acts by different people in different places — somebody enrolling
     * on their account screen, and a step-up in the middle of a login — and one exhausting
     * the other would make the second look broken.
     */
    public function testTheTwoPurposesAreCountedSeparately(): void
    {
        // Arrange — the login allowance, spent
        $factor = new EmailSecondFactor($this->db);
        $this->db->queryBuilder()->table('authserver.user_activity_log')->insert([
            'userid'     => $this->uid,
            'action'     => 'twofactor_email_code_sent',
            'details'    => json_encode(['purpose' => EmailSecondFactor::PURPOSE_LOGIN]),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        // Act & Assert
        $this->assertFalse($factor->maySend($this->uid, EmailSecondFactor::PURPOSE_LOGIN));
        $this->assertTrue($factor->maySend($this->uid, EmailSecondFactor::PURPOSE_ENROL));
    }


    /**
     * The wait is reportable, which is what the screens needed.
     *
     * Reported as "the limit does not work": it did, but every refusal said "we could not
     * email you a code — check the address on your profile". That reads as a broken mailer,
     * so the person presses the button again, sees the same words, and concludes nothing is
     * being sent — while the code sits in their inbox. A limit nobody can distinguish from
     * a fault is a limit that looks like a fault.
     */
    public function testTheWaitIsReportableSoAScreenCanExplainItself(): void
    {
        // Arrange
        $factor = new EmailSecondFactor($this->db);
        $this->assertTrue($factor->send($this->uid));

        // Act
        $wait = $factor->secondsUntilResend($this->uid);

        // Assert — a number a screen can put in a sentence, not merely "no"
        $this->assertGreaterThan(0, $wait);
        $this->assertLessThanOrEqual(EmailSecondFactor::RESEND_INTERVAL, $wait);
        $this->assertFalse($factor->maySend($this->uid));
    }

}
