<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use Pramnos\Auth\NewSignInAlert;
use Pramnos\Auth\SignInFingerprint;
use Pramnos\Framework\Testing\DatabaseTestCase;

/**
 * The opt-in, and the history lookup that decides whether a sign-in is new.
 *
 * Against a real database because both halves are queries, and the interesting cases
 * are about what a query returns when there is nothing there — which a mock answers
 * by construction rather than by behaviour.
 *
 * The case worth the whole class is `testAnAccountWithNoHistoryIsNotTreatedAsNew`.
 * A device detector with no history says *everything is new*, so on the day it ships
 * every user who opted in is notified at once. Reading the audit trail that already
 * holds months of user agents is what prevents that, and this is the test that fails
 * if somebody later "simplifies" the history source to a table that starts empty.
 */
class NewSignInAlertTest extends DatabaseTestCase
{
    /** @var string A user id no fixture uses */
    private const USER = 918273;

    /**
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'mysql',
            'server'   => 'db',
            'user'     => 'root',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 3306,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function ownedTables(): array
    {
        return ['userdetails', 'authserver_user_activity_log'];
    }

    /**
     * The two tables this reads, in the shapes the framework creates them.
     *
     * The log table is created as `authserver_user_activity_log`, which is what the
     * query builder resolves `authserver.user_activity_log` to on MySQL — the schema
     * becomes part of the **name**, not a database. On PostgreSQL the same call
     * resolves to a real schema.
     *
     * Worth stating because the first version of this test created the plain
     * `user_activity_log`, on the reasoning that the schema is dropped with an empty
     * prefix. It is not dropped, it is joined with an underscore — so every history
     * lookup found nothing, and four tests failed by reporting "no history" rather
     * than "wrong table".
     *
     * @return array<int, string>
     */
    protected static function schemaStatements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS `userdetails` (
                `userid` BIGINT NOT NULL,
                `fieldname` VARCHAR(35) NOT NULL,
                `value` TEXT,
                PRIMARY KEY (`userid`, `fieldname`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'CREATE TABLE IF NOT EXISTS `authserver_user_activity_log` (
                `id` BIGINT NOT NULL AUTO_INCREMENT,
                `userid` BIGINT NOT NULL,
                `action` VARCHAR(100) NOT NULL,
                `details` TEXT NULL,
                `ip_address` VARCHAR(45) NULL,
                `user_agent` TEXT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }

    /**
     * Records a previous sign-in with the given user agent.
     *
     * @param  string $userAgent The agent
     * @param  string $when      A datetime
     * @return void
     */
    private function recordLogin(string $userAgent, string $when = '2026-01-01 10:00:00'): void
    {
        $this->db->queryBuilder()->table('authserver.user_activity_log')->insert([
            'userid'     => self::USER,
            'action'     => 'login',
            'user_agent' => $userAgent,
            'created_at' => $when,
        ]);
    }

    /** @var string Chrome on Windows */
    private const CHROME_WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36';

    /** @var string Safari on an iPhone */
    private const SAFARI_IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) '
        . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

    /**
     * The preference is off until it is set.
     *
     * @return void
     */
    public function testTheNotificationIsOffByDefault(): void
    {
        // Act & Assert
        $this->assertFalse(NewSignInAlert::isEnabledFor(self::USER, $this->db));
    }

    /**
     * It can be switched on and off again.
     *
     * @return void
     */
    public function testThePreferenceRoundTrips(): void
    {
        // Act
        NewSignInAlert::setEnabledFor(self::USER, true, $this->db);

        // Assert
        $this->assertTrue(NewSignInAlert::isEnabledFor(self::USER, $this->db));

        // Act
        NewSignInAlert::setEnabledFor(self::USER, false, $this->db);

        // Assert — off is stored, not deleted; both mean the same to the reader
        $this->assertFalse(NewSignInAlert::isEnabledFor(self::USER, $this->db));
    }

    /**
     * Switching it on twice does not fail on the composite key.
     *
     * `userdetails` is keyed on `(userid, fieldname)`, so the second write is an
     * update. A plain insert would throw here, and it would throw during a settings
     * save rather than during a test.
     *
     * @return void
     */
    public function testEnablingTwiceIsSafe(): void
    {
        // Act
        NewSignInAlert::setEnabledFor(self::USER, true, $this->db);
        NewSignInAlert::setEnabledFor(self::USER, true, $this->db);

        // Assert
        $this->assertTrue(NewSignInAlert::isEnabledFor(self::USER, $this->db));
    }

    /**
     * A sign-in from a browser the account has used before is not new.
     *
     * @return void
     */
    public function testAFamiliarBrowserIsNotNew(): void
    {
        // Arrange
        $this->recordLogin(self::CHROME_WIN);

        // Act & Assert
        $this->assertFalse(
            NewSignInAlert::isNew(self::USER, SignInFingerprint::fromUserAgent(self::CHROME_WIN), $this->db)
        );
    }

    /**
     * A browser update on a familiar machine is not new.
     *
     * The end-to-end version of the fingerprint's stability test: the history holds
     * Chrome 109, the sign-in is Chrome 133, and nobody should be told anything.
     *
     * @return void
     */
    public function testABrowserUpdateIsNotNew(): void
    {
        // Arrange
        $this->recordLogin(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36'
        );

        // Act & Assert
        $this->assertFalse(
            NewSignInAlert::isNew(self::USER, SignInFingerprint::fromUserAgent(self::CHROME_WIN), $this->db)
        );
    }

    /**
     * A genuinely different device is new.
     *
     * @return void
     */
    public function testADifferentDeviceIsNew(): void
    {
        // Arrange
        $this->recordLogin(self::CHROME_WIN);

        // Act & Assert
        $this->assertTrue(
            NewSignInAlert::isNew(self::USER, SignInFingerprint::fromUserAgent(self::SAFARI_IOS), $this->db)
        );
    }

    /**
     * An account with no history at all is not treated as new.
     *
     * **The test this class exists for.** A device detector with nothing to compare
     * against says everything is new — so on the day the feature ships, every user who
     * opted in is notified at once, about a sign-in they are performing right now.
     *
     * Reading an audit trail that already holds months of user agents is what avoids
     * that, and this test fails if the history source is ever changed to something
     * that starts empty.
     *
     * @return void
     */
    public function testAnAccountWithNoHistoryIsNotTreatedAsNew(): void
    {
        // Act & Assert — no rows recorded at all
        $this->assertFalse(
            NewSignInAlert::isNew(self::USER, SignInFingerprint::fromUserAgent(self::CHROME_WIN), $this->db)
        );
    }

    /**
     * The current sign-in's own log row does not make it familiar.
     *
     * The activity log is written *before* this runs — `Auth` records the login and
     * then checks. Without excluding the fingerprint being tested, every sign-in
     * matches itself and the feature silently never fires: green tests, no mail, no
     * indication anything is wrong.
     *
     * @return void
     */
    public function testTheCurrentSignInDoesNotMakeItselfFamiliar(): void
    {
        // Arrange — an old sign-in, plus the row for the one happening now
        $this->recordLogin(self::CHROME_WIN, '2026-01-01 10:00:00');
        $this->recordLogin(self::SAFARI_IOS, '2026-08-16 09:00:00');

        // Act & Assert — the iPhone is still new, despite its own row existing
        $this->assertTrue(
            NewSignInAlert::isNew(self::USER, SignInFingerprint::fromUserAgent(self::SAFARI_IOS), $this->db)
        );
    }

    /**
     * Sign-ins by other users are not part of this account's history.
     *
     * Obvious, and the kind of filter that gets dropped in a refactor — after which
     * the feature stops firing for everybody, because somebody somewhere has used
     * every browser there is.
     *
     * @return void
     */
    public function testAnotherUsersHistoryIsNotConsidered(): void
    {
        // Arrange
        $this->recordLogin(self::CHROME_WIN);
        $this->db->queryBuilder()->table('authserver.user_activity_log')->insert([
            'userid'     => self::USER + 1,
            'action'     => 'login',
            'user_agent' => self::SAFARI_IOS,
            'created_at' => '2026-01-02 10:00:00',
        ]);

        // Act & Assert
        $this->assertTrue(
            NewSignInAlert::isNew(self::USER, SignInFingerprint::fromUserAgent(self::SAFARI_IOS), $this->db)
        );
    }

    /**
     * Actions other than `login` are not history.
     *
     * `logout` and `login_failed` carry a user agent too. A failed sign-in attempt
     * from an attacker's browser must not make that browser familiar — which would
     * turn the log into a way of switching the alarm off.
     *
     * @return void
     */
    public function testOnlySuccessfulLoginsCount(): void
    {
        // Arrange
        $this->recordLogin(self::CHROME_WIN);
        $this->db->queryBuilder()->table('authserver.user_activity_log')->insert([
            'userid'     => self::USER,
            'action'     => 'login_failed',
            'user_agent' => self::SAFARI_IOS,
            'created_at' => '2026-01-02 10:00:00',
        ]);

        // Act & Assert
        $this->assertTrue(
            NewSignInAlert::isNew(self::USER, SignInFingerprint::fromUserAgent(self::SAFARI_IOS), $this->db),
            'A failed attempt must not teach the account to trust that browser.'
        );
    }

    /**
     * The site can force the alert on for everybody.
     *
     * The feature was per-user opt-in and nothing else, so an operator could not turn it
     * on for a service where the account *is* the product — an authentication server
     * telling somebody their credentials were used from a new device is closer to an
     * obligation than a setting.
     */
    public function testASitePolicyOfAlwaysOverridesThePreference(): void
    {
        // Arrange — the account has not opted in
        NewSignInAlert::setEnabledFor(self::USER, false, $this->db);
        \Pramnos\Application\Settings::setSetting(NewSignInAlert::POLICY_SETTING, 'always');

        try {
            // Act & Assert
            $this->assertTrue(NewSignInAlert::isEnabledFor(self::USER, $this->db));
        } finally {
            \Pramnos\Application\Settings::setSetting(NewSignInAlert::POLICY_SETTING, 'optin');
        }
    }

    /**
     * And it can switch the whole feature off.
     *
     * The case this exists for: an incident generating thousands of sign-ins, where the
     * alert stops being a security feature and becomes the outage's own mailing list.
     */
    public function testASitePolicyOfOffOverridesThePreference(): void
    {
        // Arrange — the account *has* opted in
        NewSignInAlert::setEnabledFor(self::USER, true, $this->db);
        \Pramnos\Application\Settings::setSetting(NewSignInAlert::POLICY_SETTING, 'off');

        try {
            // Act & Assert
            $this->assertFalse(NewSignInAlert::isEnabledFor(self::USER, $this->db));
        } finally {
            \Pramnos\Application\Settings::setSetting(NewSignInAlert::POLICY_SETTING, 'optin');
        }
    }

    /**
     * `optout` is on for an account that has never touched the preference.
     *
     * Under `optin` the people who most need this mail are the ones who will never find the
     * checkbox, so a security feature ends up protecting the users who were already careful.
     * This is the same per-user choice with the starting point reversed.
     */
    public function testOptOutIsOnForAnAccountThatNeverChoseAnything(): void
    {
        // Arrange — no stored preference at all, which is what a real account looks like
        $this->db->queryBuilder()
            ->table('#PREFIX#userdetails')
            ->where('userid', self::USER)
            ->where('fieldname', NewSignInAlert::PREFERENCE)
            ->delete();
        \Pramnos\Application\Settings::setSetting(NewSignInAlert::POLICY_SETTING, 'optout');

        try {
            // Act & Assert
            $this->assertTrue(NewSignInAlert::isEnabledFor(self::USER, $this->db));
        } finally {
            \Pramnos\Application\Settings::setSetting(NewSignInAlert::POLICY_SETTING, 'optin');
        }
    }

    /**
     * And it still stops for an account that turned it off.
     *
     * The half that makes it an opt-*out* rather than `always` under a friendlier name. It
     * works because `setEnabledFor()` writes `'0'` instead of deleting the row: "chose no" and
     * "never chose" are different states, and this policy is the difference between them.
     */
    public function testOptOutRespectsAnAccountThatTurnedItOff(): void
    {
        // Arrange
        NewSignInAlert::setEnabledFor(self::USER, false, $this->db);
        \Pramnos\Application\Settings::setSetting(NewSignInAlert::POLICY_SETTING, 'optout');

        try {
            // Act & Assert
            $this->assertFalse(NewSignInAlert::isEnabledFor(self::USER, $this->db));

            // …and back on when they change their mind
            NewSignInAlert::setEnabledFor(self::USER, true, $this->db);
            $this->assertTrue(NewSignInAlert::isEnabledFor(self::USER, $this->db));
        } finally {
            \Pramnos\Application\Settings::setSetting(NewSignInAlert::POLICY_SETTING, 'optin');
        }
    }

    /**
     * With no policy set, the user's own preference decides.
     *
     * Which is the behaviour every installation had before the setting existed: upgrading
     * must not start or stop sending anybody's mail.
     */
    public function testNoPolicyLeavesTheDecisionWithTheUser(): void
    {
        // Arrange
        \Pramnos\Application\Settings::setSetting(NewSignInAlert::POLICY_SETTING, '');
        NewSignInAlert::setEnabledFor(self::USER, true, $this->db);

        try {
            // Act & Assert
            $this->assertTrue(NewSignInAlert::isEnabledFor(self::USER, $this->db));

            NewSignInAlert::setEnabledFor(self::USER, false, $this->db);
            $this->assertFalse(NewSignInAlert::isEnabledFor(self::USER, $this->db));
        } finally {
            \Pramnos\Application\Settings::setSetting(NewSignInAlert::POLICY_SETTING, 'optin');
        }
    }
}
