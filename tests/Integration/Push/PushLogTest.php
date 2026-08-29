<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Push;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
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
        // and the migration is the thing this store is asserted against.
        $this->runMigrations([CreatePushLogTable::class], $this->db);

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
                ['total' => 0, 'delivered' => 0, 'gone' => 0, 'refused' => 0, 'failed' => 0],
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

}
