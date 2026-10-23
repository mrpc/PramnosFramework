<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Messages;
use Pramnos\Conversations\References;
use Pramnos\Conversations\Unfurls;

/**
 * A link's preview card under a message, on each backend: which links get one, what a page or a
 * video says of itself, kept once per link, its pictures kept here, a private address refused,
 * and a card its writer takes away.
 */
#[CoversClass(Unfurls::class)]
#[CoversClass(\Pramnos\Conversations\UnfurlTask::class)]
class UnfurlsTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The tree the pictures are kept in. */
    private string $tree = '';

    /** @var array<string, string|false> The network, as this test has it: URL → body */
    private array $web = [];

    /** @var list<string> The URLs read, in order */
    private array $asked = [];

    /** The network back, the tree gone, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        Unfurls::fetchWith(null);
        References::reset();
        if ($this->tree !== '' && is_dir($this->tree)) {
            exec('rm -rf ' . escapeshellarg($this->tree));
        }
        $this->probeTearDown();
    }

    /** Boot, with the pictures in a tree of their own and the network this test's. */
    private function start(string $type, string $host, int $port, string $user, array $unfurl = []): int
    {
        $this->boot($type, $host, $port, $user);
        $this->tree = sys_get_temp_dir() . '/pfunfurl-' . bin2hex(random_bytes(4));
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30, 'files_path' => $this->tree] + ($unfurl === [] ? [] : ['unfurl' => $unfurl['unfurl']]);
        Unfurls::fetchWith(function (string $url): string|false {
            $this->asked[] = $url;

            return $this->web[$url] ?? false;
        });

        return Conversations::create('channel', 'general', [$this->ids['maria']], $this->ids['eleni']);
    }

    /** A small PNG of this size. */
    private static function png(int $width, int $height): string
    {
        ob_start();
        imagepng(imagecreatetruecolor($width, $height));

        return (string) ob_get_clean();
    }

    /**
     * A message keeps the links that get a card — not one in code, not the application's own
     * reference, not past the third, each once — and the worker reads a page's OpenGraph: its
     * title in its own charset, description, site, picture and icon kept here and given as this
     * server's addresses.
     */
    #[DataProvider('databases')]
    public function testALinkGetsItsPagesCard(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $general = $this->start($type, $host, $port, $user);
        References::match('jira', '#https://acme\.atlassian\.net/browse/([A-Z]+-\d+)#');
        // The whole page in Windows-1253, as Greek sites still serve it.
        $page = (string) iconv('UTF-8', 'WINDOWS-1253', '<html><head><meta charset="windows-1253"><title>άλλο</title>'
            . '<meta property="og:title" content="Νέα συνταγή">'
            . '<meta property="og:description" content="Βήμα βήμα"><meta property="og:site_name" content="Κουζίνα">'
            . '<meta property="og:image" content="/img/cover.png"><link rel="icon" href="/fav.png"></head><body></body></html>');
        $this->web = ['https://food.example/recipe' => $page, 'https://food.example/img/cover.png' => self::png(1200, 630), 'https://food.example/fav.png' => self::png(32, 32)];
        $body = "Δες https://Food.example/recipe#top, και `https://code.example/x`, το https://acme.atlassian.net/browse/NAN-1,\n"
            . 'https://food.example/recipe ξανά, https://a.example/1 https://b.example/2 https://c.example/3';

        // Act
        $id    = Messages::post($general, $this->ids['eleni'], $body);
        $kept  = array_column(Messages::get($id)['unfurls'], 'url');
        $shown = Unfurls::run($id);
        $card  = Messages::list($general)[0]['unfurls'][0];

        // Assert
        $this->assertSame(['https://food.example/recipe', 'https://a.example/1', 'https://b.example/2'], $kept, 'normalised, once, outside code and references, three');
        $this->assertSame(1, $shown, 'the other two show nothing');
        $this->assertCount(1, Messages::list($general)[0]['unfurls'], 'only links that show something have a card');
        $this->assertSame(['https://food.example/recipe', 'Νέα συνταγή', 'Βήμα βήμα', 'Κουζίνα', null],
            [$card['url'], $card['title'], $card['description'], $card['site'], $card['embed']], 'read in its own charset');
        $this->assertSame([1200, 630], [$card['image']['width'], $card['image']['height']]);
        $this->assertStringContainsString('Conversations/preview?name=', $card['image']['url'], 'from this server, never the other site');
        $this->assertStringContainsString('Conversations/preview?name=', (string) $card['icon']);
        $name = substr($card['image']['url'], strpos($card['image']['url'], 'name=') + 5);
        ob_start();
        $sent = Unfurls::sendImage($name);
        $bytes = (string) ob_get_clean();
        $this->assertTrue($sent);
        $this->assertSame(self::png(1200, 630), $bytes);
        $this->assertFalse(Unfurls::sendImage('../../etc/passwd'), 'a kept picture by its name only');
    }

    /**
     * A video's card comes from its site's oEmbed — the title, the channel, the picture — with the
     * player by the video's id and its proportions; a page's own oEmbed is found where it names
     * one; a tweet is its words; where oEmbed says nothing, the page.
     */
    #[DataProvider('databases')]
    public function testAVideoGetsItsPlayer(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $general = $this->start($type, $host, $port, $user);
        $video   = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10';
        $this->web = [
            'https://www.youtube.com/oembed?format=json&url=' . rawurlencode($video) => json_encode(['title' => 'Το τραγούδι', 'author_name' => 'Κανάλι',
                'provider_name' => 'YouTube', 'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', 'width' => 400, 'height' => 300]),
            'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg' => self::png(480, 360),
            'https://blog.example/post' => '<html><head><title>Ανάρτηση</title><link rel="alternate" type="application/json+oembed" href="/oembed?u=post"></head></html>',
            'https://blog.example/oembed?u=post' => json_encode(['title' => 'Από το oEmbed', 'provider_name' => 'Blog']),
            'https://publish.twitter.com/oembed?url=' . rawurlencode('https://x.com/someone/status/1') => json_encode(['author_name' => 'Someone',
                'html' => '<blockquote><p>Καλημέρα &amp; καλή εβδομάδα</p>&mdash; Someone <a href="https://x.com/someone/status/1">1 Oct</a></blockquote>', 'provider_name' => 'Twitter']),
            'https://vimeo.com/76979871' => '<html><head><meta property="og:title" content="Από τη σελίδα"></head></html>',
        ];

        // Act
        $id = Messages::post($general, $this->ids['eleni'], "$video https://blog.example/post https://x.com/someone/status/1");
        Unfurls::run($id);
        $other = Messages::post($general, $this->ids['eleni'], 'https://vimeo.com/76979871');
        // As the worker runs it.
        $controller = new \Pramnos\Application\Controller();
        $item = new \Pramnos\Queue\QueueItem($controller);
        $item->payload = (string) json_encode(['message' => $other]);
        $task = new \Pramnos\Conversations\UnfurlTask($controller);
        $task->execute($item);
        $described = $task->getDescription($item);
        [$yt, $blog, $tweet] = Messages::list($general)[0]['unfurls'];
        $vimeo = Messages::list($general)[1]['unfurls'][0];

        // Assert
        $this->assertSame(['Το τραγούδι', 'Κανάλι', 'YouTube'], [$yt['title'], $yt['author'], $yt['site']]);
        $this->assertSame(['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'aspect' => 1.3333], $yt['embed'], 'the player by its id, its proportions');
        $this->assertSame(480, $yt['image']['width']);
        $this->assertSame(['Από το oEmbed', 'Blog'], [$blog['title'], $blog['site']], 'the oEmbed the page names');
        $this->assertSame(['Someone', 'Καλημέρα & καλή εβδομάδα'], [$tweet['title'], $tweet['description']], 'a tweet is its words');
        $this->assertSame(['Από τη σελίδα', 'vimeo'], [$vimeo['title'], $vimeo['embed']['provider']], 'oEmbed silent: the page');
        $this->assertSame('Read the links of message ' . $other . ' for their previews', $described);
        $this->assertSame(\Pramnos\Conversations\UnfurlTask::class, \Pramnos\Queue\Worker::FRAMEWORK_HANDLERS[Unfurls::TASK], 'answered without registering');
    }

    /**
     * What a link shows is read once and kept a week: the same link in another message is not
     * read again; older than a week, it is. A link to a private address is never read — the real
     * network refuses it — and shows nothing.
     */
    #[DataProvider('databases')]
    public function testALinkIsReadOnceAWeekAndNeverAPrivateAddress(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $general = $this->start($type, $host, $port, $user);
        $url = 'https://news.example/item-' . bin2hex(random_bytes(3));
        $this->web = [$url => '<html><head><meta property="og:title" content="Είδηση"></head></html>'];

        // Act
        Unfurls::run(Messages::post($general, $this->ids['eleni'], $url));
        Unfurls::run(Messages::post($general, $this->ids['maria'], 'κι εδώ ' . $url));
        $once = count(array_keys($this->asked, $url, true));
        $this->db->queryBuilder()->table('pramnos.link_previews')->where('url_hash', hash('sha256', $url))->update(['fetched_at' => time() - Unfurls::FRESH - 1]);
        Unfurls::preview($url);
        $again = count(array_keys($this->asked, $url, true));
        Unfurls::fetchWith(null);
        $private = Messages::post($general, $this->ids['eleni'], 'http://127.0.0.1:9/admin');
        $shown   = Unfurls::run($private);

        // Assert
        $this->assertSame(1, $once, 'the same link, read once');
        $this->assertSame(2, $again, 'a week on, read again');
        $this->assertSame('Είδηση', Messages::list($general)[1]['unfurls'][0]['title']);
        $this->assertSame(0, $shown, 'a private address is refused');
        $this->assertSame([], Messages::list($general)[2]['unfurls']);
    }

    /**
     * Its writer takes a card off their message — nobody else may — and an edit keeps it off;
     * `conversations.unfurl` turns cards off, or allows or refuses sites; the application's own
     * pages get none.
     */
    #[DataProvider('databases')]
    public function testAWriterHidesACardAndTheApplicationChoosesTheSites(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $general = $this->start($type, $host, $port, $user);
        $this->web = ['https://one.example/' => '<title>Ένα</title>', 'https://two.example/' => '<title>Δύο</title>'];
        $id = Messages::post($general, $this->ids['eleni'], 'https://one.example/ https://two.example/');
        Unfurls::run($id);

        // Act
        $refused = null;
        try {
            Unfurls::hide($id, 'https://one.example/', $this->ids['maria']);
        } catch (\InvalidArgumentException $e) {
            $refused = $e->getMessage();
        }
        Unfurls::hide($id, 'https://one.example/', $this->ids['eleni']);
        Messages::edit($id, $this->ids['eleni'], 'https://one.example/ https://two.example/ ξανά');
        $titles = array_column(Messages::list($general)[0]['unfurls'], 'title');
        $_SERVER['HTTP_HOST'] = 'team.example';
        $own = Unfurls::urls('https://team.example/admin/x https://sub.team.example/y');
        unset($_SERVER['HTTP_HOST']);
        $app = Application::getInstance();
        $app->applicationInfo['conversations']['unfurl'] = false;
        $off = Unfurls::urls('https://one.example/');
        $app->applicationInfo['conversations']['unfurl'] = ['deny' => ['ads.example']];
        $denied = Unfurls::urls('https://x.ads.example/ https://one.example/');
        $app->applicationInfo['conversations']['unfurl'] = ['allow' => ['youtube.com']];
        $allowed = Unfurls::urls('https://m.youtube.com/watch?v=dQw4w9WgXcQ https://one.example/');

        // Assert
        $this->assertSame('Only its writer changes a message.', $refused);
        $this->assertSame(['Δύο'], $titles, 'hidden, and still after an edit');
        $this->assertSame(['https://sub.team.example/y'], $own, 'not the application\'s own pages');
        $this->assertSame([], $off);
        $this->assertSame(['https://one.example/'], $denied);
        $this->assertSame(['https://m.youtube.com/watch?v=dQw4w9WgXcQ'], $allowed);
    }

    /**
     * What shows nothing: a message without links keeps none and the worker has nothing to do; a
     * page whose title is only its own address — a wall shown to a robot — and no picture; a page
     * in a charset nobody knows, or with broken UTF-8, is still read; a link that is not one is
     * kept as written.
     */
    #[DataProvider('databases')]
    public function testWhatShowsNothing(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $general = $this->start($type, $host, $port, $user);
        $this->web = [
            'https://wall.example/' => '<html><head><title>https://wall.example/</title></head></html>',
            'https://odd.example/' => '<html><head><meta charset="x-nobody-knows"><title>Odd</title></head></html>',
            'https://broken.example/' => "<html><head><title>Broken \xff here</title></head></html>",
        ];

        // Act
        $plain = Messages::post($general, $this->ids['eleni'], 'χωρίς συνδέσμους');
        $none  = Unfurls::run($plain);
        $wall  = Unfurls::preview('https://wall.example/');
        $odd   = Unfurls::preview('https://odd.example/');
        $broken = Unfurls::preview('https://broken.example/');

        // Assert
        $this->assertSame([[], 0], [Messages::get($plain)['unfurls'], $none], 'nothing kept, nothing to read');
        $this->assertNull($wall, 'a title that is only the address shows nothing');
        $this->assertSame('Odd', $odd['title'], 'an unknown charset: read as it is');
        $this->assertStringStartsWith('Broken', $broken['title'], 'broken UTF-8 cleaned');
        $this->assertSame('not a link', Unfurls::normalize('not a link'));
    }

    /**
     * History brought in with `quiet` keeps its links too — a reader who opens an imported
     * thread later still deserves a card, not a bare URL, even though nobody is notified of it.
     */
    #[DataProvider('databases')]
    public function testQuietHistoryStillGetsACard(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $general = $this->start($type, $host, $port, $user);
        $this->web = ['https://old.example/' => '<title>Παλιά είδηση</title>'];

        // Act
        $id = Messages::post($general, $this->ids['eleni'], 'https://old.example/', ['quiet' => true]);

        // Assert — the link is kept on the message despite quiet, so the worker has something
        // to read once it runs; the moderation/mention/realtime half of post() stays skipped,
        // which is covered by the other quiet tests.
        $this->assertSame(['https://old.example/'], array_column(Messages::get($id)['unfurls'], 'url'));
        $this->assertSame(1, Unfurls::run($id));
    }

    /** The picture name a card's image URL carries after `?name=`. */
    private static function pictureName(array $card): string
    {
        parse_str((string) parse_url((string) $card['image']['url'], PHP_URL_QUERY), $q);

        return (string) ($q['name'] ?? '');
    }

    /**
     * prune() drops previews not read again past the given number of days, and the picture
     * files no remaining row still names — but a picture two links happen to share (the same
     * image URL) is not deleted while a fresher row still points at it.
     */
    #[DataProvider('databases')]
    public function testPruneDropsStalePreviewsAndTheirPictures(string $type, string $host, int $port, string $user): void
    {
        // Arrange — two links, each with its own image, and a third sharing one link's image
        $general = $this->start($type, $host, $port, $user);
        $this->web = [
            'https://stale.example/' => '<head><meta property="og:title" content="Παλιό"><meta property="og:image" content="/img.png"></head>',
            'https://fresh.example/' => '<head><meta property="og:title" content="Φρέσκο"><meta property="og:image" content="/shared.png"></head>',
            'https://shared.example/' => '<head><meta property="og:title" content="Κοινό"><meta property="og:image" content="https://fresh.example/shared.png"></head>',
            'https://stale.example/img.png' => self::png(10, 10),
            'https://fresh.example/shared.png' => self::png(12, 12),
        ];
        Unfurls::run(Messages::post($general, $this->ids['eleni'], 'https://stale.example/'));
        Unfurls::run(Messages::post($general, $this->ids['eleni'], 'https://fresh.example/'));
        Unfurls::run(Messages::post($general, $this->ids['eleni'], 'https://shared.example/'));
        $staleName  = self::pictureName(Messages::list($general)[0]['unfurls'][0]);
        $sharedName = self::pictureName(Messages::list($general)[1]['unfurls'][0]);
        $this->assertSame($sharedName, self::pictureName(Messages::list($general)[2]['unfurls'][0]), 'same image URL, same kept file');
        $staleHash = hash('sha256', 'https://stale.example/');
        $sharedHash = hash('sha256', 'https://shared.example/');
        $this->db->queryBuilder()->table('pramnos.link_previews')->whereIn('url_hash', [$staleHash, $sharedHash])->update(['fetched_at' => time() - 31 * 86400]);

        // Act — only https://stale.example/ and https://shared.example/ are past 30 days;
        // https://fresh.example/ still names the picture shared.example's row also used.
        $dropped = Unfurls::prune(30);

        // Assert
        $this->assertSame(2, $dropped);
        $this->assertSame([], $this->db->queryBuilder()->table('pramnos.link_previews')->where('url_hash', $staleHash)->getAllOrFail());
        $this->assertFileDoesNotExist($this->tree . '/previews/' . $staleName, 'the stale link\'s own picture is gone');
        $this->assertFileExists($this->tree . '/previews/' . $sharedName, 'still named by the fresh row');
    }
}
