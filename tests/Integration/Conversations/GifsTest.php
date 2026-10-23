<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Files;
use Pramnos\Conversations\Gifs;

/**
 * GIFs in the conversations, on each backend: asked from the provider by this server with the
 * application's key and a rating, each provider's answer read the same way, and a GIF that is
 * sent kept as a file of its conversation — only from the provider's own media hosts, only a GIF,
 * and never turned into a still thumbnail.
 */
#[CoversClass(Gifs::class)]
class GifsTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The tree files are kept in. */
    private string $tree = '';

    /** @var list<string> The URLs asked, in order */
    private array $asked = [];

    /** The network back, the setting cleared, the tree gone, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        Gifs::fetchWith(null);
        Settings::setSetting(Gifs::SETTING, '', false);
        if ($this->tree !== '' && is_dir($this->tree)) {
            exec('rm -rf ' . escapeshellarg($this->tree));
        }
        $this->probeTearDown();
    }

    /** Boot with these GIF settings and this network. @param array<string, string|false> $web */
    private function start(string $type, string $host, int $port, string $user, array $gifs, array $web): void
    {
        $this->boot($type, $host, $port, $user);
        $this->tree = sys_get_temp_dir() . '/pfgifs-' . bin2hex(random_bytes(4));
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->tree, 'gifs' => $gifs];
        Gifs::fetchWith(function (string $url) use ($web): string|false {
            $this->asked[] = $url;

            return $web[$url] ?? false;
        });
    }

    /** An animated-looking GIF of this size. */
    private static function gif(int $width, int $height): string
    {
        ob_start();
        imagegif(imagecreatetruecolor($width, $height));

        return (string) ob_get_clean();
    }

    /**
     * Giphy: the trending ones with no search, a search otherwise, asked with the key and the
     * rating app.php gives — an administrator's key winning — each read as a small picture and
     * the one to send, without its tracking query; an item without pictures is left out.
     */
    #[DataProvider('databases')]
    public function testGiphyIsAskedWithTheApplicationsKey(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $answer = json_encode(['data' => [
            ['id' => 'abc', 'title' => 'Χαρά', 'images' => ['fixed_width_small' => ['url' => 'https://media2.giphy.com/s.gif', 'width' => '100', 'height' => '80'],
                'downsized_medium' => ['url' => 'https://media2.giphy.com/m.gif?cid=track']]],
            ['id' => 'none', 'images' => []],
        ], 'meta' => ['status' => 200]]);
        $this->start($type, $host, $port, $user, ['key' => 'app-key', 'rating' => 'pg'], [
            'https://api.giphy.com/v1/gifs/trending?api_key=app-key&limit=24&rating=pg' => $answer,
            'https://api.giphy.com/v1/gifs/search?q=%CF%87%CE%B1%CF%81%CE%AC&api_key=admin-key&limit=24&rating=pg' => $answer,
        ]);

        // Act
        $trending = Gifs::search('');
        Settings::setSetting(Gifs::SETTING, 'admin-key', false);
        $found = Gifs::search('χαρά');

        // Assert
        $this->assertSame([['id' => 'abc', 'title' => 'Χαρά', 'preview' => ['url' => 'https://media2.giphy.com/s.gif', 'width' => 100, 'height' => 80],
            'url' => 'https://media2.giphy.com/m.gif']], $trending, 'read the same way, without its tracking query');
        $this->assertSame($trending, $found, 'a search, with the administrator\'s key');
        $this->assertSame(['giphy', true], [Gifs::provider(), Gifs::enabled()]);
    }

    /**
     * Klipy: its renditions by size, the largest within a budget — a light one for the picker, one
     * that loads quickly for the message — and its key in the path; an unknown rating is `g`.
     */
    #[DataProvider('databases')]
    public function testKlipyPicksItsRenditionsBySize(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $files = ['xs' => ['gif' => ['url' => 'https://static.klipy.com/xs.gif', 'size' => 50000, 'width' => 90, 'height' => 90]],
            'sm' => ['gif' => ['url' => 'https://static.klipy.com/sm.gif', 'size' => 300000]],
            'md' => ['gif' => ['url' => 'https://static.klipy.com/md.gif', 'size' => 1200000]],
            'hd' => ['gif' => ['url' => 'https://static.klipy.com/hd.gif', 'size' => 6000000]]];
        $this->start($type, $host, $port, $user, ['provider' => 'klipy', 'key' => 'k', 'rating' => 'nsfw'], [
            'https://api.klipy.com/api/v1/k/gifs/search?q=cat&per_page=10&rating=g' => json_encode(['result' => true, 'data' => ['data' => [
                ['slug' => 'cat', 'title' => 'Γάτα', 'file' => $files],
                ['slug' => 'huge', 'file' => ['hd' => ['gif' => ['url' => 'https://static.klipy.com/only.gif', 'size' => 9000000]]]],
            ]]]),
        ]);

        // Act
        [$cat, $huge] = Gifs::search('cat', 10);

        // Assert
        $this->assertSame(['cat', 'https://static.klipy.com/sm.gif', 'https://static.klipy.com/md.gif'], [$cat['id'], $cat['preview']['url'], $cat['url']]);
        $this->assertSame('https://static.klipy.com/only.gif', $huge['url'], 'all over the budget: the smallest there is');
    }

    /**
     * A GIF is sent as a file of the conversation, named for its title, shown itself — no still
     * thumbnail. Refused: a host that is not the provider's, plain http, what is not a GIF, and
     * anything at all where there is no key; a provider that fails or refuses says so.
     */
    #[DataProvider('databases')]
    public function testASentGifIsKeptAsAFile(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->start($type, $host, $port, $user, ['key' => 'k'], [
            'https://media1.giphy.com/media/x/giphy.gif' => self::gif(480, 270),
            'https://i.giphy.com/not-a-gif.gif' => '<html>',
            'https://api.giphy.com/v1/gifs/trending?api_key=k&limit=24&rating=g' => json_encode(['meta' => ['status' => 403, 'msg' => 'Forbidden']]),
        ]);
        $general = Conversations::create('channel', 'general', [], $this->ids['eleni']);

        // Act
        $id   = Gifs::keep($general, $this->ids['eleni'], 'https://media1.giphy.com/media/x/giphy.gif', 'Χαρά! <script>');
        $file = Files::get($id);
        $refusals = [];
        foreach ([
            fn () => Gifs::keep($general, $this->ids['eleni'], 'https://evil.example/giphy.gif'),
            fn () => Gifs::keep($general, $this->ids['eleni'], 'http://media1.giphy.com/media/x/giphy.gif'),
            fn () => Gifs::keep($general, $this->ids['eleni'], 'https://i.giphy.com/not-a-gif.gif'),
            fn () => Gifs::search(''),
        ] as $try) {
            try {
                $try();
            } catch (\InvalidArgumentException $e) {
                $refusals[] = $e->getMessage();
            }
        }
        Application::getInstance()->applicationInfo['conversations']['gifs'] = [];
        $off = null;
        try {
            Gifs::search('');
        } catch (\DomainException $e) {
            $off = $e->getMessage();
        }

        // Assert
        $this->assertSame(['Χαρά script.gif', 'image/gif', null], [$file['name'], $file['mime'], $file['thumbPath']], 'a GIF moves: no still thumbnail');
        $this->assertSame(self::gif(480, 270), file_get_contents(Files::root() . '/' . $file['path']));
        $this->assertSame(['That is not a GIF from here.', 'That is not a GIF from here.', 'That GIF could not be brought here.',
            'The GIFs could not be found just now.'], $refusals);
        $this->assertSame(['GIFs are not set up here.', false], [$off, Gifs::enabled()]);
    }
}
