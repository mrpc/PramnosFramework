<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Debug\Collectors\AuthCollector;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Http\RequestIdentity;

/**
 * The second-factor half of the debug panel, against a real database.
 *
 * {@see \Pramnos\Tests\Unit\Debug\AuthCollectorTest} covers the credential: which header won,
 * what the token claims, and that the token itself never travels. All of that is decided from
 * `$_SERVER` before any query runs.
 *
 * `twoFactor()` is the other half, and every line of it is a query — the factors an account
 * holds, the floors, the pending step-up, and the two reads behind
 * `debug.reveal_factor_codes`. With no database those all raise, the method answers
 * `['error' => …]` because a panel that throws takes the page with it, and **that is what the
 * unit test was asserting against**: 64 of the collector's 162 statements had never run.
 *
 * The assertion that matters most here is a negative one. This payload rides on responses, sits
 * in a browser's network log and gets pasted into bug reports, so a live six-digit code or a TOTP
 * seed must appear only where an installation asked for it on purpose — `=== true`, not merely
 * truthy, because `'1'` from an environment variable is how a switch gets turned on by accident.
 *
 * Both backends: {@see AuthCollectorRevealPostgreSQLTest} re-runs the class against
 * PostgreSQL/TimescaleDB. Worth it here because the mail lookup is a `LOWER(tomail) = ?` with an
 * `ORDER BY` and a `LIMIT`, and `authserver.twofactor_setup` is a schema on one engine and a
 * prefix on the other.
 */
#[CoversClass(AuthCollector::class)]
class AuthCollectorRevealTest extends BaseTestCase
{
    private $db;

    private int $userId = 0;

    /** The application's own settings, restored in tearDown. */
    private array $originalInfo = [];

    /** APP_KEY as this process had it, restored in tearDown. */
    private string|false $originalKey = false;

    private const USERNAME = 'authcollector_probe';

    private const EMAIL = 'authcollector_probe@example.test';

    /** A valid base32 TOTP seed. */
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

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

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUserTwofactorTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateTwofactorSetupTable::class,
            \Pramnos\Framework\Migrations\Auth\WidenTotpSecretColumns::class,
            \Pramnos\Framework\Migrations\Messaging\CreateMailsTable::class,
        ], $this->db);

        $this->originalInfo = (array) $application->applicationInfo;

        /*
         * A key of this test's own, because `twofactor_setup.temp_secret` is encrypted at rest
         * and the suite must not depend on the developer having run `key:generate`. Restored in
         * tearDown: `putenv()` outlives the test, and a key left behind would decide what the
         * next test's ciphertext opens to.
         */
        $this->originalKey = getenv('APP_KEY');
        $key = 'base64:' . base64_encode(random_bytes(32));
        putenv('APP_KEY=' . $key);
        $_ENV['APP_KEY'] = $key;

        /*
         * `User` keeps a process-wide cache of loaded accounts, and `usertypeOf()` goes through
         * it. Without this, a test that changes the probe account's usertype reads whatever an
         * earlier test in the same process loaded — so the floor assertions passed or failed
         * depending on which order they ran in.
         */
        \Pramnos\User\User::clearUserCache();

        RequestIdentity::reset();
        $_SESSION = [];

        $this->userId = $this->probeUser();
        $this->clearProbe();
    }

    protected function tearDown(): void
    {
        $this->clearProbe();

        $application = Application::currentInstance();
        if (is_object($application)) {
            $application->applicationInfo = $this->originalInfo;
        }

        if ($this->originalKey === false) {
            putenv('APP_KEY');
            unset($_ENV['APP_KEY']);
        } else {
            putenv('APP_KEY=' . $this->originalKey);
            $_ENV['APP_KEY'] = $this->originalKey;
        }

        RequestIdentity::reset();
        $_SESSION = [];

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── What the panel says about a step-up ───────────────────────────────────

    /**
     * A pending step-up is described, not reported as a failed reading.
     *
     * The distinction the unit test cannot make: `twoFactor()` catches everything and returns
     * `['error' => …]`, so with no database the block is present, non-null, and says nothing
     * true. A developer reading "no second factor" when the answer is "the query failed" looks
     * in the wrong place for as long as it takes them to notice.
     */
    public function testAPendingStepUpIsDescribedRatherThanErroring(): void
    {
        // Arrange
        $this->stepUpInFlight(30);

        // Act
        $state = (array) (new AuthCollector())->collect()['twofactor'];

        // Assert
        $this->assertArrayNotHasKey('error', $state, 'the reading failed: ' . json_encode($state));
        $this->assertIsArray($state['held']);
        $this->assertIsArray($state['pending']);
        $this->assertSame($this->userId, (int) $state['pending']['userid']);
        $this->assertGreaterThanOrEqual(30, (int) $state['pending']['waiting_for']);
    }

    /**
     * The floors are evaluated against the *pending* account, which nobody is signed in as.
     *
     * During a step-up `getCurrentUser()` is anonymous, so evaluating the floors against the
     * session would compare them with usertype 0 and answer "no second factor required" for
     * exactly the accounts the floor exists to protect. `usertypeOf()` reads the pending
     * account instead — one query, and only while a step-up is in flight.
     */
    public function testTheFloorsAreEvaluatedAgainstThePendingAccount(): void
    {
        // Arrange — an administrator mid-step-up, with a floor that applies to them.
        $this->setUsertype(90);

        $application = Application::currentInstance();
        $application->applicationInfo['auth']['security'] = [
            'require_second_factor_from_usertype' => 80,
            'require_factor_enrolment_from_usertype' => 80,
        ];

        $this->stepUpInFlight();

        // Act
        $state = (array) (new AuthCollector())->collect()['twofactor'];

        // Assert
        $this->assertArrayNotHasKey('error', $state, json_encode($state));
        $this->assertSame(80, (int) $state['sign_in_floor']);
        $this->assertTrue(
            $state['required_to_sign_in'],
            'a usertype 90 account mid-step-up was reported as not needing a second factor; '
            . json_encode($state)
        );
    }

    /**
     * A floor above the account leaves it unrequired, so the flag means something.
     *
     * The other half of the same read: a `true` that is true for everybody is not a diagnosis.
     */
    public function testAnAccountBelowTheFloorIsNotRequiredToUseASecondFactor(): void
    {
        // Arrange
        $this->setUsertype(10);

        $application = Application::currentInstance();
        $application->applicationInfo['auth']['security'] = [
            'require_second_factor_from_usertype' => 80,
        ];

        $this->stepUpInFlight();

        // Act
        $state = (array) (new AuthCollector())->collect()['twofactor'];

        // Assert
        $this->assertFalse($state['required_to_sign_in'], json_encode($state));
    }

    /** With nobody signed in and nothing pending, the block is absent rather than empty. */
    public function testAnIdlePageLoadPaysForNoQueries(): void
    {
        // Act
        $data = (new AuthCollector())->collect();

        // Assert
        $this->assertNull(
            $data['twofactor'],
            'an anonymous page load ran the second-factor queries anyway'
        );
    }

    // ── The reveal switch ─────────────────────────────────────────────────────

    /**
     * Codes appear only when the switch is exactly `true`.
     *
     * The one assertion in this file with a security consequence. `'1'`, `1` and `'true'` are
     * what a flag looks like when it arrives from an environment variable or a hand-edited
     * config, and each of them is truthy. A `==` here would mean an installation that never
     * asked for live credentials in its network log gets them because somebody typed a string.
     */
    public function testOnlyAnExactTrueRevealsCodes(): void
    {
        // Arrange
        $this->stepUpInFlight();
        $application = Application::currentInstance();

        // Act & Assert
        foreach ([null, false, 0, '', '0', 1, '1', 'true', 'yes'] as $value) {
            $application->applicationInfo['debug'] = ['reveal_factor_codes' => $value];

            $state = (array) (new AuthCollector())->collect()['twofactor'];
            $this->assertArrayNotHasKey(
                'revealed',
                $state,
                'codes were revealed by a truthy non-true value: ' . var_export($value, true)
            );
        }

        $application->applicationInfo['debug'] = ['reveal_factor_codes' => true];
        $this->assertArrayHasKey(
            'revealed',
            (array) (new AuthCollector())->collect()['twofactor'],
            'the switch is on and nothing was revealed'
        );
    }

    /**
     * With the switch on, the block says so in its own text.
     *
     * A developer reading a pasted payload has to be able to tell that this installation is
     * showing live credentials — otherwise the note is only in a docblock nobody is reading at
     * the time.
     */
    public function testTheRevealedBlockSaysItIsDevelopmentOnly(): void
    {
        // Arrange
        $this->revealOn();
        $this->stepUpInFlight();

        // Act
        $revealed = (array) ((array) (new AuthCollector())->collect()['twofactor'])['revealed'];

        // Assert
        $this->assertStringContainsString('reveal_factor_codes', (string) $revealed['note']);
        $this->assertStringContainsString('development only', (string) $revealed['note']);
    }

    /**
     * A half-finished enrolment's secret is decrypted, and a code for it is generated.
     *
     * `user_twofactor.secret` is only written when the setup completes, so during the step
     * where the screen is showing a QR code the seed lives in `authserver.twofactor_setup` —
     * which is exactly the moment somebody wants to read it without walking to their phone.
     * The column is encrypted at rest, so the panel has to open it; a `totp_now` that did not
     * verify against the seed would be worse than none, since it would be typed.
     */
    public function testAPendingEnrolmentSecretIsDecryptedAndCoded(): void
    {
        // Arrange
        $this->revealOn();
        $this->storeSetupSecret(\Pramnos\Security\Encrypter::encrypt(self::SECRET));
        $this->stepUpInFlight();

        // Act
        $revealed = (array) ((array) (new AuthCollector())->collect()['twofactor'])['revealed'];

        // Assert
        $this->assertArrayNotHasKey('totp_error', $revealed, json_encode($revealed));
        $this->assertSame(self::SECRET, $revealed['totp_secret'] ?? null);
        $this->assertMatchesRegularExpression('~^\d{6}$~', (string) ($revealed['totp_now'] ?? ''));
        $this->assertTrue(
            \Pramnos\Auth\TOTPHelper::verifyCode(self::SECRET, (string) $revealed['totp_now']),
            'the code shown does not verify against the secret shown'
        );
    }

    /**
     * A stored secret that will not open is no pending enrolment, not a string of noise.
     *
     * A key rotation leaves rows nothing can decrypt, and showing the ciphertext would put a
     * developer to work typing it into an authenticator.
     *
     * Arranged by rotating `APP_KEY` after the row is written, which is the only way to reach
     * this: `Encrypter::maybeDecrypt()` returns an **unmarked** string unchanged, on purpose, so
     * that rows written before encryption came in still work. My first attempt stored a
     * plausible-looking base64 blob and asserted it was hidden; it came back verbatim, because
     * an unmarked value is not a failed decryption — it is a value from before.
     */
    public function testASecretThatWillNotDecryptIsNotShown(): void
    {
        // Arrange
        $this->revealOn();
        $this->storeSetupSecret(\Pramnos\Security\Encrypter::encrypt(self::SECRET));

        $rotated = 'base64:' . base64_encode(random_bytes(32));
        putenv('APP_KEY=' . $rotated);
        $_ENV['APP_KEY'] = $rotated;

        $this->stepUpInFlight();

        // Act
        $revealed = (array) ((array) (new AuthCollector())->collect()['twofactor'])['revealed'];

        // Assert
        $this->assertArrayNotHasKey('totp_secret', $revealed);
        $this->assertArrayNotHasKey('totp_now', $revealed);
    }

    /** A completed enrolment is used before the pending one, since it is the live factor. */
    public function testACompletedEnrolmentIsPreferredOverAPendingOne(): void
    {
        // Arrange
        $this->revealOn();
        $this->storeSetupSecret(\Pramnos\Security\Encrypter::encrypt('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'));
        $this->storeLiveSecret(self::SECRET);
        $this->stepUpInFlight();

        // Act
        $revealed = (array) ((array) (new AuthCollector())->collect()['twofactor'])['revealed'];

        // Assert
        $this->assertSame(
            self::SECRET,
            $revealed['totp_secret'] ?? null,
            'the panel showed a half-finished enrolment while a live factor exists'
        );
    }

    // ── The mailed code ───────────────────────────────────────────────────────

    /**
     * The mailed code comes out of the newest mail that has one.
     *
     * Out of the mail log rather than the code store, because `twofactor_email_codes` holds an
     * HMAC and nothing else — the right design, and the reason the code is unrecoverable from
     * it. Newest-first matters more than it looks: a code from last week is worse than no code,
     * because somebody will type it and then look for the bug somewhere else.
     */
    public function testTheNewestMailedCodeWins(): void
    {
        // Arrange
        $this->revealOn();
        $this->storeMail('Your code is 111111', time() - 3600);
        $this->storeMail('Your code is 222222', time() - 60);
        $this->stepUpInFlight();

        // Act
        $revealed = (array) ((array) (new AuthCollector())->collect()['twofactor'])['revealed'];

        // Assert
        $this->assertArrayNotHasKey('mailed_error', $revealed, json_encode($revealed));
        $this->assertSame('222222', $revealed['mailed_code'] ?? null);
    }

    /**
     * A newer mail with no code in it does not hide an older one that has.
     *
     * A password-change confirmation arriving after the step-up mail is the ordinary case, and
     * reading only the newest mail would answer "no code" while one is on screen.
     */
    public function testANewerMailWithNoCodeDoesNotHideAnOlderOne(): void
    {
        // Arrange
        $this->revealOn();
        $this->storeMail('Your code is 333333', time() - 300);
        $this->storeMail('Your password was changed. Nothing to do.', time() - 10);
        $this->stepUpInFlight();

        // Act
        $revealed = (array) ((array) (new AuthCollector())->collect()['twofactor'])['revealed'];

        // Assert
        $this->assertSame('333333', $revealed['mailed_code'] ?? null);
    }

    /** Somebody else's mail is not read, however recent it is. */
    public function testAnotherAccountsMailIsNotRead(): void
    {
        // Arrange
        $this->revealOn();
        $this->storeMail('Your code is 444444', time(), 'somebody.else@example.test');
        $this->stepUpInFlight();

        // Act
        $revealed = (array) ((array) (new AuthCollector())->collect()['twofactor'])['revealed'];

        // Assert
        $this->assertArrayNotHasKey(
            'mailed_code',
            $revealed,
            "the panel read another account's mail"
        );
    }

    /**
     * The address is matched case-insensitively, because a mailbox is.
     *
     * `tomail` holds whatever the sender wrote, and an account that registered as
     * `Probe@Example.test` gets mail addressed that way. A case-sensitive comparison answers
     * "no code" on exactly the installation where somebody capitalises their address.
     */
    public function testTheAddressIsMatchedRegardlessOfCase(): void
    {
        // Arrange
        $this->revealOn();
        $this->storeMail('Your code is 555555', time(), strtoupper(self::EMAIL));
        $this->stepUpInFlight();

        // Act
        $revealed = (array) ((array) (new AuthCollector())->collect()['twofactor'])['revealed'];

        // Assert
        $this->assertSame('555555', $revealed['mailed_code'] ?? null);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * Put a step-up in flight, the way the login flow does.
     *
     * **Both** keys, and that is not incidental: `LoginFlow::pending()` reads
     * `loginflow_pending_time` as the moment the step-up started and treats a missing one as
     * `0`, so a session carrying only the user id is an expired step-up and `pendingUserId()`
     * answers null. A test that sets the id alone reaches nothing — the collector returns null
     * for "nobody pending", every assertion about the block is made against an empty array, and
     * it all passes.
     */
    private function stepUpInFlight(int $secondsAgo = 5): void
    {
        $_SESSION['loginflow_pending_userid'] = $this->userId;
        $_SESSION['loginflow_pending_time']   = time() - $secondsAgo;
    }

    /** Turn the switch on for this test only; tearDown puts the settings back. */
    private function revealOn(): void
    {
        Application::currentInstance()->applicationInfo['debug'] = [
            'reveal_factor_codes' => true,
        ];
    }

    /**
     * Give the probe account a usertype, and make the change visible.
     *
     * Three steps, because a raw `update()` is only the first of them. `User::load()` reads
     * through the query cache — `->get(true, 10, 'userlist')`, ten seconds in the `userlist`
     * category — and then keeps the row in a process-wide static as well. Writing through the
     * query builder tells neither, so the account came back with the usertype a *previous test*
     * had given it, and the floor assertions passed or failed depending on how fast the suite
     * ran. `Model` flushes that category on every write, which is why application code never
     * meets this; a test writing SQL directly has to do the same thing by hand.
     */
    private function setUsertype(int $usertype): void
    {
        $this->db->queryBuilder()->table('users')
            ->where('userid', $this->userId)->update(['usertype' => $usertype]);

        $this->db->cacheflush('userlist');
        \Pramnos\User\User::clearUserCache();
    }

    /** This test's account, created once and reused. */
    private function probeUser(): int
    {
        $existing = $this->db->queryBuilder()->table('users')
            ->where('username', self::USERNAME)->first();

        if ($existing && $existing->numRows > 0) {
            return (int) $existing->fields['userid'];
        }

        $this->db->queryBuilder()->table('users')->insert([
            'username' => self::USERNAME,
            'email'    => self::EMAIL,
            'password' => 'x',
            'active'   => 1,
        ]);

        return (int) $this->db->getInsertId();
    }

    private function storeSetupSecret(string $stored): void
    {
        $this->db->queryBuilder()->table('authserver.twofactor_setup')->insert([
            'userid'      => $this->userId,
            'temp_secret' => $stored,
            'used'        => 0,
            'expires_at'  => time() + 900,
            'created_at'  => time(),
        ]);
    }

    /**
     * A completed enrolment: the row `getSecret()` reads, with the seed encrypted at rest.
     *
     * Written directly rather than through `completeSetup()`, which wants a verification code
     * from an authenticator. What is under test is which of the two secrets the panel shows, not
     * how the enrolment got there.
     */
    private function storeLiveSecret(string $secret): void
    {
        $this->db->queryBuilder()->table('authserver.user_twofactor')->insert([
            'userid'             => $this->userId,
            'enabled'            => 1,
            'secret'             => \Pramnos\Security\Encrypter::encrypt($secret),
            'last_used'          => 0,
            'setup_completed_at' => time(),
            'created_at'         => time(),
            'updated_at'         => time(),
        ]);
    }

    private function storeMail(string $content, int $date, ?string $to = null): void
    {
        $this->db->queryBuilder()->table('#PREFIX#mails')->insert([
            'status'     => 1,
            'frommail'   => 'no-reply@example.test',
            'fromname'   => 'Probe',
            'tomail'     => $to ?? self::EMAIL,
            'toname'     => 'Probe',
            'subject'    => 'Your sign-in code',
            'content'    => $content,
            'date'       => $date,
            'module'     => 'authcollector-probe',
            'moduleinfo' => '',
            'extrainfo'  => '',
            'path'       => '',
            'hash'       => md5($content . $date),
        ]);
    }

    /** Everything this test wrote, and nothing else. */
    private function clearProbe(): void
    {
        foreach (
            [
                ['authserver.twofactor_setup', 'userid', $this->userId],
                ['authserver.user_twofactor', 'userid', $this->userId],
                ['#PREFIX#mails', 'module', 'authcollector-probe'],
            ] as [$table, $column, $value]
        ) {
            if ($value === 0) {
                continue;
            }

            try {
                $this->db->queryBuilder()->table($table)->where($column, $value)->delete();
            } catch (\Throwable $exception) {
                // A table this lane has not migrated yet holds nothing to clear.
            }
        }
    }
}
