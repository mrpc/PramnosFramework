<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\NewSignInAlert;
use Pramnos\Auth\SignInFingerprint;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\User;

/**
 * `checkAndNotify()` — the decision at the end of a sign-in, 17 statements never executed.
 *
 * {@see NewSignInAlertTest} covers the two halves underneath it: the preference, and what counts
 * as a new device. This is the method the login lifecycle actually calls, and it is written to be
 * **incapable of failing a login**: every reason not to notify returns false, and anything that
 * raises is logged and returns false. Nothing had run any of that.
 *
 * Which makes the assertions here mostly negative, and deliberately so. A notification is worth
 * nothing next to a sign-in, so the interesting question is never "did the mail go" — it is
 * "what happens when it cannot". Four reasons it declines, and each one would otherwise be an
 * exception thrown out of a login:
 *
 *   - the account never asked for these alerts;
 *   - the device is one the account has used before;
 *   - the account has no deliverable address — not an error, since an account can exist without
 *     one, and throwing would fail the login over a notification;
 *   - **the sign-in history cannot be read.** This is the one worth the file: an empty history
 *     means "everything is new", so a database hiccup would notify on every sign-in — mail to
 *     every user with the preference on, caused by a hiccup they will never hear about.
 *
 * Both backends: {@see NewSignInAlertNotifyPostgreSQLTest} re-runs it. The history read is an
 * `ORDER BY created_at DESC LIMIT` over `authserver.user_activity_log`, which is a hypertable on
 * one engine, and the preference is an upsert on a composite key.
 */
#[CoversClass(NewSignInAlert::class)]
class NewSignInAlertNotifyTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    private const AGENT = 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/120 Safari/537.36';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

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

        // Dropped then migrated, for the reason three fixtures taught earlier: the table has
        // several owners in this suite, some with a narrower shape, and `runMigrations()` is a
        // no-op when it already exists.
        $this->db->query(
            'DROP TABLE IF EXISTS '
            . $this->db->schema()->quoteTable('authserver.user_activity_log')
        );
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class,
        ], $this->db);
        \Pramnos\Auth\ActivityLog::resetTableCache();

        $user = new User();
        $user->username = 'newsignin_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.test';
        $user->save();
        $this->uid = (int) $user->userid;

        $_SERVER['HTTP_USER_AGENT'] = self::AGENT;
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        if ($this->uid > 0) {
            foreach (
                ['authserver.user_activity_log', '#PREFIX#userdetails', '#PREFIX#users'] as $table
            ) {
                try {
                    $this->db->queryBuilder()->table($table)->where('userid', $this->uid)->delete();
                } catch (\Throwable $exception) {
                    // Nothing to undo.
                }
            }
        }

        unset($_SERVER['HTTP_USER_AGENT']);
        \Pramnos\Http\Request::resetInstance();
        User::clearUserCache();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── The four reasons it declines ──────────────────────────────────────────

    /**
     * An account that never asked for these alerts is not notified.
     *
     * Opt-in, and checked first so that an installation where nobody enabled it pays for one
     * indexed read per sign-in rather than a history query and a mail.
     */
    public function testAnAccountThatDidNotAskIsNotNotified(): void
    {
        // Arrange — history from another device, so the only thing declining is the preference.
        $this->recordLogin('Mozilla/5.0 (Windows NT 10.0) Firefox/121.0');

        // Act & Assert
        $this->assertFalse(NewSignInAlert::checkAndNotify($this->uid, $this->db));
    }

    /**
     * A device the account has used before is not new, so nothing is sent.
     *
     * The point of the whole feature: an alert on every sign-in is an alert nobody reads, and the
     * one that matters then goes unread with the rest.
     *
     * The history here is what a real one looks like at this moment — **three** rows: an earlier
     * sign-in from this Mac, one from a Windows machine, and the sign-in happening right now,
     * which the login lifecycle logs before calling this. That third row is the reason the
     * current fingerprint is excluded at all, and getting the fixture wrong is how the bug this
     * test found stayed invisible: with only one Mac row, the exclusion removes it and the device
     * looks new, which reads as correct until you notice the row it removed was supposed to be
     * *this* sign-in rather than last week's.
     */
    public function testAFamiliarDeviceIsNotNotified(): void
    {
        // Arrange
        NewSignInAlert::setEnabledFor($this->uid, true, $this->db);
        $this->recordLogin(self::AGENT, 86400);                            // last week's
        $this->recordLogin('Mozilla/5.0 (Windows NT 10.0) Firefox/121.0'); // the other machine
        $this->recordLogin(self::AGENT, 0);                                // the one happening now

        // Act & Assert
        $this->assertFalse(
            NewSignInAlert::checkAndNotify($this->uid, $this->db),
            'a device the account has signed in from before was reported as new'
        );
    }

    /**
     * Two familiar devices stay familiar — which they did not.
     *
     * An account that uses a laptop and a phone has both in its history. The exclusion dropped
     * *every* row carrying the current fingerprint, so signing in from either removed all of that
     * device's rows and left the other: a non-empty set missing the current fingerprint, which is
     * the definition of "new". Both devices produced an alert on every single sign-in, forever —
     * the alert-nobody-reads failure the whole feature is designed to avoid, arriving through the
     * mechanism meant to prevent it.
     *
     * Asserted from both directions, because a fix that merely stopped excluding would make the
     * current sign-in match itself and the feature would never fire at all.
     */
    public function testTwoFamiliarDevicesBothStayFamiliar(): void
    {
        // Arrange — a laptop and a phone, each used twice, and this sign-in on the laptop.
        NewSignInAlert::setEnabledFor($this->uid, true, $this->db);
        $phone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605 Safari/604';

        $this->recordLogin(self::AGENT, 172800);
        $this->recordLogin($phone, 86400);
        $this->recordLogin(self::AGENT, 0);

        // Act & Assert — on the laptop.
        $this->assertFalse(
            NewSignInAlert::checkAndNotify($this->uid, $this->db),
            'the laptop was reported as new'
        );

        // …and now on the phone.
        $_SERVER['HTTP_USER_AGENT'] = $phone;
        \Pramnos\Http\Request::resetInstance();
        $this->recordLogin($phone, 0);

        $this->assertFalse(
            NewSignInAlert::checkAndNotify($this->uid, $this->db),
            'the phone was reported as new'
        );
    }

    /**
     * A device the account has never used is still reported as new.
     *
     * The other side of the same fix: dropping one row of the current fingerprint must not turn
     * into dropping the check. A machine with no history of its own is what this feature exists
     * to notice.
     */
    public function testAGenuinelyNewDeviceIsStillNew(): void
    {
        // Arrange — history from one machine, and this sign-in from another.
        NewSignInAlert::setEnabledFor($this->uid, true, $this->db);
        $this->recordLogin('Mozilla/5.0 (Windows NT 10.0) Firefox/121.0', 86400);
        $this->recordLogin(self::AGENT, 0);

        // Act & Assert
        $this->assertTrue(
            NewSignInAlert::isNew($this->uid, SignInFingerprint::current(), $this->db),
            'a device with no history of its own was treated as familiar'
        );
    }

    /**
     * An account with no deliverable address is not an error.
     *
     * An account can exist without one — created by an import, by an administrator, by an
     * integration. Throwing here would fail the login over a notification nobody could have
     * received anyway.
     */
    public function testAnAccountWithNoAddressIsNotAnError(): void
    {
        // Arrange — enabled, on a new device, with nowhere to send.
        NewSignInAlert::setEnabledFor($this->uid, true, $this->db);
        $this->recordLogin('Mozilla/5.0 (Windows NT 10.0) Firefox/121.0');

        $this->db->queryBuilder()->table('#PREFIX#users')
            ->where('userid', $this->uid)->update(['email' => '']);
        $this->db->cacheflush('userlist');
        User::clearUserCache();

        // Act & Assert
        $this->assertFalse(
            NewSignInAlert::checkAndNotify($this->uid, $this->db),
            'an account with no address was treated as an error rather than as nothing to do'
        );
    }

    /**
     * A history that cannot be read says nothing, rather than notifying about everything.
     *
     * The assertion this file exists for. An unreadable history yields an empty set of known
     * fingerprints, and an empty set means "this device is new" — so without the guard a database
     * hiccup would send mail to every user with the preference on, about a sign-in they are
     * performing right now, for a reason they will never be told.
     *
     * It is the same shape as the day-one problem the feature was designed around: a device
     * detector with no history says *everything* is new.
     */
    public function testAnUnreadableHistorySaysNothing(): void
    {
        // Arrange — enabled, and the history source gone.
        NewSignInAlert::setEnabledFor($this->uid, true, $this->db);
        $this->db->query(
            'DROP TABLE IF EXISTS '
            . $this->db->schema()->quoteTable('authserver.user_activity_log')
        );

        try {
            // Act & Assert
            $this->assertFalse(
                NewSignInAlert::checkAndNotify($this->uid, $this->db),
                'an unreadable sign-in history notified about a sign-in'
            );
            $this->assertSame(
                [],
                NewSignInAlert::knownFingerprints($this->uid, '', $this->db),
                'a failed read returned something other than an empty set'
            );
        } finally {
            $this->runMigrations([
                \Pramnos\Framework\Migrations\Auth\CreateUserActivityLogTable::class,
            ], $this->db);
            \Pramnos\Auth\ActivityLog::resetTableCache();
        }
    }

    /**
     * An account with no history at all is not notified either.
     *
     * A first-ever sign-in is new by definition, and telling somebody about the thing they are
     * doing right now, from an account they just made, is noise. It is also what every account
     * looks like on the day the feature ships if the history source starts empty.
     */
    public function testAnAccountWithNoHistoryIsNotNotified(): void
    {
        // Arrange
        NewSignInAlert::setEnabledFor($this->uid, true, $this->db);

        // Act & Assert
        $this->assertFalse(NewSignInAlert::checkAndNotify($this->uid, $this->db));
    }

    // ── The preference itself ─────────────────────────────────────────────────

    /**
     * The preference survives a round trip, and defaults to off.
     *
     * Off is the default because an alert nobody asked for is mail nobody wanted, and the screen
     * that turns it on is where somebody decides they want it.
     */
    public function testThePreferenceDefaultsToOffAndSurvivesARoundTrip(): void
    {
        // Act & Assert
        $this->assertFalse(NewSignInAlert::isEnabledFor($this->uid, $this->db));

        NewSignInAlert::setEnabledFor($this->uid, true, $this->db);
        $this->assertTrue(NewSignInAlert::isEnabledFor($this->uid, $this->db));

        NewSignInAlert::setEnabledFor($this->uid, false, $this->db);
        $this->assertFalse(NewSignInAlert::isEnabledFor($this->uid, $this->db));
    }

    /** Nobody is not an account: a userid below one writes nothing and reads false. */
    public function testANonAccountIsNotAPreference(): void
    {
        // Act
        NewSignInAlert::setEnabledFor(0, true, $this->db);

        // Assert
        $this->assertFalse(NewSignInAlert::isEnabledFor(0, $this->db));
        $this->assertSame(
            0,
            (int) $this->db->queryBuilder()->table('#PREFIX#userdetails')
                ->where('userid', 0)->count(),
            'a preference row was written for nobody'
        );
    }

    // ── The configured trigger ────────────────────────────────────────────────

    /**
     * An unrecognised trigger falls back to `new_device`, not to the strictest reading.
     *
     * A typo in a settings row must not start demanding passkeys from a user base that has none.
     * Failing open on a *typo* is right here: the strict readings are the ones that can lock
     * everybody out, and the value is visible on a screen where one that does nothing gets
     * noticed.
     */
    public function testAnUnrecognisedTriggerFallsBackRatherThanTighteningUp(): void
    {
        // Arrange
        $saved = Settings::getSetting('auth_newsignin_trigger');

        try {
            // Act & Assert
            foreach (['', 'nonsense', 'every_signin', '1'] as $configured) {
                Settings::setSetting('auth_newsignin_trigger', $configured, false);

                $this->assertSame(
                    'new_device',
                    NewSignInAlert::trigger(),
                    'a trigger of ' . var_export($configured, true) . ' was taken literally'
                );
            }

            // …and a recognised one is honoured, so the fallback is not a constant.
            Settings::setSetting('auth_newsignin_trigger', 'suspicious', false);
            $this->assertSame('suspicious', NewSignInAlert::trigger());
        } finally {
            Settings::setSetting('auth_newsignin_trigger', (string) $saved, false);
        }
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** A previous successful sign-in with the given agent. */
    private function recordLogin(string $userAgent, int $secondsAgo = 3600): void
    {
        $this->db->queryBuilder()->table('authserver.user_activity_log')->insert([
            'userid'     => $this->uid,
            'action'     => 'login',
            'details'    => null,
            'ip_address' => '203.0.113.7',
            'user_agent' => $userAgent,
            'created_at' => date('Y-m-d H:i:s', time() - $secondsAgo),
        ]);
    }
}
