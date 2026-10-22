<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Chat;
use Pramnos\Application\Controllers\ConversationFiles;
use Pramnos\Application\Controllers\Conversations as Page;
use Pramnos\Application\Settings;
use Pramnos\Conversations\Access;
use Pramnos\Conversations\Client;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Dock;
use Pramnos\Conversations\Files;
use Pramnos\Conversations\Messages;
use Pramnos\Conversations\People;
use Pramnos\Http\AdminArea;
use Pramnos\Http\Request;
use Pramnos\Http\RequestIdentity;

/**
 * What a browser is given, on each backend: the conversations page and what `pf-conversations.js`
 * starts from; the people to mention or write to; the sidebar's titles and a conversation's members;
 * the dock over the administration's other pages — and where it is not drawn; the files screen —
 * the space, the limit, removing in bulk with a report and one at a time.
 */
#[CoversClass(Page::class)]
#[CoversClass(Chat::class)]
#[CoversClass(Client::class)]
#[CoversClass(Dock::class)]
#[CoversClass(ConversationFiles::class)]
#[CoversClass(People::class)]
#[CoversClass(Access::class)]
class ConversationsPageTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The tree files are kept in for this test. */
    private string $tree = '';

    /** The request, the signed-in account, the area, the tree, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        $_GET = $_POST = [];
        Request::setRawInput(null);
        RequestIdentity::reset();
        AdminArea::reset();
        Settings::setSetting(Files::SETTING, 0, false);
        if ($this->tree !== '' && is_dir($this->tree)) {
            exec('rm -rf ' . escapeshellarg($this->tree));
        }
        unset(Application::getInstance()->applicationInfo['features']);
        $this->probeTearDown();
    }

    /** The conversations and the usertype floor of 30, for every test here. */
    private function team(array $more = []): void
    {
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30] + $more;
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
    }

    /**
     * A controller whose account, view and redirect the test holds.
     *
     * @param class-string<HeldPage|HeldFiles> $class
     */
    private function controller(string $class, ?string $name): HeldPage|HeldFiles
    {
        $made         = new $class(Application::getInstance());
        $made->asking = $name === null ? null : $this->user($name);

        return $made;
    }

    /**
     * The basic page: drawn for somebody who may use the conversations, with what its script starts
     * from — where the JSON is, who is reading, their channel, its words translated; anybody else,
     * or an application without the feature, is sent back. The dock's client starts from more: the
     * limits, the emoji, every word, the files screen only for whoever manages files.
     */
    #[DataProvider('databases')]
    public function testThePageAndWhatTheClientStartsFrom(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();

        // Act
        $nikos = $this->controller(HeldPage::class, 'nikos');
        $shown = $nikos->display();
        $dock  = Client::props($this->user('nikos'), 'dock');
        $root  = Client::props($this->user('root'), 'dock');
        $tasos = $this->controller(HeldPage::class, 'tasos');
        $tasos->display();
        Application::getInstance()->applicationInfo['features'] = [];
        $off = $this->controller(HeldPage::class, 'nikos');
        $off->display();

        // Assert
        $this->assertSame('rendered', $shown);
        $this->assertSame('conversations', $nikos->view->name);
        $props = $nikos->view->props;
        $this->assertSame([$this->ids['nikos'], 'Νίκος Π.', 'private-users.' . $this->ids['nikos']], [$props['me']['id'], $props['me']['name'], $props['userChannel']]);
        $this->assertStringEndsWith('Conversations/', $props['base']);
        $this->assertSame('No conversations yet.', $props['strings']['No conversations yet.'], 'its words, as the reader\'s language has them');
        $this->assertSame(['dock', 25, '👍'], [$dock['mode'], $dock['maxFileMb'], $dock['emoji']['thumbsup']]);
        $this->assertNull($dock['files'], 'sales do not manage the files');
        $this->assertNotNull($root['files'], 'root does');
        $this->assertSame('Conversations', $dock['strings']['Conversations']);
        $this->assertGreaterThan(50, count(Client::strings()));
        $this->assertNotNull($tasos->sentTo, 'a translator is sent back');
        $this->assertNotNull($off->sentTo, 'and everybody, where the application has no conversations');
        $this->assertInstanceOf(Page::class, new Chat(Application::getInstance()), '/admin/Chat is the same screen');
    }

    /**
     * The application's own links at the foot of the list, as it configures them — already in the
     * reader's language — each to whoever passes its ability or its own check; one without a label
     * or an address is no link.
     */
    #[DataProvider('databases')]
    public function testTheApplicationsLinksAtTheFoot(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();
        $nikos = $this->ids['nikos'];
        Application::getInstance()->applicationInfo['conversations']['links'] = [
            ['label' => 'Αρχείο', 'url' => '/admin/Archive', 'icon' => '⇄'],
            ['label' => 'Για τη διοίκηση', 'url' => '/admin/Board', 'ability' => 'admin.board', 'usertype' => 98],
            ['label' => 'Μόνο ο Νίκος', 'url' => '/admin/Nikos', 'visible' => static fn (object $me): bool => (int) $me->userid === $nikos],
            ['label' => '', 'url' => '/nowhere'],
            'not a link',
        ];

        // Act
        $sales = Client::props($this->user('nikos'))['links'];
        $root  = Client::props($this->user('root'))['links'];

        // Assert
        $this->assertSame([['label' => 'Αρχείο', 'url' => '/admin/Archive', 'icon' => '⇄'], ['label' => 'Μόνο ο Νίκος', 'url' => '/admin/Nikos', 'icon' => '']], $sales,
            'below the ability\'s floor, without it');
        $this->assertSame(['Αρχείο', 'Για τη διοίκηση'], array_column($root, 'label'), 'root has the ability; the check is somebody else\'s');
    }

    /**
     * The conversations as an app, where `conversations.app` is set — for an application that draws
     * its own page and manifest: the name, short name, colours and icons, with defaults; nothing
     * without the setting.
     */
    #[DataProvider('databases')]
    public function testTheAppsNameAndIcons(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();

        // Act
        $none = Client::app();
        Application::getInstance()->applicationInfo['conversations']['app'] = ['name' => 'Team Chat',
            'icons' => [['src' => '/chat-192.png', 'sizes' => '192x192', 'type' => 'image/png']]];
        $app = Client::app();

        // Assert
        $this->assertNull($none, 'no setting, no app');
        $this->assertSame(['Team Chat', 'Team Chat', '#1f2937', '/chat-192.png'], [$app['name'], $app['short_name'], $app['theme_color'], $app['icons'][0]['src']]);
    }

    /**
     * Inactive people through the page: the sidebar says which of a conversation's others are
     * inactive and since when, a conversation's members carry it, and `people` leaves them out
     * unless `inactive` asks for them.
     */
    #[DataProvider('databases')]
    public function testInactivePeopleThroughThePage(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();
        Application::getInstance()->applicationInfo['conversations']['inactive_after_days'] = 90;
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $long = time() - 400 * 86400;
        $this->db->queryBuilder()->table('users')->where('userid', $nikos)->update(['lastlogin' => $long, 'regdate' => $long]);
        $group = Conversations::create('group', null, [$maria, $nikos], $eleni);
        $dm    = Conversations::create('dm', null, [$maria], $eleni);

        // Act
        $sidebar = $this->ask('eleni', 'conversations');
        $members = $this->ask('eleni', 'messages', $group);
        $plain   = $this->ask('eleni', 'people', null, ['q' => 'ν']);
        $asked   = $this->ask('eleni', 'people', null, ['q' => 'ν', 'inactive' => 1]);

        // Assert
        $byId = array_column($sidebar['body']['conversations'], null, 'id');
        $this->assertEquals([(string) $nikos => $long], (array) $byId[$group]['inactive'], 'the group: nikos, since when');
        $this->assertEquals([], (array) $byId[$dm]['inactive'], 'maria is active');
        $this->assertSame([$long], array_values(array_filter(array_column($members['body']['members'], 'inactive'))));
        $this->assertNotContains($nikos, array_column($plain['body']['people'], 'id'));
        $this->assertContains($nikos, array_column($asked['body']['people'], 'id'));
    }

    /**
     * The sidebar's titles — a channel by its name, a direct conversation by the other person, a
     * group by the others — the people's names, a conversation's members; and the people to write
     * to, by name, only those who may use the conversations.
     */
    #[DataProvider('databases')]
    public function testTitlesMembersAndPeople(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        Conversations::create('dm', null, [$maria], $eleni);
        Conversations::create('group', null, [$maria, $nikos], $eleni);
        Messages::post($general, $eleni, 'γεια');

        // Act
        $sidebar = $this->ask('eleni', 'conversations');
        $members = $this->ask('eleni', 'messages', $general);
        $people  = $this->ask('eleni', 'people', null, ['q' => 'ν']);
        $all     = People::search('');

        // Assert
        $this->assertEqualsCanonicalizing(['#general', 'Μαρία Κ.', 'Μαρία Κ., Νίκος Π.'], array_column($sidebar['body']['conversations'], 'title'));
        $this->assertSame('Νίκος Π.', $sidebar['body']['people'][$nikos]);
        $this->assertSame([['id' => $eleni, 'name' => 'Ελένη Σ.', 'inactive' => null], ['id' => $maria, 'name' => 'Μαρία Κ.', 'inactive' => null]],
            $members['body']['members'], 'nobody inactive: the setting is off');
        $this->assertSame(['Ελένη Σ.', 'Νίκος Π.'], array_column($people['body']['people'], 'name'), 'by a letter in the name');
        $this->assertNotContains('Τάσος', array_column($all, 'name'), 'a translator may not use them');
        $this->assertContains($this->ids['root'], array_column($all, 'id'), 'root may, by the username where there is no name');
        $this->assertSame(1, count(People::search('', 1)), 'no more than asked');
    }

    /**
     * The dock: drawn over the administration's pages for somebody who may use the conversations,
     * with the client's start; nothing for a translator, where the application turned it off or has
     * no conversations, outside the administration, or on the conversations page itself.
     */
    #[DataProvider('databases')]
    public function testTheDockAndWhereItIsNotDrawn(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->team();

        // Act
        RequestIdentity::seal($this->user('nikos'));
        $drawn = Dock::render();
        RequestIdentity::reset();
        RequestIdentity::seal($this->user('tasos'));
        $translator = Dock::render();
        RequestIdentity::reset();
        RequestIdentity::seal($this->user('nikos'));
        $this->team(['dock' => false]);
        $turnedOff = Dock::render();
        $this->team();
        $_SERVER['REQUEST_URI'] = '/products';
        AdminArea::detect('admin');
        $site = Dock::render();
        AdminArea::reset();
        $document = \Pramnos\Framework\Factory::getDocument();
        $before = $document->bodyclasses;
        $document->bodyclasses[] = 'pf-conv-page';
        $onThePage = Dock::render();
        $document->bodyclasses = $before;
        unset($_SERVER['REQUEST_URI']);

        // Assert
        $this->assertStringContainsString('data-pf-conversations-dock', $drawn);
        $this->assertStringContainsString('&quot;mode&quot;:&quot;dock&quot;', $drawn, 'the client starts in the dock');
        $this->assertStringContainsString('pf-conversations.js', $drawn);
        $this->assertSame(['', '', '', ''], [$translator, $turnedOff, $site, $onThePage]);
    }

    /**
     * The files screen: the space, by conversation and month, the largest and the oldest; the limit
     * changed, and refused outside 1–1024; old files removed with a report; one file removed, and a
     * file not there said so; a superuser's floor.
     */
    #[DataProvider('databases')]
    public function testTheFilesScreen(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->tree = sys_get_temp_dir() . '/pfchat-' . bin2hex(random_bytes(4));
        $this->team(['files_path' => $this->tree]);
        $general = Conversations::create('channel', 'general', [], $this->ids['eleni']);
        $keep    = static function (string $bytes): string {
            $tmp = (string) tempnam(sys_get_temp_dir(), 'pfchat');
            file_put_contents($tmp, $bytes);

            return $tmp;
        };
        $old = Files::store($general, $this->ids['eleni'], $keep('παλιό αρχείο'), 'old.txt');
        $new = Files::store($general, $this->ids['eleni'], $keep('νέο'), 'new.txt');
        $this->db->queryBuilder()->table('conversation_files')->where('fileid', $old)->update(['createdat' => time() - 400 * 86400]);
        $screen = $this->controller(HeldFiles::class, 'root');

        // Act
        $screen->display();
        $view = $screen->view;
        $_POST = ['mb' => '40'];
        $screen->limit();
        $limit = Files::maxMegabytes();
        $_POST = ['mb' => '5000'];
        $screen->limit();
        $_POST = ['days' => '-1'];
        $screen->prune();
        $_POST = ['days' => '365', 'conversation' => (string) $general];
        $screen->prune();
        $_GET = ['_option' => (string) $new];
        $screen->remove();
        $_GET = ['_option' => '424242'];
        $screen->remove();
        $floor = (new \ReflectionMethod(ConversationFiles::class, 'adminFloor'))->invoke(new ConversationFiles(Application::getInstance()));

        // Assert
        $this->assertSame('conversationfiles', $view->name);
        $this->assertSame(['files' => 2, 'bytes' => strlen('παλιό αρχείο') + strlen('νέο')], $view->usage['total']);
        $this->assertSame([$general => '#general'], $view->conversations);
        $this->assertSame('Ελένη Σ.', $view->people[$this->ids['eleni']]);
        $this->assertSame([25, 40], [$view->limit, $limit], 'the setting an administrator changes');
        $this->assertSame(40, Files::maxMegabytes(), '5000 refused');
        $this->assertNotNull(Files::get($old)['removedAt'], 'old: removed in bulk');
        $this->assertNotNull(Files::get($new)['removedAt'], 'and one at a time');
        $this->assertStringEndsWith('ConversationFiles', (string) $screen->sentTo);
        $this->assertSame(\Pramnos\Auth\AdminAccess::superuserUsertype(), $floor);
        $this->assertSame(['512 B', '2,0 KB', '1,5 MB', '3,0 GB'], array_map([ConversationFiles::class, 'bytes'], [512, 2048, 1572864, 3221225472]));
        $this->assertTrue(Access::managesFiles($this->user('root')));
        $this->assertFalse(Access::managesFiles($this->user('nikos')), 'a superuser\'s by default');
    }

    /** The JSON as `$name` asks it, for `$id` in the path, with these query values. */
    private function ask(string $name, string $action, ?int $id = null, array $query = []): array
    {
        $_GET = ($id === null ? [] : ['_option' => (string) $id]) + $query;
        Request::setRawInput('');
        $page = $this->controller(HeldPage::class, $name);
        $response = $page->{$action}();

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true)];
    }
}
