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
            ['m3', 'App\\Notifications\\TaskDue', self::ME, ['message' => 'Due today'], null],
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
    }
}

