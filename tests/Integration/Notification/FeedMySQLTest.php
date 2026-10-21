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
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Notifications\CreateNotificationsTable::class], $this->db);
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
        Application::getInstance()->applicationInfo = $this->info;
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
