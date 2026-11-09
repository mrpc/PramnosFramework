<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Visits;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Console\Commands\VisitsUpdate;
use Pramnos\Visits\GeoIp;
use Pramnos\Visits\Hits;
use Pramnos\Visits\Source;
use Pramnos\Visits\Visits;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The parts of counting the site's traffic that need no database: where a visit came from, the
 * device, the page's tags, the GeoIP file fetched and read, and `visits:update`.
 */
#[CoversClass(Source::class)]
#[CoversClass(GeoIp::class)]
#[CoversClass(VisitsUpdate::class)]
#[CoversClass(Visits::class)]
class VisitsUnitTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $info = [];

    /** A scratch directory for the files. */
    private string $dir = '';

    /** The settings kept; a scratch directory. */
    protected function setUp(): void
    {
        $this->info = Application::getInstance()->applicationInfo;
        $this->dir  = sys_get_temp_dir() . '/pf-visits-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    /** The settings and the reader given back; the files gone. */
    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
        GeoIp::useReader(null);
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    /**
     * The utm parameters win; then the referrer's host — an assistant before a search engine of
     * the same company, the application's own hosts as internal, its own additions — and nothing
     * is direct.
     */
    public function testWhereAVisitCameFrom(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['visits'] = ['sources' => ['search' => ['findit.test']]];
        $page = 'https://site.test/el/';

        // Act
        $cases = [
            Source::classify(null, [], $page),
            Source::classify('https://www.google.gr/search?q=x', [], $page),
            Source::classify('https://gemini.google.com/app', [], $page),
            Source::classify('https://chatgpt.com/', [], $page),
            Source::classify('https://l.facebook.com/l.php', [], $page),
            Source::classify('https://blog.example.org/post', [], $page),
            Source::classify('https://www.site.test/uk/', [], $page),
            Source::classify('https://shop.mine.test/', [], $page, ['mine.test']),
            Source::classify('https://findit.test/?q=1', [], $page),
            Source::classify('https://www.google.gr/', ['utm_source' => 'newsletter', 'utm_medium' => 'Email', 'utm_campaign' => 'nov'], $page),
            Source::classify(null, ['utm_source' => 'facebook', 'utm_campaign' => 'spring'], $page),
            Source::classify(null, ['utm_source' => 'partner', 'utm_medium' => 'cpc'], $page),
        ];

        // Assert
        $this->assertSame(['direct', 'search', 'ai', 'ai', 'social', 'referral', 'internal', 'internal', 'search', 'email', 'social', 'campaign'],
            array_column($cases, 'source'));
        $this->assertSame('organic', $cases[1]['medium']);
        $this->assertSame(['Email', 'nov'], [$cases[9]['medium'], $cases[9]['campaign']], 'the utm as they were sent');
        $this->assertSame('facebook', $cases[10]['medium'], 'no medium: the source stands for it');
    }

    /** A phone, a tablet and a computer, by the user agent. */
    public function testTheDevice(): void
    {
        // Arrange / Act / Assert
        $this->assertSame('mobile', Hits::device('Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) Mobile/15E148'));
        $this->assertSame('tablet', Hits::device('Mozilla/5.0 (iPad; CPU OS 17_2 like Mac OS X)'));
        $this->assertSame('tablet', Hits::device('Mozilla/5.0 (Linux; Android 14; SM-X710) Safari/537.36'), 'an Android without "Mobile"');
        $this->assertSame('desktop', Hits::device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0'));
    }

    /**
     * A page prints nothing without a site; with one, the meta, the script and the image of a
     * page without JavaScript. The sites are made whole; a host matches with `www.` and its
     * subdomains; the dimension is the application's own, cut to its column.
     */
    public function testTheTagsTheSitesAndTheDimension(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['visits'] = [];
        $none = Visits::tag();
        Application::getInstance()->applicationInfo['visits'] = [
            'sites'     => ['main' => ['hosts' => ['Site.test'], 'visitor' => 'nonsense', 'idle' => 5], 'bad' => 'x'],
            'dimension' => static fn (string $path): string => str_repeat('m', 30),
        ];

        // Act
        $tag   = Visits::tag();
        $sites = Visits::sites();

        // Assert
        $this->assertSame('', $none);
        $this->assertStringContainsString('<meta name="pf-hits"', $tag);
        $this->assertStringContainsString('pf-hits.js', $tag);
        $this->assertStringContainsString('Hits/pixel', $tag);
        $this->assertSame(['main' => ['hosts' => ['site.test'], 'visitor' => 'cookie', 'idle' => 60]], $sites, 'lower case, a known model, a minute at least');
        $this->assertSame(['site.test'], Visits::hosts());
        $this->assertSame(20, mb_strlen((string) Visits::dimension('/el/', [])));
        $this->assertTrue(Visits::spam('sub.www.spam.test') === false);
        Application::getInstance()->applicationInfo['visits']['spam'] = ['spam.test'];
        $this->assertTrue(Visits::spam('a.b.spam.test'), 'a subdomain of a listed host');
    }

    /**
     * The GeoIP file: this month's fetched, or last month's where this month's is not out; moved
     * into place whole; neither month an error. A reader's answer read into country, region and
     * city; an address that is none, or a reader that fails, is no place.
     */
    public function testTheGeoIpFileAndItsReading(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['visits'] = ['geoip' => ['path' => $this->dir . '/city.mmdb']];
        $asked = [];
        $fetch = static function (string $url) use (&$asked): ?string {
            $asked[] = $url;

            return str_contains($url, gmdate('Y-m')) ? null : (string) gzencode('MMDB');
        };

        // Act
        $month = GeoIp::update($fetch);
        $error = null;
        try {
            GeoIp::update(static fn (): ?string => null);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
        GeoIp::useReader(new class {
            /** A place, or a failure for one address. */
            public function get(string $ip): ?array
            {
                if ($ip === '198.51.100.1') {
                    throw new \RuntimeException('corrupt');
                }

                return ['country' => ['iso_code' => 'it'], 'city' => ['names' => ['it' => 'Roma']]];
            }
        });

        // Assert
        $this->assertSame(gmdate('Y-m', strtotime('first day of last month')), $month);
        $this->assertCount(2, $asked, 'this month first');
        $this->assertSame('MMDB', file_get_contents($this->dir . '/city.mmdb'));
        $this->assertFileDoesNotExist($this->dir . '/city.mmdb.part');
        $this->assertNotNull($error);
        $this->assertSame(['country' => 'IT', 'region' => null, 'city' => 'Roma'], GeoIp::lookup('203.0.113.1'));
        $this->assertNull(GeoIp::lookup('not an address'));
        $this->assertNull(GeoIp::lookup('198.51.100.1'));
    }

    /**
     * `visits:update` does nothing without a site; with one it fetches the GeoIP file (not one of
     * the application's own) and the spam list, keeping only host names; a list it cannot fetch
     * keeps the one it has and fails.
     */
    public function testTheUpdateCommand(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['visits'] = [];
        $idle = new CommandTester(new VisitsUpdate(static fn (): ?string => null));
        $idle->execute([]);
        Application::getInstance()->applicationInfo['visits'] = ['sites' => ['main' => ['hosts' => ['site.test']]], 'geoip' => ['path' => $this->dir . '/own.mmdb']];
        $fetch = static fn (string $url): ?string => $url === VisitsUpdate::SPAM_LIST ? "spam-one.test\n# a comment\nnot a host\nspam-two.test\n" : null;
        $tester = new CommandTester(new VisitsUpdate($fetch));
        $failing = new CommandTester(new VisitsUpdate(static fn (): ?string => null));

        // Act
        $code    = $tester->execute([]);
        $kept    = (string) @file_get_contents(ROOT . '/var/visits/referrer-spam.txt');
        $failed  = $failing->execute([]);

        // Assert
        $this->assertStringContainsString('nothing to update', $idle->getDisplay());
        $this->assertSame(0, $code);
        $this->assertStringContainsString('not fetched', $tester->getDisplay(), 'the application\'s own GeoIP file');
        $this->assertSame("spam-one.test\nspam-two.test\n", $kept);
        $this->assertSame(1, $failed);
        $this->assertStringContainsString('the one kept stays', $failing->getDisplay());
        $this->assertSame("spam-one.test\nspam-two.test\n", (string) file_get_contents(ROOT . '/var/visits/referrer-spam.txt'));
        @unlink(ROOT . '/var/visits/referrer-spam.txt');
    }
}
