<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Broadcasting;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Settings;
use Pramnos\Broadcasting\DatabaseEventStore;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The backplane for deployments that have no Redis — at 0%, never executed.
 *
 * This is the store behind `DatabaseDriver`: publishers append a row, consumers poll for rows
 * newer than the last id they delivered. The driver's own poll loop is unit-tested against an
 * in-memory store, which is what the interface exists for — and it means nothing here had ever
 * run against a real table. The three things a poll backplane can get wrong are all invisible to
 * that in-memory double:
 *
 * - **the payload shape on the way back.** It goes in as a JSON string; MySQL hands it back as one
 *   and PostgreSQL may hand back `json` either way depending on the driver. A consumer that
 *   trusted one shape would break on the other backend and only there, so
 *   {@see DatabaseEventStorePostgreSQLTest} is what makes this a claim rather than a hope.
 * - **the boundary of "newer than".** `id > $lastId`, strictly — off by one in the inclusive
 *   direction redelivers the last event on every poll, which for a driver polling twice a second
 *   is a consumer that never stops receiving the same message.
 * - **who the channel filter lets through.** An empty subscription must mean *nothing*, not
 *   everything: `IN ()` is not valid SQL, and the two natural repairs — omitting the clause, or
 *   returning the unfiltered set — both hand a consumer every channel on the server.
 *
 * The events are transient by design (a scheduled job prunes them), so every test here appends its
 * own on a channel named after itself and removes them afterwards. Sharing a fixture between them
 * would be cheaper and wrong: `latestId()` is a property of the whole table, and a test that
 * appends changes what the next one starts from.
 */
#[CoversClass(DatabaseEventStore::class)]
class DatabaseEventStoreTest extends BaseTestCase
{
    private $db;

    private DatabaseEventStore $store;

    /** Every channel this class uses starts with this, so cleanup can find them all. */
    private const PREFIX = 'store-probe-';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        \Pramnos\Application\Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        /*
         * Built from the shipped migration, not by hand.
         *
         * A hand-rolled CREATE TABLE here would assert a shape the framework does not ship — and
         * that is not hypothetical: `created_at` has a default this store depends on, since
         * `append()` writes `NOW()` and nothing else ever sets it.
         */
        $this->runMigrations([
            \Pramnos\Framework\Migrations\Broadcasting\CreateBroadcastEventsTable::class,
        ], $this->db);

        $this->clearProbe();

        $this->store = new DatabaseEventStore($this->db);
    }

    protected function tearDown(): void
    {
        $this->clearProbe();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    private function clearProbe(): void
    {
        $this->db->query(
            "DELETE FROM broadcast_events WHERE channel LIKE '" . self::PREFIX . "%'"
        );
    }

    /** A channel name unique to the calling test, so the tests do not read each other's rows. */
    private function channel(string $suffix): string
    {
        return self::PREFIX . $suffix;
    }

    // ── Round trip ────────────────────────────────────────────────────────────

    /**
     * An appended event comes back with its payload as an array.
     *
     * The whole point of the store: what the publisher handed the driver is what the consumer
     * receives, through a JSON column and two different database drivers. `payload` arriving as a
     * string would make every consumer parse it itself — and the Redis driver hands it over
     * already decoded, so half of them would not.
     */
    public function testAnAppendedEventComesBackDecoded(): void
    {
        // Arrange
        $channel = $this->channel('round-trip');

        // Act
        $this->store->append($channel, 'device.updated', ['id' => 7, 'name' => 'Sensor']);
        $rows = $this->store->fetchSince(0, [$channel]);

        // Assert
        $this->assertCount(1, $rows);
        $this->assertSame($channel, $rows[0]['channel']);
        $this->assertSame('device.updated', $rows[0]['event']);
        $this->assertIsArray($rows[0]['payload'], 'the consumer would have to decode it itself');
        /*
         * `assertEquals`, not `assertSame`, and the difference is a cross-backend fact.
         *
         * MySQL's native `JSON` column normalises the document it stores, and part of that is
         * sorting an object's members — by key length first, so `{"title":…,"url":…}` comes back
         * `{"url":…,"title":…}`. PostgreSQL's `json` keeps the text as written. So the *keys and
         * values* round-trip on both, and the **order does not**: a consumer may not depend on it,
         * and `assertSame` here would be asserting MySQL's sort order as if it were the contract.
         */
        $this->assertEquals(['id' => 7, 'name' => 'Sensor'], $rows[0]['payload']);
        $this->assertGreaterThan(0, $rows[0]['id']);
    }

    /**
     * Greek text and URLs survive the round trip unmangled.
     *
     * `append()` encodes with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, and both flags
     * earn their place here: without the first, a payload of Greek text is stored as `αβ`
     * and takes six bytes per character in a column with a length limit; without the second, every
     * URL in a payload grows backslashes that a consumer comparing strings will not expect.
     * Decoding reverses both, so the only way to see the difference is what is actually stored.
     */
    public function testTextAndUrlsSurviveTheRoundTrip(): void
    {
        // Arrange
        $channel = $this->channel('unicode');
        $payload = ['title' => 'Νέα συσκευή', 'url' => 'https://example.test/a/b'];

        // Act
        $this->store->append($channel, 'created', $payload);
        $rows = $this->store->fetchSince(0, [$channel]);

        $stored = $this->db->selectOne(
            "SELECT payload FROM broadcast_events WHERE channel = '"
            . $this->db->prepareInput($channel) . "'"
        );
        $raw = (string) ($stored['payload'] ?? '');

        // Assert — key order is MySQL's to choose; see the round-trip test above.
        $this->assertEquals($payload, $rows[0]['payload']);
        $this->assertStringContainsString('Νέα συσκευή', $raw, 'stored as \\u escapes');
        $this->assertStringContainsString('https://example.test/a/b', $raw, 'the slashes were escaped');
    }

    /**
     * A quote in the channel name or the payload does not break the insert.
     *
     * This store builds its SQL by string concatenation — deliberately, since the channel list is
     * variadic and the table name comes from configuration — so `prepareInput()` is the only thing
     * between a payload and the query. A channel name is not always a developer's constant: it
     * routinely carries a record's identifier, and an apostrophe in a name reaches here.
     */
    public function testAQuoteInTheChannelOrThePayloadIsHandled(): void
    {
        // Arrange
        $channel = $this->channel("o'brien");

        // Act
        $this->store->append($channel, 'renamed', ['was' => "d'Artagnan", 'now' => 'A--B']);
        $rows = $this->store->fetchSince(0, [$channel]);

        // Assert
        $this->assertCount(1, $rows, 'the insert or the select swallowed the row');
        $this->assertSame($channel, $rows[0]['channel']);
        $this->assertSame("d'Artagnan", $rows[0]['payload']['was']);
    }

    // ── Where a consumer resumes ──────────────────────────────────────────────

    /**
     * `latestId()` is where a fresh subscription starts, so it must be the id just written.
     *
     * A subscriber that starts from `latestId()` receives only what happens next. Reading it too
     * low replays history nobody asked for; too high and the first events after connecting are
     * lost — the silent one, because everything looks fine until somebody notices a change did not
     * arrive.
     */
    public function testLatestIdIsTheEventJustAppended(): void
    {
        // Arrange
        $channel = $this->channel('latest');

        // Act
        $this->store->append($channel, 'a', []);
        $first = $this->store->latestId();
        $this->store->append($channel, 'b', []);
        $second = $this->store->latestId();

        // Assert
        $this->assertGreaterThan(0, $first);
        $this->assertGreaterThan($first, $second, 'a second append did not move the head');

        $rows = $this->store->fetchSince($first, [$channel]);
        $this->assertCount(1, $rows, 'a subscription from the head replayed or lost an event');
        $this->assertSame('b', $rows[0]['event']);
        $this->assertSame($second, $rows[0]['id']);
    }

    /**
     * "Newer than" is strict.
     *
     * `id >= $lastId` would redeliver the last event on every poll. `DatabaseDriver` polls on a
     * cadence measured in fractions of a second, so that is not a duplicate — it is a consumer
     * receiving the same message until it disconnects.
     */
    public function testTheLastSeenEventIsNotRedelivered(): void
    {
        // Arrange
        $channel = $this->channel('strict');
        $this->store->append($channel, 'one', ['n' => 1]);
        $this->store->append($channel, 'two', ['n' => 2]);

        $all = $this->store->fetchSince(0, [$channel]);
        $this->assertCount(2, $all);

        // Act
        $after = $this->store->fetchSince($all[1]['id'], [$channel]);

        // Assert
        $this->assertSame([], $after, 'the last event was handed back a second time');
    }

    /**
     * Events arrive in the order they were appended.
     *
     * A consumer applies them in the order it receives them, so two updates to the same record
     * delivered backwards leave it showing the older value — and the poll that would correct it
     * never comes, because both events have been delivered.
     */
    public function testEventsArriveInTheOrderTheyWereAppended(): void
    {
        // Arrange
        $channel = $this->channel('order');

        // Act
        foreach (['first', 'second', 'third'] as $event) {
            $this->store->append($channel, $event, []);
        }
        $rows = $this->store->fetchSince(0, [$channel]);

        // Assert
        $this->assertSame(
            ['first', 'second', 'third'],
            array_column($rows, 'event')
        );
        $this->assertSame(
            array_column($rows, 'id'),
            $this->sorted(array_column($rows, 'id')),
            'the ids came back out of order'
        );
    }

    /** @param list<int> $ids @return list<int> */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    // ── Who the channel filter lets through ───────────────────────────────────

    /**
     * A poll only sees the channels it asked for.
     *
     * This is an authorization boundary, not a convenience: channel names are how broadcasting
     * separates one account's events from another's, and the subscriber has already been
     * authorised for exactly this list. Anything extra in the answer is somebody else's data.
     */
    public function testAPollOnlySeesTheChannelsItAskedFor(): void
    {
        // Arrange
        $mine   = $this->channel('mine');
        $theirs = $this->channel('theirs');
        $this->store->append($mine, 'ok', []);
        $this->store->append($theirs, 'secret', ['token' => 'nope']);

        // Act
        $rows = $this->store->fetchSince(0, [$mine]);

        // Assert
        $this->assertCount(1, $rows, "another channel's events were delivered");
        $this->assertSame($mine, $rows[0]['channel']);
    }

    /**
     * Several channels in one poll are all served, still in id order.
     *
     * One consumer subscribes to several channels and polls once for all of them, so the interleave
     * has to be right across the set rather than per channel.
     */
    public function testSeveralChannelsAreServedInOnePoll(): void
    {
        // Arrange
        $a = $this->channel('multi-a');
        $b = $this->channel('multi-b');
        $this->store->append($a, 'a1', []);
        $this->store->append($b, 'b1', []);
        $this->store->append($a, 'a2', []);

        // Act
        $rows = $this->store->fetchSince(0, [$a, $b]);

        // Assert
        $this->assertSame(['a1', 'b1', 'a2'], array_column($rows, 'event'));
    }

    /**
     * An empty subscription sees nothing — and that is a decision, not a shortcut.
     *
     * `channel IN ()` is not valid SQL, so the clause has to be handled before the query is built.
     * The two ways of doing that badly both fail open: omit the clause and the poll returns every
     * channel on the server, or treat empty as "no filter" and it does the same thing explicitly.
     * A consumer with no subscriptions is entitled to no events.
     */
    public function testAnEmptySubscriptionSeesNothing(): void
    {
        // Arrange
        $this->store->append($this->channel('empty'), 'not-for-you', ['secret' => true]);

        // Act
        $rows = $this->store->fetchSince(0, []);

        // Assert
        $this->assertSame([], $rows, 'an empty channel list was read as "every channel"');
    }

    // ── What a wrong row does ─────────────────────────────────────────────────

    /**
     * A payload that is not a JSON object comes back as an empty array, not null.
     *
     * The rows are appended by this class today, but the table is a plain one that a migration, a
     * fixture or another service can write to — and `json_decode('"text"')` is a perfectly valid
     * string. The envelope promises `payload` is an array, and a consumer doing `$payload['id']`
     * on a string gets a warning and a null instead of skipping the event.
     */
    public function testAPayloadThatIsNotAnObjectBecomesAnEmptyArray(): void
    {
        // Arrange
        $channel = $this->channel('scalar');
        $this->db->query(
            'INSERT INTO broadcast_events (channel, event, payload, created_at) VALUES ('
            . "'" . $this->db->prepareInput($channel) . "', 'odd', '\"a bare string\"', NOW())"
        );

        // Act
        $rows = $this->store->fetchSince(0, [$channel]);

        // Assert
        $this->assertCount(1, $rows, 'an unexpected payload lost the event entirely');
        $this->assertSame([], $rows[0]['payload']);
    }

    /**
     * A store pointed at a table that is not there fails loudly, on the first call.
     *
     * The table name is configurable, so a typo in configuration reaches here — and the tempting
     * kindness is to catch the error and answer "no events", because the caller is a poll loop and
     * an exception thrown out of a worker is noisy. It is the wrong kindness. `append()` and
     * `fetchSince()` share the table: if it is missing, every published event is being dropped as
     * well as never delivered, and a stream that quietly delivers nothing looks exactly like a
     * system where nothing is happening. `DatabaseDriver::subscribe()` calls `latestId()` before
     * its loop begins, so the failure lands at the moment the subscription is set up rather than
     * twice a second thereafter.
     *
     * Asserted rather than assumed, because the two backends raise differently — a
     * `mysqli_sql_exception` on one and the framework's own wrapper on the other — and a caller
     * that wanted to handle this would need to know it is not one type.
     */
    public function testAMissingTableFailsLoudly(): void
    {
        // Arrange
        $store = new DatabaseEventStore($this->db, 'broadcast_events_no_such_table');

        // Act & Assert
        try {
            $store->latestId();
            $this->fail('a missing table answered instead of failing');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString(
                'broadcast_events_no_such_table',
                $exception->getMessage(),
                'the error does not name the table, which is the one thing to fix'
            );
        }
    }
}
