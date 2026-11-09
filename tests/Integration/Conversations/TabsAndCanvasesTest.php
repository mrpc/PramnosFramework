<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\CanvasMention;
use Pramnos\Conversations\Canvases;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Details;
use Pramnos\Conversations\Tabs;
use Pramnos\Framework\Migrations\Conversations\AddDetailsToConversations;
use Pramnos\Framework\Migrations\Conversations\AddImageToConversations;
use Pramnos\Framework\Migrations\Conversations\CreateConversationCanvasesTable;

/**
 * A conversation's tabs and canvases (FW-370), against MySQL with and without a prefix and
 * PostgreSQL: tabs added, ordered, hidden and removed as `tabsBy` allows; canvases made with a tab,
 * edited by one person at a time under a lock that lapses when its heartbeat stops, saved as
 * versions and restored, cleaned, mentioning people once, found by search, and deleted.
 */
#[CoversClass(Tabs::class)]
#[CoversClass(Canvases::class)]
#[CoversClass(CanvasMention::class)]
#[CoversClass(\Pramnos\Html\SafeHtml::class)]
#[CoversClass(\Pramnos\Application\Controllers\Conversations::class)]
#[CoversClass(CreateConversationCanvasesTable::class)]
class TabsAndCanvasesTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The probe with the details' columns and the canvases' table. */
    private function ready(string $type, string $host, int $port, string $user): void
    {
        $this->boot($type, $host, $port, $user);
        foreach (['2026_11_09_000013_add_image_to_conversations', '2026_11_09_000014_add_details_to_conversations', '2026_11_09_000015_create_conversation_canvases_table'] as $file) {
            require_once ROOT . '/database/migrations/framework/conversations/' . $file . '.php';
        }
        \Pramnos\Framework\Testing\Schema::ensure([AddImageToConversations::class, AddDetailsToConversations::class, CreateConversationCanvasesTable::class], $this->db);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
    }

    /** The request cleared, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        $_GET = $_POST = [];
        \Pramnos\Http\Request::setRawInput('');
        $this->probeTearDown();
    }

    /** What a refusal says, or 'ok'. */
    private static function refused(callable $act): string
    {
        try {
            $act();

            return 'ok';
        } catch (\DomainException|\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Tabs: the built-in three at first; a link added by a member while everyone manages them,
     * refused once only admins do, and a bad address refused; the order kept, those not named after;
     * `messages` never hidden; a built-in removed is hidden; a link removed is gone.
     */
    #[DataProvider('databases')]
    public function testTabs(string $type, string $host, int $port, string $user): void
    {
        // Arrange — maria owns #launch, nikos is a member
        $this->ready($type, $host, $port, $user);
        [$maria, $nikos] = [$this->ids['maria'], $this->ids['nikos']];
        $launch = Conversations::create('channel', 'launch', [$nikos], $maria);

        // Act
        $first   = array_column(Tabs::of($launch), 'id');
        $link    = Tabs::add($launch, 'link', $nikos, 'Roadmap', 'https://roadmap.test/q4');
        $badLink = self::refused(fn () => Tabs::add($launch, 'link', $nikos, 'x', 'javascript:alert(1)'));
        Tabs::order($launch, [$link, 'files'], $nikos);
        $ordered = array_column(Tabs::of($launch), 'id');
        $linked  = array_column(Tabs::of($launch), null, 'id')[$link];
        $noHide  = self::refused(fn () => Tabs::hide($launch, 'messages', true, $maria));
        Tabs::remove($launch, 'pins', $nikos);
        $pins    = array_column(Tabs::of($launch), 'hidden', 'id')['pins'];
        Details::settings($launch, ['tabsBy' => 'admins'], $maria);
        $member  = self::refused(fn () => Tabs::remove($launch, $link, $nikos));
        Tabs::remove($launch, $link, $maria);

        // Assert
        $this->assertSame(['messages', 'files', 'pins'], $first);
        $this->assertStringStartsWith('link:', $link);
        $this->assertSame('A tab\'s link is a web address or a path from /.', $badLink);
        $this->assertSame([$link, 'files', 'messages', 'pins'], $ordered, 'those named first, the rest after');
        $this->assertSame('The messages are always there.', $noHide);
        $this->assertTrue($pins, 'a built-in one removed is hidden');
        $this->assertSame('Only its admins manage this conversation\'s tabs.', $member);
        $this->assertSame(['files', 'messages', 'pins'], array_column(Tabs::of($launch), 'id'));
        $this->assertSame(['Roadmap', 'https://roadmap.test/q4'], [$linked['title'], $linked['url']]);
    }

    /**
     * Canvases: made with a tab; a non-member refused; one editor at a time — the other refused
     * while the heartbeat lives, given the lock once it stops; a save without the lock takes it if
     * it is free; each save a version, the body cleaned of a script and its checklist and mention
     * kept; the person mentioned told once, not again on the next save; an old version restored as
     * the newest; found by a word of its text; deleted by its author, its tab with it.
     */
    #[DataProvider('databases')]
    public function testCanvases(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$maria, $nikos, $tasos] = [$this->ids['maria'], $this->ids['nikos'], $this->ids['tasos']];
        $launch = Conversations::create('channel', 'launch', [$nikos, $tasos], $maria);
        $this->db->queryBuilder()->table('notifications')->delete();
        $now = time();

        // Act
        $outsider = self::refused(fn () => Canvases::create($launch, 'x', $this->ids['eleni']));
        $id       = Canvases::create($launch, '', $maria);
        $tabbed   = in_array('canvas:' . $id, array_column(Tabs::of($launch), 'id'), true);
        $mine     = Canvases::lock($id, $maria, $now);
        $theirs   = Canvases::lock($id, $nikos, $now + 10);
        $blocked  = self::refused(fn () => Canvases::save($id, 'Plan', '<p>x</p>', $nikos, $now + 10));
        $one      = Canvases::save($id, 'Plan', '<h1>Plan</h1><ul data-type="taskList"><li data-type="taskItem" data-checked="true"><p>Brief</p></li></ul>'
            . '<p>Ask <span data-type="mention" data-id="' . $nikos . '" data-label="Νίκος">@Νίκος</span></p><script>alert(1)</script>', $maria, $now + 15);
        $two      = Canvases::save($id, 'Plan v2', Canvases::get($id)['body'] . '<p>More</p>', $maria, $now + 20);
        $lapsed   = Canvases::lock($id, $nikos, $now + 20 + Canvases::LOCK + 1);
        $three    = Canvases::save($id, 'Plan v3', '<p>Short</p>', $nikos, $now + 70);
        $versions = array_column(Canvases::versions($id), 'version');
        $restored = Canvases::restore($id, 1, $nikos);
        $body     = Canvases::get($id)['body'];
        $told     = $this->db->queryBuilder()->table('notifications')->where('notifiable_id', $nikos)->count();
        $found    = array_column(Canvases::search($this->user('nikos'), 'Brief'), 'id');
        $hidden   = Canvases::search($this->user('eleni'), 'nothing-like-this');
        $notTasos = self::refused(fn () => Canvases::delete($id, $tasos));
        Canvases::unlock($id, $nikos);
        Canvases::delete($id, $maria);

        // Assert
        $this->assertSame('Only its members edit a conversation\'s canvases.', $outsider);
        $this->assertTrue($tabbed, 'a tab for it');
        $this->assertSame([true, false], [$mine, $theirs], 'one editor at a time');
        $this->assertStringContainsString('is editing this canvas', $blocked);
        $this->assertSame([1, 2, 3, 4], [$one, $two, $three, $restored]);
        $this->assertTrue($lapsed, 'the heartbeat stopped: the lock is free');
        $this->assertSame([3, 2, 1], $versions, 'newest first');
        $this->assertStringNotContainsString('<script', $body, 'cleaned');
        $this->assertStringContainsString('data-type="taskItem"', $body, 'the checklist kept');
        $this->assertStringContainsString('data-checked="true"', $body);
        $this->assertStringContainsString('data-type="mention"', $body, 'the mention kept');
        $this->assertSame(1, $told, 'mentioned once: told once');
        $this->assertSame([$id], $found);
        $this->assertSame([], $hidden);
        $this->assertSame('Only its author, or an admin of the conversation, deletes a canvas.', $notTasos);
        $this->assertNull(Canvases::get($id));
        $this->assertNotContains('canvas:' . $id, array_column(Tabs::of($launch), 'id'), 'its tab gone with it');
    }

    /**
     * Through the screen: a canvas made, locked, saved, its versions listed, an old one read and
     * restored; another person told who edits it; the tabs read and changed; search finding the
     * canvas beside the messages; a canvas in a conversation the reader does not read a 404.
     */
    #[DataProvider('databases')]
    public function testThroughTheScreen(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$maria, $nikos] = [$this->ids['maria'], $this->ids['nikos']];
        $launch  = Conversations::create('channel', 'launch', [$nikos], $maria);
        $private = Conversations::create('private', 'board', [], $maria);
        \Pramnos\Broadcasting\Testing\FakeDriver::swap();

        try {
            // Act
            $made     = $this->ask('maria', 'newcanvas', $launch, ['title' => 'Plan']);
            $id       = (int) $made['body']['canvas']['id'];
            $locked   = $this->ask('maria', 'lockcanvas', $id, []);
            $other    = $this->ask('nikos', 'lockcanvas', $id, []);
            $saved    = $this->ask('maria', 'savecanvas', $id, ['title' => 'Plan', 'body' => '<p>Everything about it</p>']);
            $this->ask('maria', 'savecanvas', $id, ['title' => 'Plan', 'body' => '<p>Changed</p>']);
            $read     = $this->ask('nikos', 'canvas', $id, ['version' => 1]);
            $mine     = $this->ask('maria', 'canvas', $id, []);
            $versions = $this->ask('nikos', 'canvasversions', $id, []);
            $restored = $this->ask('maria', 'restorecanvas', $id, ['version' => 1]);
            $this->ask('maria', 'unlockcanvas', $id, []);
            $tabs     = $this->ask('nikos', 'tabs', $launch, []);
            $added    = $this->ask('nikos', 'addtab', $launch, ['kind' => 'link', 'title' => 'Docs', 'url' => '/admin/Docs']);
            $hidden   = $this->ask('maria', 'hidetab', $launch, ['tab' => 'files', 'on' => '1']);
            $ordered  = $this->ask('maria', 'ordertabs', $launch, ['order' => ['canvas:' . $id]]);
            $removed  = $this->ask('maria', 'removetab', $launch, ['tab' => 'pins']);
            $list     = $this->ask('nikos', 'canvases', $launch, []);
            $search   = $this->ask('nikos', 'search', 0, ['q' => 'Everything']);
            $secret   = $this->ask('maria', 'newcanvas', $private, ['title' => 'Secret']);
            $hiddenTo = $this->ask('nikos', 'canvas', (int) $secret['body']['canvas']['id'], []);
            $deleted  = $this->ask('maria', 'deletecanvas', $id, []);

            // Assert
            $this->assertSame([true, false, 'Μαρία Κ.'], [$locked['body']['locked'], $other['body']['locked'], $other['body']['editorName']]);
            $this->assertSame(1, $saved['body']['version']);
            $this->assertSame('<p>Everything about it</p>', $read['body']['version']['body']);
            $this->assertSame($maria, $read['body']['canvas']['lockedBy'], 'who is editing it');
            $this->assertSame([false, true], [$read['body']['canvas']['deletable'], $mine['body']['canvas']['deletable']], 'Delete offered to its author, not to a member');
            $this->assertSame([2, 1], array_column($versions['body']['versions'], 'version'));
            $this->assertIsInt($versions['body']['versions'][0]['at'], 'a moment, for the reader\'s own format');
            $this->assertGreaterThan(time() - 3600, $versions['body']['versions'][0]['at']);
            $this->assertSame(3, $restored['body']['version']);
            $this->assertTrue($tabs['body']['manages']);
            $this->assertContains('canvas:' . $id, array_column($tabs['body']['tabs'], 'id'));
            $this->assertSame(200, $added['status']);
            $this->assertTrue(array_column($hidden['body']['tabs'], 'hidden', 'id')['files']);
            $this->assertSame('canvas:' . $id, $ordered['body']['tabs'][0]['id']);
            $this->assertTrue(array_column($removed['body']['tabs'], 'hidden', 'id')['pins']);
            $this->assertSame([$id], array_column($list['body']['canvases'], 'id'));
            $this->assertArrayNotHasKey('body', $list['body']['canvases'][0], 'listed without their bodies');
            $this->assertSame([$id], array_column($search['body']['canvases'], 'id'));
            $this->assertSame(404, $hiddenTo['status'], 'a private conversation\'s canvas');
            $this->assertSame(200, $deleted['status']);
        } finally {
            \Pramnos\Broadcasting\Testing\FakeDriver::restore();
        }
    }

    /**
     * One of the controller's actions, asked by `$name` with these values; its status and answer.
     *
     * @param array<string, mixed> $values
     * @return array{status: int, body: mixed}
     */
    private function ask(string $name, string $action, int $id, array $values): array
    {
        $_GET  = ['_option' => (string) $id] + array_map(static fn ($v) => is_array($v) ? $v : (string) $v, $values);
        $_POST = [];
        \Pramnos\Http\Request::setRawInput((string) json_encode($values));
        $chat = new class (Application::getInstance()) extends \Pramnos\Application\Controllers\Conversations {
            /** Who asks, set by the test. */
            public ?object $asking = null;

            /** The test's account, not a session. */
            protected function me(): ?object
            {
                return $this->asking;
            }
        };
        $chat->asking = $this->user($name);
        $response     = $chat->{$action}();

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true)];
    }
}
