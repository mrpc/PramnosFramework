<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Broadcasting\Testing\FakeDriver;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Images;

/**
 * A channel's, a system channel's or a group's own picture: kept square at 256 a side, its address
 * in the conversation versioned, the old file gone when replaced, the application's default by a
 * channel's name until one is set; set by a channel's owner or a group's members only, never on a
 * direct conversation, never an SVG; told to the members.
 */
#[CoversClass(Images::class)]
#[CoversClass(Conversations::class)]
#[CoversClass(\Pramnos\Application\Controllers\Conversations::class)]
#[CoversClass(\Pramnos\Framework\Migrations\Conversations\AddImageToConversations::class)]
class ConversationImagesTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** Where this test keeps its pictures. */
    private string $files = '';

    /** The pictures and the fake broadcasting gone, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        FakeDriver::restore();
        if ($this->files !== '') {
            exec('rm -rf ' . escapeshellarg($this->files));
        }
        $this->probeTearDown();
    }

    /**
     * A wide PNG set on a channel by its owner is kept 256 × 256 from its middle, its address in the
     * conversation with a version; a second replaces the first, whose file goes; cleared, the
     * channel shows the application's default for its name, and a channel with none shows nothing.
     * A member who is not its owner, a direct conversation and an SVG are refused; any member sets a
     * group's. Each change is told as `conversation.changed`.
     */
    #[DataProvider('databases')]
    public function testAChannelOrAGroupHasAPictureOfItsOwn(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        require_once ROOT . '/database/migrations/framework/conversations/2026_11_09_000013_add_image_to_conversations.php';
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Conversations\AddImageToConversations::class], $this->db);
        $this->files = sys_get_temp_dir() . '/pf-images-' . bin2hex(random_bytes(4));
        $default     = $this->picture(64, 64);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->files, 'images' => ['general' => $default]];
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        $general = Conversations::create('channel', 'general', [$maria], $eleni);
        $random  = Conversations::create('channel', 'random', [$maria], $eleni);
        $group   = Conversations::create('group', null, [$maria, $nikos], $eleni);
        $direct  = Conversations::create('dm', null, [$maria], $eleni);
        $fake    = FakeDriver::swap();

        // Act
        $before = [Conversations::get($general)['image'], Conversations::get($random)['image']];
        Images::set($random, $this->picture(600, 300), $eleni);
        $own    = [Conversations::get($general)['imageOwn'], Conversations::get($random)['imageOwn']];
        $first     = glob($this->files . '/images/conversation-' . $random . '-*.png');
        $kept      = array_slice((array) getimagesize($first[0] ?? ''), 0, 2);
        $address   = Conversations::get($random)['image'];
        Images::set($random, $this->picture(100, 100), $eleni);
        $second    = glob($this->files . '/images/conversation-' . $random . '-*.png');
        Images::set($group, $this->picture(100, 100), $nikos);
        $refused = [];
        foreach ([[$random, $this->picture(50, 50), $maria], [$direct, $this->picture(50, 50), $eleni], [$random, $this->text('<svg xmlns="http://www.w3.org/2000/svg"/>'), $eleni]] as $i => [$id, $file, $by]) {
            try {
                Images::set($id, $file, $by);
            } catch (\DomainException|\InvalidArgumentException $e) {
                $refused[$i] = $e::class;
            }
        }
        Images::clear($random, $eleni);
        $cleared = Conversations::get($random)['image'];
        $listed  = array_column(Conversations::forPerson($maria), 'image', 'id');

        // Assert
        $this->assertStringContainsString('Conversations/image?id=' . $general . '&v=', (string) $before[0], 'the application\'s default by its name');
        $this->assertNull($before[1], 'no picture, no default: none');
        $this->assertSame([false, true], $own, 'the default is not its own; an uploaded one is');
        $this->assertSame([256, 256], $kept, 'kept square, 256 a side');
        $this->assertStringContainsString('Conversations/image?id=' . $random . '&v=', (string) $address);
        $this->assertCount(1, $second, 'the first file gone when replaced');
        $this->assertNotSame($first, $second);
        $this->assertSame([0 => \DomainException::class, 1 => \DomainException::class, 2 => \InvalidArgumentException::class], $refused,
            'a member who is not its owner; a direct conversation; an SVG');
        $this->assertNull($cleared, 'cleared: none again');
        $this->assertSame([], glob($this->files . '/images/conversation-' . $random . '-*'), 'and its file gone');
        $this->assertNotNull($listed[$group], 'a group\'s, set by a member, in the list');
        $this->assertNull($listed[$direct], 'a direct conversation has none');
        $this->assertTrue($fake->hasBroadcast(null, 'conversation.changed'), 'told to the members');
    }

    /**
     * Through the screen: `setimage` with the upload `image` by the owner (200, the address), by a
     * member who is not (404, unchanged), with nothing sent (422); `image` sends the picture —
     * the request ends there — to whoever may use the conversations, and to anybody only where
     * `images_public` says so; `clearimage` takes it away.
     */
    #[DataProvider('databases')]
    public function testThroughTheScreen(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        require_once ROOT . '/database/migrations/framework/conversations/2026_11_09_000013_add_image_to_conversations.php';
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Conversations\AddImageToConversations::class], $this->db);
        $this->files = sys_get_temp_dir() . '/pf-images-' . bin2hex(random_bytes(4));
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->files];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        $room = Conversations::create('channel', 'general', [$this->ids['maria']], $this->ids['eleni']);
        FakeDriver::swap();
        $upload = fn (): array => ['image' => ['tmp_name' => $this->picture(80, 80), 'name' => 'x.png', 'error' => UPLOAD_ERR_OK]];

        try {
            // Act
            $_FILES  = $upload();
            $set     = $this->ask('eleni', 'setimage', $room);
            $_FILES  = $upload();
            $refused = $this->ask('maria', 'setimage', $room);
            $_FILES  = [];
            $nothing = $this->ask('eleni', 'setimage', $room);
            $_GET    = ['id' => (string) $room];
            $sent    = $this->send('maria');
            $hidden  = $this->send('');
            Application::getInstance()->applicationInfo['conversations']['images_public'] = true;
            $public  = $this->send('');
            $cleared = $this->ask('eleni', 'clearimage', $room);
            $_GET    = ['id' => (string) $room];
            $gone    = $this->send('maria');

            // Assert
            $this->assertSame(200, $set['status']);
            $this->assertStringContainsString('Conversations/image?id=' . $room, (string) $set['body']['image']);
            $this->assertSame(404, $refused['status'], 'a member who is not its owner');
            $this->assertSame(422, $nothing['status'], 'nothing sent');
            $this->assertSame([true, false, true, false], [$sent, $hidden, $public, $gone], 'sent to a reader; to nobody without a session unless public; none once cleared');
            $this->assertSame([200, null], [$cleared['status'], $cleared['body']['image']]);
            $screen = new \Pramnos\Application\Controllers\Conversations();
            $this->assertContains('image', $screen->actions, 'a public action: an <img> with no session reaches image(), not the sign-in');
            $this->assertNotContains('image', $screen->actions_auth);
        } finally {
            $_FILES = [];
            $_GET   = [];
        }
    }

    /** One of the controller's actions, as `$name`; its status and answer. */
    private function ask(string $name, string $action, int $id): array
    {
        $_GET  = ['_option' => (string) $id];
        $_POST = [];
        \Pramnos\Http\Request::setRawInput('');
        $response = $this->screen($name)->{$action}();

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true)];
    }

    /** Whether `image` sent the picture to `$name` (nobody for ''): it ends the request when it does. */
    private function send(string $name): bool
    {
        ob_start();
        try {
            $answer = $this->screen($name)->image();

            return !($answer instanceof \Pramnos\Http\Response);
        } catch (\Pramnos\Application\ApplicationClosedException) {
            return true;
        } finally {
            ob_end_clean();
        }
    }

    /** The conversations' screen, asked by `$name` (nobody for ''). */
    private function screen(string $name): \Pramnos\Application\Controllers\Conversations
    {
        $chat = new class (Application::getInstance()) extends \Pramnos\Application\Controllers\Conversations {
            /** Who asks, set by the test. */
            public ?object $asking = null;

            /** The test's account, not a session. */
            protected function me(): ?object
            {
                return $this->asking;
            }
        };
        $chat->asking = $name === '' ? null : $this->user($name);

        return $chat;
    }

    /** A square PNG of this size, as a temporary file. */
    private function picture(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 120, 200));
        $file = tempnam(sys_get_temp_dir(), 'pf-image');
        imagepng($image, $file);

        return $file;
    }

    /** A temporary file with this text. */
    private function text(string $text): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pf-image');
        file_put_contents($file, $text);

        return $file;
    }
}
