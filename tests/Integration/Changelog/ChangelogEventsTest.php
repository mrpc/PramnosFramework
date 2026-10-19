<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Changelog;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Changelog\ChangelogReader;
use Pramnos\Changelog\ChangelogWriter;
use Pramnos\Database\Database;
use Pramnos\Database\WriteSpool;
use Pramnos\Framework\Factory;

/** A model whose events are recorded for somebody else, for the attribution tests. */
class AttributedEventProbe extends \Pramnos\Application\Model
{
    protected $modelname = 'events-probe-device';

    protected $_primaryKey = 'probeid';

    public $probeid = 77;

    /** No database lookup: the probe exists only to record events. */
    public function __construct()
    {
    }
}

/** A model whose tenant cannot be worked out. */
class UnscopableEventProbe extends AttributedEventProbe
{
    /** The tenant lookup fails. */
    protected function changeScope(): ?string
    {
        throw new \RuntimeException('the tenant is away');
    }
}

/** The writer as a web server runs it. */
class WebChangelogWriter extends ChangelogWriter
{
    /** Not a console. */
    protected static function console(): bool
    {
        return false;
    }
}

/**
 * Events in `changelog_events` written without a model, attributed to whoever did them, and read
 * back a page at a time for a listing. On MySQL (MariaDB with --mariadb) here, on PostgreSQL in
 * {@see ChangelogEventsPostgreSQLTest}.
 */
#[CoversClass(ChangelogWriter::class)]
#[CoversClass(ChangelogReader::class)]
#[CoversClass(\Pramnos\Application\Model::class)]
class ChangelogEventsTest extends TestCase
{
    protected Database $db;

    private ?Database $previous = null;

    private const ENTITY = 'events-probe';

    /** The connection this run is on — MySQL, or MariaDB with --mariadb; null skips. */
    protected function connection(): ?Database
    {
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $reference = &Database::getInstance();
        $reference = null;
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        return $db->type === 'postgresql' ? null : $db;
    }

    protected function setUp(): void
    {
        $db = $this->connection();
        if ($db === null || !$db->connected) {
            $this->markTestSkipped('This database is not reachable.');
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $instance       = &Database::getInstance();
        $instance       = $db;
        $this->db       = $db;
        \Pramnos\Framework\Testing\Schema::ensure([
            \Pramnos\Framework\Migrations\Changelog\CreateChangelogTables::class,
            \Pramnos\Framework\Migrations\Changelog\AddScopeToChangelogEvents::class,
        ], $db);
        WriteSpool::reset();
        WriteSpool::setDriver(WriteSpool::DRIVER_SYNC);
        $this->clear();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->clear();
        }
        WriteSpool::reset();
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
            $instance  = &Database::getInstance();
            $instance  = $this->previous;
        }
    }

    /** Remove what these tests wrote. */
    private function clear(): void
    {
        $this->db->queryBuilder()->table(ChangelogWriter::EVENTS_TABLE)
            ->where(static function ($q): void {
                $q->where('entity', 'like', self::ENTITY . '%')->orWhere('entity', 'events-probe-device');
            })->delete();
        $this->db->queryBuilder()->table(ChangelogWriter::TABLE)->where('entity', 'like', self::ENTITY . '%')->delete();
    }

    /** The events of an entity, oldest first, with their moment as a Unix time. @return list<array<string, mixed>> */
    private function events(string $entity): array
    {
        return $this->db->queryBuilder()->table(ChangelogWriter::EVENTS_TABLE)
            ->select(['*', \Pramnos\Database\Instant::unix($this->db, 'created_at', 'at')])
            ->where('entity', $entity)->orderBy('eventid')->get()->fetchAll();
    }

    /**
     * An event is written for any record, with no model: by whom, when, in which tenant and
     * from where, each as given — `by: 0` is nobody, and with nobody signed in and nothing
     * given there is nobody either; the source is worked out when not given.
     */
    public function testAnEventIsWrittenWithoutAModel(): void
    {
        // Arrange
        $at = 1760000000;

        // Act
        ChangelogWriter::event(self::ENTITY, 42, 'user.login', ['ip' => '10.0.0.1'], 3, 'Signed in', by: 7, scope: 'org:1', at: $at, source: 'api');
        ChangelogWriter::event(self::ENTITY, 42, 'user.expired', by: 0);
        ChangelogWriter::event(self::ENTITY, 42, 'user.seen');
        $rows = $this->events(self::ENTITY);

        // Assert
        $this->assertCount(3, $rows);
        $this->assertSame(['42', 'user.login', 3, 'Signed in', 7, 'org:1', 'api', $at], [
            (string) $rows[0]['itemid'], $rows[0]['event'], (int) $rows[0]['logtype'], $rows[0]['description'],
            (int) $rows[0]['userid'], $rows[0]['scope'], $rows[0]['source'], (int) $rows[0]['at'],
        ]);
        $this->assertSame('10.0.0.1', json_decode((string) $rows[0]['details'], true)['ip']);
        $this->assertNull($rows[1]['userid'], 'by 0 is nobody');
        $this->assertNull($rows[1]['details'], 'no details is no details, not a JSON null');
        $this->assertNull($rows[2]['userid'], 'nobody signed in, nothing given');
        $this->assertSame('cli', $rows[2]['source'], 'worked out: this is a console');
        $this->assertNull(ChangelogWriter::currentUserId());
    }

    /**
     * A model records an event for somebody else with `logEventAs()` — a console command acting
     * for a user — and `logEvent()` goes on recording whoever is signed in.
     */
    public function testAModelRecordsForSomebodyElse(): void
    {
        // Arrange
        $model = new AttributedEventProbe();

        // Act
        $model->logEventAs(9, 'device.replaced', ['by' => 43]);
        $model->logEventAs(0, 'device.expired');
        $model->logEvent('device.seen');
        $rows = $this->events('events-probe-device');

        // Assert
        $this->assertSame([9, null, null], array_map(static fn (array $r): ?int => $r['userid'] === null ? null : (int) $r['userid'], $rows));
        $this->assertSame(['77', 'device.replaced'], [(string) $rows[0]['itemid'], $rows[0]['event']]);
    }

    /**
     * Recording an event never fails what it describes: a row the database refuses, or a model
     * whose tenant cannot be worked out, is logged and written nowhere — and no exception
     * reaches the caller.
     */
    public function testAnEventThatCannotBeWrittenIsLoggedNotThrown(): void
    {
        // Act
        ChangelogWriter::event(str_repeat('e', 300), 1, 'too.long');
        (new UnscopableEventProbe())->logEventAs(3, 'device.lost');

        // Assert
        $this->assertSame([], $this->events(str_repeat('e', 300)));
        $this->assertSame([], $this->events('events-probe-device'));
    }

    /**
     * Who and where are worked out when not given: the user the request is signed in as, and
     * a browser or an API client by the address — and nobody, from a console, when nobody is.
     */
    public function testWhoAndWhereAreWorkedOut(): void
    {
        // Arrange
        $user = new \Pramnos\User\User(0);
        $user->userid = 4242;
        $saved = $_SERVER;

        // Act
        \Pramnos\Http\RequestIdentity::seal($user, 'test');
        try {
            $who = ChangelogWriter::currentUserId();
        } finally {
            \Pramnos\Http\RequestIdentity::reset();
        }
        $_SERVER['REQUEST_URI'] = '/api/v1/devices';
        $api = WebChangelogWriter::currentSource();
        $_SERVER['REQUEST_URI'] = '/admin/devices';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $web = WebChangelogWriter::currentSource();
        $_SERVER = $saved;

        // Assert
        $this->assertSame(4242, $who);
        $this->assertSame(['api', 'web'], [$api, $web]);
        $this->assertSame('cli', ChangelogWriter::currentSource());
    }

    /**
     * A listing reads a page with the total the pager needs, filtered by record, event,
     * logtype, who, from where and when, newest first or oldest first; a tenant's events from
     * their own table; and refuses a filter it does not know rather than listing everything.
     */
    public function testAListingPagesFiltersAndCounts(): void
    {
        // Arrange — 12 events on two records, an hour apart, by two users
        $start = 1760000000;
        for ($i = 0; $i < 12; $i++) {
            ChangelogWriter::event(self::ENTITY, $i % 2 === 0 ? 'a' : 'b', $i % 3 === 0 ? 'thing.made' : 'thing.seen', ['n' => $i], $i % 4,
                by: $i < 6 ? 5 : 6, scope: $i < 3 ? 'org:7' : null, at: $start + $i * 3600, source: $i === 11 ? 'api' : 'web');
        }
        $this->db->queryBuilder()->table(ChangelogWriter::TABLE)->insert([
            'entity' => self::ENTITY, 'itemid' => 'a', 'op' => 'update', 'changes' => null, 'userid' => 5, 'source' => 'web',
            'created_at' => \Pramnos\Database\Instant::local($start),
        ]);

        // Act
        $page   = ChangelogReader::listing(['entity' => self::ENTITY], 5, 0);
        $second = ChangelogReader::listing(['entity' => self::ENTITY], 5, 5);
        $a      = ChangelogReader::listing(['entity' => self::ENTITY, 'itemid' => 'a']);
        $made   = ChangelogReader::listing(['entity' => self::ENTITY, 'event' => ['thing.made'], 'logtype' => [0, 3]]);
        $user6  = ChangelogReader::listing(['entity' => self::ENTITY, 'userid' => 6, 'source' => 'api']);
        $window = ChangelogReader::listing(['entity' => self::ENTITY, 'from' => $start + 3600, 'to' => $start + 3 * 3600], order: 'asc');
        $both   = ChangelogReader::listing(['entity' => self::ENTITY, 'origins' => ['events', 'feed']]);
        $none   = ChangelogReader::listing(['entity' => self::ENTITY, 'origins' => ['nothing']]);
        $tenant = ChangelogReader::listing(['scope' => 'org:7', 'entity' => self::ENTITY], 2);
        $refused = null;
        try {
            ChangelogReader::listing(['entiti' => self::ENTITY]);
        } catch (\InvalidArgumentException $e) {
            $refused = $e->getMessage();
        }

        // Assert
        $this->assertSame(12, $page['total']);
        $this->assertSame([11, 10, 9, 8, 7], array_map(static fn (array $r): int => (int) $r['details']['n'], $page['rows']), 'newest first, details as arrays');
        $this->assertSame([6, 5, 4, 3, 2], array_map(static fn (array $r): int => (int) $r['details']['n'], $second['rows']));
        $this->assertSame(6, $a['total']);
        // thing.made is 0, 3, 6, 9, of logtypes 0, 3, 2, 1: both filters, together.
        $this->assertSame([0, 3], array_reverse(array_map(static fn (array $r): int => (int) $r['details']['n'], $made['rows'])));
        $this->assertSame(1, $user6['total'], 'who and from where');
        $this->assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['details']['n'], $window['rows']), 'from inclusive, to exclusive, oldest first');
        $this->assertSame(13, $both['total'], 'with the feed when asked');
        $this->assertSame(['rows' => [], 'total' => 0], $none);
        $this->assertSame(3, $tenant['total']);
        $this->assertCount(2, $tenant['rows']);
        $this->assertStringContainsString('does not filter by entiti', (string) $refused);
    }
}
