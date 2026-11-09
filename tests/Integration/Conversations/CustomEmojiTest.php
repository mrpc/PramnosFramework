<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Broadcasting\Testing\FakeDriver;
use Pramnos\Conversations\Access;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\CustomEmoji;
use Pramnos\Conversations\Markdown;
use Pramnos\Conversations\Messages;

/**
 * The team's own emoji: a picture under a name, kept at emoji size, written `:name:` in a message
 * and reacted with; another name for it, a new name, and gone — where it was written it reads as
 * text again. Each change told to everyone with the conversations open, and kept in the audit log.
 */
#[CoversClass(CustomEmoji::class)]
#[CoversClass(Markdown::class)]
#[CoversClass(Messages::class)]
#[CoversClass(Access::class)]
class CustomEmojiTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** Where this test keeps its pictures. */
    private string $files = '';

    /** The pictures, the fake broadcasting and the cached list gone, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        FakeDriver::restore();
        CustomEmoji::forget();
        if ($this->files !== '') {
            exec('rm -rf ' . escapeshellarg($this->files));
        }
        $this->probeTearDown();
    }

    /**
     * Added: a wide PNG kept 128 a side, its proportions and transparency kept; names refused — a
     * standard shortcode, one taken, one not allowed — and an SVG and a text file refused. Written
     * in a message it is its picture, but not in code, and a name with none stays text; reacted
     * with when it exists only, and an emoji or a shortcode still; aliased (an alias of an alias
     * names the picture), renamed with its aliases following, removed with them and its file —
     * the message then reads `:name:` as text. Told on the presence channel; in the audit log.
     */
    #[DataProvider('databases')]
    public function testTheTeamsOwnEmoji(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        require_once ROOT . '/database/migrations/framework/conversations/2026_11_09_000007_create_conversation_emoji_table.php';
        \Pramnos\Framework\Testing\Schema::ensure([\Pramnos\Framework\Migrations\Conversations\CreateConversationEmojiTable::class], $this->db);
        $this->db->queryBuilder()->table('#PREFIX#conversation_emoji')->delete();
        CustomEmoji::forget();
        $this->files = sys_get_temp_dir() . '/pf-emoji-' . bin2hex(random_bytes(4));
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->files];
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        $png   = $this->picture(300, 150);
        $svg   = $this->text('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $plain = $this->text('hello');
        $room  = Conversations::create('channel', 'general', [$maria], $eleni);
        $fake  = FakeDriver::swap();

        // Act — added, and refused
        CustomEmoji::add('team-heart', $png, $eleni);
        $kept    = glob($this->files . '/emoji/team-heart-*.png')[0] ?? '';
        $fitted  = array_slice((array) getimagesize($kept), 0, 2);
        $alpha   = imagecolorat(imagecreatefrompng($kept), 0, 0) >> 24 & 0x7F;
        $picture = (string) file_get_contents($kept);
        $refused = [];
        foreach ([['tada', $png], ['team-heart', $png], ['A', $png], ['x', $png], ['svg-one', $svg], ['text-one', $plain]] as [$name, $file]) {
            try {
                CustomEmoji::add($name, $file, $eleni);
            } catch (\InvalidArgumentException $e) {
                $refused[$name] = $e->getMessage();
            }
        }
        CustomEmoji::alias('our-love', 'team-heart', $eleni);
        CustomEmoji::alias('our-love2', 'our-love', $eleni);
        $said = Messages::post($room, $eleni, 'We :team-heart: it, `:team-heart:` in code, :our-love: too, :nope: and :tada:');
        Messages::react($said, $maria, ':team-heart:');
        Messages::react($said, $maria, '👍');
        Messages::react($said, $maria, ':eyes:');
        $reactions = [];
        foreach ([':nope:', 'hello', '<b>'] as $wrong) {
            try {
                Messages::react($said, $maria, $wrong);
            } catch (\InvalidArgumentException) {
                $reactions[] = $wrong;
            }
        }
        $html = Messages::present([Messages::get($said)])[0]['html'];

        // Act — renamed, then removed
        CustomEmoji::rename('team-heart', 'our-heart', $eleni);
        $renamed = CustomEmoji::all();
        ob_start();
        $sent = CustomEmoji::send('our-love');
        $bytes = (string) ob_get_clean();
        $gone  = CustomEmoji::remove('our-heart', $eleni);
        $again = CustomEmoji::remove('our-heart', $eleni);
        $after = Messages::present([Messages::get($said)])[0];

        // Assert — the picture
        $this->assertNotSame('', $kept);
        $this->assertSame([128, 64], $fitted, 'fitted, its proportions kept');
        $this->assertSame(127, $alpha, 'still transparent');
        $this->assertSame(['tada', 'team-heart', 'A', 'x', 'svg-one', 'text-one'], array_keys($refused));
        $this->assertSame('There is an emoji :tada: already.', $refused['tada'], 'a standard shortcode is taken');
        $this->assertSame('An emoji is a PNG, GIF, WebP or JPEG picture.', $refused['svg-one'], 'never an SVG');
        // Assert — written and reacted with
        $this->assertSame(2, substr_count($html, '<img class="pf-conv-custom-emoji"'), 'its name and its alias');
        $this->assertStringContainsString('alt=":team-heart:" title=":team-heart:"', $html);
        $this->assertStringContainsString('<code>:team-heart:</code>', $html, 'not in code');
        $this->assertStringContainsString(':nope:', $html, 'no such emoji: text');
        $this->assertStringContainsString('🎉', $html, 'the standard shortcodes as before');
        $this->assertSame([':nope:', 'hello', '<b>'], $reactions, 'an emoji, or one of the team\'s, not any text');
        // Assert — renamed, an alias of an alias, sent, removed
        $this->assertSame(['our-heart', 'our-love', 'our-love2'], array_keys($renamed));
        $this->assertSame(1, count(array_unique(array_map(static fn (string $u): string => (string) preg_replace('/&v=\d+$/', '', $u), $renamed))), 'one picture');
        $this->assertStringContainsString('name=our-heart', $renamed['our-love2'], 'its aliases follow it');
        $this->assertTrue($sent);
        $this->assertSame($picture, $bytes, 'an alias sends its picture');
        $this->assertTrue($gone);
        $this->assertFalse($again);
        $this->assertSame([], CustomEmoji::all(), 'its aliases with it');
        $this->assertFileDoesNotExist($kept);
        $this->assertStringNotContainsString('<img', $after['html'], 'gone: read as text');
        $this->assertStringContainsString(':team-heart:', $after['html']);
        $this->assertContains(':team-heart:', array_column($after['reactions'], 'emoji'), 'reactions stay, as their text');
        // Assert — told, and kept
        $told = $fake->matching('presence-chat', 'emoji.changed');
        $this->assertCount(5, $told, 'added, two aliases, renamed, removed');
        $this->assertSame([], (array) end($told)['payload']['customEmoji']);
        $events = array_column($this->db->queryBuilder()->table('authserver.audit_log')->where('object_type', 'conversation_emoji')->getAllOrFail(), 'event_type');
        $this->assertSame(['conversations.emoji_added', 'conversations.emoji_aliased', 'conversations.emoji_aliased', 'conversations.emoji_renamed', 'conversations.emoji_removed'], $events);
    }

    /** Using them is everybody's who uses the conversations; managing them a superuser's by default. */
    #[DataProvider('databases')]
    public function testWhoManagesThem(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];

        // Act
        $somebody = Access::managesEmoji($this->user('maria'));
        $root     = Access::managesEmoji((object) ['userid' => 2, 'usertype' => 99, 'active' => 1]);

        // Assert
        $this->assertFalse($somebody);
        $this->assertTrue($root);
        $this->assertFalse(Access::managesEmoji(null));
    }

    /** A transparent PNG of this size, as a temporary file. */
    private function picture(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($height / 2), (int) ($height / 2), imagecolorallocate($image, 220, 20, 60));
        $file = tempnam(sys_get_temp_dir(), 'pf-emoji');
        imagepng($image, $file);

        return $file;
    }

    /** A temporary file with this text. */
    private function text(string $text): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pf-emoji');
        file_put_contents($file, $text);

        return $file;
    }
}
