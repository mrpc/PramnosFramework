<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Push;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Migrations\Notifications\AddOpenedDismissedToPushlog;
use Pramnos\Framework\Migrations\Notifications\AddTokenToPushlog;
use Pramnos\Framework\Migrations\Notifications\CreatePushLogTable;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Push\Log;

/**
 * The push log, against a real table.
 *
 * `/admin/Emails` answers "what was sent, when, to whom, and what came of it" for email. Push
 * had no equivalent: `pushsubscriptions` records a fact about a browser, `massmessagerecipients`
 * covers one send path out of two, and everything a `notify()` sent left no trace at all.
 *
 * What has to hold here is the reading, which is where the answers come from — a store that
 * writes correctly and cannot be filtered is a store nobody can use.
 */
#[CoversClass(Log::class)]
#[CoversClass(\Pramnos\Application\Controllers\PushLogController::class)]
class PushLogTest extends BaseTestCase
{
    private $db;

    private int $userId = 0;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $this->db = \Pramnos\Framework\Factory::getDatabase();

        if (!$this->db->connected) {
            $this->db->connect();
        }

        // Built rather than skipped: a skip that never un-skips is a test that does not exist,
        // and the migration is the thing this store is asserted against. With the policy
        // store first: off TimescaleDB the migration registers its retention there, and
        // the migration tests drop it along with every other table they know.
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Core\CreateFrameworkPoliciesTable::class,
            CreatePushLogTable::class,
            AddOpenedDismissedToPushlog::class,
            AddTokenToPushlog::class,
        ], $this->db);

        // A user id nothing else in the suite uses, so the filters can be asserted exactly.
        $this->userId = 900000 + random_int(1, 89999);
    }

    protected function tearDown(): void
    {
        if ($this->userId > 0) {
            try {
                $this->db->queryBuilder()->table('pramnos.pushlog')
                    ->where('userid', $this->userId)->delete();
            } catch (\Throwable) {
                // Nothing to undo.
            }
        }

        parent::tearDown();
    }

    /**
     * A delivery is recorded with what was sent and what the service answered.
     */
    public function testADeliveryIsRecorded(): void
    {
        // Act
        $written = Log::record(
            $this->userId,
            str_repeat('a', 64),
            ['title' => 'New sign-in', 'body' => 'Chrome on Windows', 'url' => '/account', 'tag' => 'signin'],
            201,
            'Pramnos\\Auth\\Notifications\\NewSignInNotification'
        );

        // Assert
        $this->assertTrue($written);

        $rows = Log::recent(10, ['userid' => $this->userId]);

        $this->assertCount(1, $rows);
        $this->assertSame('New sign-in', $rows[0]['title']);
        $this->assertSame(201, (int) $rows[0]['status']);
        $this->assertSame('signin', $rows[0]['tag']);
        $this->assertGreaterThan(0, (int) $rows[0]['sent']);
    }

    /**
     * A refusal is recorded with the reason and with no endpoint.
     *
     * The row somebody is looking for. Without it an installation with no key pair and one where
     * everything is arriving look identical from every table.
     */
    public function testARefusalIsRecordedWithItsReason(): void
    {
        // Act
        Log::refused($this->userId, Log::NO_SUBSCRIPTION, ['title' => 'New sign-in']);

        // Assert
        $rows = Log::recent(10, ['userid' => $this->userId]);

        $this->assertCount(1, $rows);
        $this->assertSame('', $rows[0]['endpoint_hash'], 'it never reached a subscription');
        $this->assertSame(Log::NO_SUBSCRIPTION, $rows[0]['error']);
        $this->assertSame(0, (int) $rows[0]['status']);
    }

    /**
     * "Failed" for one account is that account's failures only: the OR that makes "failed" is
     * grouped, so beside the account filter it does not bring in every other account's.
     */
    public function testTheFailedFilterStaysWithinTheAccount(): void
    {
        // Arrange
        Log::record($this->userId + 1, str_repeat('e', 64), ['title' => 'theirs'], 410);
        Log::record($this->userId, str_repeat('f', 64), ['title' => 'mine'], 410);

        try {
            // Act
            $failed = array_column(Log::recent(50, ['userid' => $this->userId, 'failed' => true]), 'title');
        } finally {
            $this->db->queryBuilder()->table('pramnos.pushlog')->where('userid', $this->userId + 1)->delete();
        }

        // Assert
        $this->assertSame(['mine'], $failed, 'another account\'s failure is not this account\'s');
    }

    /**
     * "Failed" is every outcome that is not a delivery, and it is one filter.
     *
     * A 410 is a dead subscription, a 429 is a busy service and a 0 never reached one — three
     * different problems, and an operator looking for "what went wrong" wants all three. A
     * filter that only knew one status would hide the other two.
     */
    public function testTheFailedFilterCoversEveryOutcomeThatIsNotADelivery(): void
    {
        // Arrange
        $hash = str_repeat('b', 64);
        Log::record($this->userId, $hash, ['title' => 'ok'], 201);
        Log::record($this->userId, $hash, ['title' => 'gone'], 410);
        Log::record($this->userId, $hash, ['title' => 'busy'], 429);
        Log::refused($this->userId, Log::NO_KEYS, ['title' => 'refused']);

        // Act
        $failed = array_column(Log::recent(20, ['userid' => $this->userId, 'failed' => true]), 'title');

        // Assert
        $this->assertNotContains('ok', $failed);
        $this->assertContains('gone', $failed);
        $this->assertContains('busy', $failed);
        $this->assertContains('refused', $failed);
    }

    /**
     * The filter by account is exact.
     *
     * The user card links here with a `userid`, and a filter that leaked another account's
     * notifications onto somebody's screen would be a disclosure, not a bug in a list.
     */
    public function testTheAccountFilterIsExact(): void
    {
        // Arrange
        Log::record($this->userId, str_repeat('c', 64), ['title' => 'mine'], 201);
        Log::record($this->userId + 1, str_repeat('d', 64), ['title' => 'theirs'], 201);

        try {
            // Act
            $rows = Log::recent(20, ['userid' => $this->userId]);

            // Assert
            $this->assertSame(['mine'], array_column($rows, 'title'));
        } finally {
            $this->db->queryBuilder()->table('pramnos.pushlog')
                ->where('userid', $this->userId + 1)->delete();
        }
    }

    /**
     * A title longer than the column is cut, not refused.
     *
     * A push title is capped at 120 characters before it is sent, but `Log` is also called by
     * anything else that wants to record one — and losing the whole row because a caller passed
     * a long string would lose the audit trail to protect a column.
     */
    public function testAnOverlongValueIsCutRatherThanLosingTheRow(): void
    {
        // Act
        $written = Log::record(
            $this->userId,
            str_repeat('e', 64),
            ['title' => str_repeat('x', 400), 'body' => str_repeat('y', 900)],
            201
        );

        // Assert
        $this->assertTrue($written);

        $rows = Log::recent(1, ['userid' => $this->userId]);

        $this->assertLessThanOrEqual(200, mb_strlen((string) $rows[0]['title']));
        $this->assertLessThanOrEqual(500, mb_strlen((string) $rows[0]['body']));
    }

    /**
     * The week's shape separates the four outcomes.
     *
     * "4,812 delivered" beside "900 not sent" is a different situation from "4,812 delivered"
     * alone, and the second number is the one that leads anywhere.
     */
    public function testTheStatsSeparateTheOutcomes(): void
    {
        // Arrange
        $hash = str_repeat('f', 64);
        Log::record($this->userId, $hash, ['title' => 'ok'], 201);
        Log::record($this->userId, $hash, ['title' => 'gone'], 410);
        Log::record($this->userId, $hash, ['title' => 'busy'], 503);
        Log::refused($this->userId, Log::NO_LIBRARY, ['title' => 'refused']);

        // Act
        $before = Log::stats();

        // Assert — asserted as differences, because the table is shared with the rest of the run
        $this->assertGreaterThanOrEqual(1, $before['delivered']);
        $this->assertGreaterThanOrEqual(1, $before['gone']);
        $this->assertGreaterThanOrEqual(1, $before['refused']);
        $this->assertGreaterThanOrEqual(1, $before['failed']);
        $this->assertSame(
            $before['delivered'] + $before['gone'] + $before['refused'] + $before['failed'],
            $before['total'],
            'every row is in exactly one bucket, or the numbers do not add up on the screen'
        );
    }

    /**
     * A push is recorded at the moment it was sent, whatever zone PHP runs in.
     *
     * `sent` is a `timestamptz`. A local time written without its offset is read in the
     * database's zone, so on a PHP that is not on the database's clock every row moved by the
     * difference — and the last day's stats counted the wrong day. +14 hours makes any such
     * shift impossible to miss.
     */
    public function testTheMomentIsTheSameInEveryZone(): void
    {
        // Arrange
        $zone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati');

        try {
            // Act
            Log::record($this->userId, str_repeat('a', 64), ['title' => 'now'], 201);
            $row   = Log::recent(1, ['userid' => $this->userId])[0];
            $stats = Log::stats(1);
        } finally {
            date_default_timezone_set($zone);
        }

        // Assert — within two minutes of now, not fourteen hours off
        $this->assertEqualsWithDelta(time(), Log::sentAt($row), 120);
        $this->assertGreaterThanOrEqual(1, $stats['delivered'], 'the last day counts it');
    }

    /**
     * Pruning removes what is old and keeps what is not.
     *
     * A notification is cheap to send, so applications send many, and none of it is worth a
     * year. A log that only grows is one somebody eventually truncates by hand.
     */
    public function testPruningRemovesOnlyTheOldRows(): void
    {
        // Arrange
        Log::record($this->userId, str_repeat('a', 64), ['title' => 'recent'], 201);

        $this->db->queryBuilder()->table('pramnos.pushlog')
            ->insert([
                'userid' => $this->userId,
                'endpoint_hash' => str_repeat('a', 64),
                'notification' => '',
                'title' => 'ancient',
                'body' => '',
                'url' => '',
                'tag' => '',
                'status' => 201,
                'error' => '',
                'sent' => date('Y-m-d H:i:s', time() - 200 * 86400),
            ]);

        // Act
        Log::prune(90);

        // Assert
        $titles = array_column(Log::recent(20, ['userid' => $this->userId]), 'title');

        $this->assertContains('recent', $titles);
        $this->assertNotContains('ancient', $titles);
    }
    /**
     * With the table gone, every method answers rather than throwing.
     *
     * Not hypothetical: it is what a half-run migration looks like, and a push is usually one
     * line inside something that mattered more — a sign-in, a password change. An exception
     * here would turn that into a 500 for a notification nobody would have missed.
     */
    public function testAMissingTableIsAnAnswerRatherThanAnException(): void
    {
        // Arrange
        $table = $this->db->schema()->resolveTableName('pramnos.pushlog');
        $this->db->query('DROP TABLE IF EXISTS `' . $table . '`');

        try {
            // Act & Assert
            $this->assertFalse(Log::record($this->userId, 'a', ['title' => 'x'], 201));
            $this->assertFalse(Log::refused($this->userId, Log::NO_KEYS));
            $this->assertSame([], Log::recent(10, ['userid' => $this->userId]));
            $this->assertSame(
                ['total' => 0, 'delivered' => 0, 'gone' => 0, 'refused' => 0, 'failed' => 0, 'opened' => 0, 'dismissed' => 0],
                Log::stats()
            );
            $this->assertSame(0, Log::prune(90));
        } finally {
            $this->runMigrations([CreatePushLogTable::class], $this->db);
        }
    }

    /**
     * A row's `sent` reads back as a timestamp whatever the driver hands over.
     *
     * PostgreSQL returns `2026-08-29 14:49:32.517335+00` and MySQL `2026-08-29 14:49:32`, and a
     * screen wants neither. One reader, so no view parses a date itself — which is how `d/m/Y`
     * ended up being sorted as a string elsewhere in this framework.
     */
    public function testTheTimestampIsReadBackWhateverShapeItArrivesIn(): void
    {
        // Arrange
        Log::record($this->userId, str_repeat('a', 64), ['title' => 'now'], 201);
        $row = Log::recent(1, ['userid' => $this->userId])[0];

        // Act & Assert
        $this->assertGreaterThan(time() - 300, Log::sentAt($row));
        $this->assertSame(1756000000, Log::sentAt(['sent' => '1756000000']),
            'a unix integer, for anything that still writes one');
        $this->assertSame(0, Log::sentAt([]), 'and nothing at all is zero, not a crash');
    }

    /**
     * A status filter narrows to exactly that answer.
     *
     * "Show me the 410s" is the question somebody asks after seeing the count — the
     * subscriptions that are gone are the ones worth acting on.
     */
    public function testTheStatusFilterIsExact(): void
    {
        // Arrange
        $hash = str_repeat('9', 64);
        Log::record($this->userId, $hash, ['title' => 'ok'], 201);
        Log::record($this->userId, $hash, ['title' => 'gone'], 410);

        // Act
        $rows = Log::recent(20, ['userid' => $this->userId, 'status' => 410]);

        // Assert
        $this->assertSame(['gone'], array_column($rows, 'title'));
    }

    /**
     * The screen's own readers are the store, not a second query.
     *
     * Every unit test of that controller replaces these. Without one real call the seams could
     * point anywhere — at a method that no longer exists, at a filter shape the store does not
     * understand — and the suite would stay green while the screen showed nothing.
     */
    public function testTheControllerReadsThroughTheStore(): void
    {
        // Arrange
        Log::record($this->userId, str_repeat('c', 64), ['title' => 'through the controller'], 201);

        $controller = new class extends \Pramnos\Application\Controllers\PushLogController {
            /** @return list<array<string, mixed>> */
            public function probeRows(int $limit, array $filter): array
            {
                return $this->rows($limit, $filter);
            }

            /** @return array<string, int> */
            public function probeStats(): array
            {
                return $this->stats();
            }
        };

        // Act
        $rows  = $controller->probeRows(10, ['userid' => $this->userId]);
        $stats = $controller->probeStats();

        // Assert
        $this->assertSame(['through the controller'], array_column($rows, 'title'));
        $this->assertGreaterThanOrEqual(1, $stats['delivered']);
    }

    /**
     * And the action is registered, or the address is a 404.
     *
     * `Controller::exec()` dispatches what `addAuthAction()` was given and nothing else — the
     * recurring failure in this codebase being a screen that renders correctly at an address
     * nothing routes to.
     */
    public function testTheDisplayActionIsRegistered(): void
    {
        // Act
        $controller = new \Pramnos\Application\Controllers\PushLogController();

        // Assert
        $this->assertContains(
            'display',
            $controller->actions_auth
        );
    }

    /**
     * One row is found by its id, with everything the detail page shows; an unknown id is null.
     */
    public function testARowIsFoundById(): void
    {
        // Arrange
        Log::record($this->userId, str_repeat('b', 64), ['title' => 'Editorial report', 'body' => 'Ready', 'url' => '/r', 'tag' => 'report'], 201, 'App\\Report');
        $id = (int) Log::recent(1, ['userid' => $this->userId])[0]['pushid'];

        // Act
        $row = Log::find($id);

        // Assert
        $this->assertSame('Editorial report', $row['title']);
        $this->assertSame('report', $row['tag']);
        $this->assertSame(str_repeat('b', 64), $row['endpoint_hash']);
        $this->assertNull(Log::find(0));
        $this->assertNull(Log::find(PHP_INT_MAX));
    }

    /**
     * The device filter returns one browser's pushes and no other's.
     *
     * The detail page's "recent pushes to this device" — a filter that leaked would put another
     * browser's history beside the one being diagnosed.
     */
    public function testTheDeviceFilterIsExact(): void
    {
        // Arrange — two browsers on one account
        Log::record($this->userId, str_repeat('c', 64), ['title' => 'to c'], 201, 'X');
        Log::record($this->userId, str_repeat('d', 64), ['title' => 'to d'], 201, 'X');
        Log::record($this->userId, str_repeat('c', 64), ['title' => 'to c again'], 410, 'X');

        // Act
        $rows = Log::recent(10, ['endpoint_hash' => str_repeat('c', 64)]);

        // Assert
        $this->assertSame(['to c again', 'to c'], array_column($rows, 'title'));
    }

    /**
     * FW-495: a row is reserved **before** the send, with the id it gets usable right away — the
     * payload the browser receives has to carry it — and `complete()` only ever sets the status
     * of that same row rather than writing a second one.
     */
    public function testAReservedRowIsCompletedNotDuplicated(): void
    {
        // Act
        $pushId = Log::reserve($this->userId, str_repeat('e', 64), ['title' => 'Νέο μήνυμα'], 'Pramnos\\Conversations\\ConversationNotification');
        $before = Log::find($pushId);
        $completed = Log::complete($pushId, 201);
        $after  = Log::find($pushId);

        // Assert
        $this->assertGreaterThan(0, $pushId);
        $this->assertSame(0, $before['status'], 'reserved with a placeholder status');
        $this->assertTrue($completed);
        $this->assertSame(201, $after['status']);
        $this->assertSame($pushId, Log::recent(10, ['userid' => $this->userId])[0]['pushid'], 'one row, not two');
        $this->assertTrue(Log::complete($pushId, 500), 'completing twice is harmless');
        $this->assertFalse(Log::complete(0, 201), 'no id, nothing to complete');
    }

    /**
     * `opened()` / `dismissed()` set their own column once, the first report; the other stays
     * null — the hold-unopened rule (FW-495) reads both as "was this seen at all", and a push
     * that was both opened and later dismissed must not look like neither happened.
     */
    /** `verifyToken()` itself: `hash_equals`, not `===`, and false on anything that is not an exact match. */
    public function testVerifyTokenIsExactAndTimingSafe(): void
    {
        // Arrange
        $token  = bin2hex(random_bytes(16));
        $pushId = Log::reserve($this->userId, str_repeat('m', 64), ['title' => 'x'], '', $token);
        $noToken = Log::reserve($this->userId, str_repeat('n', 64), ['title' => 'y']);

        // Assert
        $this->assertTrue(Log::verifyToken($pushId, $token));
        $wrongLastChar = substr($token, 0, -1) . (substr($token, -1) === '0' ? '1' : '0');
        $this->assertFalse(Log::verifyToken($pushId, $wrongLastChar));
        $this->assertFalse(Log::verifyToken($pushId, ''));
        $this->assertFalse(Log::verifyToken(0, $token));
        $this->assertFalse(Log::verifyToken($noToken, ''), 'nothing was ever stored for this row');
        $this->assertFalse(Log::verifyToken($noToken, 'anything'));
        $this->assertFalse(Log::verifyToken(PHP_INT_MAX, $token), 'no such row');
    }

    public function testOpenedAndDismissedAreRecordedOnce(): void
    {
        // Arrange
        $opened    = Log::reserve($this->userId, str_repeat('f', 64), ['title' => 'a']);
        $dismissed = Log::reserve($this->userId, str_repeat('f', 64), ['title' => 'b']);

        // Act
        $this->assertTrue(Log::opened($opened));
        $this->assertTrue(Log::opened($opened), 'reported twice: still true, still one row');
        $this->assertTrue(Log::dismissed($dismissed));

        // Assert
        $this->assertNotNull(Log::find($opened)['opened_at']);
        $this->assertNull(Log::find($opened)['dismissed_at']);
        $this->assertNotNull(Log::find($dismissed)['dismissed_at']);
        $this->assertNull(Log::find($dismissed)['opened_at']);
        $this->assertFalse(Log::opened(0));
        $this->assertFalse(Log::dismissed(0));
    }

    /**
     * `unresolved()` finds the most recent delivered push to this browser, of this notification
     * class, that is neither opened nor dismissed — and nothing once it is either, or once a
     * newer one of the same kind was delivered and resolved.
     */
    public function testUnresolvedFindsOnlyWhatIsStillSitting(): void
    {
        // Arrange
        $hash = str_repeat('g', 64);
        $class = 'Pramnos\\Conversations\\ConversationNotification';

        // Nothing yet
        $this->assertNull(Log::unresolved($hash, $class));

        $first = Log::reserve($this->userId, $hash, ['title' => 'first', 'tag' => 'conversation-1'], $class);
        Log::complete($first, 201);

        // Act & Assert — delivered, unopened: found
        $found = Log::unresolved($hash, $class);
        $this->assertSame($first, $found['pushid']);
        $this->assertSame('conversation-1', $found['tag']);

        // Opening it clears it
        Log::opened($first);
        $this->assertNull(Log::unresolved($hash, $class));

        // A different notification class on the same browser does not count
        $second = Log::reserve($this->userId, $hash, ['title' => 'other'], 'App\\OtherNotification');
        Log::complete($second, 201);
        $this->assertNull(Log::unresolved($hash, $class));

        // Never delivered (status 0): not "unresolved", just never sent
        $third = Log::reserve($this->userId, $hash, ['title' => 'failed'], $class);
        $this->assertNull(Log::unresolved($hash, $class));
    }

    /**
     * The open rate, by device: delivered, opened and dismissed counted per `endpoint_hash`, one
     * account's own only, and within the window — not another account's, and not stale.
     */
    public function testOpenRateIsPerDevice(): void
    {
        // Arrange — two browsers, one account
        $a = str_repeat('h', 64);
        $b = str_repeat('i', 64);
        $id1 = Log::reserve($this->userId, $a, ['title' => '1']);
        Log::complete($id1, 201);
        Log::opened($id1);
        $id2 = Log::reserve($this->userId, $a, ['title' => '2']);
        Log::complete($id2, 201);
        $id3 = Log::reserve($this->userId, $b, ['title' => '3']);
        Log::complete($id3, 201);
        Log::dismissed($id3);
        // Somebody else's device must not show up here
        $theirs = Log::reserve($this->userId + 1, $a, ['title' => 'theirs']);
        Log::complete($theirs, 201);

        // Act
        $rate = Log::openRate($this->userId);
        $byHash = array_combine(array_column($rate, 'endpoint_hash'), $rate);

        // Assert
        $this->assertSame(['delivered' => 2, 'opened' => 1, 'dismissed' => 0], array_intersect_key($byHash[$a], ['delivered' => 0, 'opened' => 0, 'dismissed' => 0]));
        $this->assertSame(['delivered' => 1, 'opened' => 0, 'dismissed' => 1], array_intersect_key($byHash[$b], ['delivered' => 0, 'opened' => 0, 'dismissed' => 0]));
        $this->db->queryBuilder()->table('pramnos.pushlog')->where('pushid', $theirs)->delete();
    }

    /**
     * `deviceSummary()`: {@see testOpenRateIsPerDevice}'s answer, for every account at once — a
     * screen listing every device wants this grouped once, not `openRate()` looped per account.
     */
    public function testDeviceSummaryGroupsEveryAccountAtOnce(): void
    {
        // Arrange — two accounts, one browser each, and a third that is not delivered at all
        $other = $this->userId + 1;
        $a     = str_repeat('o', 64);
        $b     = str_repeat('p', 64);
        $id1   = Log::reserve($this->userId, $a, ['title' => '1'], '', bin2hex(random_bytes(8)));
        Log::complete($id1, 201);
        Log::opened($id1);
        $id2 = Log::reserve($other, $b, ['title' => '2'], '', bin2hex(random_bytes(8)));
        Log::complete($id2, 201);
        $id3 = Log::reserve($other, $b, ['title' => '3'], '', bin2hex(random_bytes(8)));
        Log::complete($id3, 410); // not delivered: out of the summary

        // Act
        $summary = Log::deviceSummary();
        $byHash  = array_combine(array_column($summary, 'endpoint_hash'), $summary);

        // Assert
        $this->assertSame(['userid' => $this->userId, 'delivered' => 1, 'opened' => 1, 'dismissed' => 0, 'open_rate' => 100.0],
            array_intersect_key($byHash[$a], ['userid' => 0, 'delivered' => 0, 'opened' => 0, 'dismissed' => 0, 'open_rate' => 0]));
        $this->assertSame(['userid' => $other, 'delivered' => 1, 'opened' => 0, 'dismissed' => 0, 'open_rate' => 0.0],
            array_intersect_key($byHash[$b], ['userid' => 0, 'delivered' => 0, 'opened' => 0, 'dismissed' => 0, 'open_rate' => 0]));
        $this->db->queryBuilder()->table('pramnos.pushlog')->where('userid', $other)->delete();
    }

    /**
     * `Push::report()`, against the real table: the service worker's receipt is what sets
     * `opened_at` / `dismissed_at` at all, and it answers the same shape whether the id or the
     * action was good or not — nothing here is worth telling a stranger apart by.
     */
    public function testTheReportActionSetsItsOwnColumn(): void
    {
        // Arrange
        $token  = bin2hex(random_bytes(16));
        $pushId = Log::reserve($this->userId, str_repeat('j', 64), ['title' => 'x'], '', $token);
        Log::complete($pushId, 201);
        $controller = $this->reportController(['pushid' => $pushId, 'token' => $token, 'action' => 'opened']);
        $badAction  = $this->reportController(['pushid' => $pushId, 'token' => $token, 'action' => 'nonsense']);

        // Act
        $controller->report();
        $badAction->report();

        // Assert
        $this->assertSame(['ok' => true], $controller->payload);
        $this->assertNotNull(Log::find($pushId)['opened_at']);
        $this->assertSame(['ok' => false], $badAction->payload, 'an action it does not recognise');
    }

    /**
     * FW-495 follow-up (security): `pushid` is a plain auto-increment, so a report against it
     * has to prove it, or anybody could mark any account's push opened — skewing their open
     * rates and clearing the hold-unopened rule for a device that is not theirs. A wrong token,
     * no token, or a row with none stored (reserved without one, or before the migration ran)
     * all refuse — and change nothing.
     */
    public function testAWrongOrMissingTokenChangesNothing(): void
    {
        // Arrange
        $token    = bin2hex(random_bytes(16));
        $pushId   = Log::reserve($this->userId, str_repeat('k', 64), ['title' => 'x'], '', $token);
        Log::complete($pushId, 201);
        $noToken  = Log::reserve($this->userId, str_repeat('l', 64), ['title' => 'y']); // reserved without one
        Log::complete($noToken, 201);
        $wrong    = $this->reportController(['pushid' => $pushId, 'token' => bin2hex(random_bytes(16)), 'action' => 'opened']);
        $missing  = $this->reportController(['pushid' => $pushId, 'action' => 'opened']);
        $unknown  = $this->reportController(['pushid' => 999999999, 'token' => $token, 'action' => 'opened']);
        $neverSet = $this->reportController(['pushid' => $noToken, 'token' => '', 'action' => 'opened']);

        // Act
        $wrong->report();
        $missing->report();
        $unknown->report();
        $neverSet->report();

        // Assert
        $this->assertSame(['ok' => false], $wrong->payload, 'a token that does not match');
        $this->assertSame(['ok' => false], $missing->payload, 'no token at all');
        $this->assertSame(['ok' => false], $unknown->payload, 'no such row, so nothing to match against');
        $this->assertSame(['ok' => false], $neverSet->payload, 'reserved without a token: never verifiable, by design');
        $this->assertNull(Log::find($pushId)['opened_at'], 'the real row: untouched by the wrong-token attempts');
        $this->assertNull(Log::find($noToken)['opened_at'], 'the token-less row: untouched too');

        // The real token still works afterwards
        $this->reportController(['pushid' => $pushId, 'token' => $token, 'action' => 'opened'])->report();
        $this->assertNotNull(Log::find($pushId)['opened_at']);
    }

    /** A `Push` controller whose body is the given array, with `json()` captured rather than sent. */
    private function reportController(array $body): object
    {
        return new class ($body) extends \Pramnos\Application\Controllers\Push {
            public array $payload = [];

            public function __construct(private array $requestBody)
            {
            }

            protected function body(): array { return $this->requestBody; }

            protected function json(array $data, int $status = 200): mixed
            {
                $this->payload = $data;

                return null;
            }
        };
    }
}
