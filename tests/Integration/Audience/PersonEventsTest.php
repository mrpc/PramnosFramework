<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Audience\Filter;
use Pramnos\Audience\PersonEvent;
use Pramnos\Audience\PersonEvents;
use Pramnos\Audience\RecordPersonEvent;
use Pramnos\Audience\Segments;
use Pramnos\Console\Commands\AudiencePruneEvents;
use Pramnos\Email\Tracking;
use Pramnos\Event\Event;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * What people did, on MySQL, prefixed MySQL and PostgreSQL: recorded from a dispatched event
 * class, a page, an email open or click, or the past; summed for "ever", "first", "last"; logged
 * for "N times in the last M days"; pruned past the retention.
 *
 * anna paid three times — 40, 20 and 2 days ago; bob once, 100 days ago; cara never.
 */
#[CoversClass(PersonEvents::class)]
#[CoversClass(Segments::class)]
#[CoversClass(RecordPersonEvent::class)]
#[CoversClass(Filter::class)]
#[CoversClass(AudiencePruneEvents::class)]
#[CoversClass(Tracking::class)]
#[CoversClass(Event::class)]
class PersonEventsTest extends TestCase
{
    use AudienceProbe {
        tearDown as private probeTearDown;
    }
    use AdminScreen;

    /** Switch email tracking back off and the request back, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        unset(Application::getInstance()->applicationInfo['email'], Application::getInstance()->applicationInfo['audience']);
        $_SERVER['REQUEST_URI'] = '/';
        $this->clearRequest();
        $this->probeTearDown();
    }

    /** The payments: anna's recorded live, bob's from the past. */
    private function payments(): void
    {
        $day = 86400;
        foreach ([40, 2, 20] as $ago) {
            PersonEvents::record($this->ids['anna'], 'payment.made', ['amount' => $ago], time() - $ago * $day);
        }
        PersonEvents::backfill('payment.made', [[$this->ids['bob'], time() - 100 * $day]]);
    }

    /**
     * The change log's rows of person events, oldest first, filtered by column.
     *
     * @param  array<string, scalar> $where
     * @return list<array<string, mixed>>
     */
    private function logged(array $where = []): array
    {
        $query = $this->db->queryBuilder()->table(PersonEvents::LOG)->where('entity', 'user')->orderBy('created_at');
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return $query->getAll();
    }

    /** A logged row's properties. @return array<string, mixed>|null */
    private static function properties(array $row): ?array
    {
        return is_string($row['details']) ? json_decode($row['details'], true) : $row['details'];
    }

    /** The people a condition about payments selects. @return list<string> */
    private function who(array $condition): array
    {
        return $this->names(Filter::fromArray(['conditions' => [['event' => 'payment.made'] + $condition]])->ids());
    }

    /**
     * Each occurrence moves the summary — how many, the earliest first, the latest last, whatever
     * order they arrive in — and leaves a row in the log with its properties.
     */
    #[DataProvider('databases')]
    public function testOccurrencesAreSummedAndLogged(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);

        // Act
        $this->payments();
        $anna = PersonEvents::of($this->ids['anna']);
        $log  = $this->logged(['itemid' => (string) $this->ids['anna']]);

        // Assert
        $this->assertSame(1, count($anna));
        $this->assertSame(['payment.made', 'payment.made', 3], [$anna[0]['event'], $anna[0]['label'], $anna[0]['count']]);
        $this->assertEqualsWithDelta(time() - 40 * 86400, $anna[0]['first'], 5);
        $this->assertEqualsWithDelta(time() - 2 * 86400, $anna[0]['last'], 5);
        $this->assertCount(3, $log);
        $this->assertSame(['amount' => 40], self::properties($log[0]));
        $this->assertSame([(string) $this->ids['anna'], 'payment.made', $this->ids['anna']], [$log[0]['itemid'], $log[0]['event'], (int) $log[0]['userid']]);
        $this->assertSame([], PersonEvents::of($this->ids['cara']));
    }

    /**
     * Every event operator selects the people it names: from the summary for all time, first and
     * last; from the log for a window of days.
     */
    #[DataProvider('databases')]
    public function testEveryEventOperatorSelectsThePeopleItNames(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->payments();
        $daysAgo = static fn (int $n): string => date('Y-m-d', time() - $n * 86400);

        // Act
        $answers = [
            'ever'          => $this->who(['op' => 'ever']),
            'never'         => $this->who(['op' => 'never']),
            'at least 2'    => $this->who(['op' => 'at_least', 'value' => 2]),
            'at least 0'    => $this->who(['op' => 'at_least', 'value' => 0]),
            'at most 1'     => $this->who(['op' => 'at_most', 'value' => 1]),
            '2 in 30 days'  => $this->who(['op' => 'at_least', 'value' => 2, 'days' => 30]),
            '3 in 30 days'  => $this->who(['op' => 'at_least', 'value' => 3, 'days' => 30]),
            '0 in 30 days'  => $this->who(['op' => 'at_least', 'value' => 0, 'days' => 30]),
            '≤1 in 30 days' => $this->who(['op' => 'at_most', 'value' => 1, 'days' => 30]),
            'last 7 days'   => $this->who(['op' => 'last_within_days', 'value' => 7]),
            'not 7 days'    => $this->who(['op' => 'not_within_days', 'value' => 7]),
            'first 50 days' => $this->who(['op' => 'first_within_days', 'value' => 50]),
            'first before'  => $this->who(['op' => 'first_before', 'value' => $daysAgo(30)]),
            'first after'   => $this->who(['op' => 'first_after', 'value' => $daysAgo(60)]),
            'last before'   => $this->who(['op' => 'last_before', 'value' => $daysAgo(10)]),
            'last after'    => $this->who(['op' => 'last_after', 'value' => $daysAgo(10)]),
        ];

        // Assert
        $this->assertSame([
            'ever'          => ['anna', 'bob'],
            'never'         => ['cara'],
            'at least 2'    => ['anna'],
            'at least 0'    => ['anna', 'bob', 'cara'],
            'at most 1'     => ['bob', 'cara'],
            '2 in 30 days'  => ['anna'],
            '3 in 30 days'  => [],
            '0 in 30 days'  => ['anna', 'bob', 'cara'],
            '≤1 in 30 days' => ['bob', 'cara'],
            'last 7 days'   => ['anna'],
            'not 7 days'    => ['bob', 'cara'],
            'first 50 days' => ['anna'],
            'first before'  => ['anna', 'bob'],
            'first after'   => ['anna'],
            'last before'   => ['bob'],
            'last after'    => ['anna'],
        ], $answers);
    }

    /** explain() says what the person did: how many, first, last, and how many in the window. */
    #[DataProvider('databases')]
    public function testExplainSaysWhatThePersonDid(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        PersonEvents::define('payment.made', 'Paid', ['amount' => 'Amount'], 'Payments');
        $this->payments();
        $filter = Filter::fromArray(['conditions' => [['event' => 'payment.made', 'op' => 'at_least', 'value' => 3, 'days' => 30]]]);

        // Act
        $anna = $filter->explain($this->ids['anna'])['conditions'][0];
        $cara = $filter->explain($this->ids['cara'])['conditions'][0];

        // Assert
        $this->assertFalse($anna['ok'], 'two in the window, not three');
        $this->assertSame('Paid', $anna['label']);
        $this->assertSame(3, $anna['actual']['count']);
        $this->assertSame(2, $anna['actual']['inWindow']);
        $this->assertSame(['count' => 0, 'first' => null, 'last' => null, 'inWindow' => 0], $cara['actual']);
    }

    /**
     * Dispatching an event class that is a PersonEvent records it with no other call — here, with
     * no queue to put it in, at once rather than lost — and one that names nobody records nothing.
     */
    #[DataProvider('databases')]
    public function testADispatchedPersonEventIsRecorded(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $paid = new class ($this->ids['cara']) implements PersonEvent {
            public const EVENT = 'payment.made';

            /** @param ?int $who The person, or nobody */
            public function __construct(private readonly ?int $who)
            {
            }

            /** The person. */
            public function personId(): ?int
            {
                return $this->who;
            }

            /** What is kept. */
            public function properties(): array
            {
                return ['amount' => 49];
            }
        };
        $heard = [];
        Event::listen('payment.made', function () use (&$heard): void {
            $heard[] = 'listener';
        });

        // Act
        Event::dispatch($paid);
        Event::dispatch(new ($paid::class)(null));

        // Assert
        $this->assertSame(['listener', 'listener'], $heard, 'the listeners run as before');
        $this->assertSame(1, PersonEvents::of($this->ids['cara'])[0]['count']);
        $this->assertSame(['amount' => 49], self::properties($this->logged(['itemid' => (string) $this->ids['cara']])[0]));
        $this->assertSame(1, count($this->logged()));
    }

    /**
     * The worker's listener records the occurrence; asking for the segments to be worked out again
     * is best-effort and does not fail the task.
     */
    #[DataProvider('databases')]
    public function testTheQueuedListenerRecords(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);

        // Act
        $done = (new RecordPersonEvent())->handle($this->ids['bob'], 'login', ['via' => 'passkey'], time() - 60);
        PersonEvents::record(0, 'login');
        PersonEvents::recordLater(0, 'login');

        // Assert
        $this->assertTrue($done);
        $this->assertSame(1, PersonEvents::of($this->ids['bob'])[0]['count']);
        $this->assertSame(1, count($this->logged()), 'nobody records nothing');
    }

    /**
     * The past comes from the application's own table — Unix seconds or a datetime column, with
     * properties from each row — and forget() clears an event so it can be backfilled again
     * without counting twice.
     */
    #[DataProvider('databases')]
    public function testThePastIsBackfilledFromATable(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $joined = static fn (): \Pramnos\Database\QueryBuilder => \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('audtest_profiles');
        $backfill = fn (): int => PersonEvents::backfillFrom('profile.made', $joined(), 'userid', 'joined', 'datetime',
            static fn (array $row): array => ['pillar' => (int) $row['pillar']], 1);

        // Act
        $first  = $backfill();
        $once   = PersonEvents::of($this->ids['bob']);
        PersonEvents::forget('profile.made');
        $again  = $backfill();
        $twice  = PersonEvents::of($this->ids['bob']);
        $unix   = PersonEvents::backfillFrom('signed.up', \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('users')->where('usertype', '<', 97), 'userid', 'regdate');
        $skipped = PersonEvents::backfill('noise', [[0, time()], [$this->ids['anna'], 0]]);

        // Assert
        $this->assertSame([2, 2], [$first, $again], 'bob and cara, one row a page');
        $this->assertSame($once, $twice, 'forgotten and backfilled again: counted once');
        $this->assertSame(1, $once[0]['count']);
        $this->assertSame(strtotime('2026-02-01 10:00:00'), $once[0]['first']);
        $this->assertSame(['pillar' => 3], self::properties($this->logged(['event' => 'profile.made', 'itemid' => (string) $this->ids['bob']])[0]));
        $this->assertSame(3, $unix);
        $this->assertSame(0, $skipped, 'no person or no time is not an occurrence');
    }

    /**
     * The daily prune deletes what has a retention of its own — rule run-log rows, and segment stays
     * that ended long ago — and keeps the open stays; at 0 a history is kept whole. Each person's
     * occurrences are the change log's to keep, and the summary is kept for good.
     */
    #[DataProvider('databases')]
    public function testThePruneKeepsWhatIsStillAnswered(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->payments();
        $segment = Segments::create('Paid', ['conditions' => [['event' => 'payment.made', 'op' => 'ever']]]);
        $old     = time() - 400 * 86400;
        $members = $this->db->queryBuilder()->table('segment_members');
        $members->insert(['segmentid' => $segment, 'userid' => $this->ids['anna'], 'entered_at' => $old - 86400, 'left_at' => $old]);
        $this->db->queryBuilder()->table('segment_members')->insert(['segmentid' => $segment, 'userid' => $this->ids['bob'], 'entered_at' => $old, 'left_at' => null]);
        $this->db->queryBuilder()->table('segment_members')->insert(['segmentid' => $segment, 'userid' => $this->ids['cara'], 'entered_at' => $old, 'left_at' => time() - 86400]);
        $tester = new CommandTester(new AudiencePruneEvents());

        // Act
        $code = $tester->execute(['--days' => '30']);
        $said = $tester->getDisplay();
        $left = array_map('intval', array_column($this->db->queryBuilder()->table('segment_members')->getAll(), 'userid'));
        Application::getInstance()->applicationInfo['audience'] = ['segment_history_days' => 0, 'rule_log_days' => 0];
        $tester->execute([]);
        $kept = $tester->getDisplay();
        unset(Application::getInstance()->applicationInfo['audience']);

        // Assert
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Rule log: 0 rows', $said);
        $this->assertStringContainsString('Segment history: 1 stays', $said, "anna's stay ended over a year ago");
        sort($left);
        $this->assertSame([$this->ids['bob'], $this->ids['cara']], $left, 'the open stay and the recent one are kept');
        $this->assertStringContainsString('Nothing to prune here', $kept);
        $this->assertSame(['anna', 'bob'], $this->who(['op' => 'ever']), 'the summary is kept');
        $this->assertCount(4, $this->logged(), "anna's three and bob's: the change log's to keep");
    }

    /**
     * A named page a signed-in person opens is that event — matched without its query string and
     * ignoring case — and the page's render records it; another page, or nobody signed in, is not.
     */
    #[DataProvider('databases')]
    public function testANamedPageIsAnEvent(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $before = PersonEvents::hasPages();
        PersonEvents::page('premium.viewed', 'Saw the Premium page', '/el/premium*');

        // Act
        $hit   = PersonEvents::visited('/EL/Premium/plans?from=mail', $this->ids['anna']);
        // What every page's render asks: this request, for whoever is signed in.
        $this->signIn(1, $this->ids['bob']);
        $_SERVER['REQUEST_URI']    = '/el/premium';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $posted = PersonEvents::visitedNow();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $now = PersonEvents::visitedNow();
        $this->clearRequest();
        $miss  = PersonEvents::visited('/en/home', $this->ids['anna']);
        $anon  = PersonEvents::visited('/el/premium', null);
        $label = PersonEvents::label('premium.viewed');

        // Assert
        $this->assertFalse($before);
        $this->assertSame('premium.viewed', $hit);
        $this->assertNull($miss);
        $this->assertNull($anon);
        $this->assertSame('Saw the Premium page', $label);
        $this->assertSame(['path' => '/EL/Premium/plans'], self::properties($this->logged(['itemid' => (string) $this->ids['anna']])[0]));
        $this->assertArrayHasKey('premium.viewed', PersonEvents::all());
        $this->assertNull($posted, 'a form sent to the page is not a visit');
        $this->assertSame('premium.viewed', $now);
        $this->assertSame(1, PersonEvents::of($this->ids['bob'])[0]['count'] ?? null, 'recorded for whoever is signed in');
        $this->assertSame('unknown.event', PersonEvents::label('unknown.event'));
    }

    /**
     * An email open and a link click are events of the person the message was for — when the
     * mail log knows the account — with the message and the link; a message for nobody records
     * nothing.
     */
    #[DataProvider('databases')]
    public function testEmailOpensAndClicksAreEventsOfThePerson(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['email'] = ['tracking' => true];
        $mail = function (?int $userid): int {
            $this->db->queryBuilder()->table('mails')->insert(['status' => 1, 'frommail' => 'a@b.c', 'fromname' => 'A', 'tomail' => 'x@y.z',
                'toname' => 'X', 'subject' => 'Hi', 'content' => '', 'date' => time(), 'module' => 'test', 'moduleinfo' => '',
                'extrainfo' => '', 'path' => '', 'hash' => md5((string) microtime(true)), 'userid' => $userid]);

            return (int) $this->db->queryBuilder()->table('mails')->orderBy('id', 'desc')->first()->fields['id'];
        };
        $annas  = $mail($this->ids['anna']);
        $nobody = $mail(null);
        Tracking::begin('anna@example.com', 'newsletter', 'Hi', $annas, 'track-anna');
        Tracking::begin('x@y.z', 'newsletter', 'Hi', $nobody, 'track-nobody');
        Tracking::begin('y@y.z', 'newsletter', 'Hi', null, 'track-unlogged');

        // Act
        Tracking::recordOpen('track-anna', 'Mozilla/5.0', '10.0.0.1');
        Tracking::recordOpen('track-nobody', 'Mozilla/5.0', '10.0.0.1');
        $link = Tracking::link('track-anna', 'https://example.com/offer');
        Tracking::recordClick(urldecode(explode('c=', $link)[1]));
        Tracking::recordOpen('track-unlogged', 'Mozilla/5.0', '10.0.0.1');
        // With no mail log to ask, the open still counts — only the person event is skipped.
        $this->db->schema()->dropTableIfExists('mails');
        $this->probeChanged($this->db->type);
        $stillCounted = Tracking::recordOpen('track-anna', 'Mozilla/5.0', '10.0.0.1');

        // Assert
        $this->assertTrue($stillCounted);
        $events = array_column(PersonEvents::of($this->ids['anna']), 'count', 'event');
        $this->assertSame(1, $events['email.opened'] ?? null);
        $this->assertSame(1, $events['email.clicked'] ?? null);
        $click = $this->logged(['event' => 'email.clicked'])[0];
        // A JSON column keeps its own key order.
        $this->assertEquals(['mail' => $annas, 'category' => 'newsletter', 'url' => 'https://example.com/offer'], self::properties($click));
        $this->assertSame(2, count($this->logged()), 'the message for nobody recorded nothing');
    }

    /**
     * Without the change log only the summary is kept: occurrences are summed, backfilled and
     * forgotten all the same; a count in the last days is refused with what to enable, and an A/B
     * test says its goal is not counted rather than showing nobody reached it.
     */
    #[DataProvider('databases')]
    public function testWithoutTheChangeLogOnlyTheSummaryIsKept(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->db->schema()->dropView('pramnos.changelog_history');
        $this->db->schema()->dropTableIfExists(PersonEvents::LOG);
        $this->probeChanged($this->db->type);
        \Pramnos\Audience\Installed::reset();
        $test = \Pramnos\Audience\Experiments::create('t', 'T', [['key' => 'A', 'share' => 50], ['key' => 'B', 'share' => 50]], 'payment.made');
        \Pramnos\Audience\Experiments::start($test);
        \Pramnos\Audience\Experiments::variant('t', $this->ids['anna']);

        // Act
        $this->payments();
        PersonEvents::forget('nothing');
        $ever    = $this->who(['op' => 'ever']);
        $refused = null;
        try {
            $this->who(['op' => 'at_least', 'value' => 1, 'days' => 30]);
        } catch (\InvalidArgumentException $e) {
            $refused = $e->getMessage();
        }
        $results = \Pramnos\Audience\Experiments::results($test);

        // Assert
        $this->assertSame(['anna', 'bob'], $ever);
        $this->assertSame(3, PersonEvents::of($this->ids['anna'])[0]['count']);
        $this->assertStringContainsString('enable the changelog feature', (string) $refused);
        $this->assertFalse($results['counted']);
        $this->assertFalse(\Pramnos\Audience\Installed::eventLog());
    }

    /**
     * An occurrence written to the spool in one process is drained in another where the changelog
     * provider never booted — an application with the table and the feature off — and lands with
     * its properties: the JSON is made when the row is spooled, not by a drain-time transformer.
     */
    #[DataProvider('databases')]
    public function testAnOccurrenceDrainsWhereTheChangelogFeatureIsOff(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $directory = sys_get_temp_dir() . '/pf_audience_spool_' . bin2hex(random_bytes(4));
        mkdir($directory, 0777, true);
        $spool = new class () extends \Pramnos\Database\WriteSpool {
            /** Point the file driver at a directory of the test's own. */
            public static function useDirectory(?string $directory): void
            {
                static::$directory = $directory;
            }
        };
        $spool::useDirectory($directory);
        \Pramnos\Database\WriteSpool::setDriver(\Pramnos\Database\WriteSpool::DRIVER_FILE);

        // Act
        PersonEvents::record($this->ids['cara'], 'payment.made', ['amount' => 12]);
        \Pramnos\Database\WriteSpool::resetTransformers();   // the drain's process: no provider booted
        $drained = \Pramnos\Database\WriteSpool::drain();
        $spool::useDirectory(null);
        array_map('unlink', glob($directory . '/*') ?: []);
        rmdir($directory);

        // Assert
        $this->assertSame([1, 0], [$drained['written'], $drained['failed']], 'nothing kept back for the next run');
        $rows = $this->logged(['itemid' => (string) $this->ids['cara']]);
        $this->assertCount(1, $rows);
        $this->assertEquals(['amount' => 12], self::properties($rows[0]));
        $this->assertSame(['cara'], $this->who(['op' => 'at_least', 'value' => 1, 'days' => 1]), 'a window counts it');
    }
}
