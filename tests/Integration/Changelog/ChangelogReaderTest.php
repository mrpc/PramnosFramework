<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Changelog;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Settings;
use Pramnos\Changelog\ChangelogReader;
use Pramnos\Changelog\ChangelogWriter;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Reading the audit trail back — the other 0% file in the changelog feature.
 *
 * `ChangelogReader` is what a screen calls to answer "what happened to this record". It reads a
 * view over two tables — the automatic per-save feed and the events an application writes
 * deliberately — and the interesting decision is which of the two it shows by default.
 *
 * **Events only.** The automatic feed is one row per save and would bury the semantic rows: the
 * reference application writes `AND logtype != 90` by hand in every listing that shows a person
 * what happened, and forgetting it once is exactly what this default exists to prevent. A
 * diagnostic caller asks for both.
 *
 * Both backends, and here that is not a formality: the view is a `UNION ALL` over two tables, and
 * the JSON columns come back as strings from MySQL while PostgreSQL may hand `jsonb` back either
 * way depending on the driver — so a caller that trusted one shape would break on the other
 * backend, **and only there**. {@see ChangelogReaderPostgreSQLTest} is what makes that a claim
 * rather than a hope.
 */
#[CoversClass(ChangelogReader::class)]
class ChangelogReaderTest extends BaseTestCase
{
    private $db;

    private const ENTITY = 'reader-probe-device';

    private const ITEM = '4242';

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

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Changelog\CreateChangelogTables::class,
        ], $this->db);

        $this->clearProbe();
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

    // ── Which origin a timeline shows ─────────────────────────────────────────

    /**
     * By default a timeline shows the application's events and not the per-save feed.
     *
     * One save produces one feed row, so a busy record's timeline is hundreds of "updated"
     * entries with the two lines somebody actually wanted buried among them. The reference
     * application filtered that out by hand in every listing; this is the same decision made
     * once, in the place that cannot be forgotten.
     */
    public function testATimelineShowsEventsAndNotTheFeed(): void
    {
        // Arrange
        $this->seedFeed('updated', 300);
        $this->seedFeed('updated', 200);
        $this->seedEvent('activated', 'The device was activated');

        // Act
        $rows = ChangelogReader::history(self::ENTITY, self::ITEM);

        // Assert
        $this->assertCount(1, $rows, 'the per-save feed reached a user-facing timeline');
        $this->assertSame('events', $rows[0]['origin']);
        $this->assertSame('activated', $rows[0]['event']);
    }

    /** A diagnostic caller asks for both, and gets both. */
    public function testADiagnosticCallerCanAskForBoth(): void
    {
        // Arrange
        $this->seedFeed('updated', 300);
        $this->seedEvent('activated', 'The device was activated');

        // Act
        $rows = ChangelogReader::history(self::ENTITY, self::ITEM, ['events', 'feed']);

        // Assert
        $this->assertCount(2, $rows);
        $this->assertSame(
            ['events', 'feed'],
            array_values(array_unique(array_map(
                static fn (array $row): string => (string) $row['origin'],
                $rows
            ))),
            'newest first, and both origins present'
        );
    }

    /** Asking for only the feed gives only the feed. */
    public function testTheFeedCanBeAskedForAlone(): void
    {
        // Arrange
        $this->seedFeed('deleted', 100);
        $this->seedEvent('activated', 'Not this one');

        // Act
        $rows = ChangelogReader::history(self::ENTITY, self::ITEM, ['feed']);

        // Assert
        $this->assertCount(1, $rows);
        $this->assertSame('feed', $rows[0]['origin']);
        $this->assertSame('deleted', $rows[0]['op']);
    }

    /**
     * An origin list that names nothing returns nothing.
     *
     * The important direction. `array_intersect` against the two known names leaves an empty
     * list, and an empty `whereIn` would either error or match everything — so a caller passing a
     * typo, or a filter built from user input that came back empty, would be handed the whole
     * trail instead of none of it.
     */
    public function testAnOriginListThatNamesNothingReturnsNothing(): void
    {
        // Arrange
        $this->seedFeed('updated', 100);
        $this->seedEvent('activated', 'Something');

        // Act & Assert
        foreach ([[], ['nonsense'], ['FEED'], ['', null]] as $origins) {
            $this->assertSame(
                [],
                ChangelogReader::history(self::ENTITY, self::ITEM, (array) $origins),
                'an unrecognised origin list returned rows: ' . json_encode($origins)
            );
        }
    }

    /** Newest first, because a timeline is read from the top. */
    public function testTheNewestEntryComesFirst(): void
    {
        // Arrange
        $this->seedEvent('first', 'Oldest', 3600);
        $this->seedEvent('second', 'Middle', 1800);
        $this->seedEvent('third', 'Newest', 60);

        // Act
        $rows = ChangelogReader::history(self::ENTITY, self::ITEM);

        // Assert
        $this->assertSame(['third', 'second', 'first'], array_column($rows, 'event'));
    }

    /** The limit is honoured, so a record with years of history does not load all of it. */
    public function testTheLimitIsHonoured(): void
    {
        // Arrange
        for ($i = 0; $i < 5; $i++) {
            $this->seedEvent('event' . $i, 'Entry ' . $i, 60 * $i);
        }

        // Act
        $rows = ChangelogReader::history(self::ENTITY, self::ITEM, ['events'], 2);

        // Assert
        $this->assertCount(2, $rows);
        $this->assertSame('event0', $rows[0]['event'], 'the limit dropped the newest rather than the oldest');
    }

    /** A record nothing happened to has an empty history, not an error. */
    public function testARecordWithNoHistoryIsEmpty(): void
    {
        // Act & Assert
        $this->assertSame([], ChangelogReader::history(self::ENTITY, 'no-such-item'));
    }

    // ── The JSON columns, which is why both lanes exist ───────────────────────

    /**
     * The JSON columns come back as arrays, on either engine.
     *
     * MySQL hands them back as strings; PostgreSQL may hand `jsonb` back either way depending on
     * the driver. So a caller that trusted one shape — `$row['changes']['name']` — would work in
     * development and raise in production, or the reverse, and only ever on one backend. Decoding
     * in the reader is what makes the two engines answer the same question the same way.
     */
    public function testTheJsonColumnsComeBackAsArrays(): void
    {
        // Arrange
        $this->seedFeed('updated', 100, ['name' => ['old' => 'a', 'new' => 'b']]);
        $this->seedEvent('activated', 'With details', 50, ['reason' => 'operator']);

        // Act
        $rows = ChangelogReader::history(self::ENTITY, self::ITEM, ['events', 'feed']);

        // Assert
        $byOrigin = [];
        foreach ($rows as $row) {
            $byOrigin[(string) $row['origin']] = $row;
        }

        $this->assertIsArray(
            $byOrigin['feed']['changes'] ?? null,
            'changes came back as a string, so $row["changes"]["name"] would raise'
        );
        $this->assertSame('b', $byOrigin['feed']['changes']['name']['new'] ?? null);

        $this->assertIsArray($byOrigin['events']['details'] ?? null);
        $this->assertSame('operator', $byOrigin['events']['details']['reason'] ?? null);
    }

    // ── The trace ─────────────────────────────────────────────────────────────

    /**
     * A row with no trace answers null.
     *
     * Most rows have none: traces are kept days and the rows they describe are kept weeks, and
     * capturing one is opt-in per model in the first place. Returning null rather than an empty
     * array is what lets a screen say "no trace captured" instead of drawing an empty panel.
     */
    public function testARowWithNoTraceAnswersNull(): void
    {
        // Arrange
        $this->seedFeed('updated', 100);

        // Act & Assert
        $this->assertNull(
            ChangelogReader::trace(self::ENTITY, self::ITEM, $this->stamp(100))
        );
    }

    /**
     * And a row that has one gets it back, decoded.
     *
     * Deliberately a separate query rather than a join into `history()`: a listing would pay for
     * a column no listing shows, which is what the reference application's compatibility view
     * does. This is for the one row somebody is investigating.
     */
    public function testARowWithATraceGetsItBack(): void
    {
        // Arrange
        $at = $this->stamp(100);
        $this->db->queryBuilder()->table('pramnos.changelog_trace')->insert([
            'entity'      => self::ENTITY,
            'itemid'      => self::ITEM,
            'created_at'  => $at,
            'trace'       => '#0 somewhere',
            'request_uri' => '/admin/devices/save',
            'user_agent'  => 'phpunit',
            'ip_address'  => '203.0.113.7',
            'context'     => json_encode(['extra' => 'context']),
        ]);

        // Act
        $trace = ChangelogReader::trace(self::ENTITY, self::ITEM, $at);

        // Assert
        $this->assertNotNull($trace, 'the trace could not be joined back on its natural key');
        $this->assertSame('#0 somewhere', $trace['trace']);
        $this->assertSame('/admin/devices/save', $trace['request_uri']);
        $this->assertIsArray($trace['context'], 'the context came back as a string');
        $this->assertSame('context', $trace['context']['extra'] ?? null);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** A timestamp $secondsAgo before now, in the form the columns hold. */
    private function stamp(int $secondsAgo): string
    {
        return date('Y-m-d H:i:s', time() - $secondsAgo);
    }

    /** One row in the automatic feed. */
    private function seedFeed(string $op, int $secondsAgo, ?array $changes = null): void
    {
        $this->db->queryBuilder()->table(ChangelogWriter::TABLE)->insert([
            'entity'     => self::ENTITY,
            'itemid'     => self::ITEM,
            'op'         => $op,
            'changes'    => $changes === null ? null : json_encode($changes),
            'userid'     => 7,
            'source'     => 'web',
            'created_at' => $this->stamp($secondsAgo),
        ]);
    }

    /** One row the application wrote deliberately. */
    private function seedEvent(
        string $event,
        string $description,
        int $secondsAgo = 100,
        ?array $details = null
    ): void {
        $this->db->queryBuilder()->table(ChangelogWriter::EVENTS_TABLE)->insert([
            'entity'      => self::ENTITY,
            'itemid'      => self::ITEM,
            'event'       => $event,
            'logtype'     => 0,
            'details'     => $details === null ? null : json_encode($details),
            'description' => $description,
            'userid'      => 7,
            'source'      => 'web',
            'created_at'  => $this->stamp($secondsAgo),
        ]);
    }

    private function clearProbe(): void
    {
        foreach (
            [
                ChangelogWriter::TABLE,
                ChangelogWriter::EVENTS_TABLE,
                ChangelogWriter::TRACE_TABLE,
            ] as $table
        ) {
            try {
                $this->db->queryBuilder()->table($table)
                    ->where('entity', self::ENTITY)->delete();
            } catch (\Throwable $exception) {
                // No table on a lane mid-migration; nothing to clear.
            }
        }
    }
}
