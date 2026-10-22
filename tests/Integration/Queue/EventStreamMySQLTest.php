<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\EventsConsume;
use Pramnos\Console\Commands\EventsPrune;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Event\Event;
use Pramnos\Event\QueuedEvents;
use Pramnos\Event\QueuedListener;
use Pramnos\Event\Transport\StreamConsumer;
use Pramnos\Event\Transport\StreamTransport;
use Pramnos\Event\Transport\Transports;
use Pramnos\Framework\Factory;
use Pramnos\Queue\QueueManager;
use Pramnos\Queue\Worker;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A stream consumer the tests watch: what it ran with, whether to fail, folded and once on cue.
 */
final class StreamListenerA extends QueuedListener
{
    /** @var list<list<mixed>> The arguments of every run */
    public static array $runs = [];

    /** Whether runs throw. */
    public static bool $fail = false;

    /** Record the run, or fail. */
    public function handle(mixed ...$args): mixed
    {
        if (self::$fail) {
            throw new \RuntimeException('provider down');
        }
        self::$runs[] = $args;

        return true;
    }

    /** Two tries, then dead. */
    public function maxAttempts(): int
    {
        return 2;
    }

    /** Folded per account, when the event says so. */
    public function debounceKey(mixed ...$args): ?string
    {
        return ($args[1] ?? null) === 'fold' ? 'stream-sync:' . $args[0] : null;
    }

    /** Once per account, when the event says so. */
    public function idempotencyKey(mixed ...$args): ?string
    {
        return ($args[1] ?? null) === 'once' ? 'stream-welcome:' . $args[0] : null;
    }
}

/**
 * A second group, which never fails.
 */
final class StreamListenerB extends QueuedListener
{
    /** @var list<list<mixed>> The arguments of every run */
    public static array $runs = [];

    /** Record the run. */
    public function handle(mixed ...$args): mixed
    {
        self::$runs[] = $args;

        return true;
    }
}

/**
 * The event stream on MySQL: a fired event is one entry in `event_log`, written in the caller's
 * transaction, for the groups set to `stream`; each group reads from its own position, one
 * consumer at a time; a failure waits to be tried again and dies past its attempts without holding
 * up the rest; a folded listener goes on to the queue, a once-only one runs once; the log is pruned
 * of what every group has read.
 */
#[CoversClass(StreamTransport::class)]
#[CoversClass(StreamConsumer::class)]
#[CoversClass(EventsConsume::class)]
#[CoversClass(EventsPrune::class)]
#[CoversClass(QueuedEvents::class)]
class EventStreamMySQLTest extends TestCase
{
    protected Database $db;
    protected Controller $controller;

    /** The queue tables and the stream's from the framework's migrations, empty; both listeners on the stream. */
    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');
        $this->db = $this->connect();
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        $controller = $this->getMockBuilder(Controller::class)->disableOriginalConstructor()->getMock();
        $controller->application = $app;
        $this->controller = $controller;

        $this->dropTables();
        foreach (MigrationLoader::loadFromDirectory(dirname(__DIR__, 3) . '/database/migrations/framework/queue', $app) as $migration) {
            $migration->up();
        }
        QueueManager::forgetSchemaCache();
        \Pramnos\Application\Model::$columnCache = [];
        Event::forget();
        QueuedEvents::reset();
        QueuedEvents::$queue = new QueueManager($controller);
        Transports::reset();
        StreamTransport::reset();
        StreamListenerA::$runs = [];
        StreamListenerA::$fail = false;
        StreamListenerB::$runs = [];
        Application::getInstance()->applicationInfo['events'] = ['transport' => 'stream'];
        Event::listen('account.changed', StreamListenerA::class);
        Event::listen('account.changed', StreamListenerB::class);
    }

    /** Nothing of this class left for the next. */
    protected function tearDown(): void
    {
        Event::forget();
        QueuedEvents::reset();
        QueuedEvents::$queue = null;
        Transports::reset();
        StreamTransport::reset();
        unset(Application::getInstance()->applicationInfo['events']);
        $this->dropTables();
        QueueManager::forgetSchemaCache();
    }

    /** The connection under test. */
    protected function connect(): Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect(true);
        }

        return $db;
    }

    /** Drop the tables this class builds. */
    protected function dropTables(): void
    {
        foreach (['event_deliveries', 'event_consumers', 'event_log', 'queueitems', 'queuestats', 'queue_idempotency'] as $table) {
            $this->db->schema()->dropTableIfExists($table);
        }
    }

    /** How many rows a table holds. */
    private function rows(string $table): int
    {
        return count($this->db->queryBuilder()->table($table)->getAll());
    }

    /** A group's position. */
    private function position(string $group): int
    {
        return (int) $this->db->queryBuilder()->table('event_consumers')->where('groupname', $group)->first()->fields['position'];
    }

    /**
     * One event for two listeners is one entry, for both groups, with no queue task; the groups
     * start just before it; a rolled-back transaction takes its entry with it.
     */
    public function testAnEventIsOneEntryWrittenInTheCallersTransaction(): void
    {
        // Arrange
        $this->db->startTransaction();

        // Act
        $results = Event::fire('account.changed', 7, 'plain');
        $this->db->rollbackTransaction();
        $after = $this->rows('event_log');
        $kept  = Event::fire('account.changed', 8, 'plain');

        // Assert
        $this->assertSame($results[0], $results[1], 'one entry, both listeners');
        $this->assertSame(0, $after, 'the rollback took it');
        $entries = $this->db->queryBuilder()->table('event_log')->getAll();
        $this->assertCount(1, $entries);
        $this->assertSame([StreamListenerA::class, StreamListenerB::class], json_decode($entries[0]['groups'], true));
        $this->assertSame('account.changed', $entries[0]['event']);
        $this->assertSame([8, 'plain'], json_decode($entries[0]['payload'], true)['args']);
        $this->assertSame(0, $this->rows('queueitems'), 'nothing for the queue');
        $this->assertSame((int) $kept[0] - 1, $this->position(StreamListenerA::class), 'the group starts just before its first entry');
    }

    /**
     * Each group reads from where it got to: a consumer that stops part-way leaves the position at
     * what it ran, and another one picks up from there; the two groups move apart.
     */
    public function testAConsumerResumesFromItsPositionAndGroupsAreIndependent(): void
    {
        // Arrange
        foreach ([1, 2, 3] as $account) {
            Event::fire('account.changed', $account, 'plain');
        }
        $first = new StreamConsumer('one');

        // Act
        $part = $first->consume(StreamListenerA::class, 1);
        $held = (new StreamConsumer('two'))->consume(StreamListenerA::class);
        $first->release(StreamListenerA::class);
        $rest = (new StreamConsumer('two'))->consume(StreamListenerA::class);

        // Assert
        $this->assertSame(1, $part['ran']);
        $this->assertTrue($held['held'], 'one consumer per group at a time');
        $this->assertSame(2, $rest['ran'], 'the second picked up where the first stopped');
        $this->assertSame([[1, 'plain'], [2, 'plain'], [3, 'plain']], StreamListenerA::$runs);
        $this->assertSame([], StreamListenerB::$runs, 'the other group has read nothing yet');
        $this->assertSame((int) $this->db->queryBuilder()->table('event_log')->orderBy('entryid', 'desc')->first()->fields['entryid'], $this->position(StreamListenerA::class));
    }

    /**
     * A failure moves the position on and waits to be tried again; tried again when due it runs;
     * failing past the listener's attempts it is kept as dead — and the other group never waited.
     */
    public function testAFailureIsTriedAgainAndDiesPastItsAttempts(): void
    {
        // Arrange
        Event::fire('account.changed', 1, 'plain');
        Event::fire('account.changed', 2, 'plain');
        StreamListenerA::$fail = true;
        $consumer = new StreamConsumer('one');

        // Act
        $failed = $consumer->consume(StreamListenerA::class);
        $other  = $consumer->consume(StreamListenerB::class);
        $notDue = $consumer->consume(StreamListenerA::class);
        // The first failure falls due; the second not yet.
        $first = (int) $this->db->queryBuilder()->table('event_deliveries')->orderBy('id')->first()->fields['id'];
        $this->db->queryBuilder()->table('event_deliveries')->where('id', $first)->update(['nextat' => 0]);
        StreamListenerA::$fail = false;
        $retried = $consumer->consume(StreamListenerA::class);
        StreamListenerA::$fail = true;
        $this->db->queryBuilder()->table('event_deliveries')->where('id', '>', 0)->update(['nextat' => 0]);
        $died = $consumer->consume(StreamListenerA::class);

        // Assert
        $this->assertSame(['failed' => 2, 'ran' => 0], ['failed' => $failed['failed'], 'ran' => $failed['ran']]);
        $this->assertSame(2, $other['ran'], 'the other group never waited');
        $this->assertSame(0, $notDue['retried'], 'not before it is due');
        $this->assertSame(1, $retried['retried']);
        $this->assertSame([[1, 'plain']], StreamListenerA::$runs, 'run when tried again');
        $this->assertSame(1, $died['dead'], 'its second attempt was its last');
        $dead = $this->db->queryBuilder()->table('event_deliveries')->getAll();
        $this->assertCount(1, $dead);
        $this->assertSame(['dead', 2], [$dead[0]['status'], (int) $dead[0]['attempts']]);
        $this->assertStringContainsString('provider down', (string) $dead[0]['error']);
        $this->assertSame(30, StreamConsumer::backoff(1));
        $this->assertSame(3600, StreamConsumer::backoff(20), 'at most an hour');
    }

    /**
     * A listener that folds is handed to the queue, which folds it as before and a worker runs it
     * once; a once-only listener runs once, however many entries carry its key.
     */
    public function testFoldGoesToTheQueueAndOnceRunsOnce(): void
    {
        // Arrange
        Event::fire('account.changed', 5, 'fold');
        Event::fire('account.changed', 5, 'fold');
        Event::fire('account.changed', 6, 'once');
        Event::fire('account.changed', 6, 'once');

        // Act
        $tally = (new StreamConsumer('one'))->consume(StreamListenerA::class);
        $tasks = $this->db->queryBuilder()->table('queueitems')->where('type', 'event.listener')->getAll();
        // The fold's window, over.
        $this->db->queryBuilder()->table('queueitems')->where('type', 'event.listener')->update(['availableat' => date('Y-m-d H:i:s', time() - 60)]);
        (new Worker($this->controller))->processNextTask();

        // Assert
        $this->assertSame(4, $tally['ran']);
        $this->assertCount(1, $tasks, 'the two folded into one task');
        $this->assertSame([[6, 'once'], [5, 'fold']], StreamListenerA::$runs, 'once ran once; the fold ran in the worker');
    }

    /**
     * Pruning deletes old entries every group has read, and keeps what a group has not read and
     * what waits to be tried again; the commands say what they did, and with no stream here,
     * that there is none.
     */
    public function testPruningKeepsWhatIsStillWantedAndTheCommandsReport(): void
    {
        // Arrange
        foreach ([1, 2, 3] as $account) {
            Event::fire('account.changed', $account, 'plain');
        }
        $this->db->queryBuilder()->table('event_log')->update(['createdat' => time() - 40 * 86400]);
        $consume = new CommandTester(new EventsConsume());
        $consume->execute(['--once' => true, '--group' => [StreamListenerA::class]]);
        StreamListenerB::$runs = [];
        $middle = (int) $this->db->queryBuilder()->table('event_log')->orderBy('entryid')->getAll()[1]['entryid'];
        $this->db->queryBuilder()->table('event_consumers')->where('groupname', StreamListenerB::class)->update(['position' => $middle]);
        $this->db->queryBuilder()->table('event_deliveries')->insert(['groupname' => StreamListenerA::class, 'entryid' => $middle - 1,
            'attempts' => 1, 'status' => 'pending', 'nextat' => time() + 60, 'createdat' => time()]);
        $prune = new CommandTester(new EventsPrune());

        // Act
        $prune->execute(['--days' => '30']);
        $left = array_map('intval', array_column($this->db->queryBuilder()->table('event_log')->orderBy('entryid')->getAll(), 'entryid'));
        $all  = new CommandTester(new EventsConsume());
        $all->execute(['--once' => true]);

        // Assert
        $this->assertStringContainsString(StreamListenerA::class . ': read, ran 3', $consume->getDisplay());
        $this->assertSame([$middle - 1, $middle + 1], $left, 'the waiting one and the unread one stay; the read one goes');
        $this->assertStringContainsString('Event stream: 1 entries', $prune->getDisplay());
        $this->assertStringContainsString(StreamListenerB::class . ': read, ran 1', $all->getDisplay(), 'every group by default');
        $this->dropTables();
        StreamTransport::reset();
        $none = new CommandTester(new EventsConsume());
        $none->execute(['--once' => true]);
        $this->assertStringContainsString('No event stream here', $none->getDisplay());
        $prune->execute([]);
        $this->assertStringContainsString('No event stream here', $prune->getDisplay());
    }

    /**
     * The daemon's own paths: with no listener on the stream it says there is nothing to read; as
     * a daemon it waits while there is nothing and stops when its time is up; pruning takes the
     * application's days, 30 by default; a group that is not a listener is refused.
     */
    public function testTheDaemonWaitsAndStopsAndNothingToReadIsSaid(): void
    {
        // Arrange
        Event::forget();
        $nothing = new CommandTester(new EventsConsume());
        $daemon  = new CommandTester(new EventsConsume());
        $prune   = new CommandTester(new EventsPrune());

        // Act
        $nothing->execute(['--max-time' => '1']);
        Event::listen('account.changed', StreamListenerB::class);
        $started = time();
        $daemon->execute(['--max-time' => '1', '--sleep' => '1']);
        $took = time() - $started;
        $prune->execute([]);

        // Assert
        $this->assertStringContainsString('No queued listener goes by the stream', $nothing->getDisplay());
        $this->assertGreaterThanOrEqual(1, $took, 'it waited for its time to be up');
        $this->assertStringContainsString('older than 30 days', $prune->getDisplay());
        $this->expectExceptionMessage('Not a queued listener: stdClass');
        (new StreamConsumer())->consume(\stdClass::class);
    }

    /**
     * The queue screen and the analyst see each group: entries waiting for it, to be tried again
     * and dead; the dead are tried again at once, or deleted, from the screen, and each theme draws
     * the groups with their buttons.
     */
    public function testTheQueueScreenShowsTheGroupsAndRedrivesTheDead(): void
    {
        // Arrange
        foreach ([1, 2, 3] as $account) {
            Event::fire('account.changed', $account, 'plain');
        }
        StreamListenerA::$fail = true;
        (new StreamConsumer('one'))->consume(StreamListenerA::class, 2);
        $this->db->queryBuilder()->table('event_deliveries')->where('id', '>', 0)->update(['status' => 'dead']);
        $screen = new class () extends \Pramnos\Queue\Controllers\QueueController {
            /** @var list<string> What was flashed */
            public array $said = [];

            /** Signed in, for the test. */
            protected function requireMinUserType(int $minType): bool
            {
                return false;
            }

            /** No redirect in a test. */
            public function redirect($url = null, $quit = true, $code = '302'): void
            {
            }

            /** Keep the flash. */
            public function addMessage($message, $type = 'info'): void
            {
                $this->said[] = (string) $message;
            }

            /** The groups, as display() gives them to the view. */
            public function groups(): array
            {
                return $this->streams();
            }
        };

        // Act
        $status = StreamConsumer::status();
        $tool   = (new \Pramnos\Llm\Analyst\Tools\QueueTool())->execute([]);
        $pages  = [];
        foreach (['tailwind', 'bootstrap', 'plain-css'] as $theme) {
            $view = new class () extends \stdClass {
                /** No partials in a test. */
                public function insert(string $name): void
                {
                }
            };
            $view->datatable = new class () {
                /** No table in a test. */
                public function render(): string
                {
                    return '';
                }
            };
            $view->statuses = [];
            $view->counts   = [];
            $view->byType   = [];
            $view->streams  = $screen->groups();
            $pages[$theme]  = (function (string $path): string {
                ob_start();
                include $path;

                return (string) ob_get_clean();
            })->call($view, ROOT . '/scaffolding/themes/' . $theme . '/views/queue/queue.html.php');
        }
        $_POST = ['group' => StreamListenerA::class];
        $screen->streamretry();
        $retried = $this->db->queryBuilder()->table('event_deliveries')->where('status', 'pending')->getAll();
        $this->db->queryBuilder()->table('event_deliveries')->where('id', '>', 0)->update(['status' => 'dead']);
        $screen->streamdrop();
        $_POST = [];

        // Assert
        $byGroup = array_column($status, null, 'group');
        $this->assertSame([1, 0, 2], [$byGroup[StreamListenerA::class]['lag'], $byGroup[StreamListenerA::class]['pending'], $byGroup[StreamListenerA::class]['dead']]);
        $this->assertSame(3, $byGroup[StreamListenerB::class]['lag'], 'the other group has read nothing');
        $this->assertSame(2, array_column($tool['streams'], null, 'group')[StreamListenerA::class]['dead']);
        foreach ($pages as $theme => $html) {
            $this->assertStringContainsString('Event streams', $html, $theme);
            $this->assertStringContainsString('Try the dead again', $html, $theme);
            $this->assertStringContainsString('Queue/streamdrop', $html, $theme);
        }
        $this->assertCount(2, $retried, 'tried again at once');
        $this->assertSame([0, 0], array_map('intval', array_column($retried, 'attempts')), 'with their attempts back to none');
        $this->assertSame([], $this->db->queryBuilder()->table('event_deliveries')->getAll(), 'deleted');
        $this->assertSame(['2 dead entries will be tried again.', '2 dead entries deleted.'], $screen->said);
    }
}
