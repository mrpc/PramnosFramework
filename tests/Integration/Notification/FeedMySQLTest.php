<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Notification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\NotificationsController;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Notification\Feed;

/**
 * One person's notification feed, read back from the table the `database` channel writes — on
 * MySQL here, on PostgreSQL in the sibling.
 *
 * What a header's bell relies on: the newest first, a page at a time; the unread only when asked;
 * a group's rows by the classes app.php lists for it or by the `group` the notification stored;
 * the counts the bell and the tabs show; and read and read-all touching only this person's rows.
 * The table is shared with the channel's own tests, so rows are written for ids nobody else uses
 * and removed after — the table is never dropped.
 */
#[CoversClass(Feed::class)]
#[CoversClass(NotificationsController::class)]
#[CoversClass(\Pramnos\Notification\FeedApi::class)]
#[CoversClass(\Pramnos\Html\NotificationBell::class)]
#[CoversClass(\Pramnos\Console\Commands\NotificationsPrune::class)]
class FeedMySQLTest extends TestCase
{
    protected const ME = 990001;

    protected const SOMEBODY = 990002;

    protected Database $db;

    /** @var array<string, mixed> */
    private array $info = [];

    protected function setUp(): void
    {
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $this->db = $this->connect();
        require_once ROOT . '/database/migrations/framework/notifications/2026_11_12_000002_add_snoozed_until_to_notifications.php';
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Notifications\CreateNotificationsTable::class,
            \Pramnos\Framework\Migrations\Notifications\AddSnoozedUntilToNotifications::class], $this->db);
        $this->clean();

        $this->info = Application::getInstance()->applicationInfo;
        Application::getInstance()->applicationInfo['notifications'] = ['groups' => [
            'tasks'  => ['App\\Notifications\\TaskAssigned', 'App\\Notifications\\TaskDue'],
            'empty'  => [],
        ]];

        // Newest last: m1 oldest … m6 newest.
        $rows = [
            ['m1', 'App\\Notifications\\TaskAssigned', self::ME, ['title' => 'Task one', 'message' => 'Do it', 'url' => '/tasks/1'], null],
            ['m2', 'App\\Notifications\\Welcome', self::ME, ['message' => 'Welcome', 'group' => 'system'], '2026-10-01 10:00:00'],
            // A Notification\\Message writes its title as `subject`.
            ['m3', 'App\\Notifications\\TaskDue', self::ME, ['subject' => 'Due', 'message' => 'Due today'], null],
            ['m4', 'App\\Notifications\\Mention', self::ME, ['message' => 'Anna mentioned you', 'group' => 'mine_and_yours'], null],
            ['m5', 'App\\Notifications\\Backup', self::ME, ['body' => 'Backup done', 'group' => 'system'], null],
            ['m6', 'App\\Notifications\\TaskAssigned', self::SOMEBODY, ['message' => 'Not yours'], null],
        ];
        foreach ($rows as $i => [$tag, $type, $who, $data, $read]) {
            $this->db->queryBuilder()->table('notifications')->insert([
                'id'              => sprintf('00000000-0000-4000-8000-%012d', $i + 1),
                'type'            => $type,
                'notifiable_type' => 'App\\User',
                'notifiable_id'   => $who,
                'data'            => json_encode($data + ['tag' => $tag], JSON_UNESCAPED_UNICODE),
                'read_at'         => $read,
                'created_at'      => sprintf('2026-10-15 10:00:%02d', $i),
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->clean();
        // Restored only where it was kept: a test skipped before then would otherwise wipe it.
        if ($this->info !== []) {
            Application::getInstance()->applicationInfo = $this->info;
        }
        \Pramnos\Http\RequestIdentity::reset();
        $_GET = $_POST = [];
    }

    /** The connection under test. */
    protected function connect(): Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect(false);
        }
        if (!$db->connected) {
            $this->markTestSkipped('MySQL is not reachable.');
        }

        return $db;
    }

    private function clean(): void
    {
        // A test skipped before it connected has nothing to clean.
        if (!isset($this->db)) {
            return;
        }
        $this->db->queryBuilder()->table('notifications')->whereIn('notifiable_id', [self::ME, self::SOMEBODY])->delete();
    }

    /** @param array<string, mixed> $page */
    private static function tags(array $page): array
    {
        return array_map(static fn (array $item): string => $item['data']['tag'], $page['items']);
    }

    /**
     * Newest first, a page at a time, this person's only; the unread when asked; and each item
     * with the parts a bell draws — title, message (or body), link, group, read.
     */
    public function testTheFeedPageByPage(): void
    {
        // Arrange
        $feed = new Feed(self::ME, $this->db);

        // Act
        $first  = $feed->list(1, 2);
        $second = $feed->list(2, 2);
        $unread = $feed->list(1, 20, true);

        // Assert
        $this->assertSame(['m5', 'm4'], self::tags($first));
        $this->assertSame(['m3', 'm2'], self::tags($second));
        $this->assertSame([5, 4, 3, 2], [$first['total'], $first['unread'], $first['pages'], $second['page']]);
        $this->assertSame(['m5', 'm4', 'm3', 'm1'], self::tags($unread), 'm2 is read; m6 is somebody else\'s');
        $task = $unread['items'][3];
        $this->assertSame(['Task one', 'Do it', '/tasks/1', 'tasks', false], [$task['title'], $task['message'], $task['url'], $task['group'], $task['read']]);
        $this->assertSame('Backup done', $first['items'][0]['message'], 'body when there is no message');
        $this->assertSame('Due', $unread['items'][2]['title'], 'subject when there is no title: a Message came without one');
        $this->assertSame('system', $first['items'][0]['group'], 'from the stored group');
        $this->assertSame(1, $feed->list(0, 0)['page'], 'page and size are clamped');
    }

    /**
     * A group named in app.php is its classes; any other is the stored `group`, matched exactly —
     * an underscore is not a wildcard — and a declared group with no classes is empty.
     */
    public function testGroups(): void
    {
        // Arrange
        $feed = new Feed(self::ME, $this->db);

        // Act / Assert
        $this->assertSame(['m3', 'm1'], self::tags($feed->list(1, 20, false, 'tasks')));
        $this->assertSame(['m5', 'm2'], self::tags($feed->list(1, 20, false, 'system')));
        $this->assertSame(['m4'], self::tags($feed->list(1, 20, false, 'mine_and_yours')));
        $this->assertSame([], self::tags($feed->list(1, 20, false, 'mineXandXyours')));
        $this->assertSame([], self::tags($feed->list(1, 20, false, 'empty')));
        $this->assertSame(['' => 4, 'tasks' => 2, 'empty' => 0], $feed->unreadByGroup());
        $this->assertSame(1, $feed->unreadCount('system'));
    }

    /**
     * Groups left out of a bell (FW-392): a group named by its classes and one named in the
     * stored JSON both go from the list and the counts, and "mark all read" leaves them unread —
     * through the feed, the endpoint (`?except=`) and the bell's markup.
     *
     * A page whose own screen shows the conversations keeps their notifications off its bell;
     * marking the bell read there must not mark read what that screen still has to show.
     */
    public function testGroupsLeftOut(): void
    {
        // Arrange — unread: m1, m3 (tasks), m4 (mine_and_yours), m5 (system); m2 (system) read
        $feed = new Feed(self::ME, $this->db);
        $me   = new \Pramnos\User\User();
        $me->userid = self::ME;
        \Pramnos\Http\RequestIdentity::seal($me, 'test');
        $controller = new NotificationsController(Application::getInstance());

        // Act
        $noSystem = self::tags($feed->list(1, 20, false, null, ['system']));
        $noTasks  = self::tags($feed->list(1, 20, false, null, ['tasks', '', ' ']));
        $neither  = $feed->list(1, 20, false, null, ['system', 'tasks']);
        $byGroup  = $feed->unreadByGroup(['tasks']);
        $_GET     = ['except' => 'system,tasks'];
        $listed   = json_decode((string) $controller->display()->getBody(), true);
        $counted  = json_decode((string) $controller->count()->getBody(), true);
        $bell     = \Pramnos\Html\NotificationBell::render(['except' => ['system', 'tasks']]);
        $_GET     = [];
        $_POST    = ['except' => ['system']];
        $marked   = json_decode((string) $controller->readall()->getBody(), true);

        // Assert
        $this->assertSame(['m4', 'm3', 'm1'], $noSystem, 'a group named in the stored JSON');
        $this->assertSame(['m5', 'm4', 'm2'], $noTasks, 'a group named by its classes; empty names ignored');
        $this->assertSame(['m4'], self::tags($neither));
        $this->assertSame(1, $neither['unread']);
        $this->assertSame(['' => 2, 'tasks' => 0, 'empty' => 0], $byGroup);
        $this->assertSame(['m4'], array_map(static fn ($i) => $i['data']['tag'], $listed['items']));
        $this->assertSame(1, $counted['unread']);
        $this->assertStringContainsString(' data-except="system,tasks"', $bell);
        $this->assertStringContainsString('data-pf-notifications-count>1</span>', $bell, 'the count drawn leaves them out too');
        // Read-all without the system group: the three others read, the system one still unread.
        $this->assertSame(['marked' => 3, 'unread' => 0], $marked);
        $this->assertSame(1, $feed->unreadCount(), 'm5 untouched');
        $this->assertSame(1, $feed->unreadCount('system'));
    }

    /**
     * Whatever marks notifications read tells each person's channel `notification.read` (FW-392):
     * one, all, and those settled about something — once per person, none when nothing was read —
     * so a bell open in another window refreshes at once, not at its next poll.
     */
    public function testReadingIsAnnounced(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('notifications')->insert([
            'id' => '20000000-0000-4000-8000-000000000001', 'type' => 'App\\Notifications\\CommentWaiting', 'notifiable_type' => 'App\\User',
            'notifiable_id' => self::SOMEBODY, 'data' => json_encode(['about' => ['comment:9']]), 'read_at' => null, 'created_at' => '2026-10-15 11:00:00',
        ]);
        $this->db->queryBuilder()->table('notifications')->insert([
            'id' => '20000000-0000-4000-8000-000000000002', 'type' => 'App\\Notifications\\CommentWaiting', 'notifiable_type' => 'App\\User',
            'notifiable_id' => self::ME, 'data' => json_encode(['about' => ['comment:9']]), 'read_at' => null, 'created_at' => '2026-10-15 11:00:00',
        ]);
        $feed = new Feed(self::ME, $this->db);
        $fake = \Pramnos\Broadcasting\Testing\FakeDriver::swap();

        try {
            // Act
            $feed->markRead('00000000-0000-4000-8000-000000000001');
            $one = $fake->recorded();
            $fake->flush();
            Feed::settleAbout(['comment:9'], null, $this->db);
            $settled = $fake->recorded();
            $fake->flush();
            $feed->markAllRead();
            $feed->markAllRead();
            $feed->markRead('00000000-0000-4000-8000-000000000001');
            $all = $fake->recorded();
        } finally {
            \Pramnos\Broadcasting\Testing\FakeDriver::restore();
        }

        // Assert
        $this->assertSame([['private-users.' . self::ME, 'notification.read', []]], array_map(static fn ($r) => [$r['channel'], $r['event'], $r['payload']], $one));
        $channels = array_column($settled, 'channel');
        sort($channels);
        $this->assertSame(['private-users.' . self::ME, 'private-users.' . self::SOMEBODY], $channels, 'each person settled, once');
        // The first read-all marked the rest; the second, and reading one already read, told nobody.
        $this->assertCount(1, $all);
    }

    /**
     * A broadcast that fails costs the announcement only: the rows are read, and nothing throws.
     */
    public function testAFailingAnnouncementLeavesTheRowsRead(): void
    {
        // Arrange
        $previous = \Pramnos\Broadcasting\BroadcastingManager::currentInstance();
        $manager  = new \Pramnos\Broadcasting\BroadcastingManager();
        $manager->addDriver(new class implements \Pramnos\Broadcasting\Drivers\DriverInterface {
            /** Always fails, as a backplane that is down does. */
            public function broadcast(string $channel, string $event, array $payload): void
            {
                throw new \RuntimeException('backplane down');
            }

            /** Its name. */
            public function name(): string
            {
                return 'down';
            }
        })->setDefault('down');
        \Pramnos\Broadcasting\BroadcastingManager::setInstance($manager);

        try {
            // Act
            $marked = (new Feed(self::ME, $this->db))->markAllRead();
        } finally {
            \Pramnos\Broadcasting\BroadcastingManager::setInstance($previous);
        }

        // Assert
        $this->assertSame(4, $marked);
        $this->assertSame(0, (new Feed(self::ME, $this->db))->unreadCount());
    }

    /**
     * Read and read-all touch only this person's unread rows: somebody else's id, an id already
     * read, and a string that is not an id all mark nothing.
     */
    public function testReadAndReadAll(): void
    {
        // Arrange
        $feed  = new Feed(self::ME, $this->db);
        $other = new Feed(self::SOMEBODY, $this->db);

        // Act
        $mine    = $feed->markRead('00000000-0000-4000-8000-000000000001');
        $again   = $feed->markRead('00000000-0000-4000-8000-000000000001');
        $theirs  = $feed->markRead('00000000-0000-4000-8000-000000000006');
        $garbage = $feed->markRead("x' OR 1=1 --");
        $tasks   = $feed->markAllRead('tasks');
        $rest    = $feed->markAllRead();
        $none    = $feed->markAllRead();

        // Assert
        $this->assertSame([true, false, false, false], [$mine, $again, $theirs, $garbage]);
        $this->assertSame([1, 2, 0], [$tasks, $rest, $none]);
        $this->assertSame(0, $feed->unreadCount());
        $this->assertSame(1, $other->unreadCount(), 'somebody else\'s is untouched');
        $this->assertSame(1, (Feed::badge())(self::SOMEBODY));
    }

    /**
     * The endpoint answers the signed-in person's feed as JSON, the counts by group, and marks
     * read; nobody signed in gets a 401.
     */
    public function testTheEndpoint(): void
    {
        // Arrange
        $controller = new NotificationsController(Application::getInstance());

        // Act — nobody signed in
        $refused = $controller->display();

        $me = new \Pramnos\User\User();
        $me->userid = self::ME;
        \Pramnos\Http\RequestIdentity::seal($me, 'test');
        $_GET    = ['unread' => '1', 'group' => 'tasks'];
        $list    = json_decode((string) $controller->display()->getBody(), true);
        $_GET    = [];
        $counts  = json_decode((string) $controller->count()->getBody(), true);
        $_POST   = ['id' => '00000000-0000-4000-8000-000000000003'];
        $read    = json_decode((string) $controller->read()->getBody(), true);
        $_POST   = ['group' => 'system'];
        $all     = json_decode((string) $controller->readall()->getBody(), true);

        // Assert
        $this->assertSame(401, $refused->getStatusCode());
        $this->assertSame(['m3', 'm1'], array_map(static fn ($i) => $i['data']['tag'], $list['items']));
        $this->assertSame(['unread' => 4, 'groups' => ['tasks' => 2, 'empty' => 0]], $counts);
        $this->assertSame(['read' => true, 'unread' => 3], $read);
        $this->assertSame(['marked' => 1, 'unread' => 2], $all);
    }

    /**
     * The bell: nothing for somebody signed out; for the signed-in person, the unread count already
     * drawn, the hooks pf-utils.js reads, and a tab per group with its count to fill.
     */
    public function testTheBell(): void
    {
        // Act — signed out, then signed in
        $out = \Pramnos\Html\NotificationBell::render();
        $me  = new \Pramnos\User\User();
        $me->userid = self::ME;
        \Pramnos\Http\RequestIdentity::seal($me, 'test');
        $html = \Pramnos\Html\NotificationBell::render([
            'url' => '/admin/Notifications', 'interval' => 30, 'channel' => 'users.' . self::ME,
            'groups' => ['' => 'For me', 'tasks' => 'Tasks'], 'class' => 'btn btn-ghost',
        ]);

        // Assert
        $this->assertSame('', $out);
        $this->assertStringStartsWith('<div class="pf-notify" data-pf-notifications data-url="/admin/Notifications" data-interval="30" data-channel="users.990001">', $html);
        $this->assertStringContainsString('class="pf-notify-toggle btn btn-ghost" data-pf-notifications-toggle data-label="Notifications" aria-haspopup="true" aria-expanded="false" aria-label="Notifications (4)"', $html);
        $this->assertStringContainsString('<span class="pf-notify-count" data-pf-notifications-count>4</span>', $html);
        $this->assertStringContainsString('data-pf-notifications-group="" aria-selected="true">For me</button>', $html);
        $this->assertStringContainsString('data-pf-notifications-group="tasks" aria-selected="false">Tasks <span class="pf-notify-tab-count" data-pf-notifications-group-count="tasks" hidden></span>', $html);
        $this->assertStringContainsString('<ul class="pf-notify-list" data-pf-notifications-list></ul>', $html);
        $this->assertStringContainsString('data-url="' . \sURL . 'Notifications"', \Pramnos\Html\NotificationBell::render(), 'the site\'s /Notifications by default');
        $this->assertStringNotContainsString('pf-notify-all', $html, 'a link to a page nobody named');
        // The arrival: the title flashes unless switched off; a sound only when asked for —
        // a tone made in the page, or an address, escaped.
        $this->assertStringNotContainsString('data-title-flash', $html, 'the title flash is on by default');
        $this->assertStringNotContainsString('data-sound', $html, 'a sound nobody asked for');
        $quiet = \Pramnos\Html\NotificationBell::render(['titleFlash' => false, 'sound' => true]);
        $this->assertStringContainsString(' data-title-flash="0" data-sound="beep">', $quiet);
        $this->assertStringContainsString(' data-sound="/sounds/a&amp;b.mp3">', \Pramnos\Html\NotificationBell::render(['sound' => '/sounds/a&b.mp3']));
        // With the page of every notification: a link at the panel's foot, after the list and
        // the empty line, so it is there when the list is empty too.
        $this->assertStringEndsWith(
            '<p class="pf-notify-empty" data-pf-notifications-empty hidden>No notifications</p>'
            . '<div class="pf-notify-foot"><a class="pf-notify-all" href="/admin/Myaccount/notifications">All notifications</a></div></div></div>',
            \Pramnos\Html\NotificationBell::render(['all' => '/admin/Myaccount/notifications', 'allLabel' => 'All notifications'])
        );
    }

    /**
     * The bell listens on the user's own channel by default where the page has a live connection
     * — `private-users.{id}`, or the configured pattern — and on none where the transport is one
     * the page's client does not speak; a channel the caller gives wins, and an empty one is none.
     */
    public function testTheBellListensOnTheUsersOwnChannelWhereThereIsAConnection(): void
    {
        // Arrange
        $me = new \Pramnos\User\User();
        $me->userid = self::ME;
        \Pramnos\Http\RequestIdentity::seal($me, 'test');
        $app = Application::getInstance();

        // Act
        $app->applicationInfo['broadcasting'] = ['transport' => 'websocket'];
        $default = \Pramnos\Html\NotificationBell::render();
        $given   = \Pramnos\Html\NotificationBell::render(['channel' => 'private-mine']);
        $none    = \Pramnos\Html\NotificationBell::render(['channel' => '']);
        $app->applicationInfo['broadcasting'] = ['transport' => 'websocket', 'notifications_channel' => 'private-team.{id}'];
        $team = \Pramnos\Html\NotificationBell::render();
        $app->applicationInfo['broadcasting'] = ['transport' => 'sse'];
        $polling = \Pramnos\Html\NotificationBell::render();

        // Assert
        $this->assertStringContainsString('data-channel="private-users.' . self::ME . '"', $default);
        $this->assertStringContainsString('data-channel="private-mine"', $given);
        $this->assertStringContainsString('data-channel="private-team.' . self::ME . '"', $team);
        $this->assertStringNotContainsString('data-channel', $polling, 'no connection to hear it on');
        $this->assertStringNotContainsString('data-channel', $none, 'an empty channel keeps the bell off the connection');
    }

    /**
     * A notification about something now dealt with is settled with it: everybody's when the
     * thing itself was handled, one person's when they opened it; one about something else, an
     * already read one, and one that only mentions the key in its text are left as they were.
     */
    public function testWhatIsAboutSomethingDealtWithIsSettled(): void
    {
        // Arrange
        $about = static fn (string $id, int $who, array $data, ?string $read = null) => [
            'id' => $id, 'type' => 'App\\Notifications\\CommentWaiting', 'notifiable_type' => 'App\\User', 'notifiable_id' => $who,
            'data' => json_encode($data), 'read_at' => $read, 'created_at' => '2026-10-15 11:00:00',
        ];
        foreach ([
            $about('10000000-0000-4000-8000-000000000001', self::ME, ['message' => 'A comment', 'about' => ['comment:42', 'post:7']]),
            $about('10000000-0000-4000-8000-000000000002', self::SOMEBODY, ['message' => 'A comment', 'about' => 'comment:42']),
            $about('10000000-0000-4000-8000-000000000003', self::ME, ['message' => 'Another', 'about' => ['comment:43']]),
            $about('10000000-0000-4000-8000-000000000004', self::ME, ['message' => 'Says "comment:42" but is not about it']),
            $about('10000000-0000-4000-8000-000000000005', self::ME, ['message' => 'Read', 'about' => ['comment:42']], '2026-10-01 10:00:00'),
        ] as $row) {
            $this->db->queryBuilder()->table('notifications')->insert($row);
        }
        $read = fn (string $id): bool => $this->db->queryBuilder()->table('notifications')->where('id', $id)->first()->fields['read_at'] !== null;

        // Act
        $mine     = Feed::settleAbout(['comment:42'], self::ME, $this->db);
        $everyone = Feed::settleAbout(['comment:42', 'comment:43', ''], null, $this->db);
        $nothing  = Feed::settleAbout(['', ''], null, $this->db);

        // Assert
        $this->assertSame(1, $mine, 'only mine, of what is about it');
        $this->assertSame(2, $everyone, 'then the rest, of anybody');
        $this->assertSame(0, $nothing);
        $this->assertTrue($read('10000000-0000-4000-8000-000000000002'));
        $this->assertTrue($read('10000000-0000-4000-8000-000000000003'));
        $this->assertFalse($read('10000000-0000-4000-8000-000000000004'), 'a mention in the text is not what it is about');
    }

    /**
     * A group app.php collapses comes as one item where there are enough unread of it (FW-486):
     * "You have 2 tasks", at the newest of them, linking to their list, its id every one of
     * theirs — so the endpoint reading it reads them all; fewer than asked, each as before.
     * `settle` reads one's own about what a page shows, and nobody else's; and the bell carries
     * read-on-view where asked.
     */
    public function testAGroupCollapsedAndWhatAPageShowsSettled(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['notifications']['collapse'] = ['tasks' => ['title' => 'You have %d tasks', 'url' => '/tasks', 'min' => 2]];
        $feed = new Feed(self::ME, $this->db);
        foreach ([[self::ME, '20000000-0000-4000-8000-000000000001'], [self::SOMEBODY, '20000000-0000-4000-8000-000000000002']] as [$who, $id]) {
            $this->db->queryBuilder()->table('notifications')->insert(['id' => $id, 'type' => 'App\\Notifications\\TodoWaiting', 'notifiable_type' => 'App\\User',
                'notifiable_id' => $who, 'data' => json_encode(['message' => 'A to-do', 'about' => ['todo:42']]), 'created_at' => '2026-10-15 09:00:00']);
        }
        $controller = new NotificationsController(Application::getInstance());
        $me = new \Pramnos\User\User();
        $me->userid = self::ME;

        // Act
        $collapsed = $feed->list(1, 20, false, null, [], true)['items'];
        $one       = array_values(array_filter($collapsed, static fn (array $i): bool => !empty($i['collapsed'])));
        $at        = array_search(true, array_map(static fn (array $i): bool => !empty($i['collapsed']), $collapsed), true);
        $plain     = $feed->list(1, 20)['items'];
        Application::getInstance()->applicationInfo['notifications']['collapse']['tasks']['min'] = 3;
        $fewer     = $feed->list(1, 20, false, null, [], true)['items'];
        $refused   = $controller->settle();
        \Pramnos\Http\RequestIdentity::seal($me, 'test');
        $_POST     = ['id' => $one[0]['id']];
        $read      = json_decode((string) $controller->read()->getBody(), true);
        $_POST     = ['about' => 'todo:42, todo:43'];
        $settled   = json_decode((string) $controller->settle()->getBody(), true);
        $theirs    = (new Feed(self::SOMEBODY, $this->db))->unreadCount();
        $bell      = \Pramnos\Html\NotificationBell::render(['readOnView' => 1200]);

        // Assert
        $this->assertCount(1, $one, 'one item');
        $this->assertSame(['You have 2 tasks', '/tasks', 2], [$one[0]['title'], $one[0]['url'], $one[0]['count']]);
        $this->assertSame(array_search('m3', array_map(static fn (array $i): string => (string) ($i['data']['tag'] ?? ''), $plain), true), $at, 'where the newest of them was');
        $this->assertCount(count($plain) - 1, $collapsed, 'in place of the two');
        $this->assertSame(2, count(explode(',', $one[0]['id'])), 'its id every one of theirs');
        $this->assertSame(count($plain), count($fewer), 'fewer than asked: each as before');
        $this->assertSame(401, $refused->getStatusCode(), 'nobody signed in settles nothing');
        $this->assertTrue($read['read']);
        $this->assertSame(0, $feed->unreadCount('tasks'), 'reading the one read them all');
        $this->assertSame(1, $settled['settled'], 'one\'s own about what the page shows');
        $this->assertSame(2, $theirs, 'nobody else\'s');
        $this->assertStringContainsString('data-read-on-view="1200"', $bell);
    }

    /**
     * The history, narrowed (FW-488): by a type, by a span of days, either end open; the kinds a
     * person has had, labelled where the application names them; through the endpoint too.
     */
    public function testTheHistoryNarrowedByTypeAndDays(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['notifications']['types'] = ['App\\Notifications\\TaskAssigned' => 'A task given to me'];
        $feed = new Feed(self::ME, $this->db);
        $this->db->queryBuilder()->table('notifications')->insert(['id' => '50000000-0000-4000-8000-000000000001', 'type' => 'App\\Notifications\\TaskAssigned',
            'notifiable_type' => 'App\\User', 'notifiable_id' => self::ME, 'data' => json_encode(['message' => 'Old', 'tag' => 'old']), 'created_at' => '2026-09-01 10:00:00']);
        $me = new \Pramnos\User\User();
        $me->userid = self::ME;
        \Pramnos\Http\RequestIdentity::seal($me, 'test');

        // Act
        $tasks   = self::tags($feed->list(1, 20, false, null, [], false, ['type' => 'App\\Notifications\\TaskAssigned']));
        $october = self::tags($feed->list(1, 20, false, null, [], false, ['from' => '2026-10-01', 'to' => '2026-10-31']));
        $before  = self::tags($feed->list(1, 20, false, null, [], false, ['to' => '2026-09-30']));
        $kinds   = $feed->typesSeen();
        $_GET    = ['type' => 'App\\Notifications\\TaskAssigned', 'from' => '2026-10-01', 'types' => '1'];
        $answer  = json_decode((string) (new NotificationsController(Application::getInstance()))->display()->getBody(), true);

        // Assert
        $this->assertSame(['m1', 'old'], $tasks, 'by a type');
        $this->assertSame(['m5', 'm4', 'm3', 'm2', 'm1'], $october, 'by a span of days');
        $this->assertSame(['old'], $before, 'either end open');
        $this->assertSame('A task given to me', $kinds['App\\Notifications\\TaskAssigned'], 'labelled where the application names it');
        $this->assertSame('Welcome', $kinds['App\\Notifications\\Welcome'], 'else its short name');
        $this->assertSame(['m1'], self::tags($answer));
        $this->assertArrayHasKey('App\\Notifications\\TaskAssigned', $answer['types']);
    }

    /**
     * Quiet hours (FW-488, do not disturb): what would interrupt — a push, an email — is held back
     * in a person's quiet hours, on their clock, and the bell keeps it; one that must be
     * acknowledged goes through; hours that wrap past midnight hold; a time that is not one is
     * refused, and nulls clear them.
     */
    public function testQuietHoursHoldBackWhatWouldInterrupt(): void
    {
        // Arrange
        $sent = (new \Pramnos\Notification\Notifier())->registerChannel('database', RecordedDatabase::class)
            ->registerChannel('push', RecordedPush::class)->registerChannel('mail', RecordedMail::class);
        Recorded::$through = [];
        $notice = static fn (bool $ack): object => new class ($ack) implements \Pramnos\Notification\NotificationInterface {
            public function __construct(private bool $ack)
            {
            }

            /** Every channel. */
            public function via(mixed $notifiable): array
            {
                return ['database', 'push', 'mail'];
            }

            /** @return array<string, mixed> */
            public function toDatabase(mixed $notifiable): array
            {
                return ['message' => 'x'] + ($this->ack ? ['requires_ack' => true] : []);
            }
        };
        $person = (object) ['userid' => self::ME, 'timezone' => 'UTC'];
        $hour   = gmdate('H');
        \Pramnos\Notification\Preferences::setQuiet($person, $hour . ':00', sprintf('%02d:59', (int) $hour));

        // Act
        $sent->sendNow($person, $notice(false));
        $quiet = Recorded::$through;
        Recorded::$through = [];
        $sent->sendNow($person, $notice(true));
        $ack   = Recorded::$through;
        $wraps = \Pramnos\Notification\Preferences::setQuiet($person, '22:00', '07:00')
            && \Pramnos\Notification\Preferences::inQuiet($person, (int) gmmktime(23, 30, 0, 1, 1, 2027))
            && !\Pramnos\Notification\Preferences::inQuiet($person, (int) gmmktime(12, 0, 0, 1, 1, 2027));
        $bad   = \Pramnos\Notification\Preferences::setQuiet($person, '25:00', '07:00');
        \Pramnos\Notification\Preferences::setQuiet($person, null, null);
        Recorded::$through = [];
        $sent->sendNow($person, $notice(false));

        // Assert
        $this->assertSame(['database'], $quiet, 'in quiet hours: the bell alone');
        $this->assertSame(['database', 'push', 'mail'], $ack, 'one that must be acknowledged goes through');
        $this->assertTrue($wraps, 'hours past midnight');
        $this->assertFalse($bad);
        $this->assertSame(['database', 'push', 'mail'], Recorded::$through, 'cleared: everything again');
    }

    /**
     * Why it is here and what can be done from the bell (FW-488): its own `why`, or the type's
     * label where a person may turn it off, with where to; its `actions`, a path on this site
     * alone — one elsewhere left out.
     */
    public function testWhyItIsHereAndWhatCanBeDone(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['notifications']['types'] = ['App\\Notifications\\TaskAssigned' => 'A task given to me'];
        Application::getInstance()->applicationInfo['notifications']['preferences_url'] = '/Myaccount/notifications';
        $this->db->queryBuilder()->table('notifications')->insert(['id' => '40000000-0000-4000-8000-000000000001', 'type' => 'App\\Notifications\\Leave',
            'notifiable_type' => 'App\\User', 'notifiable_id' => self::ME, 'created_at' => '2026-10-15 12:00:00', 'data' => json_encode(['message' => 'Maria asks for leave',
            'why' => 'You approve leave', 'actions' => [['label' => 'Approve', 'url' => '/leave/9/approve', 'style' => 'primary'],
                ['label' => 'Decline', 'url' => '/leave/9/decline', 'style' => 'danger'], ['label' => 'Elsewhere', 'url' => 'https://evil.example/x']]])]);
        $items = array_column((new Feed(self::ME, $this->db))->list(1, 20)['items'], null, 'id');

        // Act
        $leave = $items['40000000-0000-4000-8000-000000000001'];
        $task  = $items['00000000-0000-4000-8000-000000000001'];
        $plain = $items['00000000-0000-4000-8000-000000000005'];

        // Assert
        $this->assertSame(['text' => 'You approve leave', 'url' => null], $leave['why'], 'its own words');
        $this->assertSame(['text' => 'Because you get: A task given to me', 'url' => '/Myaccount/notifications'], $task['why'], 'the type\'s, with where to turn it off');
        $this->assertNull($plain['why']);
        $this->assertSame([['label' => 'Approve', 'url' => '/leave/9/approve', 'style' => 'primary'], ['label' => 'Decline', 'url' => '/leave/9/decline', 'style' => 'danger']],
            $leave['actions'], 'a path on this site alone');
    }

    /**
     * Put off (FW-488, snooze): out of the list and its count until its moment, back unread
     * after; not one already read, nor somebody else's, nor to a moment past; the endpoint by a
     * preset name.
     */
    public function testOnePutOffComesBackLater(): void
    {
        // Arrange
        $feed = new Feed(self::ME, $this->db);
        $id   = '00000000-0000-4000-8000-000000000001';

        // Act
        $before = $feed->unreadCount();
        $done   = $feed->snooze($id, 'tomorrow');
        $hidden = [$feed->unreadCount(), in_array($id, array_column($feed->list(1, 20)['items'], 'id'), true)];
        $read   = $feed->snooze('00000000-0000-4000-8000-000000000002', '1h');   // read already
        $theirs = $feed->snooze('00000000-0000-4000-8000-000000000006', '1h');   // somebody else's
        $past   = $feed->snooze('00000000-0000-4000-8000-000000000003', time() - 5);
        $this->db->queryBuilder()->table('notifications')->where('id', $id)->update(['snoozed_until' => time() - 1]);
        $back   = $feed->unreadCount();

        // Assert
        $this->assertTrue($done);
        $this->assertSame([$before - 1, false], $hidden, 'out of the count and the list');
        $this->assertSame([false, false, false], [$read, $theirs, $past]);
        $this->assertSame($before, $back, 'back, unread, when its moment came');
    }

    /**
     * One that must be acknowledged (FW-488, `requires_ack`): flagged so on the feed, left unread
     * by "mark all read" and out of a collapsed item; a click on it alone reads it.
     */
    public function testOneThatMustBeAcknowledgedIsReadByAClickAlone(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['notifications']['collapse'] = ['tasks' => ['title' => '%d tasks', 'min' => 2]];
        $ack = '30000000-0000-4000-8000-000000000001';
        $this->db->queryBuilder()->table('notifications')->insert(['id' => $ack, 'type' => 'App\\Notifications\\TaskDue', 'notifiable_type' => 'App\\User',
            'notifiable_id' => self::ME, 'data' => json_encode(['message' => 'The deadline is today', 'requires_ack' => true]), 'created_at' => '2026-10-15 11:00:00']);
        $feed = new Feed(self::ME, $this->db);

        // Act
        $items  = array_column($feed->list(1, 20)['items'], null, 'id');
        $folded = $feed->list(1, 20, false, null, [], true)['items'];
        $marked = $feed->markAllRead();
        $left   = $feed->unreadCount();
        $read   = $feed->markRead($ack);

        // Assert
        $this->assertTrue($items[$ack]['ack'], 'flagged on the feed');
        $this->assertFalse($items['00000000-0000-4000-8000-000000000001']['ack']);
        $this->assertContains($ack, array_column($folded, 'id'), 'not folded into the collapsed item');
        $this->assertSame([4, 1], [$marked, $left], 'mark all read leaves it unread');
        $this->assertTrue($read, 'a click on it reads it');
    }

    /**
     * A person's choices (FW-488): a declared type they turned off is not sent to them at all,
     * by any channel, and the rest still are; a type not declared cannot be turned off; the bell
     * reads what stays in sight only when they chose it, after the application's delay; the
     * endpoint reads and saves them, a form comes back where it says; the form shows each type.
     */
    public function testAPersonsChoices(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['notifications']['types'] = [\Pramnos\Notification\Message::class => 'A message from the team'];
        // Kept on the person as user details; here, on the signed-in object itself.
        $me = new \Pramnos\User\User();
        $me->userid = self::ME;
        \Pramnos\Http\RequestIdentity::seal($me, 'test');
        $plain = (object) ['userid' => self::ME];
        $other = new class implements \Pramnos\Notification\NotificationInterface {
            /** No channel: only its type matters here. */
            public function via(mixed $notifiable): array
            {
                return [];
            }
        };

        // Act
        $before    = \Pramnos\Notification\Preferences::of($me);
        $declared  = \Pramnos\Notification\Preferences::set($plain, \Pramnos\Notification\Message::class, false);
        $undeclared = \Pramnos\Notification\Preferences::set($plain, 'App\\Notifications\\Other', false);
        $allowed   = \Pramnos\Notification\Preferences::allows($plain, new \Pramnos\Notification\Message('Hi', 'There'));
        $offView   = \Pramnos\Notification\Preferences::readOnView($me);
        $me->{\Pramnos\Notification\Preferences::READ_ON_VIEW} = '1';
        $onView    = \Pramnos\Notification\Preferences::readOnView($me);
        $bell      = \Pramnos\Html\NotificationBell::render();
        $form      = \Pramnos\Html\NotificationPreferences::render('/Myaccount/notifications');
        $answer    = json_decode((string) (new NotificationsController(Application::getInstance()))->preferences()->getBody(), true);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $refused   = (new NotificationsController(Application::getInstance()))->preferences()->getStatusCode();
        unset($_SERVER['REQUEST_METHOD']);

        // Assert
        $this->assertSame([['type' => \Pramnos\Notification\Message::class, 'label' => 'A message from the team', 'on' => true]], $before['types']);
        $this->assertSame([true, false], [$declared, $undeclared], 'only a declared type is turned off');
        $this->assertFalse($allowed, 'turned off: not sent');
        $this->assertTrue(\Pramnos\Notification\Preferences::allows($plain, $other), 'another type still is');
        $this->assertSame([0, 500], [$offView, $onView], 'reading what is in sight: their choice, the default delay');
        $this->assertStringContainsString('data-read-on-view="500"', $bell);
        $this->assertStringContainsString('name="types[' . htmlspecialchars(\Pramnos\Notification\Message::class) . ']"', $form);
        $this->assertStringContainsString('name="readOnView" value="1" checked', $form);
        $this->assertSame([true, true], [$answer['types'][0]['on'], $answer['readOnView']], 'the endpoint reads them');
        $this->assertSame(403, $refused, 'a change without the page\'s token');
    }

    /**
     * Read notifications past the age are removed and unread ones never are; with no age given
     * or configured, the command removes nothing and says how to set one.
     */
    public function testReadOnesPastTheirAgeArePruned(): void
    {
        // Arrange — m2 was read on 1 October; mark m1 read a moment ago
        $this->db->queryBuilder()->table('notifications')->where('id', '00000000-0000-4000-8000-000000000001')->update(['read_at' => date('Y-m-d H:i:s')]);
        $command = new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\NotificationsPrune());

        // Act
        $command->execute([]);
        $unset   = $command->getDisplay();
        $none    = Feed::prune(0, $this->db);
        Application::getInstance()->applicationInfo['notifications']['keep_read_days'] = 7;
        $command->execute([]);
        $pruned  = $command->getDisplay();
        $left    = $this->db->queryBuilder()->table('notifications')->whereIn('notifiable_id', [self::ME, self::SOMEBODY])->count();
        $command->execute(['--days' => '7']);

        // Assert
        $this->assertStringContainsString('No retention is set', $unset);
        $this->assertSame(0, $none);
        $this->assertStringContainsString('1 notification(s) read over 7 days ago removed', $pruned, 'm2 only: m1 was read just now');
        $this->assertSame(5, $left, 'the unread and the recently read stay');
        $this->assertStringContainsString('0 notification(s)', $command->getDisplay());
    }

    /**
     * The API a SPA reads: the same feed as the endpoint, in the API's envelope, from a JSON
     * body as a SPA posts one; nobody signed in is refused.
     */
    public function testTheApi(): void
    {
        // Arrange
        $api      = new class extends \Pramnos\Notification\FeedApi {
            /** @var array<string, mixed> A JSON body, as a SPA posts one */
            public array $body = [];

            protected function input(): array
            {
                return $this->body !== [] ? $this->body : parent::input();
            }
        };
        $router   = new \Pramnos\Routing\Router(null);
        \Pramnos\Notification\FeedApi::routes($router, 'api/notifications/');

        // Act
        $refused = [$api->list()['status'], $api->count()['status'], $api->read()['status'], $api->readAll()['status']];
        $me      = new \Pramnos\User\User();
        $me->userid = self::ME;
        \Pramnos\Http\RequestIdentity::seal($me, 'test');
        $_GET    = ['per_page' => '2'];
        $list    = $api->list();
        $_GET    = [];
        $counts  = $api->count();
        $api->body = ['id' => '00000000-0000-4000-8000-000000000003'];
        $read    = $api->read();
        $api->body = [];
        $_POST   = ['group' => 'system'];
        $all     = $api->readAll();
        $_POST   = [];
        $everything = $api->readAll();
        $routed  = [];
        foreach (['/api/notifications' => 'GET', '/api/notifications/count' => 'GET', '/api/notifications/read' => 'POST', '/api/notifications/readall' => 'POST'] as $uri => $method) {
            $route = $router->getMatchedRoute(\Pramnos\Http\Request::create($uri, $method));
            $routed[$uri] = $route === null ? null : (($route->action)())['status'];
        }

        // Assert
        $this->assertSame([401, 401, 401, 401], $refused, 'nobody signed in: every route refuses');
        $this->assertSame(['m5', 'm4'], array_map(static fn ($i) => $i['data']['tag'], $list['data']['items']));
        $this->assertSame(4, $counts['data']['unread']);
        $this->assertSame(['read' => true, 'unread' => 3], $read['data']);
        $this->assertSame(['marked' => 1, 'unread' => 2], $all['data']);
        $this->assertSame(['marked' => 2, 'unread' => 0], $everything['data']);
        $this->assertSame(['/api/notifications' => 200, '/api/notifications/count' => 200, '/api/notifications/read' => 200, '/api/notifications/readall' => 200], $routed, 'four routes, under the path given');
    }

    /**
     * The header's bell: nothing for somebody signed out, the bell between the given markup for
     * somebody signed in.
     */
    public function testTheHeaderButton(): void
    {
        // Act
        $out = \Pramnos\Html\NotificationBell::headerButton('<li>', '</li>');
        $me  = new \Pramnos\User\User();
        $me->userid = self::ME;
        \Pramnos\Http\RequestIdentity::seal($me, 'test');
        $in  = \Pramnos\Html\NotificationBell::headerButton('<li>', '</li>', ['class' => 'nav-link']);

        // Assert
        $this->assertSame('', $out);
        $this->assertStringStartsWith('<li><div class="pf-notify"', $in);
        $this->assertStringEndsWith('</div></li>', $in);
        $this->assertStringContainsString('pf-notify-toggle nav-link', $in);
    }

    /**
     * Where there is no notifications table: the header draws no bell, a bell drawn anyway counts
     * nothing rather than failing, and the prune says there is nothing to prune.
     */
    public function testWithoutTheTable(): void
    {
        // Arrange
        $me = new \Pramnos\User\User();
        $me->userid = self::ME;
        \Pramnos\Http\RequestIdentity::seal($me, 'test');
        $hasFeed = new \ReflectionProperty(\Pramnos\Html\NotificationBell::class, 'hasFeed');
        $table   = $this->db->schema()->quoteTable('notifications');
        $away    = $this->db->schema()->quoteTable('notifications_away');
        $this->db->query('ALTER TABLE ' . $table . ' RENAME TO ' . ($this->db->type === 'postgresql' ? 'notifications_away' : $away));
        $hasFeed->setValue(null, null);

        // Act
        try {
            $header = \Pramnos\Html\NotificationBell::headerButton('<li>', '</li>');
            $bell   = \Pramnos\Html\NotificationBell::render();
            $prune  = new \Symfony\Component\Console\Tester\CommandTester(new \Pramnos\Console\Commands\NotificationsPrune());
            $prune->execute(['--days' => '30']);
        } finally {
            $this->db->query('ALTER TABLE ' . $away . ' RENAME TO ' . ($this->db->type === 'postgresql' ? 'notifications' : $table));
            $hasFeed->setValue(null, null);
        }

        // Assert
        $this->assertSame('', $header);
        $this->assertStringNotContainsString('data-pf-notifications-count>', str_replace('data-pf-notifications-count hidden>', '', $bell), 'no count drawn');
        $this->assertStringContainsString('There is no notifications table', $prune->getDisplay());
    }
}

/** A channel that records that it was used, by its name, instead of sending. */
abstract class Recorded implements \Pramnos\Notification\ChannelInterface
{
    /** @var list<string> The channels used, in order */
    public static array $through = [];

    /** The channel's name. */
    abstract protected function name(): string;

    /** Recorded. */
    public function send(mixed $notifiable, \Pramnos\Notification\NotificationInterface $notification): void
    {
        self::$through[] = $this->name();
    }
}

/** The bell, recorded. */
class RecordedDatabase extends Recorded
{
    /** Its name. */
    protected function name(): string
    {
        return 'database';
    }
}

/** A push, recorded. */
class RecordedPush extends Recorded
{
    /** Its name. */
    protected function name(): string
    {
        return 'push';
    }
}

/** An email, recorded. */
class RecordedMail extends Recorded
{
    /** Its name. */
    protected function name(): string
    {
        return 'mail';
    }
}

