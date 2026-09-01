<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\Token;
use Pramnos\User\User;

/**
 * Ending somebody else's session — 54 statements on the path a compromised account takes out.
 *
 * A session here is two things, and the reason this code exists is that ending one without the
 * other leaves the account reachable: the `sessions` row a browser is tracked by, and the
 * `web_session` token that actually authenticates its requests. Revoke only the row and a live
 * bearer token remains; revoke only the token and the tracker still believes in a session. Both
 * halves therefore get their own `try`, so a failure on one does not skip the other — which is a
 * property no reading of the code can confirm and this class asserts directly.
 *
 * The other half of the family is what happens on the way in. Every login mints a token, and until
 * `retireSupersededWebSessionTokens()` existed none of them were ever retired — `loadByToken()`
 * reads 0 and NULL as "never expires", and nothing set anything else. A two-day-old installation
 * with one user held 7,255 live tokens, every one of them a working credential.
 *
 * Two subtleties are pinned because both were bugs:
 *
 * - **the device is matched on the fingerprint alone.** The stored `deviceinfo` also carries the
 *   address the token was issued from, and comparing the whole value meant a router reboot between
 *   two logins made the strings differ, so the older token was never retired. The address is the
 *   part that legitimately changes; the fingerprint is the part that identifies the browser.
 * - **the returned count is a count.** `update()` hands back a `Result`, and `(int)` on it raised
 *   «Object of class Result could not be converted to int». The sessions were ended correctly and
 *   only the number was noise, which is exactly why it survived — and why the tests here assert the
 *   count as well as the effect.
 *
 * Both backends: {@see SessionRevocationPostgreSQLTest} re-runs it. The revocation is an update
 * scoped by two and three columns, and `whereIn` over a token list.
 */
#[CoversClass(User::class)]
class SessionRevocationTest extends BaseTestCase
{
    private $db;

    private int $uid = 0;

    private int $otherUid = 0;

    private array $originalSession = [];

    /** @var array<string, bool> Which lanes have rebuilt their tables this run. */
    private static array $migrated = [];

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

        /*
         * Once per class, not once per test.
         *
         * Dropping and re-migrating two tables costs a few hundred milliseconds, and doing it for
         * every test in two lanes added half a minute to the suite — for a shape that cannot change
         * between tests of the same class. The drop still happens, because the point is to defeat a
         * shape left behind by a *different* class; the flag is keyed by class so each backend lane
         * rebuilds its own.
         */
        if (!isset(self::$migrated[static::class])) {
            $this->migrateTables();
            self::$migrated[static::class] = true;
        }

        $this->uid      = $this->makeUser('revoke');
        $this->otherUid = $this->makeUser('bystander');

        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [];

        // A stable browser, so the fingerprint is the same across two logins in one test.
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) Chrome/120 Safari/537.36';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /**
     * Both tables from their shipped migrations, dropped first.
     *
     * `sessions` and `usertokens` are each created by more than one test in this suite, and
     * `runMigrations()` is a no-op when the table already exists — so a shape left by whoever ran
     * first would decide whether these writes land. `usertokens` needs its follow-up migration too:
     * `createWebSessionToken()` re-reads the row it just wrote by `token_lookup`, and against a
     * table without that column the re-read finds nothing and the method silently returns its
     * in-memory fallback, which has no tokenid — so every assertion about retiring a *stored* token
     * would pass against nothing.
     */
    private function migrateTables(): void
    {
        foreach (['#PREFIX#sessions', '#PREFIX#usertokens'] as $table) {
            $this->db->query(
                'DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable($table)
            );
        }

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Core\CreateSessionsTable::class,
            \Pramnos\Framework\Migrations\Auth\CreateUsertokensTable::class,
            \Pramnos\Framework\Migrations\Auth\AddTokenLookupToUsertokens::class,
            /*
             * The retrofit, and not for this class's own sake.
             *
             * `AddMissingIndexes` is where `idx_sessions_userid`, `idx_sessions_time` and
             * `idx_usertokens_token` come from — the creation migrations do not declare them.
             * Rebuilding from the creation migration alone therefore hands the *rest of the suite*
             * two unindexed tables, and every later test that looks a session up by user scans.
             * That cost 40 seconds of wall clock, measured, in tests that have nothing to do with
             * this file. A table rebuilt from part of its history is not the shipped table.
             */
            \Pramnos\Database\Migrations\AddMissingIndexesToExistingTables::class,
        ], $this->db);
    }

    private function makeUser(string $prefix): int
    {
        $user = new User();
        $user->username = $prefix . '_' . bin2hex(random_bytes(4));
        $user->email    = $user->username . '@example.test';
        $user->save();

        return (int) $user->userid;
    }

    protected function tearDown(): void
    {
        foreach ([$this->uid, $this->otherUid] as $uid) {
            if ($uid <= 0) {
                continue;
            }

            foreach (
                ['#PREFIX#usertokens', '#PREFIX#sessions', '#PREFIX#userdetails', '#PREFIX#users']
                as $table
            ) {
                try {
                    $this->db->queryBuilder()->table($table)->where('userid', $uid)->delete();
                } catch (\Throwable) {
                    // Nothing to undo.
                }
            }
        }

        $_SESSION = $this->originalSession;
        unset($_SERVER['HTTP_USER_AGENT'], $_SERVER['REMOTE_ADDR']);
        Settings::setSetting('web_session_lifetime', '');
        User::clearUserCache();

        parent::tearDown();
    }

    /** Distinct per row: `visitorid` is this table's primary key, not a spare column. */
    private int $nextVisitorId = 900001;

    /** Put a `sessions` row in place, as the session handler would. */
    private function openSession(string $sid, int $userid, int $loggedOut = 0): void
    {
        /*
         * The shipped column names, not the obvious ones.
         *
         * `sessions` calls the address `host_addr` and the user agent `agent`, and has no
         * `lastvisit`/`firstvisit` at all — it keeps one `time`. Hand-writing what a framework
         * table «obviously» looks like is how a fixture ends up asserting a shape that does not
         * ship; these came out of the migration.
         */
        $this->db->queryBuilder()->table('#PREFIX#sessions')->insert([
            'sid'       => $sid,
            'userid'    => $userid,
            'logout'    => $loggedOut,
            'host_addr' => '203.0.113.7',
            'agent'     => 'test',
            'time'      => time(),
            'visitorid' => $this->nextVisitorId++,
            'guest'     => 0,
            'uname'     => 'test',
            'url'       => '/',
            'history'   => '',
        ]);
    }

    /** @return array<int, array<string, mixed>> The account's token rows, newest last. */
    private function tokenRows(?int $userid = null): array
    {
        $result = $this->db->queryBuilder()
            ->table('#PREFIX#usertokens')
            ->where('userid', $userid ?? $this->uid)
            ->orderBy('tokenid')
            ->get();

        return (array) ($result === null ? [] : $result->fetchAll());
    }

    private function liveSessionCount(?int $userid = null): int
    {
        $result = $this->db->queryBuilder()
            ->table('#PREFIX#sessions')
            ->where('userid', $userid ?? $this->uid)
            ->where('logout', 0)
            ->get();

        return (int) ($result->numRows ?? 0);
    }

    // ── Minting a session token ───────────────────────────────────────────────

    /**
     * A login mints a live token, stores it, and hands back the stored row.
     *
     * The returned object has to be the *stored* one — it carries the `tokenid` that everything
     * afterwards is scoped by. The in-memory fallback below it exists for a write that succeeded
     * and could not be re-read, and a token with no id cannot be retired, revoked or matched to a
     * session.
     */
    public function testALoginMintsAStoredLiveToken(): void
    {
        // Act
        $token = (new User($this->uid))->createWebSessionToken('198.51.100.4');

        // Assert
        $this->assertGreaterThan(0, (int) $token->tokenid, 'the in-memory fallback was returned');
        $this->assertSame(Token::TYPE_WEB_SESSION, $token->tokentype);
        $this->assertSame('198.51.100.4', $token->ipaddress);
        $this->assertSame($token, $_SESSION['usertoken'] ?? null, 'the request cannot find its token');

        $rows = $this->tokenRows();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['status']);
    }

    /**
     * The token expires, and when it expires is configuration.
     *
     * Until a lifetime existed none of these ever expired, and every login left a permanent
     * credential behind. `0` restores that deliberately — for an installation that wants it — which
     * is why both cases are asserted: a default that quietly became "never" is the original bug
     * returning.
     */
    public function testTheTokenExpiresUnlessTheLifetimeIsZero(): void
    {
        // Arrange
        Settings::setSetting('web_session_lifetime', '3600');

        // Act
        $before = time();
        (new User($this->uid))->createWebSessionToken();
        $rows = $this->tokenRows();

        // Assert
        $expiry = (int) $rows[0]['expires'];
        $this->assertGreaterThanOrEqual($before + 3600, $expiry);
        $this->assertLessThan($before + 3700, $expiry, 'the configured lifetime was ignored');

        // And zero means never, on purpose.
        Settings::setSetting('web_session_lifetime', '0');
        $_SESSION = [];
        (new User($this->uid))->createWebSessionToken();

        $rows   = $this->tokenRows();
        $latest = end($rows);
        $this->assertTrue(
            ($latest['expires'] ?? null) === null || (int) $latest['expires'] === 0,
            'zero was read as an hour rather than as no expiry'
        );
    }

    /**
     * The default lifetime is thirty days, not forever.
     *
     * Generous next to the PHP session it belongs to — whose own idle timeout is 24 minutes out of
     * the box — and short enough that the table stops being append-only.
     */
    public function testTheDefaultLifetimeIsThirtyDays(): void
    {
        // Arrange
        Settings::setSetting('web_session_lifetime', '');

        // Act & Assert
        $this->assertSame(2592000, User::webSessionLifetime());
    }

    /**
     * A second login from the same browser retires the first token.
     *
     * Without this every login left its predecessor valid, and the table filled with working
     * credentials nobody was using. The old token must be *retired*, not deleted: it is the record
     * that the session existed.
     */
    public function testASecondLoginFromTheSameBrowserRetiresTheFirst(): void
    {
        // Arrange
        $first = (new User($this->uid))->createWebSessionToken();
        $firstId = (int) $first->tokenid;

        // Act
        $second = (new User($this->uid))->createWebSessionToken();

        // Assert
        $byId = [];
        foreach ($this->tokenRows() as $row) {
            $byId[(int) $row['tokenid']] = $row;
        }

        $this->assertSame(0, (int) $byId[$firstId]['status'], 'the old token is still a credential');
        $this->assertGreaterThan(0, (int) $byId[$firstId]['removedate']);
        $this->assertSame(1, (int) $byId[(int) $second->tokenid]['status']);
    }

    /**
     * A changed address does not stop the old token being retired.
     *
     * The bug this behaviour was written for: the device is matched on the **fingerprint** decoded
     * out of `deviceinfo`, not on the whole stored value, which also carries the issuing address.
     * Consumer addresses are dynamic — a router reboot between two logins made the two strings
     * differ, the match failed, and the older token stayed live forever. Simulated here by editing
     * the stored address, which is exactly what the real world does to it.
     */
    public function testAChangedAddressStillRetiresTheOldToken(): void
    {
        // Arrange
        $first = (new User($this->uid))->createWebSessionToken();
        $firstId = (int) $first->tokenid;

        $stored = json_decode((string) $this->tokenRows()[0]['deviceinfo'], true);
        $this->assertIsArray($stored, 'the device information was not stored at all');
        $stored['ip'] = '198.51.100.99';
        $this->db->queryBuilder()->table('#PREFIX#usertokens')
            ->where('tokenid', $firstId)
            ->update(['deviceinfo' => json_encode($stored)]);

        $_SESSION = [];

        // Act
        (new User($this->uid))->createWebSessionToken();

        // Assert
        $byId = [];
        foreach ($this->tokenRows() as $row) {
            $byId[(int) $row['tokenid']] = $row;
        }
        $this->assertSame(
            0,
            (int) $byId[$firstId]['status'],
            'a new address left the old token live — the whole value was compared'
        );
    }

    /**
     * A different browser's token is left alone.
     *
     * The other side of the same match, and the reason it is per-device rather than per-account:
     * signing in on a phone must not sign the person out of their laptop.
     */
    public function testADifferentBrowsersTokenSurvives(): void
    {
        // Arrange — the laptop
        $laptop = (new User($this->uid))->createWebSessionToken();
        $laptopId = (int) $laptop->tokenid;

        $_SESSION = [];
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/605.1';

        // Act — the phone
        (new User($this->uid))->createWebSessionToken();

        // Assert
        $byId = [];
        foreach ($this->tokenRows() as $row) {
            $byId[(int) $row['tokenid']] = $row;
        }
        $this->assertSame(
            1,
            (int) $byId[$laptopId]['status'],
            'signing in on a phone signed the laptop out'
        );
    }

    // ── Ending the others ─────────────────────────────────────────────────────

    /**
     * Both halves of every other session are ended, and the count is the sessions.
     *
     * The count is asserted as well as the effect because it is the part that was wrong: `update()`
     * returns a `Result`, and casting that to int raised rather than counting. The sessions were
     * ended correctly and only the number was noise, which is why nobody noticed — a caller
     * reporting «3 other sessions ended» was reporting a number that meant nothing.
     */
    public function testEveryOtherSessionIsEndedInBothHalves(): void
    {
        // Arrange
        $this->openSession('sid-one', $this->uid);
        $this->openSession('sid-two', $this->uid);
        (new User($this->uid))->createWebSessionToken();
        $_SESSION = [];
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux) Firefox/121.0';
        (new User($this->uid))->createWebSessionToken();

        // Act
        $ended = (new User($this->uid))->revokeOtherSessions();

        // Assert
        $this->assertSame(2, $ended, 'the documented count is not the number of sessions ended');
        $this->assertSame(0, $this->liveSessionCount(), 'a session row survived');

        foreach ($this->tokenRows() as $row) {
            $this->assertSame(
                0,
                (int) $row['status'],
                'a live bearer token outlived the session it belonged to'
            );
        }
    }

    /**
     * The caller's own session and token are spared when a sid is given.
     *
     * This is what makes the method usable from a password change: people change a password
     * *because* they think somebody else has it, and signing them out of their own browser reads as
     * the change not having worked. Signing out only themselves is the opposite failure — the other
     * person keeps the account while the owner believes they have taken it back, which is worse than
     * not offering the feature, because it manufactures confidence.
     */
    public function testTheCallersOwnSessionAndTokenAreSpared(): void
    {
        // Arrange
        $this->openSession('sid-mine', $this->uid);
        $this->openSession('sid-theirs', $this->uid);

        $mine = (new User($this->uid))->createWebSessionToken();

        /*
         * The other browser's token is minted with `$_SESSION` empty, and that is not tidiness.
         *
         * `createWebSessionToken()` retires the token in `$_SESSION['usertoken']` before minting —
         * correctly, because a login arriving on a request that already holds one *is* a
         * replacement. So minting the second token while still holding the first would revoke
         * `$mine` here, in the fixture, and the test would be asserting `revokeOtherSessions()`
         * spared something that was already gone. Two logins are two requests; the fixture has to
         * model that.
         */
        $savedAgent = $_SERVER['HTTP_USER_AGENT'];
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux) Firefox/121.0';
        $_SESSION = [];
        $theirs = (new User($this->uid))->createWebSessionToken();
        $_SERVER['HTTP_USER_AGENT'] = $savedAgent;

        // And now this request is the first browser again, holding its own token.
        $_SESSION['usertoken'] = $mine;

        $before = [];
        foreach ($this->tokenRows() as $row) {
            $before[(int) $row['tokenid']] = (int) $row['status'];
        }
        $this->assertSame(1, $before[(int) $mine->tokenid], 'the fixture revoked it before the act');
        $this->assertSame(1, $before[(int) $theirs->tokenid]);

        // Act
        $ended = (new User($this->uid))->revokeOtherSessions('sid-mine');

        // Assert
        $this->assertSame(1, $ended, 'the kept session was counted, or the other was not');

        $result = $this->db->queryBuilder()->table('#PREFIX#sessions')
            ->where('sid', 'sid-mine')->first();
        $this->assertSame(0, (int) $result->fields['logout'], 'the caller signed themselves out');

        $byId = [];
        foreach ($this->tokenRows() as $row) {
            $byId[(int) $row['tokenid']] = $row;
        }
        $this->assertSame(
            1,
            (int) $byId[(int) $mine->tokenid]['status'],
            "the caller's own credential was revoked under them"
        );
        $this->assertSame(
            0,
            (int) $byId[(int) $theirs->tokenid]['status'],
            'the other browser kept a working credential'
        );
    }

    /**
     * A session already ended is not counted again.
     *
     * Scoped by `logout = 0`, so the number reported is sessions this call ended rather than
     * sessions that exist. A screen saying «4 sessions ended» about one is a screen nobody trusts
     * the second time.
     */
    public function testAnAlreadyEndedSessionIsNotCountedAgain(): void
    {
        // Arrange
        $this->openSession('sid-live', $this->uid);
        $this->openSession('sid-gone', $this->uid, 1);

        // Act
        $ended = (new User($this->uid))->revokeOtherSessions();

        // Assert
        $this->assertSame(1, $ended);
    }

    /** Nobody else's sessions or tokens are touched. */
    public function testAnotherAccountIsUntouched(): void
    {
        // Arrange
        $this->openSession('sid-mine', $this->uid);
        $this->openSession('sid-theirs', $this->otherUid);
        (new User($this->otherUid))->createWebSessionToken();
        $_SESSION = [];

        // Act
        (new User($this->uid))->revokeOtherSessions();

        // Assert
        $this->assertSame(1, $this->liveSessionCount($this->otherUid));
        foreach ($this->tokenRows($this->otherUid) as $row) {
            $this->assertSame(1, (int) $row['status'], "another account's session was ended");
        }
    }

    /**
     * The guest and system accounts have nothing to revoke.
     *
     * `userid` 0 and 1 are not people. Without the guard the update would be scoped to a userid
     * every unauthenticated visitor shares, and `revokeOtherSessions()` on a guest would end
     * something.
     */
    public function testTheGuestAndSystemAccountsHaveNothingToRevoke(): void
    {
        // Arrange
        $this->openSession('sid-guest', 0);

        foreach ([0, 1] as $notAPerson) {
            $user = new User();
            $user->userid = $notAPerson;

            // Act & Assert
            $this->assertSame(0, $user->revokeOtherSessions());
        }

        $this->assertSame(1, $this->liveSessionCount(0), "a guest's session row was ended");
    }

    /**
     * With the sessions table gone, the tokens are still revoked.
     *
     * The reason the two halves have their own `try`. Half of this operation failing must not skip
     * the other, because either half left alone leaves the account reachable — and the half that
     * still works is the one that actually authenticates requests. The count comes back 0, which is
     * honest: no session rows were ended.
     *
     * The table is put back in `finally`, because the rest of the suite shares this database.
     */
    public function testWithTheSessionsTableGoneTheTokensAreStillRevoked(): void
    {
        // Arrange
        (new User($this->uid))->createWebSessionToken();
        $_SESSION = [];

        $this->db->query(
            'DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable('#PREFIX#sessions')
        );

        try {
            // Act
            $ended = (new User($this->uid))->revokeOtherSessions();

            // Assert
            $this->assertSame(0, $ended, 'it counted sessions from a table that is not there');

            foreach ($this->tokenRows() as $row) {
                $this->assertSame(
                    0,
                    (int) $row['status'],
                    'one half failing skipped the other, and the credential survived'
                );
            }
        } finally {
            $this->migrateTables();
        }
    }
}
